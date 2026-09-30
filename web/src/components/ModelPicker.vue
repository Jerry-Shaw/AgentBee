<script setup lang="ts">
import { Check, ChevronDown, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

const props = defineProps<{
  availableModels: string[];
  disabled: boolean;
  labels: Record<string, string>;
  modelName: string;
}>();

const emit = defineEmits<{
  select: [modelName: string];
}>();

const pickerId = useId();
const trigger = ref<HTMLButtonElement | null>(null);
const dialog = ref<HTMLDialogElement | null>(null);
const list = ref<HTMLElement | null>(null);
const isOpen = ref(false);
const focusedModel = ref('');
const viewportStyle = ref<Record<string, string>>({});
const models = computed(() => {
  const available = props.availableModels.filter(Boolean);
  if (props.modelName && !available.includes(props.modelName)) available.unshift(props.modelName);
  return [...new Set(available)];
});
const pickerDisabled = computed(() => props.disabled || !models.value.length);

function updateViewport() {
  const viewport = window.visualViewport;
  viewportStyle.value = {
    top: `${viewport?.offsetTop ?? 0}px`,
    left: `${viewport?.offsetLeft ?? 0}px`,
    width: `${viewport?.width ?? window.innerWidth}px`,
    height: `${viewport?.height ?? window.innerHeight}px`,
  };
}

async function openPicker() {
  if (pickerDisabled.value || !dialog.value || isOpen.value) return;
  // Return focus to this button on close, without reopening the textarea's keyboard.
  trigger.value?.focus({ preventScroll: true });
  focusedModel.value = props.modelName || models.value[0] || '';
  updateViewport();
  isOpen.value = true;
  window.addEventListener('resize', updateViewport);
  window.visualViewport?.addEventListener('resize', updateViewport);
  window.visualViewport?.addEventListener('scroll', updateViewport);
  await nextTick();
  if (!isOpen.value || !dialog.value || pickerDisabled.value) return;
  dialog.value.showModal();
  focusModel(Math.max(0, models.value.indexOf(focusedModel.value)));
}

function closePicker() {
  isOpen.value = false;
  window.removeEventListener('resize', updateViewport);
  window.visualViewport?.removeEventListener('resize', updateViewport);
  window.visualViewport?.removeEventListener('scroll', updateViewport);
  if (dialog.value?.open) dialog.value.close();
}

function selectModel(model: string) {
  if (pickerDisabled.value) return;
  closePicker();
  if (model !== props.modelName) emit('select', model);
}

function focusModel(index: number) {
  if (!isOpen.value || !list.value) return;
  const option = list.value.querySelectorAll<HTMLButtonElement>('[role="option"]')[index];
  if (!option) return;
  focusedModel.value = models.value[index];
  option.focus({ preventScroll: true });
  // Only scroll the options, keeping the page and composer stationary.
  const itemBounds = option.getBoundingClientRect();
  const listBounds = list.value.getBoundingClientRect();
  if (itemBounds.top < listBounds.top) list.value.scrollTop += itemBounds.top - listBounds.top;
  else if (itemBounds.bottom > listBounds.bottom) list.value.scrollTop += itemBounds.bottom - listBounds.bottom;
}

function onListKeydown(event: KeyboardEvent) {
  const index = Math.max(0, models.value.indexOf(focusedModel.value));
  let nextIndex: number;
  if (event.key === 'ArrowDown') nextIndex = Math.min(index + 1, models.value.length - 1);
  else if (event.key === 'ArrowUp') nextIndex = Math.max(0, index - 1);
  else if (event.key === 'Home') nextIndex = 0;
  else if (event.key === 'End') nextIndex = models.value.length - 1;
  else return;
  event.preventDefault();
  focusModel(nextIndex);
}

watch(pickerDisabled, (disabled) => {
  if (disabled) closePicker();
});

onBeforeUnmount(closePicker);
</script>

<template>
  <div class="composer-model-picker">
    <button
      ref="trigger"
      type="button"
      class="model-picker-trigger"
      :aria-label="`${labels.modelName}: ${modelName || labels.noModelsAvailable}`"
      :title="modelName || labels.noModelsAvailable"
      :aria-controls="pickerId"
      :aria-expanded="isOpen"
      aria-haspopup="dialog"
      :disabled="pickerDisabled"
      @click="openPicker"
    >
      <span>{{ modelName || labels.noModelsAvailable }}</span>
      <ChevronDown :size="16" aria-hidden="true" />
    </button>
  </div>

  <Teleport to="body">
    <dialog
      :id="pickerId"
      ref="dialog"
      class="model-picker-dialog"
      :style="viewportStyle"
      :aria-labelledby="`${pickerId}-title`"
      @cancel.prevent="closePicker"
      @close="closePicker"
      @click.self="closePicker"
    >
      <section class="model-picker-panel">
        <header class="model-picker-header">
          <h2 :id="`${pickerId}-title`">{{ labels.modelName }}</h2>
          <button
            type="button"
            class="model-picker-close"
            :aria-label="labels.close"
            :title="labels.close"
            @click="closePicker"
          >
            <X :size="18" aria-hidden="true" />
          </button>
        </header>
        <div
          ref="list"
          class="model-picker-list"
          role="listbox"
          :aria-label="labels.modelName"
          @keydown="onListKeydown"
        >
          <button
            v-for="model in models"
            :key="model"
            type="button"
            class="model-picker-option"
            role="option"
            :aria-selected="model === modelName"
            :tabindex="model === focusedModel ? 0 : -1"
            @focus="focusedModel = model"
            @click="selectModel(model)"
          >
            <span>{{ model }}</span>
            <Check v-if="model === modelName" :size="18" aria-hidden="true" />
          </button>
        </div>
      </section>
    </dialog>
  </Teleport>
</template>

<style scoped>
.model-picker-dialog {
  position: fixed;
  box-sizing: border-box;
  max-width: none;
  max-height: none;
  margin: 0;
  padding:
    max(12px, env(safe-area-inset-top))
    max(12px, env(safe-area-inset-right))
    max(12px, env(safe-area-inset-bottom))
    max(12px, env(safe-area-inset-left));
  border: 0;
  color: var(--text);
  background: transparent;
  overflow: hidden;
  overscroll-behavior: contain;
}

.model-picker-dialog[open] {
  display: grid;
  place-items: center;
}

.model-picker-dialog::backdrop {
  background: rgba(0, 0, 0, 0.28);
}

.model-picker-panel {
  display: flex;
  flex-direction: column;
  width: min(440px, 100%);
  min-width: 0;
  min-height: 0;
  max-height: min(480px, 100%);
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: 16px;
  background: var(--surface);
  box-shadow: var(--shadow);
}

.model-picker-header {
  display: flex;
  flex: 0 0 auto;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 6px 8px 6px 16px;
  border-bottom: 1px solid var(--line-soft);
}

.model-picker-header h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}

.model-picker-close {
  display: inline-grid;
  place-items: center;
  flex: 0 0 auto;
  width: 44px;
  height: 44px;
  padding: 0;
  color: var(--muted);
  background: transparent;
}

.model-picker-list {
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 6px;
  scrollbar-gutter: stable;
}

.model-picker-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
  min-height: 48px;
  padding: 12px 10px;
  background: transparent;
  font-size: 16px;
  line-height: 1.45;
  text-align: left;
  -webkit-tap-highlight-color: transparent;
}

.model-picker-option span {
  min-width: 0;
  white-space: normal;
  overflow-wrap: anywhere;
}

.model-picker-option svg {
  flex: 0 0 auto;
}

.model-picker-option[aria-selected="true"] {
  color: var(--accent);
  background: var(--accent-soft);
}

.model-picker-option:focus-visible,
.model-picker-close:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: -2px;
}

/* 判据同 base.css 的移动端主媒体查询（含 `(any-hover: none)`）。 */
@media (max-width: 820px),
       (hover: none) and (pointer: coarse) and (any-hover: none) {
  .model-picker-dialog[open] {
    align-items: end;
  }
}
</style>
