<script setup lang="ts">
import { computed, ref } from 'vue';
import { Wrench } from 'lucide-vue-next';
import type { ToolEvent } from '../protocol/types';

const props = defineProps<{
  events: ToolEvent[];
  title?: string;
  labels: Record<string, string>;
}>();
const expanded = ref(false);
const fullResults = ref(new Set<string>());
const fullCalls = ref(new Set<string>());
const TOOL_PREVIEW_CHARS = 1000;
function callText(round: ToolRound): string {
  const data = round.call?.data || '';
  return fullCalls.value.has(round.key) ? data : data.slice(0, TOOL_PREVIEW_CHARS);
}
function toggleFullCall(key: string) {
  const next = new Set(fullCalls.value);
  if (next.has(key)) next.delete(key);
  else next.add(key);
  fullCalls.value = next;
}
function resultText(round: ToolRound): string {
  const data = round.result?.data || '';
  return fullResults.value.has(round.key) ? data : data.slice(0, TOOL_PREVIEW_CHARS);
}
function toggleFullResult(key: string) {
  const next = new Set(fullResults.value);
  if (next.has(key)) next.delete(key);
  else next.add(key);
  fullResults.value = next;
}
const openEvents = ref(new Set<string>());
interface ToolRound {
  key: string;
  call?: ToolEvent;
  result?: ToolEvent;
}
const rounds = computed<ToolRound[]>(() => {
  const events = props.events.flatMap(event => event.calls?.length ? event.calls : [event]);
  const calls = events.filter(event => event.kind === 'tool_calls');
  const results = events.filter(event => event.kind === 'tool_result');
  const paired: ToolRound[] = calls.map((call, index) => ({ key: `call-${call.id}-${index}`, call }));
  const used = new Set<ToolEvent>();
  // Match explicit protocol IDs first, before name/order fallback can consume a result.
  for (const round of paired) {
    if (!round.call?.callId) continue;
    const result = results.find(item => !used.has(item) && item.callId === round.call!.callId);
    if (result) { round.result = result; used.add(result); }
  }
  for (const round of paired) {
    if (round.result) continue;
    const call = round.call!;
    const compatible = (result: ToolEvent) => !used.has(result)
      && !(call.callId && result.callId && call.callId !== result.callId);
    const result = results.find(item => compatible(item) && Boolean(call.name) && item.name === call.name)
      || results.find(item => compatible(item) && (!call.name || !item.name));
    if (result) { round.result = result; used.add(result); }
  }
  results.forEach((result, index) => {
    if (!used.has(result)) paired.push({ key: `result-${result.id}-${index}`, result });
  });
  return paired;
});
const summary = computed(() => {
  const count = rounds.value.reduce((total, round) => total + (round.call?.callCount ?? 1), 0);
  const names = Array.from(new Set(rounds.value.flatMap(round => {
    const event = round.call || round.result;
    return event?.names?.length ? event.names : [event?.name || ''];
  }).filter(Boolean)));
  const label = props.labels.executedTools.replace('{count}', String(count));
  return names.length ? `${label} · ${names.join(', ')}` : label;
});
function toggleRound(key: string) {
  const next = new Set(openEvents.value);
  if (next.has(key)) next.delete(key);
  else next.add(key);
  openEvents.value = next;
}
function roundName(round: ToolRound) {
  return round.call?.name || round.result?.name || props.labels.toolEvent;
}
</script>

<template>
  <section class="codex-fold tool-stack" :class="{ expanded }">
    <button type="button" class="codex-fold-head"
      :aria-expanded="expanded"
      :title="`${summary} · ${expanded ? labels.collapse : labels.expand}`"
      @click="expanded = !expanded">
      <Wrench class="codex-fold-icon" :size="14" aria-hidden="true" />
      <span class="codex-fold-title">{{ title || summary }}</span>
    </button>
    <div v-if="expanded" class="tool-stack-body tool-round-list">
      <article v-for="round in rounds" :key="round.key" class="tool-round"
        :class="{ failed: round.result?.ok === false || round.call?.ok === false }">
        <button type="button" class="tool-event-head" :aria-expanded="openEvents.has(round.key)"
          :title="roundName(round)" @click="toggleRound(round.key)">
          <span class="fold-chevron" :class="{ open: openEvents.has(round.key) }" aria-hidden="true"></span>
          <span class="tool-event-name">{{ roundName(round) }}</span>
          <span v-if="round.result?.ok === false || round.call?.ok === false" class="tool-status-label">{{ labels.failed }}</span>
          <span class="tool-event-time">{{ round.call?.time || round.result?.time }}</span>
        </button>
        <div v-if="openEvents.has(round.key)" class="tool-round-body">
          <section v-if="round.call" class="tool-round-content tool-round-call">
            <div class="tool-round-label">{{ labels.toolCalls }}</div>
            <pre class="tool-event-data">{{ callText(round) }}</pre>
            <button v-if="round.call.data.length > TOOL_PREVIEW_CHARS" type="button"
              class="tool-result-more" :aria-expanded="fullCalls.has(round.key)"
              @click="toggleFullCall(round.key)">
              {{ fullCalls.has(round.key) ? labels.showLessResult : labels.showFullResult }}
            </button>
          </section>
          <section v-if="round.result" class="tool-round-content tool-round-result">
            <div class="tool-round-label">{{ labels.toolResults }}</div>
            <pre class="tool-event-data">{{ resultText(round) }}</pre>
            <button v-if="round.result.data.length > TOOL_PREVIEW_CHARS" type="button"
              class="tool-result-more" :aria-expanded="fullResults.has(round.key)"
              @click="toggleFullResult(round.key)">
              {{ fullResults.has(round.key) ? labels.showLessResult : labels.showFullResult }}
            </button>
          </section>
          <div v-else class="tool-round-pending">{{ labels.toolResultPending }}</div>
        </div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.tool-round-list { min-width: 0; padding-block: 0; }
.tool-round { padding: 6px 0; min-width: 0; }
.tool-round:first-child { padding-top: 0; }
.tool-round:last-child { padding-bottom: 0; }
.tool-round + .tool-round { border-top: 1px solid color-mix(in srgb, var(--muted) 22%, transparent); }
.tool-round-body { display: grid; gap: var(--message-inner-gap, 8px); margin-top: var(--message-inner-gap, 8px); padding: 0 0 0 16px; min-width: 0; }
.tool-round-content { display: grid; gap: var(--message-inner-gap, 8px); border-radius: 6px; padding: 8px 10px; min-width: 0; }
.tool-round-call {
  border-left: 2px solid #b39a62;
  background: color-mix(in srgb, #b39a62 9%, transparent);
}
.tool-round-result {
  border-left: 2px solid #568e80;
  background: color-mix(in srgb, #568e80 9%, transparent);
}
.tool-round-label { font-size: 0.75rem; color: var(--muted); margin: 0; }
.tool-round-content .tool-event-data { margin: 0; padding: 0; background: transparent; max-width: 100%; overflow-x: auto; }
.tool-round-pending { font-size: 0.8rem; color: var(--muted); padding: 0; }
.tool-round.failed .tool-status-label { color: #d16a6a; }
.tool-result-more { justify-self: start; margin: 0; color: var(--muted); background: transparent; border: 1px solid currentColor; border-radius: 5px; padding: 4px 9px; cursor: pointer; }
</style>