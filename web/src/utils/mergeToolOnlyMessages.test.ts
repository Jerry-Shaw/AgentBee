import { describe, expect, it } from 'vitest';
import { mergeToolOnlyMessages } from './mergeToolOnlyMessages';
import type { ChatMessage } from '../protocol/types';

const message = (id: string, patch: Partial<ChatMessage> = {}): ChatMessage => ({
  id, role: 'assistant', content: '', time: '', WindowName: 'workerA', sender: 'workerA', ...patch,
});
const tool = (id: string) => message(id, { toolEvents: [{
  id, kind: 'tool_calls', name: 'test', summary: '', data: '', time: '',
}] });

describe('child window tool grouping', () => {
  it('merges into the following non-main reply despite distinct record/message IDs', () => {
    const items = [tool('t'), message('main', { sender: 'AgentBee', content: 'main' }),
      message('reply', { content: 'answer', messageId: 'reply-id' })];
    const result = mergeToolOnlyMessages(items, { childWindow: true });
    expect(result.map(item => item.id)).toEqual(['main', 'reply']);
    expect(result[0].toolEvents).toBeUndefined();
    expect(result[1].toolEvents?.map(item => item.id)).toEqual(['t']);
    expect(items[2].toolEvents).toBeUndefined();
  });
  it('does not cross a user boundary', () => {
    const result = mergeToolOnlyMessages([tool('t'), message('u', { role: 'user' }),
      message('r', { content: 'answer' })], { childWindow: true });
    expect(result.map(item => item.id)).toEqual(['t', 'u', 'r']);
    expect(result[2].toolEvents).toBeUndefined();
  });
  it('does not cross windows', () => {
    const result = mergeToolOnlyMessages([tool('t'), message('r', { WindowName: 'workerB', content: 'answer' })], { childWindow: true });
    expect(result).toHaveLength(2);
    expect(result[1].toolEvents).toBeUndefined();
  });
  it('preserves trailing tools without a following reply', () => {
    const result = mergeToolOnlyMessages([tool('t')], { childWindow: true });
    expect(result[0].toolEvents?.[0].id).toBe('t');
  });
  it('keeps main window context checks unchanged', () => {
    const result = mergeToolOnlyMessages([tool('t'), message('r', { sender: 'other', content: 'answer' })]);
    expect(result).toHaveLength(2);
  });
});