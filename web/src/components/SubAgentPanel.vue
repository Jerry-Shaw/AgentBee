<script setup lang="ts">
import { Bot, X } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import type { ChatFile, ChatMessage as AgentChatMessage } from '../protocol/types';
import type { SubAgentSummary } from './SubAgentMenu.vue';
import ChatMessage from './ChatMessage.vue';

const props = defineProps<{
  agent: SubAgentSummary;
  labels: Record<string, string>;
  messages: AgentChatMessage[];
  showDebugInfo: boolean;
  /** 装在移动端抽屉里时，高度交给外层 flex 分配，不再自己撑满 100%。 */
  embedded?: boolean;
}>();

const emit = defineEmits<{
  close: [];
  resendUserMessage: [messageId: string];
  updateUserMessage: [messageId: string, content: string];
  previewFile: [file: ChatFile];
}>();

const PAGE_SIZE = 50;
const visibleMessageCount = ref(PAGE_SIZE);
let restoreDistance: number | null = null;
let restoringScroll = false;
/**
 * 「贴底自动跟随」状态：默认 true，即用户没主动往上翻过之前，新消息一律滚到底部。
 * 和主聊天区（App.vue）的 `shouldAutoScroll` 同一套语义——子 Agent 面板每次打开、
 * 切换 Agent 时都会重置回 true，所以「有新消息就自动落底」是默认行为；用户手动
 * 往上翻超过阈值后停止跟随，避免抢滚动位置。
 */
let shouldFollowBottom = true;

const allFilteredMessages = computed(() => (
  props.messages.filter((message) => message.WindowName === props.agent.name)
));

const filteredMessages = computed(() => (
  allFilteredMessages.value.slice(-visibleMessageCount.value)
));

/** 滚到底部：面板内出现新消息、或刚打开时调用。 */
function scrollToBottom() {
  const element = messagesElement.value;
  if (!element) return;
  element.scrollTop = element.scrollHeight;
}

const messagesElement = ref<HTMLElement | null>(null);

/**
 * 面板内容变化（新消息 / 流式更新）后，若用户仍贴着底部就自动滚到底。
 * `deep: false` 监听的是数组引用：`props.messages` 是 App.vue 里 activeSession
 * 的 messages 数组切片，新消息进来会生成新的 computed 结果；同一条消息流式增长
 * （content/status 原地改）时靠下方 watchEffect 兜底。
 */
watch(filteredMessages, () => {
  if (shouldFollowBottom) scrollToBottom();
});

/**
 * 流式更新（同一条 assistant 消息 content/think/toolEvents 原地变长）不会改变
 * `filteredMessages` 的引用，需要单独盯住最后一条消息的内容长度。
 */
watch(
  () => {
    const last = filteredMessages.value[filteredMessages.value.length - 1];
    if (!last) return '';
    return [
      last.id,
      last.status || '',
      last.content?.length ?? 0,
      last.think?.length ?? 0,
      last.toolEvents?.length ?? 0,
    ].join('|');
  },
  () => {
    if (shouldFollowBottom) scrollToBottom();
  },
);

/**
 * 用户手动滚动：滚到顶部附近触发「加载更早消息」；同时判断是否还在贴底，
 * 决定后续新消息要不要自动跟随。
 */
function onScroll(event: Event) {
  const element = event.currentTarget as HTMLElement;
  shouldFollowBottom = element.scrollHeight - element.scrollTop - element.clientHeight < 80;

  if (restoringScroll || element.scrollTop >= 80 || allFilteredMessages.value.length <= visibleMessageCount.value) return;
  restoreDistance = element.scrollHeight - element.scrollTop;
  restoringScroll = true;
  visibleMessageCount.value += PAGE_SIZE;
  nextTick(() => {
    if (restoreDistance !== null) {
      element.scrollTop = Math.max(0, element.scrollHeight - restoreDistance);
    }
    restoreDistance = null;
    restoringScroll = false;
  });
}

/**
 * 切换 Agent（或面板首次挂载）：重置分页与滚动状态，并直接落底。
 * `immediate: true` 覆盖「组件一渲染出来就贴底」的场景——移动端每次点开抽屉、
 * 桌面端从列表选中某个子 Agent，都会走这里。
 */
