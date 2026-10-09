import { describe, expect, it } from 'vitest';
import { resolveEnterAction, shouldIgnoreCompositionEnter } from './composerKeyboard';

function key(overrides: Partial<{ ctrlKey: boolean; metaKey: boolean; shiftKey: boolean }> = {}) {
  return { ctrlKey: false, metaKey: false, shiftKey: false, ...overrides };
}

describe('resolveEnterAction', () => {
  it('桌面端裸 Enter 发送', () => {
    expect(resolveEnterAction(key(), false)).toBe('submit');
  });

  it('桌面端 Ctrl / Cmd + Enter 换行，且不依赖平台判断', () => {
    expect(resolveEnterAction(key({ ctrlKey: true }), false)).toBe('newline');
    expect(resolveEnterAction(key({ metaKey: true }), false)).toBe('newline');
  });

  it('Shift+Enter 交给浏览器默认的换行', () => {
    expect(resolveEnterAction(key({ shiftKey: true }), false)).toBe('none');
  });

  it('移动端 Enter 一律换行，不发送', () => {
    expect(resolveEnterAction(key(), true)).toBe('none');
    expect(resolveEnterAction(key({ ctrlKey: true }), true)).toBe('none');
    expect(resolveEnterAction(key({ metaKey: true }), true)).toBe('none');
  });
});

describe('shouldIgnoreCompositionEnter', () => {
  it('输入法组合期间 / 刚结束 / keyCode 229 都要放过', () => {
    expect(shouldIgnoreCompositionEnter({ isComposing: false, keyCode: 13 }, true, false)).toBe(true);
    expect(shouldIgnoreCompositionEnter({ isComposing: false, keyCode: 13 }, false, true)).toBe(true);
    expect(shouldIgnoreCompositionEnter({ isComposing: true, keyCode: 13 }, false, false)).toBe(true);
    expect(shouldIgnoreCompositionEnter({ isComposing: false, keyCode: 229 }, false, false)).toBe(true);
    expect(shouldIgnoreCompositionEnter({ isComposing: false, keyCode: 13 }, false, false)).toBe(false);
  });
});
