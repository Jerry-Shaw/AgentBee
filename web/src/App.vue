<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, toRaw, watch } from 'vue';
import {
  ArrowDownToLine,
  History,
  LoaderCircle,
  MessagesSquare,
  RefreshCw,
  X,
} from 'lucide-vue-next';
import ChatMessage from './components/ChatMessage.vue';
import FilePreviewPanel from './components/FilePreviewPanel.vue';
import Composer from './components/Composer.vue';
import ConfirmDialog from './components/ConfirmDialog.vue';
import ConnectionPanel from './components/ConnectionPanel.vue';
import SessionPanel from './components/SessionPanel.vue';
import SessionDrawer from './components/SessionDrawer.vue';
import ToastStack from './components/ToastStack.vue';
import LoginWin from './components/LoginWin.vue';
import SettingsView from './components/SettingsView.vue';
import SystemLogGroup from './components/SystemLogGroup.vue';
import SubAgentPanel from './components/SubAgentPanel.vue';
import SubAgentMenu from './components/SubAgentMenu.vue';
import type { SubAgentSummary } from './components/SubAgentMenu.vue';
import { useAppViewport } from './composables/useAppViewport';
import { useI18n } from './composables/useI18n';
import { useSessions } from './composables/useSessions';
import { useTheme } from './composables/useTheme';
import { useToasts } from './composables/useToasts';
import {
  useWebSocketAgent,
  type ConnectionIssue,
} from './composables/useWebSocketAgent';
import { normalizeServerError } from './protocol/normalizers';
import { extractMessageArtifacts, isAutoOpenCandidate } from './utils/artifacts';
import { isBrowserLoadableUrl, resolveFileUrl } from './utils/fileUrl';
import type {
  ChatMessage as AgentChatMessage,
  ChatFile,
  ClientAttachment,
  ClientSettingAct,
  MemoryRecord,
  RemoteSession,
  ServerMessage,
} from './protocol/types';

interface BasicSettings {
  apiKey: string;
  apiUrl: string;
  inSandbox: boolean;
  modelName: string;
  workspacePath: string;
  workspaceUrl: string;
}

type SettingStatusTone = 'success' | 'warning' | 'error';
type MemoryReadMode = 'latest' | 'older';

type VisibleChatItem =
  | {
    key: string;
    message: AgentChatMessage;
    type: 'message';
  }
  | {
    key: string;
    messages: AgentChatMessage[];
    type: 'system-group';
  };

const chatContainer = ref<HTMLElement | null>(null);
const chatShell = ref<HTMLElement | null>(null);
const shouldAutoScroll = ref(true);
const currentView = ref<'chat' | 'settings'>('chat');
const showLoginWindow = ref(true);
const agentConfig = ref<Record<string, unknown>>(readAgentConfig());
const configJson = ref(JSON.stringify(agentConfig.value, null, 2));
const configJsonError = ref('');
const settingStatus = ref('');
const settingStatusTone = ref<SettingStatusTone>('success');
const availableModels = ref<string[]>([]);
const memoryLoading = ref(false);
const memoryDeleting = ref(false);
const memoryDeleteCandidateId = ref<number | null>(null);
const memoryError = ref('');
const memoryHasMore = ref(true);
const memoryReadMode = ref<MemoryReadMode | null>(null);
const showDebugInfo = ref(false);
const selectedSubAgentName = ref<string | null>(null);
const previewFile = ref<ChatFile | null>(null);
const subAgentPaneWidth = ref(readSubAgentPaneWidth());
const isSubAgentResizing = ref(false);
const visibleMessageCount = ref(50);
let historyRestoreHeight: number | null = null;
let restoringHistoryScroll = false;
const appVersion = __APP_VERSION__;

const SUB_AGENT_MIN_WIDTH = 300;
const SUB_AGENT_MAX_WIDTH = 680;
const CHAT_PANE_MIN_WIDTH = 360;
const SUB_AGENT_DIVIDER_WIDTH = 8;
const SUB_AGENT_WIDTH_STORAGE_KEY = 'agentbee.subAgentPaneWidth';
const HISTORY_PAGE_SIZE = 50;
const MEMORY_PAGE_SIZE = 30;
const MEMORY_LATEST_PAGE_SIZE = 50;
const MEMORY_RESPONSE_TIMEOUT_MS = 15_000;
const SESSION_RESPONSE_TIMEOUT_MS = 15_000;
const LEGACY_MEMORY_CACHE_STORAGE_KEY = 'agentbee.memoryCache.v1';
localStorage.removeItem(LEGACY_MEMORY_CACHE_STORAGE_KEY);
// 「收起侧栏」已经去掉，侧栏常驻展开。清掉遗留的键，免得以后重新引入折叠时
// 从一个陈旧的 `true` 开始（老版本默认就是收起的）。
localStorage.removeItem('agentbee.sidebarCollapsed');
const memoryRecords = ref<MemoryRecord[]>([]);
/**
 * 当前在途 memory 读取请求所属的会话 id。
 * 用来丢弃「会话已经切走之后才回来」的响应——否则上一个会话的历史会被画进新会话。
 */
let memoryRequestSessionId = '';
/** 服务端历史已经加载过的会话 id，用来避免对同一个会话重复拉取。 */
let historyLoadedForSession = '';
let pendingMemoryDeleteIds: number[] = [];
let memoryResponseTimer: number | null = null;
let pendingMemoryReadMode: MemoryReadMode | null = null;
const sessionLoading = ref(false);
const sessionDeleting = ref(false);
const sessionDeleteCandidateId = ref<string | null>(null);
/**
 * 在途的改名请求。失败时要回滚成改名前，所以得记下「原来叫什么、原来是不是手动命名的」；
 * `nextTitle` 供成功提示用。不需要 timeout：本地已经先生效了，
 * 后端没有回应也不影响用户看到的结果。
 */
let sessionRenameCandidate: {
  sessionId: string;
  nextTitle: string;
  previous: { title: string; titleEdited: boolean };
} | null = null;
const sessionError = ref('');
let sessionResponseTimer: number | null = null;
/**
 * 删除专用的超时计时器。
 * 不能复用 `sessionResponseTimer`：那个的守卫是 `sessionLoading`，删除时它一直是 false，
 * 计时器会直接空跑——会话既没删掉、界面上又没有任何解释。
 */
let sessionDeleteTimer: number | null = null;
let resizeStartX = 0;
let resizeStartWidth = 0;
let resizePointerId: number | null = null;
let chatShellResizeObserver: ResizeObserver | null = null;

const { locale, setLocale, t } = useI18n();
const { setTheme, theme } = useTheme();
const toasts = useToasts();
useAppViewport();

/**
 * 移动端布局标记。
 * 字符串必须与 `base.css` 里的媒体查询一致
 * （`@media (max-width: 820px), (hover: none) and (pointer: coarse)`），
 * 否则会出现「CSS 认为该收起侧栏、JS 却不渲染抽屉」这种错位。
 */
const MOBILE_MEDIA_QUERY = '(max-width: 820px), (hover: none) and (pointer: coarse)';
/** 顶栏那个「会话」入口按钮和左侧抽屉都只在移动端出现。 */
const isMobileLayout = ref(false);
const sessionDrawerOpen = ref(false);
let mobileMediaQuery: MediaQueryList | null = null;

function syncMobileLayout(matches: boolean) {
  isMobileLayout.value = matches;
  // 转回桌面布局时把抽屉收起来，否则会留一个盖住整个界面的浮层。
  if (!matches) sessionDrawerOpen.value = false;
}

function onMobileMediaQueryChange(event: MediaQueryListEvent) {
  syncMobileLayout(event.matches);
}

const sessions = useSessions({ defaultTitle: () => t.value.untitledSession });
sessions.loadSessions();

const agent = useWebSocketAgent({
  activeSession: () => sessions.activeSession.value,
  addMessage: sessions.addMessage,
  getSessionById: sessions.getSessionById,
  onMemoryMessage: handleMemoryMessage,
  onSessionMessage: handleSessionMessage,
  onSettingMessage: handleSettingMessage,
  onSystemMessage: handleSystemMessage,
  saveSessions: sessions.saveSessions,
  scheduleSaveSessions: sessions.scheduleSaveSessions,
  touchSession: sessions.touchSession,
});
const connectionErrorText = computed(() => formatConnectionIssue(agent.connectionError.value));
watch(() => agent.canSend.value, (canSend) => {
  if (canSend) {
    showLoginWindow.value = false;
    const sent = agent.sendSettingAct('getConfig');
    if (sent) {
      // Config will be applied in handleSettingMessage
    }
    requestModels(true);
    resetMemoryHistory();
    // 先拉会话列表：`readMemory` 必须带 sessionId，而「当前是哪个会话」要等
    // `applyRemoteSessions` 落定（可能把本地落到后端最近的一条）之后才准确。
    // 列表响应到了再拉历史（见 handleSessionMessage）；发不出去就立刻兜底拉一次。
    if (!requestSessionRead()) requestMemoryRead('latest');
    return;
  }
  memoryLoading.value = false;
  memoryDeleting.value = false;
  memoryDeleteCandidateId.value = null;
  memoryReadMode.value = null;
  pendingMemoryDeleteIds = [];
  pendingMemoryReadMode = null;
  clearMemoryResponseTimer();
  clearSessionResponseTimer();
  clearSessionDeleteTimer();
  sessionLoading.value = false;
  sessionDeleting.value = false;
  sessionDeleteCandidateId.value = null;
  // 掉线了就不该再等改名的回执：本地已经生效，回执来了也没人认领。
  sessionRenameCandidate = null;
});

