type CompositionKeyboardEvent = Pick<KeyboardEvent, 'isComposing' | 'keyCode'>;

export function shouldIgnoreCompositionEnter(
  event: CompositionKeyboardEvent,
  compositionActive: boolean,
  compositionJustEnded: boolean,
): boolean {
  return compositionActive || compositionJustEnded || event.isComposing || event.keyCode === 229;
}

export type EnterAction = 'newline' | 'submit' | 'none';

type EnterKeyEvent = Pick<KeyboardEvent, 'ctrlKey' | 'metaKey' | 'shiftKey'>;

/**
 * 裸 Enter 该发送还是换行。
 *
 * - **移动端一律换行**（返回 `'none'`，交给浏览器默认行为）：软键盘上的回车是给
 *   换行用的，发送交给右下角那个按钮——不然想换行的人会把半句话发出去。
 * - 桌面端裸 Enter 发送，Ctrl / ⌘ + Enter 换行。两个修饰键都认，**不去猜平台**：
 *   `navigator.platform` 已经废弃，隐私模式下可能是空串，猜错整条快捷键就失效。
 * - Shift+Enter 也走 `'none'`，由浏览器插入换行。
 */
export function resolveEnterAction(event: EnterKeyEvent, mobileLayout: boolean): EnterAction {
  if (mobileLayout) return 'none';
  if (event.ctrlKey || event.metaKey) return 'newline';
  if (event.shiftKey) return 'none';
  return 'submit';
}
