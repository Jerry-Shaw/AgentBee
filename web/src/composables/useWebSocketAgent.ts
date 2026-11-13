import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type {
  ChatAttachment,
  ChatMessage,
  ChatSession,
  ClientAttachment,
  ClientMessage,
  ClientSessionAct,
  ClientSettingAct,
  ClientSystemAct,
  ServerMessage,
} from '../protocol/types';
import {
  getMessageId,
  getServerType,
  normalizePayload,
  normalizeServerError,
  normalizeImageEvent,
  normalizeFileEvent,
  normalizeToolEvent,
  parseServerMessage,
} from '../protocol/normalizers';
import { makeId, nowTime } from './useSessions';
import { RESET_COMMAND } from '../utils/commands';

const NO_RESPONSE_TIMEOUT_MS = 5 * 60_000;
const AUTO_CONNECT_WINDOW_MS = 60_000;
const AUTO_CONNECT_BASE_RETRY_MS = 1000;
const AUTO_CONNECT_MAX_RETRY_MS = 15_000;
const FOREGROUND_RECONNECT_THRESHOLD_MS = 30_000;
const STREAM_FLUSH_MS = 80;
const STOP_REQUEST_TTL_MS = 15_000;
const WS_TOKEN_STORAGE_KEY = 'agentbee.wsToken';
/** 会话请求走哪个 type；后端目前只在 process_memory 里实现了这两个 act。 */
const SESSION_REQUEST_TYPE: 'memory' | 'session' = 'memory';

export type ConnectionIssueKind =
  | 'closed'
  | 'initialization'
  | 'invalid-url'
  | 'mixed-content'
  | 'network'
  | 'offline';

export interface ConnectionIssue {
  code?: number;
  detail?: string;
  kind: ConnectionIssueKind;
  occurredAt: number;
  reason?: string;
  url: string;
  wasClean?: boolean;
}

export type ConnectionState =
  | 'connected'
  | 'connecting'
  | 'idle'
  | 'offline'
  | 'paused'
  | 'retrying';

interface PendingStreamUpdate {
  content: string;
  think: string;
  message?: ServerMessage;
}

interface QueuedTextSend {
  attachments: ClientAttachment[];
  onDispatched?: (dispatched: boolean) => void;
  text: string;
}

/**
 * 一轮对话的归属。messageId → 这条 assistant 消息所在会话。
 * 有了它，用户在流式输出期间切到别的会话，后续事件仍能落回原来的会话。
 */
interface PendingTurn {
  assistantId: string | null;
  sessionId: string;
}

/** 用户点击停止后等待服务端 abort 回执的本地目标。 */
interface StopRequest {
  messageId: string;
  requestedAt: number;
}

interface UseWebSocketAgentOptions {
  activeSession: () => ChatSession | null;
  addMessage: (role: ChatMessage['role'], content: string, extra?: Partial<ChatMessage>) => ChatMessage;
  /** 按 id 找会话；找不到（比如已被删除）时返回 null，宁可丢弃也不写错会话。 */
  getSessionById?: (sessionId: string) => ChatSession | null;
  onSettingMessage?: (act: string, content: unknown, msg: ServerMessage) => void;
  onSystemMessage?: (act: string, content: unknown, msg: ServerMessage) => void;
  onMemoryMessage?: (act: string, msg: ServerMessage) => void;
  onSessionMessage?: (act: string, msg: ServerMessage) => void;
  registerSession: () => void;
  scheduleCreateSession: () => void;
  touchSession: (session: ChatSession) => void;
}