const activeMeta = computed(() => {
  const url = agent.wsUrl.value.trim() || t.value.noUrl;
  return `${agent.connected.value ? t.value.activeConnected : t.value.activeWaiting} · ${url}`;
});

/**
 * 当前会话的标题，给顶栏 / 移动端头部用。
 *
 * 标题的来源只有一个：WS 返回的 `session_name`（见 `useSessions.applyRemoteSessions()`）。
 * 列表为空时 `activeSession` 是内存里的兜底会话，标题就是 i18n 占位文案。
 */
const activeSessionTitle = computed(() => {
  const title = sessions.activeSession.value.title.trim();
  return title || t.value.untitledSession;
});

/**
 * `SessionPanel` 的 props 汇总。
 * 桌面侧栏和移动抽屉用的是**同一个面板**，绑定从这里统一取，
 * 避免两处各写一份、以后加字段漏掉一个（移动端缺功能往往就是这么来的）。
 */
const sessionPanelBindings = computed(() => ({
  labels: t.value,
  sessions: sessions.sessions.value,
  activeSessionId: sessions.activeSessionId.value,
  loading: sessionLoading.value,
  streamingSessionIds: agent.streamingSessionIds.value,
  canRequest: agent.canSend.value,
  error: sessionError.value,
}));

const localMainMessages = computed(() => (
  (sessions.activeSession.value?.messages || [])
    .filter((message) => message.isSubTalk !== 1)
));

const hasOlderMessages = computed(() => (
  localMainMessages.value.length > visibleMessageCount.value
));

const visibleMainMessages = computed<AgentChatMessage[]>(() => {
  const startIndex = Math.max(0, localMainMessages.value.length - visibleMessageCount.value);
  return mergeLocalAndMemoryMessages(
    localMainMessages.value.slice(startIndex),
    memoryRecords.value,
  );
});

const visibleChatItems = computed<VisibleChatItem[]>(() => {
  const items: VisibleChatItem[] = [];
  let pendingSystemMessages: AgentChatMessage[] = [];

  function flushSystemMessages() {
    if (!pendingSystemMessages.length) return;
    items.push({
      key: `system-${pendingSystemMessages[0].id}`,
      messages: pendingSystemMessages,
      type: 'system-group',
    });
    pendingSystemMessages = [];
  }

  visibleMainMessages.value.forEach((message) => {
    // 过滤掉子agent的消息，不在主聊天区显示
    if (message.isSubTalk === 1) {
      return;
    }

    if (message.role === 'system' && !message.isRemoteHistory) {
      pendingSystemMessages.push(message);
      return;
    }

    flushSystemMessages();
    items.push({
      key: message.id,
      message,
      type: 'message',
    });
  });

  flushSystemMessages();
  return items;
});

const subAgentMessages = computed(() => {
  const messages = sessions.activeSession.value?.messages || [];
  return messages.filter((msg) => msg.isSubTalk === 1);
});

const subAgents = computed<SubAgentSummary[]>(() => {
  const agents = new Map<string, SubAgentSummary>();
  subAgentMessages.value.forEach((msg) => {
    const name = msg.WindowName;
    if (name) {
      const existing = agents.get(name);
      if (existing) {
        existing.count += 1;
        existing.lastActive = msg.time || existing.lastActive;
        existing.role ||= msg.senderRole?.trim() || msg.senderName?.trim() || '';
        existing.status = msg.status || existing.status;
      } else {
        agents.set(name, {
          name,
          count: 1,
          lastActive: msg.time || '',
          role: msg.senderRole?.trim() || msg.senderName?.trim() || '',
          status: msg.status || '',
        });
      }
    }
  });
  return Array.from(agents.values());
});

const selectedSubAgent = computed(() => (
  subAgents.value.find((agentItem) => agentItem.name === selectedSubAgentName.value) || null
));

watch(subAgents, (agents) => {
  if (
    selectedSubAgentName.value &&
    !agents.some((agentItem) => agentItem.name === selectedSubAgentName.value)
  ) {
    selectedSubAgentName.value = null;
  }
});

watch(chatShell, (nextShell, previousShell) => {
  if (previousShell) chatShellResizeObserver?.unobserve(previousShell);
  if (nextShell) chatShellResizeObserver?.observe(nextShell);
});

function onSend(
  text: string,
  attachments: ClientAttachment[],
  onDispatched: (dispatched: boolean) => void,
) {
  currentView.value = 'chat';
  // 会话列表可能被删空（空列表是合法状态）。直接发消息就先起一条，
  // 否则这一轮会挂到内存里的兜底会话上，既不在列表里也存不下来。
  if (!sessions.sessions.value.length) sessions.createSession();
  agent.sendText(text, attachments, (dispatched) => {
    onDispatched(dispatched);
    if (dispatched) maybeScrollAfterUpdate();
  });
}

function maybeScrollAfterUpdate() {
  nextTick(() => {
    if (shouldAutoScroll.value) scrollToBottom();
  });
}

function scrollToBottom() {
  if (!chatContainer.value) return;
  chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
  shouldAutoScroll.value = true;
}

function scrollToLatestAfterRender() {
  nextTick(() => {
    window.requestAnimationFrame(() => {
      scrollToBottom();
    });
  });
}

function onScroll() {
  const el = chatContainer.value;
  if (!el) return;
  shouldAutoScroll.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
  if (restoringHistoryScroll || el.scrollTop >= 80) return;

  if (hasOlderMessages.value) {
    preserveHistoryScrollAfterUpdate();
    visibleMessageCount.value += HISTORY_PAGE_SIZE;
    return;
  }

  requestMemoryHistory();
}

function preserveHistoryScrollAfterUpdate() {
  const element = chatContainer.value;
  if (!element || restoringHistoryScroll) return;
  historyRestoreHeight = element.scrollHeight;
  restoringHistoryScroll = true;
  nextTick(() => {
    window.requestAnimationFrame(() => {
      const current = chatContainer.value;
      if (current && historyRestoreHeight !== null) {
        current.scrollTop += current.scrollHeight - historyRestoreHeight;
      }
      historyRestoreHeight = null;
      restoringHistoryScroll = false;
      if (
        current &&
        current.scrollHeight <= current.clientHeight + 1 &&
        !hasOlderMessages.value
      ) {
        requestMemoryHistory();
      }
    });
  });
}

function requestMemoryHistory() {
  requestMemoryRead('older');
}

function retryMemoryHistory() {
  requestMemoryRead(memoryRecords.value.length ? 'older' : 'latest');
}

function requestMemoryRead(mode: MemoryReadMode) {
  if (!agent.canSend.value) return false;
  // 没有当前会话就没有「属于它的历史」：这时发出去后端会按空 session_id 返回**全局**记录，
  // 正好是「删光会话后旧历史又冒出来」的成因。直接不发。
  if (!sessions.activeSessionId.value) return false;
  if (mode === 'older' && !memoryHasMore.value) return false;

  if (memoryReadMode.value !== null || memoryDeleting.value) {
    if (mode === 'latest') pendingMemoryReadMode = 'latest';
    if (mode === 'older' && pendingMemoryReadMode !== 'latest') {
      pendingMemoryReadMode = 'older';
    }
    return false;
  }

  const length = mode === 'latest' ? MEMORY_LATEST_PAGE_SIZE : MEMORY_PAGE_SIZE;
  const createId = mode === 'older' ? (memoryRecords.value[0]?.create_id || 0) : 0;
  if (mode === 'older' && createId <= 0) {
    pendingMemoryReadMode = 'older';
    requestMemoryRead('latest');
    return false;
  }
  if (!agent.readMemory(length, createId)) return false;

  // 记下这次请求属于哪个会话，回来时用来丢弃「已经切走」的响应。
  memoryRequestSessionId = sessions.activeSessionId.value;
  memoryReadMode.value = mode;
  if (mode === 'older') preserveHistoryScrollAfterUpdate();
  memoryLoading.value = mode === 'older' || (mode === 'latest' && !memoryRecords.value.length);
  memoryError.value = '';
  startMemoryResponseTimer('read');
  return true;
}

function runPendingMemoryRead() {
  if (memoryReadMode.value !== null || memoryDeleting.value) return;
  const mode = pendingMemoryReadMode;
  pendingMemoryReadMode = null;
  if (!mode) return;
  window.setTimeout(() => requestMemoryRead(mode), 0);
}

function deleteMemoryMessage(createId: number) {
  if (!agent.canSend.value || memoryReadMode.value !== null || memoryDeleting.value) return;
  memoryDeleteCandidateId.value = createId;
}

function cancelMemoryDelete() {
  memoryDeleteCandidateId.value = null;
}

function confirmMemoryDelete() {
  const createId = memoryDeleteCandidateId.value;
  if (createId === null) return;
  if (!agent.deleteMemory([createId])) return;
  memoryDeleteCandidateId.value = null;
  pendingMemoryDeleteIds = [createId];
  memoryDeleting.value = true;
  memoryError.value = '';
  startMemoryResponseTimer('delete');
}

