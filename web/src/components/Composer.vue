<script setup lang="ts">
import { ArrowUp, LoaderCircle, Paperclip, Plus, RotateCcw, Square, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ModelPicker from './ModelPicker.vue';
import type { ClientAttachment } from '../protocol/types';
import { RESET_COMMAND } from '../utils/commands';
import { resolveEnterAction, shouldIgnoreCompositionEnter } from '../utils/composerKeyboard';

const props = defineProps<{
  availableModels: string[];
  disabled: boolean;
  labels: Record<string, string>;
  /** 移动端布局：软键盘上的 Enter 只换行，发送交给按钮。 */
  mobileLayout: boolean;
  modelName: string;
}>();

const emit = defineEmits<{
  selectModel: [modelName: string];
  send: [
    text: string,
    attachments: ClientAttachment[],
    onDispatched: (dispatched: boolean) => void,
  ];
  stop: [];
}>();

const text = ref('');
const attachments = ref<ClientAttachment[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const textarea = ref<HTMLTextAreaElement | null>(null);
const sizeProbe = ref<HTMLTextAreaElement | null>(null);
const uploadWarnings = ref<string[]>([]);
const isComposing = ref(false);
const submissionPending = ref(false);
const pendingFileBatches = ref(0);
let fileReadQueue: Promise<void> = Promise.resolve();
const isDraggingFile = ref(false);
const COMPOSITION_ENTER_GUARD_MS = 100;
let ignoreEnterUntil = 0;
let textareaResizeObserver: ResizeObserver | null = null;
let textareaWidth = 0;
let dragDepth = 0;
/** 发送按钮的悬浮提示：桌面端讲「Enter 发送」，移动端讲「Enter 换行」。 */
const sendHint = computed(() => (
  props.mobileLayout ? props.labels.sendShortcutHintMobile : props.labels.sendShortcutHint
));

const canSubmit = computed(() => (
  !submissionPending.value &&
  pendingFileBatches.value === 0 &&
  !isComposing.value &&
  (Boolean(text.value.trim()) || attachments.value.length > 0)
));

/**
 * 图片附件就地预览。
 *
 * 待发送的附件还没有 workspace 路径，走不了侧栏那个 FilePreviewPanel（它要
 * workspacePath / workspaceUrl），所以这里只做一个轻量灯箱：点缩略图看大图，
 * 点遮罩 / 关闭按钮 / Esc 退出。Teleport 到 body，免得被 .composer-panel 裁掉。
 */
const previewAttachment = ref<ClientAttachment | null>(null);
const previewCloseButton = ref<HTMLButtonElement | null>(null);

/** 只有拿得到字节的图片才给缩略图，其它（pdf / 文本…）继续走原来的胶囊。 */
function isImageAttachment(attachment: ClientAttachment): boolean {
  return Boolean(attachment.base64) && (attachment.type || '').startsWith('image/');
}

function attachmentSrc(attachment: ClientAttachment): string {
  return 'data:' + (attachment.type || 'image/png') + ';base64,' + (attachment.base64 || '');
}

function openAttachmentPreview(attachment: ClientAttachment) {
  if (!isImageAttachment(attachment)) return;
  previewAttachment.value = attachment;
}

function closeAttachmentPreview() {
  previewAttachment.value = null;
}

function onAttachmentPreviewKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape') return;
  event.preventDefault();
  closeAttachmentPreview();
}

watch(previewAttachment, (next) => {
  if (next) nextTick(() => previewCloseButton.value?.focus());
});

function submit() {
  if (!canSubmit.value) return;
  const submittedText = text.value;
  const value = submittedText.trim();
  if (!value && !attachments.value.length) return;
  const submittedAttachmentIds = new Set(attachments.value.map((attachment) => attachment.id));
  const submittedAttachments = attachments.value.map((attachment) => ({ ...attachment }));
  submissionPending.value = true;
  emit('send', value, submittedAttachments, (dispatched) => {
    submissionPending.value = false;
    if (!dispatched) return;
    if (text.value === submittedText) text.value = '';
    attachments.value = attachments.value.filter(
      (attachment) => !submittedAttachmentIds.has(attachment.id),
    );
    uploadWarnings.value = [];
    if (fileInput.value) fileInput.value.value = '';
    nextTick(resize);
  });
}

function resetSession() {
  emit('send', RESET_COMMAND, [], () => undefined);
}

function onKeydown(event: KeyboardEvent) {
  if (event.key !== 'Enter') return;
  if (shouldIgnoreCompositionEnter(
    event,
    isComposing.value,
    performance.now() < ignoreEnterUntil,
  )) return;
  // 具体规则见 utils/composerKeyboard.ts 的 resolveEnterAction。
  const action = resolveEnterAction(event, props.mobileLayout);
  // 'none' 不拦默认行为：移动端 / Shift+Enter 都靠浏览器自己插入换行。
  if (action === 'none') return;
  event.preventDefault();
  if (action === 'newline') insertNewlineAtCursor();
  else submit();
}

function onCompositionStart() {
  isComposing.value = true;
  ignoreEnterUntil = 0;
}

function onCompositionEnd() {
  isComposing.value = false;
  ignoreEnterUntil = performance.now() + COMPOSITION_ENTER_GUARD_MS;
  nextTick(resize);
}

function insertNewlineAtCursor() {
  const input = textarea.value;
  if (!input) {
    text.value = `${text.value}\n`;
    nextTick(resize);
    return;
  }

  const start = input.selectionStart;
  const end = input.selectionEnd;
  text.value = `${text.value.slice(0, start)}\n${text.value.slice(end)}`;
  nextTick(() => {
    input.selectionStart = start + 1;
    input.selectionEnd = start + 1;
    resize();
  });
}

function resize() {
  const input = textarea.value;
  const probe = sizeProbe.value;
  if (!input || !probe) return;
  // Measure outside the layout so the focused input never collapses between keystrokes.
  probe.value = input.value;
  input.style.height = `${Math.min(150, probe.scrollHeight)}px`;
}

onMounted(() => {
  resize();
  textareaResizeObserver = new ResizeObserver(([entry]) => {
    if (!entry || entry.contentRect.width === textareaWidth) return;
    textareaWidth = entry.contentRect.width;
    resize();
  });
  if (textarea.value) textareaResizeObserver.observe(textarea.value);
});

onBeforeUnmount(() => {
  textareaResizeObserver?.disconnect();
});

/** Native paste only: no background clipboard access or permission prompt. */
function onPaste(event: ClipboardEvent) {
  const clipboard = event.clipboardData;
  if (!clipboard) return;
  // Use items OR files, not both: browsers often expose the same image twice.
  const itemFiles = Array.from(clipboard.items || [])
    .filter(item => item.kind === 'file')
    .map(item => item.getAsFile())
    .filter((file): file is File => file !== null);
  const files = itemFiles.length ? itemFiles : Array.from(clipboard.files || []);
  if (!files.length) return; // Keep native text paste, selection and undo behavior.

  event.preventDefault();
  const pastedText = clipboard.getData('text/plain');
  const input = textarea.value;
  if (pastedText && input) {
    const start = input.selectionStart;
    const end = input.selectionEnd;
    text.value = text.value.slice(0, start) + pastedText + text.value.slice(end);
    nextTick(() => {
      input.setSelectionRange(start + pastedText.length, start + pastedText.length);
      resize();
    });
  }
  const stamp = Date.now();
  const namedFiles = files.map((file, index) => {
    if (file.name.trim() && file.name !== 'image.png') return file;
    const extension = file.type === 'image/jpeg' ? 'jpg'
      : file.type.split('/')[1]?.replace(/[^a-z0-9]/gi, '') || 'bin';
    const prefix = file.type.startsWith('image/') ? 'clipboard-image' : 'clipboard-file';
    return new File([file], `${prefix}-${stamp}-${index + 1}.${extension}`, {
      type: file.type, lastModified: file.lastModified,
    });
  });
  void addFiles(namedFiles);
}

async function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement;
  await addFiles(Array.from(input.files || []));
  input.value = '';
}