watch(
  () => props.agent.name,
  () => {
    visibleMessageCount.value = PAGE_SIZE;
    restoreDistance = null;
    restoringScroll = false;
    shouldFollowBottom = true;
    nextTick(() => {
      window.requestAnimationFrame(scrollToBottom);
    });
  },
  { immediate: true },
);

function onResendUserMessage(messageId: string) {
  emit('resendUserMessage', messageId);
}

function onUpdateUserMessage(messageId: string, content: string) {
  emit('updateUserMessage', messageId, content);
}

function onPreviewFile(file: ChatFile) {
  emit('previewFile', file);
}

function getAgentInitial(agentName: string) {
  return Array.from(agentName.trim())[0]?.toUpperCase() || '?';
}

function getAgentStatusLabel(status: string) {
  return {
    loading: props.labels.subAgentRunning,
    done: props.labels.subAgentCompleted,
    error: props.labels.subAgentError,
    stopped: props.labels.subAgentStopped,
  }[status] || props.labels.subAgentCompleted;
}
</script>

<template>
  <aside
    class="subagent-panel"
    :class="{ 'is-embedded': embedded }"
    :aria-label="`${labels.subAgent}: ${agent.name}`"
  >
    <header class="subagent-header">
      <span class="subagent-avatar" aria-hidden="true">
        {{ getAgentInitial(agent.name) }}
        <i class="subagent-status-dot" :class="agent.status || 'done'"></i>
      </span>
      <span class="subagent-identity">
        <strong>{{ agent.name }}</strong>
        <span>
          {{ agent.role || labels.subAgent }} · {{ getAgentStatusLabel(agent.status) }} · {{ agent.count }} {{ labels.items }}
        </span>
      </span>
      <Bot :size="16" class="subagent-type-icon" aria-hidden="true" />
      <button type="button" class="icon-button" :title="labels.closeSubAgent" @click="emit('close')">
        <X :size="16" aria-hidden="true" />
      </button>
    </header>

    <div ref="messagesElement" class="subagent-messages" @scroll="onScroll">
      <div v-if="!filteredMessages.length" class="subagent-empty">
        {{ labels.noMessages }}
      </div>
      <ChatMessage
        v-for="message in filteredMessages"
        :key="message.id"
        :labels="labels"
        :message="message"
        :show-debug-info="showDebugInfo"
        @resend-user-message="onResendUserMessage"
        @update-user-message="onUpdateUserMessage"
        @preview-file="onPreviewFile"
      />
    </div>
  </aside>
</template>

<style scoped>
.subagent-panel {
  overflow: hidden;
  min-width: 0;
  min-height: 0;
  height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--surface);
}

.subagent-panel.is-embedded {
  height: auto;
  flex: 1;
}

.subagent-header {
  min-height: 54px;
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr) 20px 32px;
  align-items: center;
  gap: 9px;
  padding: 9px 10px 9px 12px;
  border-bottom: 1px solid var(--line-soft);
  background: var(--surface-soft);
}

.subagent-avatar {
  position: relative;
  width: 34px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  color: white;
  background: var(--accent-strong);
  font-size: 0.82rem;
  font-weight: 750;
}

.subagent-status-dot {
  position: absolute;
  right: -1px;
  bottom: -1px;
  width: 10px;
  height: 10px;
  border: 2px solid var(--surface-soft);
  border-radius: 50%;
  background: var(--faint);
}

.subagent-status-dot.loading {
  background: var(--success);
}

.subagent-status-dot.error {
  background: var(--danger);
}

.subagent-status-dot.stopped {
  background: var(--warn);
}

.subagent-identity {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.subagent-identity strong,
.subagent-identity span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.subagent-identity strong {
  font-size: 0.82rem;
}

.subagent-identity span {
  color: var(--muted);
  font-size: 0.7rem;
}

.subagent-type-icon {
  color: var(--muted);
}

.subagent-messages {
  flex: 1;
  min-width: 0;
  min-height: 0;
  overflow-y: auto;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.subagent-empty {
  min-height: 160px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--muted);
  font-size: 0.78rem;
}
</style>