function startMemoryResponseTimer(act: 'delete' | 'read') {
  clearMemoryResponseTimer();
  memoryResponseTimer = window.setTimeout(() => {
    memoryResponseTimer = null;
    if (act === 'read') {
      const mode = memoryReadMode.value;
      memoryReadMode.value = null;
      if (mode === 'older') preserveHistoryScrollAfterUpdate();
      memoryLoading.value = false;
      if (mode === 'older' || (mode === 'latest' && !memoryRecords.value.length)) {
        memoryError.value = t.value.memoryReadTimeout;
      }
      runPendingMemoryRead();
      return;
    }
    if (act === 'delete') {
      memoryDeleting.value = false;
      pendingMemoryDeleteIds = [];
      memoryError.value = t.value.memoryDeleteTimeout;
      runPendingMemoryRead();
    }
  }, MEMORY_RESPONSE_TIMEOUT_MS);
}

function clearMemoryResponseTimer() {
  if (memoryResponseTimer === null) return;
  window.clearTimeout(memoryResponseTimer);
  memoryResponseTimer = null;
}

function updateAndResendUserMessage(messageId: string, content: string) {
  const updated = sessions.updateMessageContent(messageId, content);
  if (updated) {
    agent.resendEditedText(messageId, content);
    maybeScrollAfterUpdate();
    return;
  }

  const remoteMessage = visibleMainMessages.value.find((item) => (
    item.id === messageId && item.role === 'user' && item.isRemoteHistory
  ));
  if (!remoteMessage) return;
  agent.sendText(content);
  maybeScrollAfterUpdate();
}

function resendUserMessage(messageId: string) {
  const message = sessions.activeSession.value.messages.find((item) => (
    item.id === messageId && item.role === 'user'
  )) || visibleMainMessages.value.find((item) => (
    item.id === messageId && item.role === 'user'
  ));
  if (!message || (!message.content.trim() && !message.attachments?.length)) return;
  const attachments: ClientAttachment[] = (message.attachments || []).map((attachment) => ({
    ...attachment,
  }));
  agent.sendText(message.content, attachments);
  maybeScrollAfterUpdate();
}

function deleteSubAgent(agentName: string) {
  const session = sessions.activeSession.value;
  if (!session) return;
  session.messages = session.messages.filter((msg) => msg.WindowName !== agentName);
  if (selectedSubAgentName.value === agentName) {
    selectedSubAgentName.value = null;
  }
  sessions.saveSessions();
}

function selectSubAgent(agentName: string) {
  previewFile.value = null;
  selectedSubAgentName.value = agentName;
  nextTick(() => {
    subAgentPaneWidth.value = clampSubAgentPaneWidth(subAgentPaneWidth.value);
  });
}

function closeSubAgentPanel() {
  selectedSubAgentName.value = null;
}

function startSubAgentResize(event: PointerEvent) {
  if (event.button !== 0 || (!selectedSubAgent.value && !previewFile.value)) return;
  const target = event.currentTarget as HTMLElement;
  resizePointerId = event.pointerId;
  resizeStartX = event.clientX;
  resizeStartWidth = subAgentPaneWidth.value;
  isSubAgentResizing.value = true;
  target.setPointerCapture(event.pointerId);
  event.preventDefault();
}

function resizeSubAgentPanel(event: PointerEvent) {
  if (!isSubAgentResizing.value || resizePointerId !== event.pointerId) return;
  subAgentPaneWidth.value = clampSubAgentPaneWidth(
    resizeStartWidth + resizeStartX - event.clientX,
  );
}

function stopSubAgentResize(event: PointerEvent) {
  if (resizePointerId !== event.pointerId) return;
  const target = event.currentTarget as HTMLElement;
  if (target.hasPointerCapture(event.pointerId)) {
    target.releasePointerCapture(event.pointerId);
  }
  isSubAgentResizing.value = false;
  resizePointerId = null;
  persistSubAgentPaneWidth();
}

function resizeSubAgentWithKeyboard(event: KeyboardEvent) {
  const steps: Record<string, number> = {
    ArrowLeft: 24,
    ArrowRight: -24,
  };
  if (!(event.key in steps) && event.key !== 'Home' && event.key !== 'End') return;
  event.preventDefault();

  if (event.key === 'Home') {
    subAgentPaneWidth.value = SUB_AGENT_MIN_WIDTH;
  } else if (event.key === 'End') {
    subAgentPaneWidth.value = clampSubAgentPaneWidth(SUB_AGENT_MAX_WIDTH);
  } else {
    subAgentPaneWidth.value = clampSubAgentPaneWidth(
      subAgentPaneWidth.value + steps[event.key],
    );
  }
  persistSubAgentPaneWidth();
}

function clampSubAgentPaneWidth(width: number) {
  const shellWidth = chatShell.value?.getBoundingClientRect().width || window.innerWidth;
  const availableWidth = shellWidth - CHAT_PANE_MIN_WIDTH - SUB_AGENT_DIVIDER_WIDTH;
  const maxWidth = Math.max(
    SUB_AGENT_MIN_WIDTH,
    Math.min(SUB_AGENT_MAX_WIDTH, availableWidth),
  );
  return Math.round(Math.max(SUB_AGENT_MIN_WIDTH, Math.min(width, maxWidth)));
}

function persistSubAgentPaneWidth() {
  localStorage.setItem(SUB_AGENT_WIDTH_STORAGE_KEY, String(subAgentPaneWidth.value));
}

function readSubAgentPaneWidth() {
  const savedWidth = Number(localStorage.getItem('agentbee.subAgentPaneWidth'));
  return Number.isFinite(savedWidth) && savedWidth > 0 ? savedWidth : 420;
}

let settingsConfigRequested = false;
let refreshModelsAfterSave = false;
let reconnectAfterSave = false;
let requestModelsAfterNextConnect = false;

function openSettings() {
  previewFile.value = null;
  refreshConfigJson();
  configJsonError.value = '';
  clearSettingStatus();
  settingsConfigRequested = false;
  currentView.value = 'settings';
  requestServerConfigIfNeeded();
}

function closeSettings() {
  currentView.value = 'chat';
  scrollToLatestAfterRender();
}

function updateWsUrl(value: string) {
  agent.wsUrl.value = value;
  localStorage.setItem('agentbee.lastUrl', value);
  agent.clearConnectionError();
}

function updateWsToken(value: string) {
  agent.wsToken.value = value;
  agent.clearConnectionError();
}

function openFilePreview(file: ChatFile) {
  selectedSubAgentName.value = null;
  previewFile.value = file;
}

function closeFilePreview() {
  previewFile.value = null;
}

/**
 * 产物能否真正被浏览器渲染。
 * 只有内联内容，或能解析出 http(s)/data/blob 的地址才算数；
 * 落到 file:// 的路径（浏览器读不到后端本地文件）只保留手动预览入口，不自动打开。
 */
function canAutoPreview(file: ChatFile): boolean {
  if (file.content !== undefined) return true;
  const url = resolveFileUrl(file, {
    workspacePath: basicSettings.value.workspacePath,
    workspaceUrl: basicSettings.value.workspaceUrl,
  });
  return isBrowserLoadableUrl(url);
}

/**
 * 侧边栏自动打开：只针对「最新一轮」assistant 回复，且每个 messageId 只自动打开一次。
 * 优先级由 artifacts.ts 的 artifactRank 决定（HTML > Markdown 文档 > PDF/其它）。
 * 用轻量签名触发识别，避免思考流 / 状态变更反复跑正则。
 */
const autoPreviewOpenedIds = new Set<string>();
let pendingAutoPreview: ChatFile | null = null;

const latestAssistantMessage = computed<AgentChatMessage | null>(() => {
  const messages = localMainMessages.value;
  for (let index = messages.length - 1; index >= 0; index -= 1) {
    const message = messages[index];
    if (message.role !== 'assistant' || message.isSubTalk === 1) continue;
    return message.isRemoteHistory ? null : message;
  }
  return null;
});

watch(
  () => {
    const message = latestAssistantMessage.value;
    if (!message) return '';
    return [
      message.id,
      message.status || '',
      message.content?.length ?? 0,
      message.toolEvents?.length ?? 0,
      message.files?.length ?? 0,
    ].join('|');
  },
  () => {
    const message = latestAssistantMessage.value;
    if (!message || autoPreviewOpenedIds.has(message.id)) return;
    const target = extractMessageArtifacts(message)
      .filter((file) => isAutoOpenCandidate(file) && canAutoPreview(file))[0];
    if (!target) return;

    autoPreviewOpenedIds.add(message.id);
    // 子 agent 面板占着右侧时先挂着，等它关闭再打开，避免互相抢位置。
    if (selectedSubAgentName.value) {
      pendingAutoPreview = target;
      return;
    }
    previewFile.value = target;
  },
);

watch(selectedSubAgentName, (name) => {
  if (name || !pendingAutoPreview) return;
  if (previewFile.value) {
    pendingAutoPreview = null;
    return;
  }
  previewFile.value = pendingAutoPreview;
  pendingAutoPreview = null;
});

