<script setup lang="ts">
import { ChevronDown, UsersRound } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import SubAgentList from './SubAgentList.vue';

export interface SubAgentSummary {
  count: number;
  lastActive: string;
  name: string;
  role: string;
  status: string;
}

defineProps<{
  agents: SubAgentSummary[];
  labels: Record<string, string>;
  selectedAgentName: string | null;
}>();

const emit = defineEmits<{
  deleteSubAgent: [agentName: string];
  selectAgent: [agentName: string];
}>();

const isOpen = ref(false);
const menuRoot = ref<HTMLElement | null>(null);

function toggleMenu() {
  isOpen.value = !isOpen.value;
}

function onSelectAgent(agentName: string) {
  emit('selectAgent', agentName);
  isOpen.value = false;
}

function onDeleteSubAgent(agentName: string) {
  emit('deleteSubAgent', agentName);
}

function onDocumentPointerDown(event: PointerEvent) {
  if (!menuRoot.value?.contains(event.target as Node)) {
    isOpen.value = false;
  }
}

function onDocumentKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') isOpen.value = false;
}

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown);
  document.addEventListener('keydown', onDocumentKeydown);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown);
  document.removeEventListener('keydown', onDocumentKeydown);
});
</script>

<template>
  <div ref="menuRoot" class="subagent-menu">
    <button
      type="button"
      class="subagent-menu-trigger"
      :class="{ active: isOpen || selectedAgentName }"
      :aria-expanded="isOpen"
      aria-haspopup="menu"
      :title="labels.subAgents"
      @click="toggleMenu"
    >
      <UsersRound :size="17" aria-hidden="true" />
      <span class="subagent-menu-count">{{ agents.length }}</span>
      <ChevronDown :size="14" aria-hidden="true" />
    </button>

    <div v-if="isOpen" class="subagent-menu-popover" role="menu">
      <div class="subagent-menu-heading">
        <span>{{ labels.subAgents }}</span>
        <span>{{ agents.length }}</span>
      </div>
      <div class="subagent-menu-list">
        <SubAgentList
          :agents="agents"
          :labels="labels"
          :selected-agent-name="selectedAgentName"
          @select-agent="onSelectAgent"
          @delete-sub-agent="onDeleteSubAgent"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.subagent-menu {
  position: relative;
}

.subagent-menu-trigger {
  height: 32px;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 0 9px;
  color: var(--muted);
  background: transparent;
  border-color: var(--line-soft);
}

.subagent-menu-trigger:hover,
.subagent-menu-trigger.active {
  color: var(--text);
  background: var(--surface-raised);
  border-color: var(--line);
}

.subagent-menu-count {
  min-width: 18px;
  height: 18px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 5px;
  border-radius: 999px;
  color: var(--text);
  background: var(--accent-soft);
  font-size: 0.72rem;
  font-weight: 700;
}

.subagent-menu-popover {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 40;
  width: min(320px, calc(100vw - 24px));
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--surface);
  box-shadow: var(--shadow);
}

.subagent-menu-heading {
  height: 38px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px;
  border-bottom: 1px solid var(--line-soft);
  color: var(--muted);
  font-size: 0.74rem;
  font-weight: 700;
}

.subagent-menu-list {
  max-height: min(420px, calc(100vh - 100px));
  overflow-y: auto;
  padding: 6px;
}
</style>