export function useWebSocketAgent(options: UseWebSocketAgentOptions) {
  const wsUrl = ref(localStorage.getItem('agentbee.lastUrl') || 'ws://127.0.0.1:8686');
  const wsToken = ref(localStorage.getItem(WS_TOKEN_STORAGE_KEY) || '');
  const connected = ref(false);
  const connecting = ref(false);
  const connectionError = ref<ConnectionIssue | null>(null);
  const autoConnectPaused = ref(false);
  const retryScheduled = ref(false);
  const isOnline = ref(navigator.onLine);
  const pendingTurns = ref(new Map<string, PendingTurn>());
  const socket = ref<WebSocket | null>(null);
  // 保留已结束轮次的归属，迟到事件也不能回退到当前会话。
  const turnSessionIds = new Map<string, string>();
  // 用户点击停止后，服务端 abort 回执可能晚于 end 到达；按会话记住要收尾的那一轮。
  const stopRequests = new Map<string, StopRequest>();
  const noResponseTimers = new Map<string, number>();
  const pendingStreamUpdates = new Map<string, PendingStreamUpdate>();
  let streamFlushTimer: number | null = null;
  let autoRetryTimer: number | null = null;
  let manualDisconnect = false;
  let currentConnectIsAuto = false;
  let autoConnectAttempts = 0;
  let autoConnectStartedAt = 0;
  let reconnectAfterClose = false;
  let connectionRequested = false;
  let hiddenAt: number | null = null;
  let queuedTextSend: QueuedTextSend | null = null;
  // readGroups has no response correlation ID: keep exactly one request in flight.
  let groupsRequestSessionId = '';
  let queuedGroupsSessionId = '';

  const canSend = computed(() => connected.value && socket.value?.readyState === WebSocket.OPEN);
  const hasPendingTurns = computed(() => pendingTurns.value.size > 0);
  /** 正在流式输出的会话 id 列表：会话列表用它打「生成中」标记，但不阻止切换。 */
  const streamingSessionIds = computed(() => {
    const ids = new Set<string>();
    pendingTurns.value.forEach((turn) => {
      if (turn?.sessionId) ids.add(turn.sessionId);
    });
    return Array.from(ids);
  });
  const connectionState = computed<ConnectionState>(() => {
    if (connected.value) return 'connected';
    if (!isOnline.value) return 'offline';
    if (connecting.value) return currentConnectIsAuto ? 'retrying' : 'connecting';
    if (retryScheduled.value) return 'retrying';
    if (autoConnectPaused.value) return 'paused';
    return 'idle';
  });

  function connect(automatic = false): boolean {
    if (
      socket.value &&
      (socket.value.readyState === WebSocket.OPEN || socket.value.readyState === WebSocket.CONNECTING)
    ) {
      if (!automatic) options.addMessage('system', 'A connection is already active.');
      return true;
    }

    const url = wsUrl.value.trim();
    const token = wsToken.value.trim();
    connectionRequested = true;
    manualDisconnect = false;
    connectionError.value = null;
    if (!automatic) {
      autoConnectPaused.value = false;
      autoConnectAttempts = 0;
      autoConnectStartedAt = 0;
    }
    const validationIssue = validateWebSocketUrl(url);
    if (validationIssue) {
      connectionError.value = validationIssue;
      options.addMessage('error', 'WebSocket URL must start with ws:// or wss://.');
      if (automatic) pauseAutoConnect('Auto connection stopped because the WebSocket URL is invalid. Waiting for manual connection.');
      return false;
    }

    if (!navigator.onLine) {
      isOnline.value = false;
      connecting.value = false;
      connectionError.value = makeConnectionIssue('offline', url);
      return true;
    }

    clearAutoRetryTimer();
    currentConnectIsAuto = automatic;
    connecting.value = true;
    options.addMessage('system', `Connecting to ${url}`);
    let nextSocket: WebSocket;
    try {
      nextSocket = createWebSocketConnection(url, token);
      socket.value = nextSocket;
    } catch (error) {
      connecting.value = false;
      connectionError.value = makeConnectionIssue(
        'initialization',
        url,
        error instanceof Error ? error.message : String(error),
      );
      socket.value = null;
      const detail = error instanceof Error ? ` ${error.message}` : '';
      options.addMessage('error', `WebSocket connection could not be initialized.${detail}`);
      if (automatic) pauseAutoConnect('Auto connection stopped because the WebSocket token is invalid. Waiting for manual connection.');
      return false;
    }

    nextSocket.onopen = () => {
      if (socket.value !== nextSocket) return;
      connected.value = true;
      connecting.value = false;
      connectionError.value = null;
      autoConnectPaused.value = false;
      retryScheduled.value = false;
      autoConnectAttempts = 0;
      autoConnectStartedAt = 0;
      localStorage.setItem('agentbee.lastUrl', url);
      if (token) {
        localStorage.setItem(WS_TOKEN_STORAGE_KEY, token);
      } else {
        localStorage.removeItem(WS_TOKEN_STORAGE_KEY);
      }
      options.addMessage('system', 'WebSocket connected.');
      flushQueuedTextSend();
    };

    nextSocket.onmessage = (event) => {
      if (socket.value !== nextSocket) return;
      if (typeof event.data === 'string') handleServerMessage(event.data);
    };

    nextSocket.onerror = () => {
      if (socket.value !== nextSocket) return;
      connectionError.value = makeConnectionIssue('network', url);
      // if (!currentConnectIsAuto) {
      //   options.addMessage('error', 'WebSocket connection error. Waiting for close details.');
      // }
    };

    nextSocket.onclose = (event) => {
      if (socket.value !== nextSocket) return;
      connected.value = false;
      connecting.value = false;
      groupsRequestSessionId = '';
      queuedGroupsSessionId = '';
      retryScheduled.value = false;
      const reason = event.reason ? `, reason: ${event.reason}` : '';
      finishAllPendingWithoutResponse();
      const shouldReconnect = !manualDisconnect;
      if (shouldReconnect) {
        connectionError.value = {
          ...makeConnectionIssue('closed', url),
          code: event.code,
          reason: event.reason || undefined,
          wasClean: event.wasClean,
        };
      } else {
        connectionError.value = null;
      }
      if (!currentConnectIsAuto || manualDisconnect) {
        options.addMessage('system', `Connection closed. code=${event.code}${reason}`);
      }
      socket.value = null;
      if (reconnectAfterClose) {
        reconnectAfterClose = false;
        window.setTimeout(() => connect(false), 100);
        return;
      }
      if (shouldReconnect) {
        startAutoConnect(false);
      }
    };
    return true;
  }

  function disconnect() {
    rejectQueuedTextSend('The waiting message was not sent because the connection was disconnected.');
    manualDisconnect = true;
    connectionRequested = false;
    autoConnectPaused.value = true;
    reconnectAfterClose = false;
    clearAutoRetryTimer();
    if (socket.value) {
      socket.value.close(1000, 'User disconnected');
    } else {
      connected.value = false;
      connecting.value = false;
      connectionError.value = null;
    }
  }

  function clearConnectionError() {
    if (!connecting.value) connectionError.value = null;
  }

  function reconnect() {
    clearAutoRetryTimer();
    connectionRequested = true;
    autoConnectPaused.value = false;
    manualDisconnect = false;
    currentConnectIsAuto = false;

    if (socket.value && socket.value.readyState !== WebSocket.CLOSED) {
      reconnectAfterClose = true;
      socket.value.close(1000, 'Reconnecting');
      return;
    }

    window.setTimeout(() => connect(false), 0);
  }

  function startAutoConnect(resetWindow = true) {
    if (autoConnectPaused.value || connected.value || connecting.value) return;
    if (document.visibilityState === 'hidden') return;
    const startsNewWindow = resetWindow || !autoConnectStartedAt;
    if (startsNewWindow) {
      autoConnectStartedAt = Date.now();
      autoConnectAttempts = 0;
    }
    scheduleAutoReconnect(startsNewWindow ? 0 : getAutoReconnectDelay());
  }

  function resumeConnection() {
    if (!connectionRequested || manualDisconnect) return;
    isOnline.value = navigator.onLine;
    if (!isOnline.value) {
      connectionError.value = makeConnectionIssue('offline', wsUrl.value.trim());
      return;
    }
    if (canSend.value || connecting.value) return;
    autoConnectPaused.value = false;
    autoConnectStartedAt = Date.now();
    autoConnectAttempts = 0;
    scheduleAutoReconnect(0);
  }

  function sendText(
    text: string,
    attachments: ClientAttachment[] = [],
    onDispatched?: (dispatched: boolean) => void,
  ): boolean {
    const trimmed = text.trim();
    if (!trimmed && !attachments.length) {
      onDispatched?.(false);
      return false;
    }

    if (canSend.value) {
      const dispatched = dispatchText(trimmed, attachments);
      onDispatched?.(dispatched);
      return dispatched;
    }

    if (queuedTextSend) {
      options.addMessage('error', 'Another message is already waiting for the connection.');
      onDispatched?.(false);
      return false;
    }

    queuedTextSend = {
      attachments: attachments.map((attachment) => ({ ...attachment })),
      onDispatched,
      text: trimmed,
    };
    options.addMessage('system', 'Connection unavailable. Connecting before sending.');

    if (
      socket.value &&
      (socket.value.readyState === WebSocket.CONNECTING || connecting.value)
    ) {
      return true;
    }

    if (connect(false)) return true;
    rejectQueuedTextSend();
    return false;
  }

  function dispatchText(text: string, attachments: ClientAttachment[]): boolean {
    if (!canSend.value) return false;

    const session = options.activeSession();
    if (!session) return false;
    // 新的一轮开始，上一轮还没等到回执的停止请求作废。
    stopRequests.delete(session.id);

    const messageId = makeId();
    // `/reset` 是指令不是聊天内容：照常发出去、照常等后端那句确认，
    // 但不给它在聊天区留一条「/reset」的用户气泡（顺带也不会拿它给会话起标题）。
    if (text !== RESET_COMMAND) {
      options.addMessage('user', text, {
        attachments: toChatAttachments(attachments),
        messageId,
      });
    }
    pendingTurns.value.set(messageId, { assistantId: null, sessionId: session.id });
    ensureAssistantMessage(messageId);
    startNoResponseTimer(messageId);

    sendJson({
      sessionId: session.id,
      messageId,
      createdAt: new Date().toISOString(),
      ...createClientContentPayload(text, attachments),
    });
    return true;
  }

  function flushQueuedTextSend() {
    const queued = queuedTextSend;
    if (!queued) return;
    queuedTextSend = null;
    const dispatched = dispatchText(queued.text, queued.attachments);
    queued.onDispatched?.(dispatched);
  }

  function rejectQueuedTextSend(message?: string) {
    const queued = queuedTextSend;
    if (!queued) return;
    queuedTextSend = null;
    queued.onDispatched?.(false);
    if (message) options.addMessage('error', message);
  }

  function resendEditedText(userMessageId: string, text: string) {
    if (!canSend.value) {
      options.addMessage('error', 'Not connected, edited message was not sent.');
      return;
    }

    const trimmed = text.trim();

    const session = options.activeSession();
    if (!session) return;

    const userMessage = session.messages.find((message) => (
      message.id === userMessageId &&
      message.role === 'user'
    ));
    if (!userMessage) return;

    const attachments = toClientAttachments(userMessage.attachments || []);
    if (!trimmed && !attachments.length) return;

    const previousMessageId = userMessage.messageId || null;
    const messageId = makeId();
    if (previousMessageId) {
      removeAssistantForMessage(session, previousMessageId);
    }
    userMessage.messageId = messageId;
    pendingTurns.value.set(messageId, { assistantId: null, sessionId: session.id });
    ensureAssistantMessage(messageId);
    startNoResponseTimer(messageId);
    options.touchSession(session);
    options.registerSession();

    sendJson({
      sessionId: session.id,
      messageId,
      createdAt: new Date().toISOString(),
      ...createClientContentPayload(trimmed, attachments),
    });
  }

  function stopCurrent() {
    if (!canSend.value) return;
    // 优先停当前会话正在跑的那一轮；当前会话没有在跑的，就停最近发起的那一轮。
    const pendingMessageId = getActiveSessionPendingMessageId() || getLatestPendingMessageId();
    // 有在途轮次时，归属会话取**那一轮自己的**会话：用户可能已经切走了，
    // 要停的必须是真正在跑的那一轮。只有没有在途轮次时才退回当前会话
    // ——这样 sessionId 永远不会是 null。
    const session = resolveTurnSession(pendingMessageId).session || options.activeSession();
    if (!session) return false;

    // 没有 pendingTurn 时（比如工具段落的 end 刚把这一轮收尾），
    // 用最近一轮的 messageId 作为停止目标；abort 回执到达时据此找回气泡。
    const targetMessageId = pendingMessageId || getLatestTurnMessageId(session);
    if (targetMessageId) {
      stopRequests.set(session.id, { messageId: targetMessageId, requestedAt: Date.now() });
    }

    // 后端把 `/abort` 当作指令前缀处理（`go.php` 里 `'/abort' === $data['type']`），
    // 不会反射成 `process_abort`；服务端「已停止生成」的回执仍然是 `type: 'abort'`。
    console.info('[abort-debug] send', {
      sessionId: session.id, messageId: targetMessageId,
      userIds: session.messages.filter(message => message.role === 'user').map(message => message.id),
    });
    sendJson({ type: '/abort', sessionId: session.id, messageId: targetMessageId || makeId() });

    // 不在本地收尾，也不写 "Stop request sent." 日志：
    // 服务端收到 `/abort` 后会回 `type: 'abort'`（或 close/end），由那条回执决定
    // 这一轮是 stopped、done 还是被移除；回执到达前继续展示已经流出的内容。
    // 否则点击停止会先冒出「已停止生成」，迟到的 content 又把它翻回加载态。
    return true;
  }

  function sendSettingAct(act: ClientSettingAct, content?: unknown) {
    if (!canSend.value) {
      options.addMessage('error', 'Not connected, setting request was not sent.');
      return false;
    }
    sendJson({
      type: 'setting',
      content: content === undefined ? { act } : { act, data: content },
    });
    return true;
  }

  function sendSystemAct(act: ClientSystemAct, content?: unknown) {
    if (!canSend.value) {
      options.addMessage('error', 'Not connected, system request was not sent.');
      return false;
    }
    sendJson({
      type: 'system',
      content: content === undefined ? { act } : { act, data: content },
    });
    return true;
  }

  /**
   * 出站消息的 **顶层 `sessionId`**，统一从这里取。
   *
   * 后端 `go.php` 的派发是
   * `$this->message->$type_method($socket_id, $data['content'], $data['sessionId'] ?? '')`——
   * `sessionId` 只从**顶层**读，再作为第三个参数进 `process_*`。
   * 所以：**`sessionId` 一律放顶层，`content` 里只放 act 自己的参数**
   * （`session_name` / `length` / `create_id` …）。
   * 目前只有 `process_memory` 真正用它（read 的会话过滤、renameSession / deleteSession 的目标会话）；
   * 其余 `process_*` 是两参数签名，多传一个会被 PHP 忽略。
   */
  function currentSessionId(sessionId?: string): string {
    const explicit = (sessionId || '').trim();
    if (explicit) return explicit;
    return options.activeSession()?.id || '';
  }

  function readGroups(targetSessionId?: string): boolean {
    const sessionId = currentSessionId(targetSessionId);
    if (!canSend.value || !sessionId) return false;
    if (groupsRequestSessionId) {
      queuedGroupsSessionId = sessionId;
      return true;
    }
    groupsRequestSessionId = sessionId;
    sendJson({ type: 'memory', sessionId, content: { act: 'readGroups' } });
    return true;
  }

  /** Shared history request: main window by default; child windows supply their target. */
  function readMemory(
    length = 10,
    createId = 0,
    target: { sessionId?: string; window_type?: 0 | 1; worker_name?: string } = {},
  ) {
    if (!canSend.value) {
      options.addMessage('error', 'Not connected, memory history was not loaded.');
      return false;
    }
    const sessionId = currentSessionId(target.sessionId);
    if (!sessionId) return false;
    const windowType = target.window_type ?? 0;
    // Default to the main worker only for the main window; child callers supply the list value.
    const workerName = (target.worker_name ?? (windowType === 0 ? 'AgentBee' : '')).trim();
    if (!workerName) return false;
    sendJson({
      type: 'memory',
      sessionId,
      content: {
        act: 'read',
        window_type: windowType,
        worker_name: workerName,
        length: Math.max(1, Math.floor(length)),
        create_id: Math.max(0, Math.floor(createId)),
      },
    });
    return true;
  }

  /**
   * 会话请求。后端（`lib/message.php process_memory`）目前是把 listSessions / deleteSession /
   * renameSession 当作 **memory 类型的一个 act** 实现的，没有独立的 process_session，
   * 所以这里必须发 `type: 'memory'`，否则后端反射不到方法。
   * 若后端哪天拆出 `type: 'session'`，把 SESSION_REQUEST_TYPE 改掉即可，
   * 响应侧两种 type 都能识别。
   *
   * `sessionId` 走**顶层**：`listSessions` 不传就带上当前会话（后端 listSessions 不按会话过滤，
   * 带上只是为了「会话相关请求一律带顶层 sessionId」这条约定统一）；
   * `deleteSession` / `renameSession` 传的是**目标会话**，后端直接读它。
   */
  function sendSessionAct(
    act: ClientSessionAct,
    sessionId?: string,
    content?: Record<string, unknown>,
  ): boolean {
    if (!canSend.value) {
      options.addMessage('error', 'Not connected, session request was not sent.');
      return false;
    }
    const payload: ClientMessage = SESSION_REQUEST_TYPE === 'session'
      ? { type: 'session', sessionId: currentSessionId(sessionId), content: { ...(content || {}), act } }
      : { type: 'memory', sessionId: currentSessionId(sessionId), content: { ...(content || {}), act } };
    sendJson(payload);
    return true;
  }

  function listSessions(): boolean {
    return sendSessionAct('listSessions');
  }

  function deleteSession(sessionId: string): boolean {
    const id = sessionId.trim();
    if (!id) return false;
    // 目标会话 id 放顶层，content 里不带 sessionId。
    return sendSessionAct('deleteSession', id);
  }

  /**
   * 重命名会话：`{ type:'memory', sessionId, content:{ act:'renameSession', session_name } }`。
   *
   * 目标会话 id 走**顶层**——后端 `process_memory` 的第三个参数就是它，
   * `content` 里只放 act 自己的参数。名字字段用蛇形 `session_name`，
   * 对齐后端 `updateSession(session_id, session_name)` 和 `listSessions` 返回里的同名字段。
   */
  function renameSession(sessionId: string, sessionName: string): boolean {
    const id = sessionId.trim();
    const name = sessionName.trim();
    if (!id || !name) return false;
    return sendSessionAct('renameSession', id, { session_name: name });
  }

  function deleteMemory(createId: number) {
    if (!canSend.value) {
      options.addMessage('error', 'Not connected, memory history was not deleted.');
      return false;
    }
    if (!Number.isSafeInteger(createId) || createId <= 0) return false;
    sendJson({
      type: 'memory',
      // 后端的 delete 分支不按会话过滤（`create_id` 是主键），但会话相关请求一律带上顶层
      // sessionId，保持和 read / renameSession / deleteSession 一致。
      sessionId: currentSessionId(),
      content: { act: 'delete', create_id: createId },
    });
    return true;
  }

  function clearPendingTurns() {
    clearAllNoResponseTimers();
    discardPendingStreamUpdates();
    pendingTurns.value.clear();
    turnSessionIds.clear();
    stopRequests.clear();
  }

  function sendJson(payload: ClientMessage) {
    socket.value?.send(`${JSON.stringify(payload)}\n`);
  }

  /**
   * 多端同步：把其他设备广播过来的 `feedback` 落成会话里的一条用户消息。
   *
   * 后端 `go.php onMessage` 会把用户输入原样广播给**其他**在线客户端，
   * 载荷形如 `{ type:'feedback', data:'文本', sessionId, messageId, ... }`。
   * 它和本机发消息时本地先插入的那条 user 消息语义相同，所以按 user 渲染。
   *
   * - 只认已存在的会话：未知 session 宁可丢弃，也不写错会话（与广播事件的路由规则一致）。
   * - 按服务端 `messageId` 去重，重复投递不会出现两条气泡。
   * - 不登记 pendingTurns：这条消息没有在本机发起一轮对话，不应该触发加载态或超时收尾。
   */
  function appendRemoteFeedbackMessage(msg: ServerMessage) {
    const sessionId = (msg.sessionId || msg.session_id || '').trim();
    if (!sessionId) return;

    const session = options.getSessionById
      ? options.getSessionById(sessionId)
      : options.activeSession()?.id === sessionId ? options.activeSession() : null;
    if (!session) return;

    const messageId = (msg.messageId || '').trim();
    if (messageId && session.messages.some((message) => message.messageId === messageId)) return;

    const content = normalizePayload(msg);
    if (!content.trim()) return;

    session.messages.push({
      id: makeId(),
      role: 'user',
      content,
      time: nowTime(),
      ...(messageId ? { messageId } : {}),
      ...(typeof msg.sender === 'string' && msg.sender.trim() ? { sender: msg.sender.trim() } : {}),
    });

    if (session.keepEmpty) delete session.keepEmpty;
    options.touchSession(session);
    options.registerSession();
  }

  function handleServerMessage(raw: string) {
    const parsed = parseServerMessage(raw);
    if (raw.includes('"delete"') && raw.includes('"memory"')) {
      console.info('[memory-delete-debug] parsed', {
        parsedAs: typeof parsed,
        type: typeof parsed === 'object' ? parsed.type : undefined,
        act: typeof parsed === 'object' ? parsed.act : undefined,
        createId: typeof parsed === 'object' ? parsed.create_id : undefined,
        frameLines: raw.trim().split(/\r?\n/).length,
      });
    }
    if (typeof parsed === 'string') {
      // 在收包时绑定唯一在途轮次，不能等缓冲刷新时再猜归属。
      if (pendingTurns.value.size !== 1) return;
      queueAssistantContent(getLatestPendingMessageId(), parsed);
      return;
    }

    const msg = parsed as ServerMessage;
    const type = getServerType(msg);
    let messageId = getMessageId(msg);

    if (type === 'setting') {
      const { act, content } = unwrapActContent(msg);
      const errorMessage = normalizeServerError(msg);
      if (errorMessage) options.addMessage('error', errorMessage);
      options.onSettingMessage?.(act, content, msg);
      return;
    }
    if (type === 'system') {
      const { act, content } = unwrapActContent(msg);
      const errorMessage = normalizeServerError(msg);
      if (errorMessage) options.addMessage('error', errorMessage);
      options.onSystemMessage?.(act, content, msg);
      return;
    }
    if (type === 'memory' || (!type && msg.act === 'readGroups')) {
      const { act } = unwrapActContent(msg);
      if (act === 'readGroups') {
        const sessionId = msg.sessionId || msg.session_id || groupsRequestSessionId;
        groupsRequestSessionId = '';
        if (sessionId) options.onMemoryMessage?.(act, { ...msg, sessionId });
        const queued = queuedGroupsSessionId;
        queuedGroupsSessionId = '';
        if (queued) readGroups(queued);
        return;
      }
      options.onMemoryMessage?.(act, msg);
      return;
    }
    if (type === 'session') {
      const { act } = unwrapActContent(msg);
      options.onSessionMessage?.(act, msg);
      return;
    }
    if (type === 'history') return;
    // 多端同步：其他设备发出的用户消息由后端以 `feedback` 广播过来。
    // 它不是一轮回复，不能走下面的 assistant 兜底分支（否则整包 JSON 会变成气泡）。
    if (type === 'feedback') {
      appendRemoteFeedbackMessage(msg);
      return;
    }
    // 停止回执：用户主动停止后由服务端返回，按 sessionId/messageId 直接收尾，
    // 不依赖下面的通用广播路由，避免 messageId 对不上时整条回执被吞掉。
    if (type === 'abort') {
      handleAbortMessage(msg);
      return;
    }
    // 广播事件按服务端 session 路由；无 session 的旧协议只能认已知轮次。
    const sessionId = (msg.sessionId || msg.session_id || '').trim();
    if (sessionId) {
      const target = options.getSessionById
        ? options.getSessionById(sessionId)
        : options.activeSession()?.id === sessionId ? options.activeSession() : null;
      if (!target) return;
      if (!messageId) {
        const candidates = Array.from(pendingTurns.value.entries())
          .filter(([, turn]) => turn.sessionId === sessionId);
        if (candidates.length !== 1) return;
        messageId = candidates[0][0];
      }
      const knownSessionId = pendingTurns.value.get(messageId)?.sessionId
        || turnSessionIds.get(messageId);
      if (knownSessionId && knownSessionId !== sessionId) return;
      turnSessionIds.set(messageId, sessionId);
    } else {
      if (!messageId) {
        if (pendingTurns.value.size !== 1) return;
        messageId = getLatestPendingMessageId();
      }
      if (!messageId || (!pendingTurns.value.has(messageId) && !turnSessionIds.has(messageId))) return;
    }

    if (['content', 'assistant', 'message'].includes(type)) {
      queueAssistantContent(messageId, normalizePayload(msg), msg);
      // `/reset` 由后端以 need_llm=false 的 message 事件答复，后面不会再有 end，
      // 这里主动收尾，否则该轮会一直停在加载态。
      if (type === 'message' && unwrapActContent(msg).act === 'reset') {
        return finishAssistantMessage(messageId, 'done', msg);
      }
      return;
    }
    if (['think', 'thinking', 'status'].includes(type)) return queueAssistantThink(messageId, normalizePayload(msg), msg);
    // Preserve ordering when a tool/image/terminal event follows buffered text.
    flushPendingStreamUpdates(messageId);
    if (['tool_calls', 'tool_call', 'tool'].includes(type)) return appendAssistantToolEvent(messageId, 'tool_calls', msg);
    if (type === 'tool_result') return appendAssistantToolEvent(messageId, 'tool_result', msg);
    if (type === 'image') return appendAssistantImage(messageId, msg);
    if (['file', 'html', 'document'].includes(type)) return appendAssistantFile(messageId, msg);
    if (type === 'error') {
      const target = resolveTurnSession(messageId).session;
      target?.messages.push({
        id: makeId(), role: 'error', time: nowTime(),
        content: normalizeServerError(msg) || normalizePayload(msg) || 'Server returned an error.',
      });
      return finishAssistantMessage(messageId, 'error', msg);
    }
    if (['end', 'done', 'finish'].includes(type)) return finishAssistantMessage(messageId, 'done', msg);
    // `close` 是服务端「放弃这一轮」的通知，可能指向早已结束的轮次；
    // 按保守策略收尾。`abort` 已经在上面按服务端回执单独处理。
    if (type === 'close') return closeAssistantMessage(messageId);

    queueAssistantContent(messageId, JSON.stringify(msg, null, 2), msg);
  }

  function queueAssistantContent(messageId: string | null, text: string, msg?: ServerMessage) {
    if (!text) return;
    const pending = getPendingStreamUpdate(messageId);
    pending.content += text;
    if (msg) pending.message = msg;
    scheduleStreamFlush();
  }

  function queueAssistantThink(messageId: string | null, text: string, msg?: ServerMessage) {
    if (!text) return;
    const pending = getPendingStreamUpdate(messageId);
    pending.think += text;
    if (msg) pending.message = msg;
    scheduleStreamFlush();
  }

  function getPendingStreamUpdate(messageId: string | null): PendingStreamUpdate {
    const key = messageId || '__latest__';
    const existing = pendingStreamUpdates.get(key);
    if (existing) return existing;
    const pending: PendingStreamUpdate = { content: '', think: '' };
    pendingStreamUpdates.set(key, pending);
    return pending;
  }

  function scheduleStreamFlush() {
    if (streamFlushTimer !== null) return;
    streamFlushTimer = window.setTimeout(() => {
      streamFlushTimer = null;
      flushPendingStreamUpdates();
    }, STREAM_FLUSH_MS);
  }

  function flushPendingStreamUpdates(messageId?: string | null) {
    const keys = messageId
      ? [messageId]
      : Array.from(pendingStreamUpdates.keys());

    keys.forEach((key) => {
      const pending = pendingStreamUpdates.get(key);
      if (!pending) return;
      pendingStreamUpdates.delete(key);
      const resolvedMessageId = key === '__latest__' ? null : key;
      if (pending.content) appendAssistantContentNow(resolvedMessageId, pending.content, pending.message);
      if (pending.think) appendAssistantThinkNow(resolvedMessageId, pending.think, pending.message);
    });

    if (!pendingStreamUpdates.size && streamFlushTimer !== null) {
      window.clearTimeout(streamFlushTimer);
      streamFlushTimer = null;
    }
  }

  function discardPendingStreamUpdates() {
    pendingStreamUpdates.clear();
    if (streamFlushTimer !== null) {
      window.clearTimeout(streamFlushTimer);
      streamFlushTimer = null;
    }
  }

  function ensureAssistantMessage(messageId: string | null, msg?: ServerMessage) {
    const resolvedMessageId = messageId || (pendingTurns.value.size === 1 ? getLatestPendingMessageId() : null);
    // 既没有 messageId 也没有在等待的轮次：这段内容没有归属。
    // 早先用 makeId() 兜底会凭空造一个空 assistant 气泡，并把它永久留在 pendingTurns 里。
    if (!resolvedMessageId) return null;
    // 归属会话取这一轮发起时记录的那个：流式输出期间切换会话，内容也不会跑错地方。
    const session = resolveTurnSession(resolvedMessageId).session;
    if (!session) return null;
    const isSubTalk = msg?.isSubTalk === 1;

    let assistant = session.messages.find((message) => (
      message.role === 'assistant' &&
      message.messageId === resolvedMessageId &&
      (message.isSubTalk === 1) === isSubTalk
    ));

    if (!assistant) {
      assistant = {
        id: makeId(),
        role: 'assistant',
        messageId: resolvedMessageId,
        content: '',
        think: '',
        toolEvents: [],
        status: 'loading',
        time: nowTime(),
        isSubTalk: isSubTalk ? 1 : 0,
      };
      session.messages.push(assistant);
    }

    turnSessionIds.set(resolvedMessageId, session.id);
    pendingTurns.value.set(resolvedMessageId, { assistantId: assistant.id, sessionId: session.id });
    return { session, assistant, messageId: resolvedMessageId };
  }

  function appendAssistantContentNow(messageId: string | null, text: string, msg?: ServerMessage) {
    const turn = ensureAssistantMessage(messageId, msg);
    if (!turn) return;
    clearNoResponseTimer(turn.messageId);
    applySenderMeta(turn.assistant, msg);
    turn.assistant.content += text || '';
    turn.assistant.status = 'loading';
    options.touchSession(turn.session);
    options.scheduleCreateSession();
  }

  function appendAssistantThinkNow(messageId: string | null, text: string, msg?: ServerMessage) {
    const turn = ensureAssistantMessage(messageId, msg);
    if (!turn) return;
    clearNoResponseTimer(turn.messageId);
    applySenderMeta(turn.assistant, msg);
    turn.assistant.think = `${turn.assistant.think || ''}${text || ''}`;
    turn.assistant.status = 'loading';
    options.touchSession(turn.session);
    options.scheduleCreateSession();
  }

  function appendAssistantToolEvent(messageId: string | null, kind: 'tool_calls' | 'tool_result', msg: ServerMessage) {
    const turn = ensureAssistantMessage(messageId, msg);
    if (!turn) return;
    clearNoResponseTimer(turn.messageId);
    applySenderMeta(turn.assistant, msg);
    turn.assistant.toolEvents ||= [];
    turn.assistant.toolEvents.push(normalizeToolEvent(kind, msg, makeId, nowTime));
    turn.assistant.status = 'loading';
    options.touchSession(turn.session);
    options.scheduleCreateSession();
  }

  function appendAssistantImage(messageId: string | null, msg: ServerMessage) {
    const image = normalizeImageEvent(msg, makeId, nowTime);
    if (!image) {
      queueAssistantContent(messageId, '', msg);
      return;
    }

    const turn = ensureAssistantMessage(messageId, msg);
    if (!turn) return;
    clearNoResponseTimer(turn.messageId);
    applySenderMeta(turn.assistant, msg);
    turn.assistant.images ||= [];
    turn.assistant.images.push(image);
    turn.assistant.status = 'loading';
    options.touchSession(turn.session);
    options.scheduleCreateSession();
  }

  function finishAssistantMessage(
    messageId: string | null,
    status: 'done' | 'error' | 'stopped',
    msg?: ServerMessage,
  ) {
    flushPendingStreamUpdates(messageId);
    const { messageId: resolvedMessageId, session } = resolveTurnSession(messageId);
    if (resolvedMessageId) clearNoResponseTimer(resolvedMessageId);
    if (session && resolvedMessageId) {
      const assistant = session.messages.find((message) => (
        message.role === 'assistant' && message.messageId === resolvedMessageId
      ));
      if (assistant) {
        applySenderMeta(assistant, msg);
        assistant.status = status;
      }
      options.touchSession(session);
    }
    if (resolvedMessageId) pendingTurns.value.delete(resolvedMessageId);
    options.registerSession();
  }

  function finishAllPendingWithoutResponse() {
    flushPendingStreamUpdates();
    if (!pendingTurns.value.size) return;

    pendingTurns.value.forEach((turn, messageId) => {
      clearNoResponseTimer(messageId);
      const session = turn?.sessionId && options.getSessionById
        ? options.getSessionById(turn.sessionId)
        : options.activeSession();
      if (!session) return;
      const assistant = session.messages.find((message) => (
        message.role === 'assistant' && message.messageId === messageId
      ));
      if (assistant && !hasAssistantOutput(assistant)) {
        assistant.status = 'done';
      }
    });
    pendingTurns.value.clear();
    options.registerSession();
  }

  /**
   * 服务端 `abort` 回执：把对应的 assistant 气泡收尾为 stopped。
   *
   * 和 `close` 不同，`abort` 是用户主动停止后的权威回执：
   * - 即使这一轮还没产出内容，也保留气泡并显示服务端返回的「已停止生成」，
   *   否则用户点停止只会看到 loader 消失，没有任何反馈；
   * - 优先按 messageId 精确匹配；匹配不到时，如果目标会话里恰好只有一个 loading
   *   的 assistant，就收尾那一个，避免 messageId 不一致导致回执被吞掉。
   */
  function handleAbortMessage(msg: ServerMessage) {
    const sessionId = (msg.sessionId || msg.session_id || '').trim();
    if (!sessionId) return;

    const session = options.getSessionById
      ? options.getSessionById(sessionId)
      : options.activeSession()?.id === sessionId ? options.activeSession() : null;
    if (!session) return;

    console.info('[abort-debug] receipt', {
      sessionId: session.id, messageId: msg.messageId,
      userIds: session.messages.filter(message => message.role === 'user').map(message => message.id),
    });
    const stopRequest = getActiveStopRequest(session.id);
    const messageId = (msg.messageId || '').trim();
    let assistant = stopRequest
      ? session.messages.find((message) => (
        message.role === 'assistant' && message.messageId === stopRequest.messageId
      ))
      : undefined;

    if (!assistant && messageId) {
      assistant = session.messages.find((message) => (
        message.role === 'assistant' && message.messageId === messageId
      ));
    }

    if (!assistant) {
      const loading = session.messages.filter((message) => (
        message.role === 'assistant' && message.status === 'loading'
      ));
      if (loading.length === 1) assistant = loading[0];
    }

    // 用户点过停止时，即使这一轮已经被 end 提前标成 done，也按回执收尾；
    // 没有停止请求的迟到 abort 仍然忽略，避免把已完成的回复改回去。
    if (!assistant) {
      const statusMessage = extractAbortMessage(msg);
      if (stopRequest && statusMessage) {
        session.messages.push({
          id: makeId(), role: 'system', content: statusMessage, time: nowTime(),
        });
        options.touchSession(session);
        options.registerSession();
      }
      stopRequests.delete(session.id);
      return;
    }

    if (assistant.status !== 'loading' && !stopRequest) return;

    // Flush already received text before marking stopped; a delayed flush must not
    // change this reply back to loading after the abort receipt.
    if (assistant.messageId) flushPendingStreamUpdates(assistant.messageId);
    assistant.status = 'stopped';
    const statusMessage = extractAbortMessage(msg);
    if (statusMessage) assistant.statusMessage = statusMessage;

    if (assistant.messageId) {
      clearNoResponseTimer(assistant.messageId);
      pendingTurns.value.delete(assistant.messageId);
    }
    stopRequests.delete(session.id);
    options.touchSession(session);
    options.registerSession();
  }

  /**
   * 后端 `close` 的语义是「放弃这一轮」，
   * 但它并不总是针对正在流式输出的那一轮：
   * go.php 会在处理下一条用户消息前，对残留的 `curr_message_id` 补发一次 close，
   * 而 `curr_message_id` 只有在最后一次 content/think 缓冲 flush 成功时才会被清空——
   * 一旦那次 flush 失败（或该轮没有 content/think），close 就会指向一个**早已结束并渲染好**的轮次。
   * 直接按 messageId 删除会把用户已经看到的整段回复抹掉，所以这里改成保守处理：
   *  - 已结束（done/error/stopped）：什么都不做，close 是过期的
   *  - 仍在 loading 且有内容/思考/工具：按 stopped 收尾，保留已流出的内容
   *  - 仍在 loading 且什么都没产出：没有可保留的东西，移除这个空壳
   */
  function closeAssistantMessage(messageId: string | null) {
    flushPendingStreamUpdates(messageId);
    const { messageId: resolvedMessageId, session } = resolveTurnSession(messageId);
    if (resolvedMessageId) clearNoResponseTimer(resolvedMessageId);
    if (!session || !resolvedMessageId) return;

    const assistant = session.messages.find((message) => (
      message.role === 'assistant' && message.messageId === resolvedMessageId
    ));

    if (assistant && assistant.status !== 'loading') {
      pendingTurns.value.delete(resolvedMessageId);
      return;
    }

    if (assistant && hasAssistantOutput(assistant)) {
      assistant.status = 'stopped';
      pendingTurns.value.delete(resolvedMessageId);
      options.registerSession();
      return;
    }

    session.messages = session.messages.filter((message) => !(
      message.role === 'assistant' && message.messageId === resolvedMessageId
    ));
    pendingTurns.value.delete(resolvedMessageId);
    options.registerSession();
  }

  function removeAssistantForMessage(session: ChatSession, messageId: string) {
    clearNoResponseTimer(messageId);
    pendingTurns.value.delete(messageId);
    session.messages = session.messages.filter((message) => !(
      message.role === 'assistant' &&
      message.messageId === messageId
    ));
  }

  function startNoResponseTimer(messageId: string) {
    clearNoResponseTimer(messageId);
    const timerId = window.setTimeout(() => {
      const session = resolveTurnSession(messageId).session;
      const assistant = session?.messages.find((message) => (
        message.role === 'assistant' && message.messageId === messageId
      ));

      if (assistant && assistant.status === 'loading' && !hasAssistantOutput(assistant)) {
        assistant.status = 'done';
        pendingTurns.value.delete(messageId);
        options.registerSession();
      }
      noResponseTimers.delete(messageId);
    }, NO_RESPONSE_TIMEOUT_MS);

    noResponseTimers.set(messageId, timerId);
  }

  function clearNoResponseTimer(messageId: string) {
    const timerId = noResponseTimers.get(messageId);
    if (timerId === undefined) return;
    window.clearTimeout(timerId);
    noResponseTimers.delete(messageId);
  }

  function clearAllNoResponseTimers() {
    noResponseTimers.forEach((timerId) => window.clearTimeout(timerId));
    noResponseTimers.clear();
  }

  function scheduleAutoReconnect(delay = getAutoReconnectDelay()) {
    clearAutoRetryTimer();
    if (manualDisconnect || autoConnectPaused.value) return;
    if (document.visibilityState === 'hidden') return;
    if (!navigator.onLine) {
      isOnline.value = false;
      connectionError.value = makeConnectionIssue('offline', wsUrl.value.trim());
      return;
    }

    const now = Date.now();
    if (!autoConnectStartedAt) autoConnectStartedAt = now;
    const remainingMs = AUTO_CONNECT_WINDOW_MS - (now - autoConnectStartedAt);
    if (remainingMs <= 0) {
      pauseAutoConnect('Auto connection stopped after 1 minute of retries. Waiting for manual connection.');
      return;
    }

    const nextDelay = Math.max(0, Math.min(delay, remainingMs));
    retryScheduled.value = true;
    autoRetryTimer = window.setTimeout(() => {
      autoRetryTimer = null;
      retryScheduled.value = false;
      if (Date.now() - autoConnectStartedAt > AUTO_CONNECT_WINDOW_MS) {
        pauseAutoConnect('Auto connection stopped after 1 minute of retries. Waiting for manual connection.');
        return;
      }
      autoConnectAttempts += 1;
      connect(true);
    }, nextDelay);
  }

  function clearAutoRetryTimer() {
    retryScheduled.value = false;
    if (autoRetryTimer === null) return;
    window.clearTimeout(autoRetryTimer);
    autoRetryTimer = null;
  }

  function getAutoReconnectDelay() {
    const exponent = Math.max(0, autoConnectAttempts - 1);
    return Math.min(AUTO_CONNECT_BASE_RETRY_MS * 2 ** exponent, AUTO_CONNECT_MAX_RETRY_MS);
  }

  function pauseAutoConnect(message: string) {
    autoConnectPaused.value = true;
    autoConnectStartedAt = 0;
    clearAutoRetryTimer();
    options.addMessage('system', message);
    rejectQueuedTextSend('The waiting message was not sent because connection retries stopped.');
  }

  function appendAssistantFile(messageId: string | null, msg: ServerMessage) {
    const file = normalizeFileEvent(msg, makeId, nowTime);
    if (!file) {
      queueAssistantContent(messageId, normalizePayload(msg), msg);
      return;
    }

    const turn = ensureAssistantMessage(messageId, msg);
    if (!turn) return;
    clearNoResponseTimer(turn.messageId);
    applySenderMeta(turn.assistant, msg);
    turn.assistant.files ||= [];
    turn.assistant.files.push(file);
    turn.assistant.status = 'loading';
    options.touchSession(turn.session);
    options.scheduleCreateSession();
  }

  function handleOffline() {
    isOnline.value = false;
    clearAutoRetryTimer();
    if (connectionRequested && !manualDisconnect) {
      connectionError.value = makeConnectionIssue('offline', wsUrl.value.trim());
    }
  }

  function handleOnline() {
    isOnline.value = true;
    resumeConnection();
  }

  function handleVisibilityChange() {
    if (document.visibilityState === 'hidden') {
      hiddenAt = Date.now();
      clearAutoRetryTimer();
      return;
    }

    const hiddenDuration = hiddenAt === null ? 0 : Date.now() - hiddenAt;
    hiddenAt = null;
    if (!connectionRequested || manualDisconnect) return;
    if (
      canSend.value &&
      hiddenDuration >= FOREGROUND_RECONNECT_THRESHOLD_MS &&
      !hasPendingTurns.value
    ) {
      reconnect();
      return;
    }
    resumeConnection();
  }

  function getLatestPendingMessageId(): string | null {
    const ids = Array.from(pendingTurns.value.keys());
    return ids.length ? ids[ids.length - 1] : null;
  }

  /** 当前会话里最近一轮的 messageId（「停止」优先停用户正在看的这一轮）。 */
  function getActiveSessionPendingMessageId(): string | null {
    const activeId = options.activeSession()?.id;
    if (!activeId) return null;
    let found: string | null = null;
    pendingTurns.value.forEach((turn, messageId) => {
      if (turn?.sessionId === activeId) found = messageId;
    });
    return found;
  }

  /** 当前会话里最近一条用户消息的 messageId：没有 pendingTurn 时用它作为停止目标。 */
  function getLatestUserMessageId(session: ChatSession): string | null {
    for (let index = session.messages.length - 1; index >= 0; index -= 1) {
      const message = session.messages[index];
      if (message.role === 'user' && message.messageId) return message.messageId;
    }
    return null;
  }

  /** 没有 pendingTurn 时的停止目标：优先最近一条有 assistant 的用户消息，其次最近 assistant。 */
  function getLatestTurnMessageId(session: ChatSession): string | null {
    const userId = getLatestUserMessageId(session);
    if (userId && session.messages.some((message) => (
      message.role === 'assistant' && message.messageId === userId
    ))) {
      return userId;
    }
    for (let index = session.messages.length - 1; index >= 0; index -= 1) {
      const message = session.messages[index];
      if (message.role === 'assistant' && message.messageId) return message.messageId;
    }
    return userId;
  }

  /** 取还没过期的停止请求；超过 TTL 视为迟到，忽略。 */
  function getActiveStopRequest(sessionId: string): StopRequest | null {
    const request = stopRequests.get(sessionId);
    if (!request) return null;
    if (Date.now() - request.requestedAt > STOP_REQUEST_TTL_MS) {
      stopRequests.delete(sessionId);
      return null;
    }
    return request;
  }

  /**
   * 解析一个 messageId 属于哪个会话。
   * 优先用该轮发起时记录的 sessionId——这样流式输出期间切到别的会话，
   * 后续 content/end 仍会落回原会话，而不是写进用户刚切过去的那一个。
   * 服务端广播先登记归属；没有可靠归属时丢弃，禁止退回当前会话。
   */
  function resolveTurnSession(messageId: string | null): {
    messageId: string | null;
    session: ChatSession | null;
  } {
    const resolvedMessageId = messageId || (pendingTurns.value.size === 1 ? getLatestPendingMessageId() : null);
    if (!resolvedMessageId) return { messageId: null, session: null };
    const sessionId = pendingTurns.value.get(resolvedMessageId)?.sessionId
      || turnSessionIds.get(resolvedMessageId);
    const session = sessionId
      ? options.getSessionById
        ? options.getSessionById(sessionId)
        : options.activeSession()?.id === sessionId ? options.activeSession() : null
      : null;
    return { messageId: resolvedMessageId, session };
  }

  function applySenderMeta(message: ChatMessage, msg?: ServerMessage) {
    if (!msg) return;
    if (typeof msg.sender === 'string' && msg.sender.trim()) {
      message.sender = msg.sender.trim();
    }
    if (typeof msg.workerName === 'string' && msg.workerName.trim()) {
      message.senderName = msg.workerName.trim();
    }
    if (typeof msg.workerRole === 'string' && msg.workerRole.trim()) {
      message.senderRole = msg.workerRole.trim();
    }
    if (typeof msg.WindowName === 'string' && msg.WindowName.trim()) {
      message.WindowName = msg.WindowName.trim();
    }
    if (typeof msg.isSubTalk === 'number') {
      message.isSubTalk = msg.isSubTalk;
    }
  }

  onMounted(() => {
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    document.addEventListener('visibilitychange', handleVisibilityChange);
  });

  onBeforeUnmount(() => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    discardPendingStreamUpdates();
    clearAllNoResponseTimers();
    clearAutoRetryTimer();
    rejectQueuedTextSend();
    const activeSocket = socket.value;
    socket.value = null;
    if (activeSocket && activeSocket.readyState !== WebSocket.CLOSED) {
      activeSocket.close(1000, 'Page unmounted');
    }
  });

  return {
    canSend,
    clearPendingTurns,
    clearConnectionError,
    connect,
    connected,
    connecting,
    connectionError,
    connectionState,
    disconnect,
    deleteMemory,
    deleteSession,
    autoConnectPaused,
    hasPendingTurns,
    reconnect,
    resumeConnection,
    readMemory,
    readGroups,
    listSessions,
    renameSession,
    resendEditedText,
    sendSessionAct,
    sendSettingAct,
    sendSystemAct,
    sendText,
    startAutoConnect,
    stopCurrent,
    streamingSessionIds,
    wsToken,
    wsUrl,
  };
}