const basicSettings = computed<BasicSettings>(() => ({
  apiKey: readString(agentConfig.value, ['agent_llm', 'api_key']),
  apiUrl: readString(agentConfig.value, ['agent_llm', 'api_url']),
  inSandbox: readBoolean(agentConfig.value, ['sandbox_mode'], true),
  modelName: readString(agentConfig.value, ['agent_llm', 'model']),
  workspacePath: readString(agentConfig.value, ['workspace_path']),
  workspaceUrl: readString(agentConfig.value, ['workspace_url']),
}));

function updateBasicSetting(field: keyof BasicSettings, value: boolean | string) {
  const nextConfig = cloneConfig(agentConfig.value);
  if (field === 'apiUrl') setNestedValue(nextConfig, ['agent_llm', 'api_url'], String(value));
  if (field === 'apiKey') setNestedValue(nextConfig, ['agent_llm', 'api_key'], String(value));
  if (field === 'inSandbox') setNestedValue(nextConfig, ['sandbox_mode'], Boolean(value));
  if (field === 'modelName') setNestedValue(nextConfig, ['agent_llm', 'model'], String(value));
  if (field === 'workspacePath') setNestedValue(nextConfig, ['workspace_path'], String(value));
  if (field === 'workspaceUrl') setNestedValue(nextConfig, ['workspace_url'], String(value));
  applyAgentConfig(nextConfig, { syncJson: true });
  configJsonError.value = '';
}

function updateConfigJson(value: string) {
  configJson.value = value;
  try {
    const parsed = JSON.parse(value);
    if (!isRecord(parsed)) {
      configJsonError.value = t.value.configJsonObjectError;
      return;
    }
    configJsonError.value = '';
    applyAgentConfig(parsed, { syncJson: false });
  } catch (error) {
    configJsonError.value = error instanceof Error ? error.message : t.value.configJsonParseError;
  }
}

function requestServerConfig() {
  sendSettingRequest('getConfig');
}

function requestServerConfigIfNeeded() {
  if (currentView.value !== 'settings' || settingsConfigRequested || !agent.canSend.value) return;
  settingsConfigRequested = true;
  requestServerConfig();
}

function requestDefaultServerConfig() {
  sendSettingRequest('getDefaultConfig');
}

function saveServerConfig() {
  const parsed = parseConfigJson(configJson.value);
  if (!parsed) return;
  saveAgentConfig(parsed);
}

function saveAgentConfig(
  config: Record<string, unknown>,
  options: { reconnect?: boolean; refreshModels?: boolean } = {},
) {
  const normalized = normalizeAgentConfig(config);
  applyAgentConfig(normalized, { syncJson: true });
  const sent = sendSettingRequest('saveConfig', normalized);
  if (!sent) return;
  refreshModelsAfterSave = options.refreshModels ?? true;
  reconnectAfterSave = options.reconnect ?? false;
}

function selectComposerModel(modelName: string) {
  if (!modelName || modelName === basicSettings.value.modelName) return;
  const nextConfig = cloneConfig(agentConfig.value);
  setNestedValue(nextConfig, ['agent_llm', 'model'], modelName);
  saveAgentConfig(nextConfig, { reconnect: true, refreshModels: true });
}

function requestModels(silent = false) {
  if (silent && !agent.canSend.value) return;
  const sent = agent.sendSystemAct('getModels');
  if (!sent) return;
  if (!silent) setSettingStatus(`${t.value.systemRequestSent}: getModels`, 'warning');
}

function sendSettingRequest(act: ClientSettingAct, content?: unknown) {
  const sent = agent.sendSettingAct(act, content);
  if (!sent) return false;
  setSettingStatus(`${t.value.settingRequestSent}: ${act}`, 'warning');
  return true;
}

function handleMemoryMessage(act: string, msg: ServerMessage) {
  // 会话 CRUD 目前是 memory 类型下的 act，转发给会话处理。
  if (act === 'readSession' || act === 'deleteSession' || act === 'renameSession') {
    handleSessionMessage(act, msg);
    return;
  }

  // 兜底：后端 `process_memory` 的每个 case 都会补 `$content['act'] = $act`，
  // 只有 default（`$data_content['act'] ?? 'unknown'` 认不出来的 act）把 content 整个换成
  // `{status, error}`，**响应里没有 act**（见 message.php + go.php 的 `['type'=>…] + $content`）。
  // 所以「发出一个后端不认识的 act」会表现为一条无 act 的 memory 错误。
  // 此刻若正好有在途的改名请求，就按改名失败处理——否则标题会一直停在用户改的名字上，
  // 后端却从没接受过。等服务端补上 case，响应自带 act，自然走上面的正常路径。
  if (!act && sessionRenameCandidate && normalizeServerError(msg)) {
    handleSessionMessage('renameSession', msg);
    return;
  }

  const errorMessage = normalizeServerError(msg);

  if (act === 'read') {
    const mode = memoryReadMode.value;
    if (!mode) return;
    // 会话已经切走之后才回来的响应：直接丢掉，否则会把上一个会话的历史画进新会话。
    // 注意不能动 memoryReadMode —— 它属于当前在途的那个请求。
    if (memoryRequestSessionId !== sessions.activeSessionId.value) return;
    clearMemoryResponseTimer();
    memoryReadMode.value = null;
    if (mode === 'older') preserveHistoryScrollAfterUpdate();
    memoryLoading.value = false;
    if (errorMessage) {
      if (mode === 'older' || (mode === 'latest' && !memoryRecords.value.length)) {
        memoryError.value = errorMessage;
      }
      runPendingMemoryRead();
      return;
    }

    const page = sortUniqueMemoryRecords(parseMemoryRecords(msg.data));
    const responseTotal = toNonNegativeInteger(msg.total);
    if (mode === 'latest') applyLatestMemorySnapshot(page, responseTotal);
    if (mode === 'older') applyOlderMemoryPage(page, responseTotal);
    // 记下「这个会话的历史已经拉过了」，会话列表回来时就不必重复拉。
    historyLoadedForSession = memoryRequestSessionId;
    memoryError.value = '';
    runPendingMemoryRead();
    return;
  }

  if (act === 'delete') {
    if (!memoryDeleting.value) return;
    clearMemoryResponseTimer();
    preserveHistoryScrollAfterUpdate();
    memoryDeleting.value = false;
    if (errorMessage) {
      memoryError.value = errorMessage;
      pendingMemoryDeleteIds = [];
      return;
    }

    const deleted = toNonNegativeInteger(msg.deleted);
    const expected = pendingMemoryDeleteIds.length;
    const deletedIds = new Set(pendingMemoryDeleteIds);
    pendingMemoryDeleteIds = [];

    if (deleted !== expected) {
      memoryError.value = t.value.memoryDeleteMismatch;
      return;
    }

    memoryRecords.value = memoryRecords.value
      .filter((record) => !deletedIds.has(record.create_id));
    memoryError.value = '';
  }
}

// ---------------------------------------------------------------------------
// 会话（session）：readSession / deleteSession，形如 memory
// ---------------------------------------------------------------------------

function requestSessionRead(): boolean {
  if (!agent.canSend.value) return false;
  if (!agent.readSessions()) return false;
  sessionLoading.value = true;
  sessionError.value = '';
  // 会话列表超时也要把历史拉起来，否则聊天区会一直空着。
  startSessionResponseTimer(() => requestMemoryRead('latest'));
  return true;
}

function startSessionResponseTimer(onTimeout?: () => void) {
  clearSessionResponseTimer();
  sessionResponseTimer = window.setTimeout(() => {
    sessionResponseTimer = null;
    if (!sessionLoading.value) return;
    sessionLoading.value = false;
    sessionError.value = t.value.sessionReadTimeout;
    onTimeout?.();
  }, SESSION_RESPONSE_TIMEOUT_MS);
}

function clearSessionResponseTimer() {
  if (sessionResponseTimer === null) return;
  window.clearTimeout(sessionResponseTimer);
  sessionResponseTimer = null;
}

/**
 * 删除的超时兜底：超时就当成「失败」弹提示，并把确认框收掉。
 * 没有它的话，后端不回执（掉线、服务端异常）时用户只会看到确认框一直挂着，
 * 既没有成功也没有失败的结论。
 */
function startSessionDeleteTimer() {
  clearSessionDeleteTimer();
  sessionDeleteTimer = window.setTimeout(() => {
    sessionDeleteTimer = null;
    if (!sessionDeleting.value) return;
    sessionDeleting.value = false;
    sessionDeleteCandidateId.value = null;
    toasts.pushToast(t.value.sessionDeleteTimeout, 'error');
  }, SESSION_RESPONSE_TIMEOUT_MS);
}

function clearSessionDeleteTimer() {
  if (sessionDeleteTimer === null) return;
  window.clearTimeout(sessionDeleteTimer);
  sessionDeleteTimer = null;
}

