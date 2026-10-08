/**
 * 客户端内联指令。
 *
 * 目前只有 `/reset` 一条：输入框工具栏那个按钮发它，后端（`lib/message.php` 的
 * `process_text` / `process_chat`）收到后清空上下文并回一句确认。
 *
 * 单独放这里是因为有两处要用同一个字面量：`Composer.vue` 负责发，
 * `useWebSocketAgent.dispatchText` 负责「发出去但不当作聊天内容渲染」。
 */
export const RESET_COMMAND = '/reset';
