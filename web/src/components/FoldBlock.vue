<script setup lang="ts">
import { computed, ref } from 'vue';
import { Brain } from 'lucide-vue-next';
import { useMarkdown } from '../composables/useMarkdown';

const props = withDefaults(defineProps<{
  collapseLabel: string;
  className?: string;
  markdown?: boolean;
  expandLabel: string;
  summary?: string;
  text: string;
  title: string;
}>(), {
  className: '',
  markdown: false,
  summary: '',
});

const { renderMarkdown, renderInlineMarkdown } = useMarkdown();
const markdownHtml = computed(() => props.markdown ? renderMarkdown(props.text, true) : '');
const expanded = ref(false);
const preview = computed(() => {
  const plain = (props.summary || props.text).replace(/\s+/g, ' ').trim();
  return plain.length > 56 ? `${plain.slice(0, 56)}...` : plain;
});
const previewHtml = computed(() => {
  if (!props.markdown) return '';
  return (props.summary || props.text)
    .split(/\r?\n[ \t]*\r?\n+/)
    .map((part) => part.replace(/\s+/g, ' ').trim())
    .filter(Boolean)
    .map((part) => renderInlineMarkdown(part))
    .join(' ');
});
</script>

<template>
  <section class="fold-card codex-fold" :class="[className, { expanded }]">
    <button
      type="button"
      class="codex-fold-head"
      :title="expanded ? collapseLabel : expandLabel"
      @click="expanded = !expanded"
    >
      <Brain class="codex-fold-icon" :size="14" aria-hidden="true" />
      <span class="codex-fold-title">{{ title }}</span>
      <span v-if="!expanded && !markdown" class="codex-fold-preview">{{ preview }}</span>
      <span v-else-if="!expanded" class="codex-fold-preview markdown-preview" v-html="previewHtml"></span>
    </button>
    <div v-if="expanded && summary" class="fold-summary">{{ summary }}</div>
    <div v-if="expanded && markdown" class="fold-content markdown-body" style="white-space: normal" v-html="markdownHtml"></div>
    <div v-else-if="expanded" class="fold-content">{{ text }}</div>
  </section>
</template>

<style scoped>
.think-card .codex-fold-title {
  flex: 0 0 auto;
}
.think-card .codex-fold-preview {
  flex: 1 1 0%;
  min-width: 0;
}
.markdown-preview {
  display: block;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.markdown-preview :deep(strong) {
  font-weight: 700;
}
.think-card .fold-content.markdown-body {
  line-height: 1.45;
}
.think-card .fold-content.markdown-body :deep(p) {
  margin: 0 0 0.35em;
}
.think-card .fold-content.markdown-body :deep(> :first-child) {
  margin-top: 0;
}
.think-card .fold-content.markdown-body :deep(> :last-child) {
  margin-bottom: 0;
}
</style>