function handleSessionMessage(act: string, msg: ServerMessage) {
  const errorMessage = normalizeServerError(msg);

  if (act === 'readSession') {
    clearSessionResponseTimer();
    sessionLoading.value = false;
    if (errorMessage) {
      sessionError.value = errorMessage;
      // 会话列表拿不到，至少把当前会话的历史拉起来，别让聊天区空着。
      requestMemoryRead('latest');
      return;
    }
    // 后端把 content 展开到了顶层，所以 sessions 可能在 msg.data 也可能直接在 msg 上。
    sessions.applyRemoteSessions(parseRemoteSessions(msg.data ?? (msg as { sessions?: unknown }).sessions));
    sessionError.value = '';
    // `applyRemoteSessions` 可能把本地落到后端最近的一条（首次连接，或当前会话已被删）。
    // 这时必须补拉那个会话的历史；已经加载过当前会话的历史就不重复拉。
    if (historyLoadedForSession !== sessions.activeSessionId.value) {
      requestMemoryRead('latest');
    }
    return;
  }

  if (act === 'deleteSession') {
    clearSessionDeleteTimer();
    sessionDeleting.value = false;
    if (errorMessage) {
      // 后端没能删掉（缺会话 ID、服务端异常…）：本地**保留**这条会话，
      // 否则下次 readSession 会把它又带回来，看起来就像「删了又复活」。
      sessionDeleteCandidateId.value = null;
      sessionError.value = '';
      toasts.pushToast(`${t.value.sessionDeleteFailed}：${errorMessage}`, 'error');
      return;
    }
    // 后端确认删掉了，本地跟着移除。
    if (sessionDeleteCandidateId.value) {
      const deletedId = sessionDeleteCandidateId.value;
      const deletedName = sessions.getSessionById(deletedId)?.title || '';
      const wasActive = deletedId === sessions.activeSessionId.value;
      sessions.removeSession(deletedId);
      sessionDeleteCandidateId.value = null;
      // 删掉的正是当前会话时落点已经换人，历史也得跟着换。
      if (wasActive) reloadActiveSessionHistory();
      sessionError.value = '';
      toasts.pushToast(
        deletedName ? `${t.value.sessionDeleted}：${deletedName}` : t.value.sessionDeleted,
        'success',
      );
    }
    return;
  }

  if (act === 'renameSession') {
    const pending = sessionRenameCandidate;
    sessionRenameCandidate = null;
    // 没有在途的改名（比如后端主动推的一条）：没什么可对齐的。
    if (!pending) return;
    if (errorMessage) {
      // 后端没改成功：把标题退回原名，别让本地和服务端不一致。
      sessions.restoreSessionTitle(
        pending.sessionId,
        pending.previous.title,
        pending.previous.titleEdited,
      );
      sessionError.value = '';
      toasts.pushToast(
        `${t.value.sessionRenameFailed}：${errorMessage}，${t.value.sessionRenameRolledBack}`,
        'error',
      );
      return;
    }
    // 成功后用后端返回的名字对齐（后端可能自己做过规范化 / 截断）。
    // 后端把 content 展开到顶层，所以名字可能在 msg 上，也可能在 msg.data 里。
    const payload = (msg.data ?? msg) as { session_name?: unknown };
    const serverName = String(payload.session_name ?? '').trim();
    // `renameSession()` 返回规范化后的标题；后端没回名字就用本地这次的。
    const finalName = (serverName && sessions.renameSession(pending.sessionId, serverName))
      || pending.nextTitle;
    sessionError.value = '';
    toasts.pushToast(`${t.value.sessionRenamed}：${finalName}`, 'success');
  }
}

function createSession() {
  sessions.createSession();
  previewFile.value = null;
  selectedSubAgentName.value = null;
  sessionError.value = '';
  // 新会话在服务端还没有任何记录，重拉后历史会是空的，不会带上别的会话的记录。
  reloadActiveSessionHistory();
  // 移动端：点了「新建会话」就该直接进聊天区，抽屉和设置页都让位。
  leaveMobileDrawer();
  maybeScrollAfterUpdate();
}

function openSessionDrawer() {
  sessionDrawerOpen.value = true;
}

function closeSessionDrawer() {
  sessionDrawerOpen.value = false;
}

/**
 * 移动端在抽屉里操作完（选会话 / 新建会话）后的收尾：收起抽屉并回到聊天。
 * 桌面端不动 `currentView`——那边侧栏和聊天区是并列的，点会话不该把设置页关掉。
 */
function leaveMobileDrawer() {
  closeSessionDrawer();
  if (isMobileLayout.value) currentView.value = 'chat';
}

function selectSession(sessionId: string) {
  if (sessionId === sessions.activeSessionId.value) {
    // 移动端点的是当前会话：把抽屉收起来看聊天就好（不用重拉历史）。
    leaveMobileDrawer();
    return;
  }
  // 流式输出期间也允许切换：每一轮都记了归属会话，后端推来的内容会落回原会话，
  // 面板上给正在输出的那条打「生成中」标记即可。
  if (!sessions.switchSession(sessionId)) return;
  previewFile.value = null;
  selectedSubAgentName.value = null;
  sessionError.value = '';
  // 历史是按会话过滤的，切过去就得重新拉这个会话自己的记录。
  reloadActiveSessionHistory();
  // 移动端：选完会话把抽屉收掉，否则还得再点一次返回。
  leaveMobileDrawer();
  maybeScrollAfterUpdate();
}

/**
 * 会话重命名。策略是「本地先生效，再同步给后端」：
 *
 * - 请求形如 `{ type:'memory', sessionId, content:{ act:'renameSession', session_name } }`
 *   （见 `useWebSocketAgent.renameSession()`）：**sessionId 在顶层**，content 里只有 `session_name`。
 * - 本地立刻改好，界面上不会有等待感；离线时只改本地，不发注定失败的请求。
 * - 结果用右下角提示播报（成功 / 失败）；后端返回 error 还会把标题回滚成改名前。
 */
function renameSession(sessionId: string, title: string) {
  const target = sessions.getSessionById(sessionId);
  if (!target) return;
  // 回滚要连 `titleEdited` 一起还原，所以先把「改名前长什么样」记下来。
  const previous = { title: target.title, titleEdited: Boolean(target.titleEdited) };

  const renamed = sessions.renameSession(sessionId, title);
  if (!renamed) return;
  sessionError.value = '';

  // 没连上就只改本地：明确说明这次不会同步到服务端，别让人以为已经生效了。
  if (!agent.canSend.value) {
    toasts.pushToast(t.value.sessionRenameLocal, 'info');
    return;
  }
  if (!agent.renameSession(sessionId, renamed)) return;
  // 成功 / 失败都等后端回执再弹（本地已经改了，但服务端认不认要以回执为准）。
  sessionRenameCandidate = { sessionId, nextTitle: renamed, previous };
}

function requestSessionDelete(sessionId: string) {
  if (!agent.canSend.value) {
    // 没连上就只删本地，不给后端发请求。
    const deletedName = sessions.getSessionById(sessionId)?.title || '';
    const wasActive = sessionId === sessions.activeSessionId.value;
    const removed = sessions.removeSession(sessionId);
    // 删的是当前会话：清掉它残留的历史，别让它继续挂在聊天区。
    if (wasActive) reloadActiveSessionHistory();
    if (removed) {
      toasts.pushToast(
        deletedName ? `${t.value.sessionDeleteLocal}：${deletedName}` : t.value.sessionDeleteLocal,
        'info',
      );
    }
    return;
  }
  sessionDeleteCandidateId.value = sessionId;
}

function cancelSessionDelete() {
  sessionDeleteCandidateId.value = null;
}

function confirmSessionDelete() {
  const sessionId = sessionDeleteCandidateId.value;
  if (!sessionId) return;
  const deletedName = sessions.getSessionById(sessionId)?.title || '';
  sessionDeleting.value = true;
  sessionError.value = '';

  const sent = agent.deleteSession(sessionId);
  if (sent) {
    // 等后端回执：成功 / 失败都会弹提示；一直没回执则由超时兜底报失败。
    startSessionDeleteTimer();
    return;
  }
  // 发送失败（多半是掉线）就只清本地。
  sessionDeleting.value = false;
  const removed = sessions.removeSession(sessionId);
  sessionDeleteCandidateId.value = null;
  if (removed) {
    toasts.pushToast(
      deletedName ? `${t.value.sessionDeleteLocal}：${deletedName}` : t.value.sessionDeleteLocal,
      'info',
    );
  }
}

function handleSettingMessage(act: string, content: unknown, msg: ServerMessage) {
  const errorMessage = normalizeServerError(msg);
  if (errorMessage) {
    setSettingStatus(errorMessage, 'error');
    return;
  }

  const config = parseSettingConfig(content);
  if (config && (act === 'getConfig' || act === 'getDefaultConfig' || !act)) {
    configJsonError.value = '';
    applyAgentConfig(config, { syncJson: true });
    setSettingStatus(act === 'getDefaultConfig'
      ? t.value.defaultConfigLoaded
      : t.value.serverConfigLoaded);
    return;
  }

  if (act === 'saveConfig') {
    setSettingStatus(t.value.serverConfigSaved);
    const shouldRefreshModels = refreshModelsAfterSave;
    const shouldReconnect = reconnectAfterSave;
    refreshModelsAfterSave = false;
    reconnectAfterSave = false;
    if (shouldReconnect) {
      if (shouldRefreshModels) requestModelsAfterNextConnect = true;
      agent.reconnect();
      return;
    }
    if (shouldRefreshModels) requestModels();
    return;
  }

  setSettingStatus(msg.message || t.value.settingResponseReceived);
}