export function validateWebSocketUrl(
  rawUrl: string,
  pageProtocol = window.location.protocol,
): ConnectionIssue | null {
  let parsed: URL;
  try {
    parsed = new URL(rawUrl);
  } catch {
    return makeConnectionIssue('invalid-url', rawUrl);
  }

  if (!['ws:', 'wss:'].includes(parsed.protocol) || !parsed.hostname) {
    return makeConnectionIssue('invalid-url', rawUrl);
  }
  if (pageProtocol === 'https:' && parsed.protocol === 'ws:') {
    return makeConnectionIssue('mixed-content', rawUrl);
  }
  return null;
}

export function createWebSocketConnection(
  url: string,
  token: string,
  WebSocketConstructor: typeof WebSocket = WebSocket,
): WebSocket {
  const normalizedToken = token.trim();
  // An empty token is valid: omit the subprotocol list entirely in that case.
  return normalizedToken
    ? new WebSocketConstructor(url, [normalizedToken])
    : new WebSocketConstructor(url);
}

function makeConnectionIssue(
  kind: ConnectionIssueKind,
  url: string,
  detail?: string,
): ConnectionIssue {
  return {
    detail: detail || undefined,
    kind,
    occurredAt: Date.now(),
    url,
  };
}

function unwrapActContent(msg: ServerMessage): { act: string; content: unknown } {
  const content = msg.content;
  if (isRecord(content)) {
    const act = asString(content.act) || msg.act || '';
    const nestedContent = content.data ?? content.config ?? content.models ?? content.result;
    if (nestedContent !== undefined) return { act, content: nestedContent };
    const { act: _act, ...contentWithoutAct } = content;
    return {
      act,
      content: Object.keys(contentWithoutAct).length ? contentWithoutAct : '',
    };
  }

  return {
    act: msg.act || '',
    content: msg.content ?? msg.data ?? msg.message ?? '',
  };
}

