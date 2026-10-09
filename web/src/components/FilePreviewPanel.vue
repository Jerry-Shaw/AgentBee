<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Check, Copy, Download, ExternalLink, FileText, X } from 'lucide-vue-next';
import { useMarkdown } from '../composables/useMarkdown';
import { isLocalFileUrl, resolveFileUrl } from '../utils/fileUrl';
import type { ChatFile } from '../protocol/types';

const props = defineProps<{
  file: ChatFile;
  labels: Record<string, string>;
  workspacePath: string;
  workspaceUrl: string;
}>();

const emit = defineEmits<{ close: [] }>();
const { renderMarkdown } = useMarkdown();
const sourceVisible = ref(false);
const copied = ref(false);
const remoteText = ref('');
const remoteError = ref('');
let requestController: AbortController | null = null;

const mimeType = computed(() => props.file.mimeType.toLowerCase());
const textContent = computed(() => decodeContent(props.file));
const previewText = computed(() => textContent.value || remoteText.value);
const isHtml = computed(() => mimeType.value.includes('html') || /\.(?:html?|xhtml)$/i.test(props.file.name));
const isMarkdown = computed(() => mimeType.value.includes('markdown') || /\.(?:md|markdown)$/i.test(props.file.name));
const isImage = computed(() => mimeType.value.startsWith('image/'));
const isPdf = computed(() => mimeType.value === 'application/pdf' || /\.pdf$/i.test(props.file.name));
const isText = computed(() => isMarkdown.value || isHtml.value || mimeType.value.startsWith('text/') || /(?:json|javascript|typescript|xml|css|yaml|toml)/i.test(mimeType.value));
const resolvedUrl = computed(() => resolveFileUrl(props.file, {
  workspacePath: props.workspacePath,
  workspaceUrl: props.workspaceUrl,
}));
const isLocalFile = computed(() => isLocalFileUrl(resolvedUrl.value));
const imageSrc = computed(() => {
  if (resolvedUrl.value && !isLocalFile.value) return resolvedUrl.value;
  const content = props.file.content;
  if (!content) return '';
  if (/^data:/i.test(content)) return content;
  if (props.file.encoding === 'base64') return `data:${props.file.mimeType};base64,${content}`;
  // 文本型图片（如 SVG 源码）走 URL 编码的 data URL。
  if (isImage.value) return `data:${props.file.mimeType};charset=utf-8,${encodeURIComponent(content)}`;
  return '';
});

async function copySource() {
  const value = textContent.value || resolvedUrl.value || props.file.path || '';
  if (!value) return;
  if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(value);
  copied.value = true;
  window.setTimeout(() => { copied.value = false; }, 1200);
}

function downloadFile() {
  if (resolvedUrl.value && !props.file.content) { window.open(resolvedUrl.value, '_blank', 'noopener,noreferrer'); return; }
  const raw = props.file.content || '';
  // content 本身可能是一条 data URL：后端推图片发的就是 `data:image/png;base64,...`
  // （见 core.php 的 sendImageMessage），气泡里点图片预览走的也是这条路
  // （ChatMessage.previewImage 把整条 data URL 塞进 content 并标成 encoding: 'text'）。
  // 不拆开就直接落盘的话，下下来的是一串 base64 文本、文件名却是 .png，图片打不开。
  const dataUrl = /^data:/i.test(raw) ? parseDataUrl(raw) : null;
  // 兜底顺序：data URL 拆出来的字节 → encoding 说是 base64 就解码 → 否则按纯文本编码。
  const bytes = dataUrl?.bytes
    ?? (props.file.encoding === 'base64' ? tryDecodeBase64Bytes(raw) : null)
    ?? new TextEncoder().encode(raw);
  // data URL 自带的 MIME 比 file.mimeType 更可信（它描述的就是这段字节）。
  const mimeType = dataUrl?.mimeType || props.file.mimeType || 'application/octet-stream';
  const url = URL.createObjectURL(new Blob([bytes], { type: mimeType }));
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = props.file.name;
  anchor.click();
  URL.revokeObjectURL(url);
}

/** `data:[<mime>][;base64],<payload>` → 字节 + MIME；不是 data URL 就返回 null。 */
function parseDataUrl(value: string) {
  const match = /^data:([^;,]*)((?:;[^,]*)*),([\s\S]*)$/i.exec(value);
  if (!match) return null;
  const mimeType = match[1] || 'application/octet-stream';
  const payload = match[3] || '';
  if (/;base64/i.test(match[2] || '')) {
    const bytes = tryDecodeBase64Bytes(payload);
    return bytes ? { bytes, mimeType } : null;
  }
  try {
    return { bytes: new TextEncoder().encode(decodeURIComponent(payload)), mimeType };
  } catch {
    return { bytes: new TextEncoder().encode(payload), mimeType };
  }
}