function handleSystemMessage(act: string, content: unknown, msg: ServerMessage) {
  const errorMessage = normalizeServerError(msg);
  if (errorMessage) {
    setSettingStatus(errorMessage, 'error');
    return;
  }

  if (act === 'getModels') {
    const models = parseModels(content);
    if (models.length) {
      availableModels.value = models;
      setSettingStatus(`${t.value.modelsLoaded}: ${models.length}`);
      return;
    }
  }

  setSettingStatus(msg.message || t.value.systemResponseReceived);
}

function setSettingStatus(message: string, tone: SettingStatusTone = 'success') {
  settingStatus.value = message;
  settingStatusTone.value = tone;
}

function clearSettingStatus() {
  settingStatus.value = '';
  settingStatusTone.value = 'success';
}

function parseConfigJson(value: string): Record<string, unknown> | null {
  try {
    const parsed = JSON.parse(value);
    if (!isRecord(parsed)) {
      configJsonError.value = t.value.configJsonObjectError;
      return null;
    }
    configJsonError.value = '';
    return parsed;
  } catch (error) {
    configJsonError.value = error instanceof Error ? error.message : t.value.configJsonParseError;
    return null;
  }
}

function parseSettingConfig(value: unknown): Record<string, unknown> | null {
  if (isRecord(value)) return value;
  if (typeof value !== 'string') return null;
  try {
    const parsed = JSON.parse(value);
    return isRecord(parsed) ? parsed : null;
  } catch {
    return null;
  }
}

function parseModels(value: unknown): string[] {
  const models = new Set<string>();
  const seen = new WeakSet<object>();

  function visit(node: unknown, depth = 0) {
    if (depth > 6 || node == null) return;
    if (typeof node === 'string') {
      models.add(node);
      return;
    }
    if (typeof node === 'object') {
      if (seen.has(node as object)) return;
      seen.add(node as object);
    }
    if (typeof node !== 'object') return;
    if (Array.isArray(node)) {
      node.forEach((item) => visit(item, depth + 1));
      return;
    }
    Object.entries(node).forEach(([entryKey, entryValue]) => {
      if (entryKey === 'id' && typeof entryValue === 'string') models.add(entryValue);
      if (Array.isArray(entryValue) || isRecord(entryValue)) visit(entryValue, depth + 1);
    });
  }

  visit(value);
  return Array.from(models);
}

function parseMemoryRecords(value: unknown): MemoryRecord[] {
  if (!Array.isArray(value)) return [];

  return value.flatMap((item) => {
    if (!isRecord(item)) return [];
    const createId = Number(item.create_id);
    if (!Number.isSafeInteger(createId) || createId <= 0) return [];

    const role = typeof item.role === 'string' && ['user', 'assistant', 'system', 'tool'].includes(item.role)
      ? item.role as MemoryRecord['role']
      : 'system';
    const content = typeof item.content === 'string'
      ? item.content
      : JSON.stringify(item.content ?? '', null, 2);

    return [{
      content,
      create_id: createId,
      create_time: typeof item.create_time === 'string' && item.create_time
        ? item.create_time
        : new Date(Math.floor(createId / 1000)).toLocaleString('zh-CN'),
      level: typeof item.level === 'string' ? item.level : 'misc',
      role,
    }];
  });
}

function applyLatestMemorySnapshot(page: MemoryRecord[], responseTotal: number | null) {
  const nextMemoryRecords = page.slice(-MEMORY_LATEST_PAGE_SIZE);
  if (!memoryRecordListsEqual(memoryRecords.value, nextMemoryRecords)) {
    memoryRecords.value = nextMemoryRecords;
    maybeScrollAfterUpdate();
  }

  memoryHasMore.value = responseTotal !== null
    ? page.length < responseTotal
    : page.length === MEMORY_LATEST_PAGE_SIZE;
}

function applyOlderMemoryPage(page: MemoryRecord[], responseTotal: number | null) {
  const previousRecordCount = memoryRecords.value.length;
  const nextMemoryRecords = sortUniqueMemoryRecords([
    ...page,
    ...memoryRecords.value,
  ]);

  if (!memoryRecordListsEqual(memoryRecords.value, nextMemoryRecords)) {
    memoryRecords.value = nextMemoryRecords;
  }
  memoryHasMore.value = responseTotal !== null
    ? page.length < responseTotal
    : page.length === MEMORY_PAGE_SIZE;
  if (!page.length || nextMemoryRecords.length === previousRecordCount) {
    memoryHasMore.value = false;
  }
}

/** 解析后端 readSession 的返回，容错掉任何缺 session_id 的脏数据。 */
function parseRemoteSessions(value: unknown): RemoteSession[] {
  const list = Array.isArray(value) ? value : [];
  const seen = new Set<string>();

  return list.flatMap((item) => {
    if (!isRecord(item)) return [];
    const id = String(item.session_id ?? item.sessionId ?? item.id ?? '').trim();
    if (!id || seen.has(id)) return [];
    seen.add(id);

    const name = [item.session_name, item.sessionName, item.name]
      .find((candidate): candidate is string => typeof candidate === 'string' && candidate.trim() !== '');
    const time = [item.create_time, item.createTime, item.created_at]
      .find((candidate) => typeof candidate === 'string' || typeof candidate === 'number');

    return [{
      session_id: id,
      session_name: name,
      create_time: time as string | number | undefined,
    }];
  });
}

function sortUniqueMemoryRecords(records: MemoryRecord[]): MemoryRecord[] {
  const uniqueRecords = new Map<number, MemoryRecord>();
  records.forEach((record) => uniqueRecords.set(record.create_id, record));
  return Array.from(uniqueRecords.values())
    .sort((left, right) => left.create_id - right.create_id);
}

function memoryRecordListsEqual(left: MemoryRecord[], right: MemoryRecord[]): boolean {
  if (left.length !== right.length) return false;
  return left.every((record, index) => {
    const candidate = right[index];
    return record.create_id === candidate.create_id &&
      record.create_time === candidate.create_time &&
      record.role === candidate.role &&
      record.level === candidate.level &&
      record.content === candidate.content;
  });
}

function mergeLocalAndMemoryMessages(
  localMessages: AgentChatMessage[],
  records: MemoryRecord[],
): AgentChatMessage[] {
  const memoryToLocalIndex = new Map<number, number>();
  let localSearchEnd = localMessages.length;

  for (let memoryIndex = records.length - 1; memoryIndex >= 0; memoryIndex -= 1) {
    const record = records[memoryIndex];
    const signature = getMessageSignature(record.role, record.content);
    let localIndex = localSearchEnd - 1;
    while (
      localIndex >= 0 &&
      getMessageSignature(localMessages[localIndex].role, localMessages[localIndex].content) !== signature
    ) {
      localIndex -= 1;
    }
    if (localIndex < 0) continue;
    memoryToLocalIndex.set(memoryIndex, localIndex);
    localSearchEnd = localIndex;
  }

  const messages: AgentChatMessage[] = [];
  let nextLocalIndex = 0;
  records.forEach((record, memoryIndex) => {
    const matchedLocalIndex = memoryToLocalIndex.get(memoryIndex);
    if (matchedLocalIndex === undefined) {
      messages.push(memoryRecordToChatMessage(record));
      return;
    }

    while (nextLocalIndex < matchedLocalIndex) {
      messages.push(localMessages[nextLocalIndex]);
      nextLocalIndex += 1;
    }
    messages.push({
      ...localMessages[matchedLocalIndex],
      isRemoteHistory: true,
      memoryCreateId: record.create_id,
    });
    nextLocalIndex = matchedLocalIndex + 1;
  });

  while (nextLocalIndex < localMessages.length) {
    messages.push(localMessages[nextLocalIndex]);
    nextLocalIndex += 1;
  }
  return messages;
}

function memoryRecordToChatMessage(record: MemoryRecord): AgentChatMessage {
  return {
    id: `memory-${record.create_id}`,
    role: record.role,
    content: record.content,
    time: record.create_time,
    status: record.role === 'assistant' ? 'done' : undefined,
    memoryCreateId: record.create_id,
    isRemoteHistory: true,
  };
}

function getMessageSignature(role: AgentChatMessage['role'], content: string): string {
  return `${role}\u0000${content.replace(/\r\n/g, '\n').trim()}`;
}

function toNonNegativeInteger(value: unknown): number | null {
  const number = Number(value);
  return Number.isSafeInteger(number) && number >= 0 ? number : null;
}

function resetMemoryHistory() {
  clearMemoryResponseTimer();
  memoryDeleteCandidateId.value = null;
  memoryRecords.value = [];
  memoryHasMore.value = true;
  memoryLoading.value = false;
  memoryDeleting.value = false;
  memoryError.value = '';
  memoryReadMode.value = null;
  pendingMemoryDeleteIds = [];
  pendingMemoryReadMode = null;
  memoryRequestSessionId = '';
  historyLoadedForSession = '';
}

/**
 * 切会话 / 新建会话后重新拉取**这个会话**的历史。
 *
 * 服务端历史是按 `session_id` 过滤的（见 `useWebSocketAgent.readMemory` 里的顶层 sessionId），
 * 所以必须重拉：否则聊天区会继续显示上一个会话的记录。新建会话时最明显——
 * 本地消息是空的，屏幕上却还挂着旧会话的历史。
 */