function extractAbortMessage(msg: ServerMessage): string {
  if (typeof msg.data === 'string' && msg.data.trim()) return msg.data.trim();
  if (isRecord(msg.data)) {
    const message = asString(msg.data.message).trim();
    if (message) return message;
  }
  return asString(msg.message).trim();
}

function hasAssistantOutput(message: ChatMessage): boolean {
  return Boolean(
    message.content?.trim() ||
    message.think?.trim() ||
    message.images?.length ||
    message.toolEvents?.length,
  );
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function toChatAttachments(attachments: ClientAttachment[]): ChatAttachment[] {
  return attachments.map((attachment) => ({
    id: attachment.id,
    name: attachment.name,
    size: attachment.size,
    type: attachment.type,
    base64: attachment.base64,
  }));
}

function toClientAttachments(attachments: ChatAttachment[]): ClientAttachment[] {
  return attachments.map((attachment) => ({
    id: attachment.id,
    name: attachment.name,
    size: attachment.size,
    type: attachment.type,
    base64: attachment.base64,
  }));
}

function createClientContentPayload(
  text: string,
  attachments: ClientAttachment[],
): {
  type: 'chat';
  content: Array<
    | { type: 'text'; text: string }
    | { type: 'file'; file: { filename: string; mimeType: string; content: string } }
  >;
} {
  const content = [
    ...(text ? [{ type: 'text' as const, text }] : []),
    ...attachments
      .filter((attachment) => attachment.base64 !== undefined)
      .map((attachment) => ({
        type: 'file' as const,
        file: {
          filename: attachment.name,
          mimeType: attachment.type,
          content: attachment.base64 || '',
        },
      })),
  ];

  return {
    type: 'chat',
    content,
  };
}
