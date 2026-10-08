<script setup lang="ts">
import { Bot, Trash2 } from 'lucide-vue-next';
import type { SubAgentSummary } from './SubAgentMenu.vue';

/**
 * 专家列表的**唯一一份**实现。
 *
 * 桌面端装在 `SubAgentMenu` 的下拉浮层里，移动端装在 `SubAgentDrawer` 的右侧抽屉里——
 * 和会话列表同样的思路：只留一份行与操作，避免「手机上少做了某个操作」。
 * 容器（浮层 / 抽屉）只负责外壳、内边距和滚动。
 */
defineProps<{
  agents: SubAgentSummary[];
  labels: Record<string, string>;
  selectedAgentName: string | null;
}>();

const emit = defineEmits<{
  deleteSubAgent: [agentName: string];
  selectAgent: [agentName: string];
}>();

function getAgentInitial(agentName: string) {
  return Array.from(agentName.trim())[0]?.toUpperCase() || '?';
}

function getAgentStatusLabel(status: string, labels: Record<string, string>) {
  return {
    loading: labels.subAgentRunning,
    done: labels.subAgentCompleted,
    error: labels.subAgentError,
    stopped: labels.subAgentStopped,
  }[status] || labels.subAgentCompleted;
}
</script>

<template>
  <div class="subagent-list">
    <div
      v-for="agent in agents"
      :key="agent.name"
      class="subagent-menu-row"
      :class="{ selected: selectedAgentName === agent.name }"
    >
      <button
        type="button"
        class="subagent-menu-select"
        role="menuitem"
        @click="emit('selectAgent', agent.name)"
      >
        <span class="subagent-avatar" aria-hidden="true">
          {{ getAgentInitial(agent.name) }}
          <i class="subagent-status-dot" :class="agent.status || 'done'"></i>
        </span>
        <span class="subagent-menu-copy">
          <strong>{{ agent.name }}</strong>
          <span>
            {{ agent.role || labels.subAgent }} · {{ getAgentStatusLabel(agent.status, labels) }} · {{ agent.count }} {{ labels.items }}
          </span>
        </span>
        <Bot :size="15" aria-hidden="true" />
      </button>
      <button
        type="button"
        class="subagent-menu-delete"
        :title="labels.deleteSubAgent"
        :aria-label="labels.deleteSubAgent"
        @click.stop="emit('deleteSubAgent', agent.name)"
      >
        <Trash2 :size="14" aria-hidden="true" />
      </button>
    </div>
  </div>
</template>

<style scoped>
.subagent-list {
  display: grid;
}

.subagent-menu-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 30px;
  align-items: center;
  border-radius: 6px;
}

.subagent-menu-row:hover,
.subagent-menu-row.selected {
  background: var(--surface-soft);
}

.subagent-menu-select {
  min-width: 0;
  min-height: 52px;
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr) 18px;
  align-items: center;
  gap: 9px;
  padding: 7px 6px;
  text-align: left;
  color: var(--text);
  background: transparent;
  border: 0;
}

.subagent-menu-select:hover {
  background: transparent;
  border-color: transparent;
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
  border: 2px solid var(--surface);
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

.subagent-menu-copy {
  min-width: 0;
  display: grid;
  gap: 3px;
}

.subagent-menu-copy strong,
.subagent-menu-copy span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.subagent-menu-copy strong {
  font-size: 0.8rem;
}

.subagent-menu-copy span {
  color: var(--muted);
  font-size: 0.7rem;
}

.subagent-menu-delete {
  width: 28px;
  height: 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  color: var(--faint);
  background: transparent;
  border: 0;
}

.subagent-menu-delete:hover {
  color: var(--danger);
  background: var(--danger-soft);
}
</style>