function reloadActiveSessionHistory() {
  resetMemoryHistory();
  if (agent.canSend.value) requestMemoryRead('latest');
}

function handlePageHide(event: PageTransitionEvent) {
  if (event.persisted) return;
  agent.clearPendingTurns();
  sessions.clearLocalHistory();
  localStorage.removeItem(LEGACY_MEMORY_CACHE_STORAGE_KEY);
  resetMemoryHistory();
}

function handlePageShow(event: PageTransitionEvent) {
  agent.resumeConnection();
  if (event.persisted) {
    sessions.loadSessions();
    resetMemoryHistory();
    if (agent.canSend.value) requestMemoryRead('latest');
  }
}

onMounted(() => {
  mobileMediaQuery = window.matchMedia(MOBILE_MEDIA_QUERY);
  syncMobileLayout(mobileMediaQuery.matches);
  mobileMediaQuery.addEventListener('change', onMobileMediaQueryChange);
  chatShellResizeObserver = new ResizeObserver(() => {
    if (!selectedSubAgent.value) return;
    const clampedWidth = clampSubAgentPaneWidth(subAgentPaneWidth.value);
    if (clampedWidth !== subAgentPaneWidth.value) {
      subAgentPaneWidth.value = clampedWidth;
      persistSubAgentPaneWidth();
    }
  });
  if (chatShell.value) chatShellResizeObserver.observe(chatShell.value);
  window.addEventListener('pagehide', handlePageHide);
  window.addEventListener('pageshow', handlePageShow);
  scrollToLatestAfterRender();
});

onBeforeUnmount(() => {
  clearMemoryResponseTimer();
  clearSessionResponseTimer();
  clearSessionDeleteTimer();
  mobileMediaQuery?.removeEventListener('change', onMobileMediaQueryChange);
  mobileMediaQuery = null;
  window.removeEventListener('pagehide', handlePageHide);
  window.removeEventListener('pageshow', handlePageShow);
  chatShellResizeObserver?.disconnect();
  chatShellResizeObserver = null;
});

function readAgentConfig(): Record<string, unknown> {
  localStorage.removeItem('agentbee.agentConfig');
  return createDefaultAgentConfig();
}

function applyAgentConfig(
  nextConfig: Record<string, unknown>,
  options: { syncJson?: boolean } = {},
) {
  const syncJson = options.syncJson ?? true;
  agentConfig.value = normalizeAgentConfig(cloneConfig(nextConfig));
  if (syncJson) refreshConfigJson();
}

function refreshConfigJson() {
  configJson.value = stringifyConfig(agentConfig.value);
}

function stringifyConfig(config: Record<string, unknown>) {
  return JSON.stringify(toRaw(config), null, 2);
}

function cloneConfig(config: Record<string, unknown>): Record<string, unknown> {
  return JSON.parse(stringifyConfig(config)) as Record<string, unknown>;
}

function createDefaultAgentConfig(): Record<string, unknown> {
  return {
    agent_server: {
      host: '127.0.0.1',
      port: 8686,
      ping_interval: 60,
    },
    agent_llm: {
      api_url: 'http://127.0.0.1:1234/v1',
      api_key: 'sk-lm-ru6XiZDE:WImxJO82hxm5L76fNcaK',
      model: 'qwen3.6-35b-a3b-genesis-v2-apex',
      org_id: '',
      hw_hash: '',
      timeout: 600,
      keep_reasons: false,
      params: {
        max_completion_tokens: 65536,
        temperature: 0.8,
        min_p: 0,
        top_p: 0.95,
        top_k: 40,
        frequency_penalty: 0,
        presence_penalty: 1,
        repetition_penalty: 1,
        enable_thinking: false,
        stop: [
          '<|im_end|>',
          '<|endoftext|>',
        ],
        chat_template_kwargs: {
          enable_thinking: false,
        },
        extra_body: {
          enable_thinking: false,
        },
        thinking: {
          type: 'enable',
        },
      },
    },
    max_ctx_len: 50,
    memory_limit: '4G',
    sandbox_mode: false,
    workspace_path: '',
    workspace_url: '',
    agent_debug: 'trace',
    socket_debug: false,
  };
}

function normalizeAgentConfig(config: Record<string, unknown>): Record<string, unknown> {
  const nextConfig = clonePlainRecord(config);
  const toolsConfig = isRecord(nextConfig.agent_tools) ? nextConfig.agent_tools : null;
  const llmConfig = isRecord(nextConfig.agent_llm) ? nextConfig.agent_llm : null;
  const serverConfig = isRecord(nextConfig.agent_server) ? nextConfig.agent_server : null;

  if (!('workspace_path' in nextConfig) && toolsConfig && typeof toolsConfig.workspace_path === 'string') {
    nextConfig.workspace_path = toolsConfig.workspace_path;
  }
  if (!('workspace_url' in nextConfig)) {
    if (toolsConfig && typeof toolsConfig.workspace_url === 'string') nextConfig.workspace_url = toolsConfig.workspace_url;
    else if (serverConfig && typeof serverConfig.workspace_url === 'string') nextConfig.workspace_url = serverConfig.workspace_url;
  }
  if (!('sandbox_mode' in nextConfig) && toolsConfig && typeof toolsConfig.in_sandbox === 'boolean') {
    nextConfig.sandbox_mode = toolsConfig.in_sandbox;
  }
  if (llmConfig && typeof llmConfig.work_name === 'string') {
    llmConfig.worker_name = llmConfig.work_name;
  }

  delete nextConfig.agent_tools;
  if (llmConfig) delete llmConfig.work_name;
  if ('debug' in nextConfig && !('agent_debug' in nextConfig)) {
    nextConfig.agent_debug = nextConfig.debug;
  }
  delete nextConfig.debug;

  return nextConfig;
}

function clonePlainRecord(config: Record<string, unknown>): Record<string, unknown> {
  return JSON.parse(JSON.stringify(config)) as Record<string, unknown>;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function readString(source: Record<string, unknown>, path: string[]): string {
  const value = readNestedValue(source, path);
  return typeof value === 'string' ? value : '';
}

function readBoolean(source: Record<string, unknown>, path: string[], fallback: boolean): boolean {
  const value = readNestedValue(source, path);
  if (typeof value === 'boolean') return value;
  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase();
    if (normalized === 'true') return true;
    if (normalized === 'false') return false;
  }
  return fallback;
}

function readNestedValue(source: Record<string, unknown>, path: string[]): unknown {
  return path.reduce<unknown>((current, key) => (
    isRecord(current) ? current[key] : undefined
  ), source);
}

function setNestedValue(source: Record<string, unknown>, path: string[], value: unknown) {
  let current = source;
  path.slice(0, -1).forEach((key) => {
    if (!isRecord(current[key])) current[key] = {};
    current = current[key] as Record<string, unknown>;
  });
  current[path[path.length - 1]] = value;
}

function formatConnectionIssue(issue: ConnectionIssue | null): string {
  if (!issue) return '';
  const labels = t.value;
  const titleByKind: Record<ConnectionIssue['kind'], string> = {
    closed: labels.connectionClosed,
    initialization: labels.connectionInitializationFailed,
    'invalid-url': labels.connectionInvalidUrl,
    'mixed-content': labels.connectionMixedContent,
    network: labels.connectionNetworkError,
    offline: labels.connectionOffline,
  };
  const lines = [titleByKind[issue.kind]];
  if (issue.url) lines.push(`${labels.connectionUrlLabel}: ${redactConnectionUrl(issue.url)}`);
  if (issue.code !== undefined) lines.push(`${labels.connectionCloseCode}: ${issue.code}`);
  if (issue.reason) lines.push(`${labels.connectionCloseReason}: ${issue.reason}`);
  if (issue.wasClean !== undefined) {
    lines.push(issue.wasClean ? labels.connectionWasClean : labels.connectionWasNotClean);
  }
  if (issue.detail) lines.push(issue.detail);
  if (issue.kind === 'network' || issue.code === 1006) {
    lines.push(labels.connectionBrowserLimited);
  }
  lines.push(`${labels.connectionTime}: ${new Date(issue.occurredAt).toLocaleString(locale.value === 'zh' ? 'zh-CN' : 'en-US')}`);
  return lines.join('\n');
}

function redactConnectionUrl(value: string): string {
  try {
    const parsed = new URL(value);
    if (parsed.username) parsed.username = '***';
    if (parsed.password) parsed.password = '***';
    if (parsed.search) parsed.search = '?...';
    return parsed.toString();
  } catch {
    return value;
  }
}
</script>

