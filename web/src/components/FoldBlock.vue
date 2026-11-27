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

const { renderMarkdown } = useMarkdown();
const markdownHtml = computed(() => props.markdown ? renderMarkdown(props.text) : '');
const expanded = ref(false);
const preview = computed(() => {
  const plain = (props.summary || props.text).replace(/\s+/g, ' ').trim();
  return plain.length > 56 ? `${plain.slice(0, 56)}...` : plain;
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
      <span v-if="!expanded" class="codex-fold-preview">{{ preview }}</span>
    </button>
    <div v-if="expanded && summary" class="fold-summary">{{ summary }}</div>
    <div v-if="expanded && markdown" class="fold-content markdown-body" style="white-space: normal" v-html="markdownHtml"></div>
    <div v-else-if="expanded" class="fold-content">{{ text }}</div>
  </section>
</template>
