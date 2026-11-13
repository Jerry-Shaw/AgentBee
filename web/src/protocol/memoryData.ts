import type { ChatMessage, ChatImage, ToolEvent, ServerMessage } from './types';
import { normalizeToolEvent, normalizeImageEvent } from './normalizers';

function decode(value: unknown): unknown {
  if (typeof value !== 'string') return value;
  try { return JSON.parse(value); } catch { return value; }
}
function record(value: unknown): Record<string, unknown> | null {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    ? value as Record<string, unknown> : null;
}
function text(value: unknown): string { return typeof value === 'string' ? value : ''; }
function list(value: unknown): unknown[] {
  const parsed = decode(value);
  return Array.isArray(parsed) ? parsed : parsed == null || parsed === '' ? [] : [parsed];
}

/** Decode memory/read only; live WebSocket events keep their existing protocol. */
export function decodeMemoryData(value: unknown, id: number, time: string): Partial<ChatMessage> {
  const data = record(decode(value));
  if (!data) return { content: text(value) };
  const events: ToolEvent[] = [];
  for (const [field, kind] of [['tools', 'tool_calls'], ['results', 'tool_result']] as const) {
    const raw = data[field];
    const parsed = decode(raw);
    const rows = typeof parsed === 'string'
      ? parsed.split(/\r?\n/).filter(line => line.trim()).map(decode)
      : list(parsed);
    rows.forEach((row, index) => {
      if (row == null || (typeof row === 'string' && !row.trim()) ||
          (Array.isArray(row) && !row.length)) return;
      const obj = record(row);
      if (obj && !Object.keys(obj).length) return;
      const msg: ServerMessage = obj && ('data' in obj || 'result' in obj)
        ? obj as ServerMessage : { data: row };
      const event = normalizeToolEvent(kind, msg, () => `${id}-${field}-${index}`, () => time);
      event.id = `${id}-${field}-${index}`;
      if (typeof row === 'string') event.data = row;
      events.push(event);
    });
  }
  const images: ChatImage[] = [];
  for (const entry of list(data.images)) {
    const group = record(decode(entry));
    if (!group) continue;
    const prompt = text(group.content);
    for (const item of list(group.images ?? group)) {
      const image = record(decode(item));
      if (!image) continue;
      const base64 = text(image.img_base64).trim();
      if (!base64) continue;
      const name = text(image.filename);
      const mime = base64.startsWith('/9j/') ? 'image/jpeg'
        : base64.startsWith('R0lGOD') ? 'image/gif'
        : base64.startsWith('UklGR') ? 'image/webp' : 'image/png';
      const normalized = normalizeImageEvent({ data: base64, prompt, mimeType: mime },
        () => `${id}-image-${images.length}`, () => time);
      if (normalized) images.push({ ...normalized, name, path: text(image.file_path) });
    }
  }
  return {
    content: text(data.content),
    think: text(data.reasoning_content),
    images,
    toolEvents: events,
    files: list(data.files).flatMap((entry, index) => {
      const file = record(decode(entry));
      if (!file || !text(file.filename).trim()) return [];
      return [{ id: `${id}-file-${index}`, name: text(file.filename), path: text(file.file_path),
        mimeType: '', source: 'path' as const, time }];
    }),
  };
}