<template>
  <LoginWin
    v-if="showLoginWindow"
    :connection-error="connectionErrorText"
    :connecting="agent.connecting.value"
    :labels="t"
    :ws-token="agent.wsToken.value"
    :ws-url="agent.wsUrl.value"
    @connect="agent.connect"
    @update:ws-token="updateWsToken"
    @update:ws-url="updateWsUrl"
  />

  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <!--
          移动端的会话入口。放在 `.brand`（移动端这一行就是「头部」）而不是 `.topbar`：
          移动端聊天页的 `.topbar` 本来就是隐藏的（矮屏容器查询还会再隐藏一次），
          入口放进去就会跟着消失，没法开抽屉。
        -->
        <button
          v-if="isMobileLayout"
          type="button"
          class="icon-button brand-session-trigger"
          :title="t.openSessions"
          :aria-label="t.openSessions"
          :aria-expanded="sessionDrawerOpen"
          @click="openSessionDrawer"
        >
          <MessagesSquare :size="18" aria-hidden="true" />
        </button>
        <!--
          移动端这一行就是「头部」，会话列表收在抽屉里，这里是唯一能看出
          「我现在在哪个会话」的地方——所以品牌名让位给会话标题。
          桌面端保持品牌名：侧栏下面就是会话列表，标题放顶栏更合适。
        -->
        <div class="brand-copy">
          <h1 :title="isMobileLayout ? activeSessionTitle : 'AgentBee Web'">
            {{ isMobileLayout ? activeSessionTitle : 'AgentBee Web' }}
          </h1>
          <p>{{ t.tagline }}</p>
        </div>
      </div>

      <div class="sidebar-body">
        <SessionPanel
          v-bind="sessionPanelBindings"
          @new-session="createSession"
          @refresh="requestSessionRead"
          @select="selectSession"
          @remove="requestSessionDelete"
          @rename="renameSession"
        />

        <ConnectionPanel
          :labels="t"
          :auto-connect-paused="agent.autoConnectPaused.value"
          :connected="agent.connected.value"
          :connecting="agent.connecting.value"
          :connection-error="connectionErrorText"
          :connection-state="agent.connectionState.value"
          @connect="agent.connect"
          @open-settings="openSettings"
        />
      </div>
    </aside>

    <main class="main">
      <header class="topbar">
        <div class="topbar-title">
          <strong :title="currentView === 'settings' ? t.settings : activeSessionTitle">
            {{ currentView === 'settings' ? t.settings : activeSessionTitle }}
          </strong>
          <span>{{ activeMeta }}</span>
        </div>
        <div class="topbar-actions">
          <SubAgentMenu
            v-if="currentView === 'chat' && subAgents.length"
            :agents="subAgents"
            :labels="t"
            :selected-agent-name="selectedSubAgentName"
            @delete-sub-agent="deleteSubAgent"
            @select-agent="selectSubAgent"
          />
          <button
            v-if="currentView === 'settings'"
            type="button"
            class="topbar-close icon-button"
            :title="t.closeSettings"
            @click="closeSettings"
          >
            <X :size="17" aria-hidden="true" />
          </button>
        </div>
      </header>

      <div
        v-if="currentView === 'chat'"
        ref="chatShell"
        class="chat-shell"
        :class="{
          'has-subagent': selectedSubAgent,
          'has-preview': previewFile,
          'is-resizing': isSubAgentResizing,
        }"
        :style="{ '--subagent-pane-width': `${subAgentPaneWidth}px` }"
      >
        <div class="chat-pane">
          <section ref="chatContainer" class="chat-area" @scroll="onScroll">
            <div v-if="memoryLoading" class="history-load-state" role="status">
              <LoaderCircle class="spin" :size="15" aria-hidden="true" />
              <span>{{ t.loadingMemory }}</span>
            </div>
            <div v-else-if="memoryError" class="history-load-state error" role="alert">
              <span>{{ memoryError }}</span>
              <button
                type="button"
                class="history-retry-button"
                :title="t.retry"
                :disabled="!agent.canSend.value"
                @click="retryMemoryHistory"
              >
                <RefreshCw :size="14" aria-hidden="true" />
              </button>
            </div>
            <div
              v-else-if="!memoryHasMore && !hasOlderMessages && agent.canSend.value"
              class="history-load-state"
            >
              <History :size="14" aria-hidden="true" />
              <span>{{ t.noEarlierMemory }}</span>
            </div>
            <div v-if="!visibleChatItems.length && !memoryLoading" class="empty">
              {{ t.empty }}
            </div>
            <template v-for="item in visibleChatItems" :key="item.key">
              <SystemLogGroup
                v-if="item.type === 'system-group'"
                :labels="t"
                :messages="item.messages"
              />
              <ChatMessage
                v-else
                :labels="t"
                :message="item.message"
                :show-debug-info="showDebugInfo"
                :deleting-memory="memoryDeleting && pendingMemoryDeleteIds.includes(item.message.memoryCreateId || 0)"
                :memory-delete-disabled="!agent.canSend.value || memoryReadMode !== null || memoryDeleting"
                @delete-memory-message="deleteMemoryMessage"
                @resend-user-message="resendUserMessage"
                @update-user-message="updateAndResendUserMessage"
                @preview-file="openFilePreview"
              />
            </template>
          </section>
          <button
            v-if="!shouldAutoScroll"
            type="button"
            class="scroll-bottom-floating"
            :title="t.scrollToBottom"
            @click="scrollToBottom"
          >
            <ArrowDownToLine :size="18" aria-hidden="true" />
          </button>
        </div>

        <div
          v-if="selectedSubAgent || previewFile"
          class="subagent-resizer"
          role="separator"
          tabindex="0"
          aria-orientation="vertical"
          :aria-label="previewFile ? t.resizePreviewPanel : t.resizeSubAgentPanel"
          :aria-valuemin="SUB_AGENT_MIN_WIDTH"
          :aria-valuemax="SUB_AGENT_MAX_WIDTH"
          :aria-valuenow="subAgentPaneWidth"
          @keydown="resizeSubAgentWithKeyboard"
          @pointerdown="startSubAgentResize"
          @pointermove="resizeSubAgentPanel"
          @pointerup="stopSubAgentResize"
          @pointercancel="stopSubAgentResize"
        ></div>

        <SubAgentPanel
          v-if="selectedSubAgent"
          :agent="selectedSubAgent"
          :labels="t"
          :messages="subAgentMessages"
          :show-debug-info="showDebugInfo"
          @close="closeSubAgentPanel"
          @resend-user-message="resendUserMessage"
          @update-user-message="updateAndResendUserMessage"
          @preview-file="openFilePreview"
        />

        <FilePreviewPanel
          v-if="previewFile"
          :file="previewFile"
          :labels="t"
          :workspace-path="basicSettings.workspacePath"
          :workspace-url="basicSettings.workspaceUrl"
          @close="closeFilePreview"
        />
      </div>

      <SettingsView
        v-else-if="currentView === 'settings'"
        :app-version="appVersion"
        :basic-settings="basicSettings"
        :connected="agent.connected.value"
        :connecting="agent.connecting.value"
        :connection-error="connectionErrorText"
        :config-json="configJson"
        :config-json-error="configJsonError"
        :labels="t"
        :locale="locale"
        :setting-status="settingStatus"
        :setting-status-tone="settingStatusTone"
        :show-debug-info="showDebugInfo"
        :theme="theme"
        :ws-token="agent.wsToken.value"
        :ws-url="agent.wsUrl.value"
        :workspace-path="basicSettings.workspacePath"
        :workspace-url="basicSettings.workspaceUrl"
        @connect="agent.connect"
        @disconnect="agent.disconnect"
        @get-config="requestServerConfig"
        @get-default-config="requestDefaultServerConfig"
        @save-config="saveServerConfig"
        @set-locale="setLocale"
        @set-theme="setTheme"
        @update:basic-setting="updateBasicSetting"
        @update:config-json="updateConfigJson"
        @update:show-debug-info="showDebugInfo = $event"
        @update:ws-token="updateWsToken"
        @update:ws-url="updateWsUrl"
      />

      <Composer
        v-if="currentView === 'chat'"
        :labels="t"
        :disabled="!agent.canSend.value"
        :available-models="availableModels"
        :model-name="basicSettings.modelName"
        @select-model="selectComposerModel"
        @send="onSend"
        @stop="agent.stopCurrent"
      />
    </main>
  </div>

  <ConfirmDialog
    v-if="memoryDeleteCandidateId !== null"
    :cancel-label="t.cancel"
    :confirm-label="t.deleteAction"
    :message="t.deleteMemoryConfirm"
    :title="t.deleteMemoryConfirmTitle"
    @cancel="cancelMemoryDelete"
    @confirm="confirmMemoryDelete"
  />

  <ConfirmDialog
    v-if="sessionDeleteCandidateId !== null"
    :cancel-label="t.cancel"
    :confirm-label="t.deleteAction"
    :message="t.deleteSessionConfirm"
    :title="t.deleteSessionConfirmTitle"
    @cancel="cancelSessionDelete"
    @confirm="confirmSessionDelete"
  />

  <SessionDrawer
    v-if="isMobileLayout"
    :label="t.sessions"
    :open="sessionDrawerOpen"
    @close="closeSessionDrawer"
  >
    <SessionPanel
      v-bind="sessionPanelBindings"
      show-close
      @close="closeSessionDrawer"
      @new-session="createSession"
      @refresh="requestSessionRead"
      @select="selectSession"
      @remove="requestSessionDelete"
      @rename="renameSession"
    />
  </SessionDrawer>

  <ToastStack
    :toasts="toasts.toasts.value"
    :dismiss-label="t.dismissToast"
    @dismiss="toasts.dismissToast"
  />
</template>
