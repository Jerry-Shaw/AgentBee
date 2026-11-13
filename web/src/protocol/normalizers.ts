import type { ChatFile, ChatImage, ServerMessage, ToolEvent } from './types';

const DEFAULT_IMAGE_MIME = 'image/png';
const DEFAULT_FILE_MIME = 'application/octet-stream';

export function normalizePayload(msg: ServerMessage): string {
  const data = msg.data ?? msg.text ?? msg.content ?? msg.delta ?? '';
  return typeof data === 'string' ? data : JSON.stringify(data, null, 2);
}

export function normalizeServerError(msg: ServerMessage): string {
  const isErrorEvent = msg.status === 'error' || getServerType(msg) === 'error' || msg.error !== undefined;
  if (!isErrorEvent) return '';

  const error = asRecord(msg.error);
  const data = asRecord(msg.data);
  const dataError = asRecord(data.error);
  const message = asString(error.message)
    || asString(dataError.message)
    || asString(data.message)
    || asString(data.error)
    || asString(msg.error)
    || msg.message
    || (typeof msg.data === 'string' ? msg.data : '')
    || '';

  const details = Array.from(new Set([
    asString(error.code),
    asString(error.type),
    asString(data.code),
    asString(data.type),
  ].filter((detail) => Boolean(detail) && detail !== 'error')));
  const suffix = details.length ? ` (${details.join(', ')})` : '';
  const act = msg.act ? `${msg.act}: ` : '';

  return `${act}${message || 'Server returned an error.'}${suffix}`;
}

export function getServerType(msg: ServerMessage): string {
  return String(msg.type || msg.event || msg.role || '');
}

export function getMessageId(msg: ServerMessage): string | null {
  return msg.messageId || msg.turnId || msg.requestId || null;
}

export function parseServerMessage(raw: string): ServerMessage | string {
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : String(raw);
  } catch {
    return raw;
  }
}

export function normalizeToolEvent(
  kind: ToolEvent['kind'],
  msg: ServerMessage,
  makeId: () => string,
  nowTime: () => string,
): ToolEvent {
  if (kind === 'tool_calls') {
    const calls = Array.isArray(msg.data) ? msg.data : [msg.data || msg];
    if (calls.length > 1) {
const entries = calls.map(call => normalizeToolEvent(kind, { ...msg, data: call, toolCallId: undefined }, makeId, nowTime));
return { ...entries[0], calls: entries, callCount: entries.length,
names: entries.map(entry => entry.name).filter(Boolean) };
}
const primary = asRecord(calls[0]);
    const fn = asRecord(primary.function);
const names = calls.map((call) => {
const record = asRecord(call);
return asString(asRecord(record.function).name) || asString(record.name);
}).filter(Boolean);

    return {
      id: msg.toolCallId || asString(primary.id) || makeId(),
callId: msg.toolCallId || asString(primary.id) || undefined,
callCount: calls.length,
names: names.length ? names : [msg.name || msg.tool || ''].filter(Boolean),
      kind,
      name: asString(fn.name) || asString(primary.name) || msg.name || msg.tool || '',
      ok: msg.ok,
      summary: calls
        .map((call) => {
          const record = asRecord(call);
          const callFn = asRecord(record.function);
          return asString(callFn.name) || asString(record.name) || asString(record.id) || 'tool call';
        })
        .join(', '),
      data: calls.map(formatToolCall).join('\n\n'),
      time: nowTime(),
    };
  }

  const data = msg.data && typeof msg.data === 'object' && !Array.isArray(msg.data) ? asRecord(msg.data) : {};

  return {
    id: msg.toolCallId || asString(data.id) || makeId(),
callId: msg.toolCallId || asString(data.tool_call_id) || asString(data.id) || undefined,
    kind,
    name: asString(data.name) || msg.name || msg.tool || '',
    ok: msg.ok ?? asBoolean(data.ok),
    summary: asString(data.name) || asString(data.id) || '',
    data: formatToolResult(data, msg),
    time: nowTime(),
  };
}

export function normalizeImageEvent(
  msg: ServerMessage,
  makeId: () => string,
  nowTime: () => string,
): ChatImage | null {
  const payload = msg.data ?? msg.content ?? msg.text;
  const record = asRecord(payload);

  // 优先支持新格式：data 直接是 base64 字符串，prompt 在同级
  const base64 = asString(payload)
    || asString(record.url)
    || asString(record.base64)
    || asString(record.data)
    || asString(record.image);
  if (!base64.trim()) return null;

  // 优先从 msg.prompt 读取（新格式），然后才是嵌套在 data 里的 prompt
  const mimeType = asString(record.mimeType) || asString(record.mime_type) || asString(msg.mimeType) || DEFAULT_IMAGE_MIME;
  const prompt = msg.prompt
    || asString(record.prompt)
    || asString(record.text)
    || asString(msg.text)
    || msg.message
    || '';

  return {
    id: asString(record.id) || msg.messageId || makeId(),
    src: toImageDataUrl(base64.trim(), mimeType),
    prompt: asString(prompt).trim(),
    time: nowTime(),
  };
}

