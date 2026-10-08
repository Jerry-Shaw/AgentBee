<script setup lang="ts">
import { UsersRound, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { ChatFile, ChatMessage as AgentChatMessage } from '../protocol/types';
import type { SubAgentSummary } from './SubAgentMenu.vue';
import SubAgentList from './SubAgentList.vue';
import SubAgentPanel from './SubAgentPanel.vue';

/**
 * 移动端的专家抽屉：从右侧滑出。
 *
 * 移动端聊天页的 `.topbar` 是隐藏的，而 `SubAgentMenu` 长在 topbar 里，
 * 「专家」这个入口在手机上因此不可达。这里在头部补一个按钮，内容用右侧抽屉承载：
 * 没选 Agent 时显示列表（和桌面下拉是同一份 `SubAgentList`），选中后就地换成
 * `SubAgentPanel`——不用再把面板铺在聊天区上面。
 *
 * 只在移动端布局下渲染（由 `App.vue` 的 `isMobileLayout` 控制），
 * 所以这里不需要再判断视口宽度。
 */
const props = defineProps<{
  agents: SubAgentSummary[];
  labels: Record<string, string>;
  messages: AgentChatMessage[];
  open: boolean;
  selectedAgentName: string | null;
  showDebugInfo: boolean;
}>();

const emit = defineEmits<{
  close: [];
  deleteSubAgent: [agentName: string];
  previewFile: [file: ChatFile];
  resendUserMessage: [messageId: string];
  selectAgent: [agentName: string];
  updateUserMessage: [messageId: string, content: string];
}>();

const selectedAgent = computed(() => (
  props.agents.find((agent) => agent.name === props.selectedAgentName) || null
));

const drawer = ref<HTMLElement | null>(null);
let previouslyFocused: HTMLElement | null = null;

/** Esc 关闭；Tab 在抽屉内部循环（和 SessionDrawer / ConfirmDialog 同一套做法）。 */
function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault();
    emit('close');
    return;
  }

  if (event.key !== 'Tab' || !drawer.value) return;
  const focusable = Array.from(
    drawer.value.querySelectorAll<HTMLElement>('button:not(:disabled), input:not(:disabled)'),
  );
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (!first || !last) return;

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

function onPreviewFile(file: ChatFile) {
  emit('previewFile', file);
}

function onResendUserMessage(messageId: string) {
  emit('resendUserMessage', messageId);
}

function onUpdateUserMessage(messageId: string, content: string) {
  emit('updateUserMessage', messageId, content);
}

watch(() => props.open, (open) => {
  if (open) {
    previouslyFocused = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    // 焦点收进抽屉，键盘 / 读屏用户不会跑到被遮住的界面上。
    nextTick(() => drawer.value?.focus());
    return;
  }
  // 关闭后把焦点还给触发按钮。
  if (previouslyFocused?.isConnected && !previouslyFocused.hasAttribute('disabled')) {
    previouslyFocused.focus();
  }
  previouslyFocused = null;
});

onBeforeUnmount(() => {
  previouslyFocused = null;
});
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="subagent-drawer-layer" @keydown="onKeydown">
      <div class="subagent-drawer-backdrop" @click="emit('close')" />
      <aside
        ref="drawer"
        class="subagent-drawer"
        role="dialog"
        aria-modal="true"
        :aria-label="labels.subAgents"
        tabindex="-1"
      >
        <SubAgentPanel
          v-if="selectedAgent"
          embedded
          :agent="selectedAgent"
          :labels="labels"
          :messages="messages"
          :show-debug-info="showDebugInfo"
          @close="emit('close')"
          @preview-file="onPreviewFile"
          @resend-user-message="onResendUserMessage"
          @update-user-message="onUpdateUserMessage"
        />

        <template v-else>
          <header class="subagent-drawer-head">
            <UsersRound :size="16" aria-hidden="true" />
            <strong>{{ labels.subAgents }}</strong>
            <span class="subagent-drawer-count">{{ agents.length }}</span>
            <button
              type="button"
              class="icon-button"
              :title="labels.close"
              :aria-label="labels.close"
              @click="emit('close')"
            >
              <X :size="16" aria-hidden="true" />
            </button>
          </header>

          <div class="subagent-drawer-list">
            <SubAgentList
              :agents="agents"
              :labels="labels"
              :selected-agent-name="selectedAgentName"
              @select-agent="emit('selectAgent', $event)"
              @delete-sub-agent="emit('deleteSubAgent', $event)"
            />
          </div>
        </template>
      </aside>
    </div>
  </Teleport>
</template>