/** 收单入口：文件选择器、剪贴板粘贴和拖拽放入共用同一套读取逻辑。 */
function addFiles(files: File[]): Promise<void> {
  if (!files.length) return Promise.resolve();
  pendingFileBatches.value += 1;
  // Serialize attachment reads from all entry points and preserve insertion order.
  const task = fileReadQueue.then(() => loadFiles(files)).catch(() => {
    uploadWarnings.value.push(formatLabel(props.labels.fileReadFailed, {
      name: files.map(file => file.name).join(', '),
    }));
  }).finally(() => {
    pendingFileBatches.value -= 1;
  });
  fileReadQueue = task;
  return task;
}

async function loadFiles(files: File[]) {
  if (!files.length) return;

  uploadWarnings.value = [];
  const results = await Promise.allSettled(files.map(readAttachment));
  const loaded: ClientAttachment[] = [];
  results.forEach((result, index) => {
    if (result.status === 'fulfilled') {
      loaded.push(result.value);
      return;
    }
    uploadWarnings.value.push(formatLabel(
      props.labels.fileReadFailed,
      { name: files[index].name },
    ));
  });
  attachments.value = [...attachments.value, ...loaded];
}

function isFileDrag(event: DragEvent): boolean {
  const types = event.dataTransfer?.types;
  return Boolean(types && Array.from(types).includes('Files'));
}