/** `atob` 遇到非法字符会抛，降级成 null 让调用方兜底，别把整个下载弄挂。 */
function tryDecodeBase64Bytes(value: string) {
  try {
    return decodeBase64Bytes(value);
  } catch {
    return null;
  }
}

function decodeContent(file: ChatFile) {
  if (!file.content) return '';
  if (file.encoding !== 'base64' || /^data:/i.test(file.content)) return file.content;
  return decodeBase64(file.content);
}

function decodeBase64(value: string) {
  try { return decodeURIComponent(escape(atob(value))); } catch { return atob(value); }
}

function decodeBase64Bytes(value: string) {
  const binary = atob(value);
  return Uint8Array.from(binary, (character) => character.charCodeAt(0));
}

async function loadRemoteText() {
  requestController?.abort();
  requestController = null;
  remoteText.value = '';
  remoteError.value = '';
  if (!isText.value || textContent.value || !resolvedUrl.value || isLocalFile.value || !/^https?:/i.test(resolvedUrl.value)) return;
  const controller = new AbortController();
  requestController = controller;
  try {
    const response = await fetch(resolvedUrl.value, { signal: controller.signal });
    if (!response.ok) throw new Error(`${response.status} ${response.statusText}`.trim());
    remoteText.value = await response.text();
  } catch (error) {
    if (controller.signal.aborted) return;
    remoteError.value = error instanceof Error ? error.message : String(error);
  }
}

watch([() => props.file.id, resolvedUrl], loadRemoteText, { immediate: true });
onBeforeUnmount(() => requestController?.abort());
</script>

<template>
  <aside class="file-preview-panel" :aria-label="labels.filePreview">
    <header class="file-preview-header">
      <FileText :size="16" aria-hidden="true" />
      <div class="file-preview-title"><strong>{{ file.name }}</strong><span>{{ file.mimeType }}</span></div>
      <button type="button" class="icon-button" :title="labels.closePreview" @click="emit('close')"><X :size="16" aria-hidden="true" /></button>
    </header>
    <div class="file-preview-toolbar">
      <button v-if="isText" type="button" class="icon-text-button" @click="sourceVisible = !sourceVisible"><FileText :size="14" /><span>{{ sourceVisible ? labels.preview : labels.source }}</span></button>
      <button v-if="isText" type="button" class="icon-text-button" :title="copied ? labels.copied : labels.copyMessage" @click="copySource"><Check v-if="copied" :size="14" /><Copy v-else :size="14" /><span>{{ copied ? labels.copied : labels.copyMessage }}</span></button>
      <button type="button" class="icon-text-button" @click="downloadFile"><Download :size="14" /><span>{{ labels.download }}</span></button>
      <a v-if="resolvedUrl && !isLocalFile" class="icon-text-button" :href="resolvedUrl" target="_blank" rel="noopener noreferrer"><ExternalLink :size="14" /><span>{{ labels.openInNewWindow }}</span></a>
    </div>
    <div v-if="isLocalFile && !previewText && !imageSrc" class="file-preview-notice" role="status">{{ labels.localFileUnavailable }}<code>{{ resolvedUrl }}</code></div>
    <div v-else-if="sourceVisible" class="file-preview-content source"><pre>{{ previewText }}</pre></div>
    <div v-else-if="isHtml && (textContent || resolvedUrl)" class="file-preview-content html"><iframe :src="textContent ? undefined : resolvedUrl" :srcdoc="textContent || undefined" sandbox="allow-scripts allow-forms allow-modals allow-popups allow-downloads" :title="file.name"></iframe></div>
    <div v-else-if="isMarkdown && previewText" class="file-preview-content markdown" v-html="renderMarkdown(previewText)"></div>
    <!-- 远端 Markdown 常被 CORS 拦住 fetch，退回 iframe 让浏览器自己渲染（通常是纯文本）。 -->
    <div v-else-if="isMarkdown && resolvedUrl && !isLocalFile" class="file-preview-content html"><iframe :src="resolvedUrl" :title="file.name"></iframe></div>
    <div v-else-if="isImage && imageSrc" class="file-preview-content image"><img :src="imageSrc" :alt="file.name" /></div>
    <div v-else-if="isPdf && resolvedUrl" class="file-preview-content pdf"><iframe :src="resolvedUrl" :title="file.name"></iframe></div>
    <div v-else-if="isText && previewText" class="file-preview-content source"><pre>{{ previewText }}</pre></div>
    <div v-else-if="remoteError" class="file-preview-content empty-preview"><span>{{ labels.previewLoadFailed }}: {{ remoteError }}</span></div>
    <div v-else-if="resolvedUrl" class="file-preview-content empty-preview"><ExternalLink :size="18" /><span>{{ labels.previewUnavailable }}</span></div>
    <div v-else class="file-preview-content empty-preview"><span>{{ labels.noPreviewContent }}</span></div>
  </aside>
</template>