export function normalizeFileEvent(
  msg: ServerMessage,
  makeId: () => string,
  nowTime: () => string,
): ChatFile | null {
  const payload = msg.file ?? msg.data ?? msg.content ?? msg.result ?? msg;
  const record = asRecord(payload);
  const nested = asRecord(record.file);
  const sourceRecord = Object.keys(nested).length ? { ...record, ...nested } : record;
  const rawContent = sourceRecord.content ?? sourceRecord.text ?? sourceRecord.body ??
    (typeof payload === 'string' ? payload : undefined);
  const url = asString(sourceRecord.url) || asString(sourceRecord.href) ||
    asString(msg.url) || asString(msg.href);
  const path = asString(sourceRecord.path) || asString(sourceRecord.filepath) ||
    asString(sourceRecord.filename) || asString(msg.path);
  const name = asString(sourceRecord.filename) || asString(sourceRecord.name) ||
    asString(msg.filename) || basename(path) || 'untitled';
  const mimeType = asString(sourceRecord.mimeType) || asString(sourceRecord.mime_type) ||
    asString(msg.mimeType) || inferMimeType(name, getServerType(msg));
  const encoding = asString(sourceRecord.encoding).toLowerCase() === 'base64'
    ? 'base64'
    : rawContent !== undefined && !isTextMime(mimeType) ? 'base64' : 'text';
  if (rawContent === undefined && !url && !path) return null;

  return {
    id: asString(sourceRecord.id) || msg.messageId || makeId(),
    name,
    mimeType,
    size: toOptionalNumber(sourceRecord.size),
    content: rawContent === undefined ? undefined : String(rawContent),
    encoding,
    url: url || undefined,
    path: path || undefined,
    source: rawContent !== undefined ? 'content' : url ? 'url' : 'path',
    time: nowTime(),
  };
}

function toImageDataUrl(value: string, mimeType: string): string {
  if (/^(data:|https?:\/\/)/i.test(value)) return value;
  return `data:${mimeType};base64,${value}`;
}

function basename(path: string): string {
  return path.split(/[\\/]/).filter(Boolean).pop() || '';
}

function isTextMime(mimeType: string): boolean {
  return mimeType.startsWith('text/') || /(?:json|javascript|xml|svg|css|yaml|toml|markdown)/i.test(mimeType);
}

export function inferMimeType(name: string, eventType = ''): string {
  if (eventType === 'html') return 'text/html';
  const extension = name.toLowerCase().split('.').pop() || '';
  return ({
    html: 'text/html', htm: 'text/html', xhtml: 'text/html',
    md: 'text/markdown', markdown: 'text/markdown',
    txt: 'text/plain', json: 'application/json', csv: 'text/csv', tsv: 'text/tab-separated-values',
    css: 'text/css', log: 'text/plain',
    js: 'text/javascript', mjs: 'text/javascript', ts: 'text/typescript',
    py: 'text/x-python', php: 'text/x-php', sh: 'text/x-shellscript',
    xml: 'application/xml', yaml: 'application/yaml', yml: 'application/yaml',
    svg: 'image/svg+xml',
    png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif',
    webp: 'image/webp', bmp: 'image/bmp', ico: 'image/x-icon', avif: 'image/avif',
    pdf: 'application/pdf',
    mp3: 'audio/mpeg', wav: 'audio/wav', ogg: 'audio/ogg',
    mp4: 'video/mp4', webm: 'video/webm',
    zip: 'application/zip', gz: 'application/gzip',
  } as Record<string, string>)[extension] || DEFAULT_FILE_MIME;
}

function toOptionalNumber(value: unknown): number | undefined {
  const number = Number(value);
  return Number.isFinite(number) && number >= 0 ? number : undefined;
}

function formatToolCall(call: unknown): string {
  const record = asRecord(call);
  const fn = asRecord(record.function);
  const details = {
    id: record.id,
    type: record.type,
    name: fn.name || record.name,
    arguments: parseJsonMaybe(fn.arguments || record.arguments || record.args),
  };

  return formatObject(details);
}

function formatToolResult(data: Record<string, unknown>, msg: ServerMessage): string {
  if (Object.keys(data).length) {
    return formatObject(parseToolResultData(data));
  }

  return formatObject(parseJsonMaybe(msg.result ?? msg.content ?? msg.data));
}

function parseToolResultData(data: Record<string, unknown>): Record<string, unknown> {
  const parsed = { ...data };
  for (const key of ['result', 'content', 'data', 'output']) {
    if (key in parsed) parsed[key] = parseJsonMaybe(parsed[key]);
  }
  return parsed;
}

function parseJsonMaybe(value: unknown): unknown {
  if (typeof value !== 'string') return value;
  try {
    return JSON.parse(value);
  } catch {
    return value;
  }
}

function formatObject(value: unknown): string {
  return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
}

function asRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function asBoolean(value: unknown): boolean | undefined {
  return typeof value === 'boolean' ? value : undefined;
}
