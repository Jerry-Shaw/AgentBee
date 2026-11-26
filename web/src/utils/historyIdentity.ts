import type { ChatMessage, MemoryRecord, ToolEvent } from '../protocol/types';

export function matchesHistoryIdentity(local: ChatMessage, record: MemoryRecord): boolean {
  if (!record.messageId || local.messageId !== record.messageId || local.role !== record.role) return false;
  const worker = record.worker_name || record.payload?.senderName || record.payload?.sender;
  const sender = local.senderName || local.sender;
  if (worker && sender && worker !== sender) return false;
  if (record.payload?.WindowName && local.WindowName && record.payload.WindowName !== local.WindowName) return false;
  if (record.payload?.isSubTalk !== undefined && (local.isSubTalk || 0) !== record.payload.isSubTalk) return false;
  return true;
}

function flatten(events: ToolEvent[] = []): ToolEvent[] {
  return events.flatMap(event => event.calls?.length ? event.calls : [event]);
}
function sameTool(a: ToolEvent, b: ToolEvent): boolean {
  if (a.kind !== b.kind) return false;
  if (a.callId && b.callId) return a.callId === b.callId;
  return a.name === b.name && a.data === b.data;
}

/** History is persisted output; local text remains an independent stream buffer. */
export function remainingLiveOutput(local: ChatMessage, records: MemoryRecord[], deleting = false): ChatMessage {
  const remainder = (value: string, saved: string) => {
    if (!saved) return value;
    if (deleting) return value.replace(saved, '');
    if (saved.startsWith(value)) return '';
    if (value.startsWith(saved)) return value.slice(saved.length);
    if (saved.includes(value)) return '';
    // Replays may start at a later chunk: strip only a known suffix/prefix overlap.
    for (let size = Math.min(saved.length, value.length); size > 0; size -= 1) {
      if (saved.endsWith(value.slice(0, size))) return value.slice(size);
    }
    return value;
  };
  const tools = records.flatMap(record => flatten(record.payload?.toolEvents));
  const used = new Set<number>();
  return {
    ...local,
    content: remainder(local.content, records.map(record => record.content).join('')),
    think: remainder(local.think || '', records.map(record => record.payload?.think || '').join('')),
    toolEvents: flatten(local.toolEvents).filter(event => {
      const index = tools.findIndex((saved, i) => !used.has(i) && sameTool(event, saved));
      if (index < 0) return true;
      used.add(index);
      return false;
    }),
    images: (local.images || []).filter(image => !records.some(record => record.payload?.images?.some(saved => saved.src === image.src))),
    files: (local.files || []).filter(file => !records.some(record => record.payload?.files?.some(saved =>
      Boolean(file.path && saved.path === file.path) || Boolean(file.url && saved.url === file.url)
      || Boolean(file.content && saved.content === file.content && saved.name === file.name)))),
  };
}

export function appendRemainingOutput(history: ChatMessage, remainder: ChatMessage): ChatMessage {
  return {
    ...history,
    id: remainder.id,
    status: remainder.status,
    statusMessage: remainder.statusMessage,
    content: history.content + remainder.content,
    think: (history.think || '') + (remainder.think || ''),
    toolEvents: [...(history.toolEvents || []), ...(remainder.toolEvents || [])],
    images: [...(history.images || []), ...(remainder.images || [])],
    files: [...(history.files || []), ...(remainder.files || [])],
  };
}