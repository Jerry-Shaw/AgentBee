import type { ChatMessage } from '../protocol/types';

/** Display-only grouping: never mutate stored messages or cross a conversation boundary. */
export function mergeToolOnlyMessages(messages: ChatMessage[], options: { childWindow?: boolean } = {}): ChatMessage[] {
  const output: ChatMessage[] = [];
  let pending: ChatMessage[] = [];

  function isToolOnly(message: ChatMessage): boolean {
    return message.role === 'assistant' && Boolean(message.toolEvents?.length)
      && !message.content?.trim() && !message.think?.trim()
      && !message.images?.length && !message.files?.length && !message.attachments?.length
      && !message.statusMessage?.trim() && message.status !== 'stopped' && message.status !== 'error';
  }

  function sameContext(left: ChatMessage, right: ChatMessage): boolean {
    if (right.role !== 'assistant') return false;
    if (options.childWindow) return (left.WindowName || '') === (right.WindowName || '');
    if ((left.isSubTalk || 0) !== (right.isSubTalk || 0)) return false;
    if ((left.WindowName || '') !== (right.WindowName || '')) return false;
    const leftSender = left.senderName || left.sender;
    const rightSender = right.senderName || right.sender;
    if (leftSender && rightSender && leftSender !== rightSender) return false;
    return !(left.messageId && right.messageId && left.messageId !== right.messageId);
  }

  function combine(target: ChatMessage, prefix: ChatMessage[]): ChatMessage {
    return {
      ...target,
      toolEvents: [...prefix.flatMap(message => message.toolEvents || []), ...(target.toolEvents || [])],
    };
  }

  function flush() {
    if (!pending.length) return;
    output.push(combine(pending[pending.length - 1], pending.slice(0, -1)));
    pending = [];
  }

  for (const message of messages) {
    // Main-process bubbles are not targets; keep pending child tools for the next reply.
    if (options.childWindow && message.role === 'assistant' && message.sender === 'AgentBee') {
      if (pending.length && (pending[0].WindowName || '') !== (message.WindowName || '')) flush();
      output.push(message);
      continue;
    }
    if (pending.length && !sameContext(pending[pending.length - 1], message)) flush();
    if (isToolOnly(message)) {
      pending.push(message);
    } else if (pending.length) {
      output.push(combine(message, pending));
      pending = [];
    } else {
      output.push(message);
    }
  }
  flush();
  return output;
}