function onDragEnter(event: DragEvent) {
  if (!isFileDrag(event)) return;
  event.preventDefault();
  dragDepth += 1;
  isDraggingFile.value = true;
}

function onDragOver(event: DragEvent) {
  if (!isFileDrag(event)) return;
  event.preventDefault();
  if (event.dataTransfer) event.dataTransfer.dropEffect = props.disabled ? 'none' : 'copy';
}

function onDragLeave(_event: DragEvent) {
  // dragleave 事件里的 dataTransfer.types 未必还带着 'Files'，所以这里不做类型判断，
  // 只按进出计数收敛，避免高亮层卡住不消失。
  if (!isDraggingFile.value) return;
  dragDepth = Math.max(0, dragDepth - 1);
  if (dragDepth === 0) isDraggingFile.value = false;
}

async function onDrop(event: DragEvent) {
  if (!isFileDrag(event)) return;
  event.preventDefault();
  dragDepth = 0;
  isDraggingFile.value = false;
  if (props.disabled) return;
  const dropped = Array.from(event.dataTransfer?.files || []);
  if (dropped.length) await addFiles(dropped);
}

function removeAttachment(id: string) {
  attachments.value = attachments.value.filter((attachment) => attachment.id !== id);
}

function formatSize(size: number) {
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${(size / 1024 / 1024).toFixed(1)} MB`;
}

async function readAttachment(file: File): Promise<ClientAttachment> {
  return {
    id: makeAttachmentId(),
    name: file.name,
    size: file.size,
    type: file.type || 'application/octet-stream',
    base64: await readFileBase64(file),
  };
}

function readFileBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = String(reader.result || '');
      resolve(result.includes(',') ? result.split(',')[1] : result);
    };
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
}

function makeAttachmentId() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return `file-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function formatLabel(template: string, values: Record<string, string>) {
  return Object.entries(values).reduce(
    (result, [key, value]) => result.split(`{${key}}`).join(value),
    template,
  );
}
</script>

<template>
  <footer class="composer">
    <div
      class="composer-panel"
      :class="{ 'is-dragging': isDraggingFile }"
      @dragenter="onDragEnter"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
      @drop="onDrop"
    >
      <div v-if="isDraggingFile" class="composer-drop-hint" aria-hidden="true">
        <Paperclip :size="16" aria-hidden="true" />
        <span>{{ labels.dropFilesHint }}</span>
      </div>
      <div v-if="uploadWarnings.length" class="attachment-warnings" role="alert" aria-live="polite">
        <span v-for="(warning, index) in uploadWarnings" :key="`${index}-${warning}`">{{ warning }}</span>
      </div>
      <div v-if="attachments.length" class="attachment-list" :aria-label="labels.attachedFiles">
        <template v-for="attachment in attachments" :key="attachment.id">
          <figure v-if="isImageAttachment(attachment)" class="attachment-item">
            <button
              type="button"
              class="attachment-thumb"
              :title="labels.preview + ': ' + attachment.name"
              :aria-label="labels.preview + ': ' + attachment.name"
              @click="openAttachmentPreview(attachment)"
            >
              <img :src="attachmentSrc(attachment)" :alt="attachment.name" decoding="async" />
            </button>
            <figcaption class="attachment-caption" :title="attachment.name">
              {{ attachment.name }}
            </figcaption>
            <button
              type="button"
              class="attachment-remove"
              :title="labels.removeFile"
              :aria-label="labels.removeFile"
              @click="removeAttachment(attachment.id)"
            >
              <X :size="12" aria-hidden="true" />
            </button>
          </figure>
          <span v-else class="attachment-chip">
            <Paperclip :size="13" aria-hidden="true" />
            <span class="attachment-name">{{ attachment.name }}</span>
            <span class="attachment-size">{{ formatSize(attachment.size) }}</span>
            <button
              type="button"
              :title="labels.removeFile"
              :aria-label="labels.removeFile"
              @click="removeAttachment(attachment.id)"
            >
              <X :size="13" aria-hidden="true" />
            </button>
          </span>
        </template>
      </div>
      <div class="composer-input">
        <textarea
          ref="textarea"
          v-model="text"
          rows="1"
          :aria-label="labels.composerPlaceholder"
          :placeholder="labels.composerPlaceholder"
          @input="resize"
          @paste="onPaste"
          @keydown="onKeydown"
          @compositionstart="onCompositionStart"
          @compositionend="onCompositionEnd"
        ></textarea>
        <textarea
          ref="sizeProbe"
          class="composer-size-probe"
          rows="1"
          tabindex="-1"
          aria-hidden="true"
          disabled
        ></textarea>
      </div>

      <div class="composer-toolbar">
        <div class="composer-toolbar-group">
          <label
            class="attach-button composer-tool-button"
            :aria-label="labels.attachFiles"
            :data-tooltip="labels.attachFiles"
            :title="labels.attachFiles"
          >
            <input
              ref="fileInput"
              class="file-input"
              type="file"
              multiple
              :aria-label="labels.attachFiles"
              @change="onFileChange"
            />
            <Plus :size="19" aria-hidden="true" />
          </label>
          <button
            type="button"
            class="reset-send composer-text-button"
            :title="labels.resetSession"
            :disabled="disabled"
            @click="resetSession"
          >
            <RotateCcw :size="15" aria-hidden="true" />
            <span>{{ labels.resetSession }}</span>
          </button>
        </div>

        <div class="composer-toolbar-group composer-toolbar-actions">
          <ModelPicker
            :available-models="availableModels"
            :disabled="disabled"
            :labels="labels"
            :model-name="modelName"
            @select="emit('selectModel', $event)"
          />
          <button
            type="button"
            class="stop-send composer-submit-button"
            :aria-label="labels.stopGeneration"
            :data-tooltip="labels.stopGeneration"
            :title="labels.stopGeneration"
            :disabled="disabled"
            @click="emit('stop')"
          >
            <Square :size="14" aria-hidden="true" />
          </button>
          <button
            type="button"
            class="send composer-submit-button"
            :aria-label="labels.send"
            :data-tooltip="submissionPending ? labels.connectingToSend : sendHint"
            :title="submissionPending ? labels.connectingToSend : sendHint"
            :disabled="!canSubmit"
            @click="submit"
          >
            <LoaderCircle v-if="submissionPending || pendingFileBatches > 0" class="spin" :size="17" aria-hidden="true" />
            <ArrowUp v-else :size="18" aria-hidden="true" />
          </button>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="previewAttachment"
        class="attachment-preview-backdrop"
        @click.self="closeAttachmentPreview"
        @keydown="onAttachmentPreviewKeydown"
      >
        <figure
          class="attachment-preview"
          role="dialog"
          aria-modal="true"
          :aria-label="previewAttachment.name"
        >
          <img :src="attachmentSrc(previewAttachment)" :alt="previewAttachment.name" />
          <figcaption>
            {{ previewAttachment.name }} · {{ formatSize(previewAttachment.size) }}
          </figcaption>
        </figure>
        <button
          ref="previewCloseButton"
          type="button"
          class="attachment-preview-close"
          :title="labels.close"
          :aria-label="labels.close"
          @click="closeAttachmentPreview"
        >
          <X :size="18" aria-hidden="true" />
        </button>
      </div>
    </Teleport>
  </footer>
</template>
