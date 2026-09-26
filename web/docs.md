# BeeWeb Current Protocol Notes

当前前端已经切换为 JSON 发送，不再使用纯文本发送。

## 已实现

- 每个用户问题生成独立 `messageId`。
- 前端统一发送 `type: "chat"` JSON，纯文本、图片和文本文件都放在 OpenAI-style `content` 数组中。
- 前端每条发送到 WS 的 JSON 字符串末尾都会追加一个换行符 `\n`。
- 后端返回 `content/status/think/tool_calls/tool_result/image/error/end` 时可以带同一个 `messageId`。
- `image` 用于返回图片，`data.url` 是图片 base64，`data.prompt` 是提示词，会显示为图片下方的说明文字；图片会按容器宽度和高度上限自适应缩放，不会撑破布局。
- `think` 会显示为类似 Codex 的思考块，超过约 3 行时默认折叠。
- `tool_calls.data` 支持函数调用数组格式，`tool_result.data.result` 支持 JSON 字符串，前端会格式化后显示在同一个 assistant 对话里的独立折叠块中。
- 后端返回 `close` 时，前端会移除对应 `messageId` 的 assistant 显示，不删除用户消息。
- 流式输出时，如果用户已经滚动到历史记录位置，前端不会强制滚动到底部。
- 输入框使用 Enter 发送，Ctrl+Enter 或 Cmd+Enter 换行。
- 前端按 `messageId` 分流多个并发回答。
- 如果后端暂时没有返回 `messageId`，前端会落到最近一个 loading 回答里。
- WS 获取聊天历史暂不启用，聊天记录保存在浏览器 `localStorage`。
- 浏览器存储不足时，前端会裁剪旧记录并优先保留较新的会话内容。
- assistant 回复支持轻量 Markdown 渲染。
- 后端返回 `file`、`html` 或 `document` 事件时，前端会按文件类型显示桌面预览侧栏；文本、Markdown、HTML、图片和 PDF 分别采用对应预览方式。
- 文件事件可提供 `content`、`url` 或 `path`。工作区内路径可通过配置中的 `workspace_url` 映射为浏览器可访问的 HTTP(S) 地址；无法映射的后端本地路径只显示路径提示，不会假装浏览器能够读取它。

文件事件示例：

```json
{
  "type": "file",
  "messageId": "question-message-id",
  "data": {
    "filename": "report.html",
    "mimeType": "text/html",
    "content": "<html><body><h1>Hello</h1></body></html>",
    "encoding": "text"
  }
}
```

仅返回工作区路径时：

```json
{
  "type": "file",
  "messageId": "question-message-id",
  "data": {
    "filename": "reports/demo.html",
    "mimeType": "text/html",
    "path": "D:/AgentBee/workspace/reports/demo.html"
  }
}
```

`workspace_url` 必须实际提供对应文件，例如 `https://example.test/workspace/`；它只是路径映射配置。`file://` 地址指向打开浏览器的电脑，普通网页不能读取后端电脑的本地文件。

## 从返回内容推断可预览产物（前端实现）

后端暂时不发 `file` / `html` / `document` 事件，前端会从已有信号里推断产物并在右侧侧边栏预览：

- 正文里的**完整 HTML 文档**（`<html>…</html>`，可带 doctype）
- 正文里的 **```html 围栏块**（嵌在说明文字里也能识别；无语言围栏但内容是完整 HTML 也识别）
- 正文里的 **```md 围栏块**，以及 ≥400 字且含 2 个以上标题的**长 Markdown 回复**
- 正文里出现的**文件路径**（相对路径 / 绝对路径 / Windows 盘符）
- 正文里的 **http(s) 文件链接**（如 `workspace_url` 拼出的 `https://…/pelican_bike.html`）
- `tool_result` 里的 `saved_files` / `file_path` / `path` 等字段

实现位置：`src/utils/artifacts.ts`（识别与排序）、`src/utils/fileUrl.ts`（路径 → URL 解析）。
预览入口只属于 **assistant 消息**的产物：用户自己贴的链接/路径不给按钮。
服务端记忆里的历史回复不派生大块预览入口（避免整屏按钮），改为在删除按钮旁给一个小小的预览按钮，见下节。

### 远端链接产物

正文里的 `http(s)` 链接，只要**文件名带可预览扩展名**（html / md / pdf / 图片 / 代码…），
就会被收成 `source: 'url'` 的产物，直接在侧栏按 URL 渲染（iframe / img / fetch 文本），
不需要后端配合，也不依赖 `workspace_url` 是否配置。

- 无扩展名的普通网页链接（`https://site/about`、`https://site/docs/guide`）会被忽略，避免整屏按钮。
- markdown 链接语法 `[文字](url)`、裸链接、末尾标点（`。`、`）`）都能正确切分；查询串 `?v=2#top` 按文件名判定。
- 自动打开只对**浏览器能渲染**的远端类型生效（html / md / pdf / 图片）；远端 csv、zip、docx 等只给按钮，
  避免点开后是一个无法渲染的空面板。
- 同一类型下，**自带字节的内联内容优先于远端链接**（`artifactRank` 里 content +12、url +8），
  本地能离线渲染的那份总会被选中。
- 远端 Markdown 的 `fetch` 常被 CORS 拦，此时退回 iframe 让浏览器自己渲染（通常是纯文本）。

### 侧边栏自动打开

- 只针对**最新一轮** assistant 回复，每个 `messageId` 只自动打开一次（用户关掉后不会重复弹出）。
- 优先级：HTML 文档 > Markdown 文档 > PDF / 其它；图片已在气泡内联展示，不抢占侧栏。
- 只有「能真正渲染」的产物才自动打开：内联内容，或能解析出 `http(s)` / `data` / `blob` 的地址。
  落到 `file://` 的纯路径（浏览器读不到后端本地文件）只保留手动预览按钮。
- 子 agent 面板占用右侧时，自动打开会挂起，等它关闭再弹出，两者不抢位置。

### 历史记录的一键预览

服务端记忆里的历史消息本来只带一个「删除」按钮。现在如果这条记录的正文里含**完整可预览内容块**
（HTML 文档 / ```html```md 围栏块 / 长 Markdown 文档 / `http(s)` 文件链接），删除按钮左侧会多出一个预览按钮，
点击直接在侧栏打开；纯路径不算，因为历史里的文件未必还在原工作区。
用户那条记录不给预览按钮（删除按钮仍在）。

实现：`artifacts.ts` 的 `pickInlinePreviewArtifact()` 只挑「自带字节、能脱离后端渲染」的产物，
`ChatMessage.vue` 据此渲染 `history-preview-button`。

### 输入框拖拽上传

把文件从系统文件管理器拖到输入框即可添加为附件，拖入时输入区高亮并提示「松开鼠标即可添加文件」。
拖拽与「+」按钮共用同一套体积校验（单文件 20 MB、总 40 MB）和读取逻辑（`Composer.vue` 的 `addFiles`）。

### 重置会话

输入框工具栏的「重置会话」按钮发送字面量 `/reset` 指令，后端（`lib/message.php` 的 `process_text` /
`process_chat`）收到后清空上下文并回一句确认，**不会**写进记忆。前端在收到 `act === 'reset'` 的
message 事件时主动收尾该轮次——后端对这种 `need_llm = false` 的答复不会再发 `end`。

### 会话（session）

侧边栏有「会话」面板：列出会话、点击切换、每条可删除，顶部可新建。

- **协议**：会话 CRUD 目前是 **memory 类型下的 act**，不是独立的 `session` 类型——后端
  `lib/message.php` 的 `process_memory` 里直接 switch 了 `read` / `delete` / `readSession` /
  `deleteSession` / `renameSession`，并没有 `process_session`。
- **`sessionId` 一律放顶层，`content` 里不放**：后端 `go.php` 的派发是
  `$this->message->$type_method($socket_id, $data['content'], $data['sessionId'] ?? '')`，
  只有顶层能进 `process_memory` 的第三个参数（`read` 拿它做会话过滤，
  `renameSession` / `deleteSession` 拿它当目标会话），`content` 里只放 act 自己的参数。
  所以请求长这样：
  ```json
  { "type": "memory", "sessionId": "uuid", "content": { "act": "readSession" } }
  { "type": "memory", "sessionId": "uuid", "content": { "act": "deleteSession" } }
  { "type": "memory", "sessionId": "uuid", "content": { "act": "renameSession", "session_name": "新名字" } }
  { "type": "memory", "sessionId": "uuid", "content": { "act": "read", "length": 20, "create_id": 0 } }
  ```
  `chat` / `stop` 同理（`{ "type":"chat", "sessionId":"…", "messageId":"…", "content":[…] }`）。
  `type:'setting'` / `type:'system'` 与会话无关，不带 `sessionId`
  （它们的 `process_*` 是两参数签名，PHP 会忽略多传的那个参数）。
  前端统一走 `useWebSocketAgent.currentSessionId()` 取这个值。
  后端把 content 展开到了顶层，响应形如 `{ "type":"memory", "act":"readSession", "status":"success",
  "sessions":[{"session_id":"...","session_name":"...","create_time":"..."}] }`。
  前端对 `type: 'session'` 的响应也兼容（`useWebSocketAgent.ts` 里的 `SESSION_REQUEST_TYPE` 可切换）。
- **拉取时机**：连接成功后和 `getConfig` / `getModels` / memory read 一起自动发一次 `readSession`；
  面板上的刷新按钮可手动再拉。
- **新建会话**：`sessionId` 由前端 `makeId()`（UUID）生成，每次 chat 请求都会带上；
  后端收到第一条消息后自动入库，会话名取第一句话的前 8 个字。前端本地标题也按同样的 8 字规则生成。
- **打开窗口时的落点**：会话列表**可以为空**，不会为了「让界面有东西可渲染」凭空造一条空会话——
  只有 `activeSession` 会退回内存兜底（不进列表、不落盘）。
  `loadSessions()` 先把落点定在缓存里的第一条，第一份 `readSession` 回来后**再定位到列表第一条**
  （后端按 `create_time DESC` 返回，即最近一条）。这就是「打开就直接进最近那个会话，不新建」。
  一旦用户自己动过（发消息 / 新建 / 切换 / 删除 / 改名，`markPlacementDecided()`），
  自动定位立刻作废：之后的手动刷新、断线重连都不会把用户从正在聊的会话里拽走。
  后端也没有历史就保持空列表并提示「还没有会话」，由用户自己点新建。
- **空壳不落盘**：`isUnusedEmptySession()` = 没有任何消息 + 标题还是占位文案 + 没被手动命名过 +
  不是后端下发的。这类会话（兜底占位、以及用户点了「新建会话」却一句话没说就刷新走的空壳）
  `saveSessions()` 不写、`loadSessions()` 加载时也会过滤。
  不这样做的话它会排在列表最前面，看起来就像「一打开窗口又自动新建了一个会话」。
  在后端下发的或用户主动命名的空会话照常保留。用户主动新建的空壳只在本次打开期间留在内存里。
- **删除**：先弹确认框，确认后发 `deleteSession`（后端是软删，即 `updateSession(..., status=2)`），
  没连上时只删本地。删掉当前会话就落到最近一条；**全部删光则保持空列表**，不再自动补一条。
  另外 `applyRemoteSessions()` 把后端当权威：本地标着「来自后端」、这次却没返回的会话会被清掉，
  删过的会话不会从本地缓存里复活。
- **流式输出期间可以自由切换会话**：每一轮在 `pendingTurns` 里都记了自己的归属
  （`{ assistantId, sessionId }`），`ensureAssistantMessage` / `finishAssistantMessage` /
  `closeAssistantMessage` / 无响应超时都通过 `resolveTurnSession()` 按记录的 sessionId 找回原会话。
  所以切走之后，后端推来的 content/end 仍会写回发起这一轮的会话，而不是当前正在看的那个。
  会话列表中正在输出的那条会显示转圈标记（`streamingSessionIds`）。
  「停止」优先停当前会话正在跑的那一轮；当前会话没有在跑的，就停最近发起的那一轮，提示写进被停的那个会话。
  注意：后端一轮只服务一个 `curr_message_id`，在 A 还在输出时又去 B 发消息，
  后端可能按「放弃上一轮」的语义把 A 那一轮 close 掉（前端会保守保留已流出的内容）。
- **会话标题**：默认占位文案走 i18n（`untitledSession`，zh「新对话」/ en「New conversation」），
  由 `useSessions({ defaultTitle })` 注入；第一句话起标题后按前 8 个字。
- **重命名会话**：列表项 hover 出现铅笔按钮，或者双击标题进入行内编辑；Enter / 失焦提交，
  Esc 取消，空标题或没改动都算放弃（保持原名，不弹错）。
  - **本地先生效，再同步后端**：`useSessions.renameSession()` 立刻改本地并落 `localStorage`，
    界面没有等待感；连上了再发
    `{ type:'memory', sessionId, content:{ act:'renameSession', session_name } }`
    （`useWebSocketAgent.renameSession()`：**目标会话 id 在顶层**，`content` 里只有 `session_name`）。
    离线时只改本地，不发注定失败的请求。
    后端回 error 就把标题**回滚成改名前**（`restoreSessionTitle()` 连 `titleEdited` 一起还原），
    避免两边不一致。成功时用后端回传的 `session_name` 对齐。
  - 后端 `process_memory` 的 `renameSession` 分支读的是第三个参数 `$session_id`（即顶层 sessionId）
    和 `$data_content['session_name']`，空值会回 `缺少会话ID`。
  - 手动标题会打上 `ChatSession.titleEdited`，之后 `applyRemoteSessions()` 不再用后端 `session_name`
    覆盖它，第一句话也不再触发「前 8 个字」的自动命名。
  - 标题规范化：折叠空白、上限 `MAX_SESSION_TITLE_LENGTH`（40 字）硬截断（不带省略号，保证重复编辑幂等）。
  - **不动 `updatedAt`**：改名不是会话活动，不该让列表重排、也不该改掉「最后聊天时间」。
- **本地存储**：`localStorage` 的 `agentbee.sessions.v3`，每个会话各自保留最多 50 条消息，
  最多存 30 个会话，写满时按 quota 自动裁剪。
- **只有对话内容才会开新会话**：`useSessions.addMessage()` 里只有 `user` / `assistant`
  才会「列表为空就先建一条」，并且只有它们才 `markPlacementDecided()`。
  `system` / `error` 是连接日志（`WebSocket connected.` / `Not connected, …`），
  它们落进内存兜底会话即可——**不能建会话**。
  否则「token 登录成功」这一步就会凭空冒出一条空会话（连接时列表通常正是空的），
  而且会把「首次落点」标记用掉，导致随后的 `readSession` 也不再自动定位到最近的真实会话。
- **聊天历史按会话隔离**：`memory read` 请求在**顶层**带 `sessionId`（`useWebSocketAgent.readMemory()`）。
  `go.php` 把它作为第三个参数传进 `process_memory`，read 分支再传给
  `Memory::read(..., $session_id, ...)`——那里对 `daily`/`misc` 只在 `session_id !== ''` 时才加 `where`。
  **漏带这个字段后端就会返回全局历史**，表现就是「新建会话还能看到旧会话的记录」。
  配套行为：切会话 / 新建会话 / 删掉当前会话都会 `resetMemoryHistory()` + 重拉
  （`reloadActiveSessionHistory()`）；连接时先 `readSession`、等会话落点定了再拉历史；
  迟到的响应按发起时的会话 id 丢弃（`memoryRequestSessionId` 比对），
  避免把上一个会话的历史画进新会话。

### 操作结果提示（toast）

删除 / 重命名的**成功与失败**都用右下角的轻提示播报，实现在
`composables/useToasts.ts`（状态 + 定时器）与 `components/ToastStack.vue`（渲染，Teleport 到 body）。

- **为什么不用 `ConfirmDialog`**：那是要用户决策的模态框，用它播报结果每次都得再点一下「确定」。
- **停留时长**：success 2.6s / info 3.4s / error 6s，错误留得久一些并且可以手动关掉；同屏最多 3 条，超了挤掉最旧的（连它的定时器一起清）。
- **语气与图标**：`success` 对勾、`error` 警告（`role="alert"` 让读屏立刻播报）、`info` 信息。
  配色走主题变量 `--success` / `--danger` / `--accent`，亮暗两套主题都适配。
- **z-index 130**，高于 `ConfirmDialog` 的 120：删除失败时确认框可能还开着，提示必须压在它上面。
- 空文案直接丢弃——失败提示常把「后端返回的 error」拼进消息里，取不到文本时不该弹空壳。

各路径的播报：

| 场景 | 语气 | 内容 |
|---|---|---|
| 重命名成功 | success | `会话已重命名：<最终名字>`（后端回了 `session_name` 就用后端的） |
| 重命名失败 | error | `重命名失败：<后端 error>，已还原为原来的标题` |
| 未连接时重命名 | info | `已在本地重命名（未连接服务器，未同步到服务端）` |
| 删除成功 | success | `会话已删除：<会话名>` |
| 删除失败 | error | `删除会话失败：<后端 error>`（**本地保留**该会话，不让它下次刷新复活） |
| 未连接时删除 | info | `已在本地删除（未连接服务器）：<会话名>` |
| 删除无回执 | error | `删除没有收到服务端确认，会话已保留。请检查连接后重试。` |

注意删除用的是**独立的超时计时器**（`sessionDeleteTimer`，15s）：不能复用
`sessionResponseTimer`——那个的守卫是 `sessionLoading`，删除时它一直是 false，计时器会空跑，
结果是「会话既没删掉、界面上也没有任何解释」。

会话列表读取失败（`readSession` 超时 / 报错）仍然用面板底部那行红字（`SessionPanel` 的 `error` prop），
因为它描述的是「列表本身不可靠」这种上下文状态，而不是一次性动作的结果。

### 移动端适配（会话列表）

移动端的头部（`.sidebar` 这一行）只保留：**会话入口按钮** + 应用名 + 连接状态。
会话列表整体收进**左侧抽屉**（`components/SessionDrawer.vue`），里面装的还是同一个
`SessionPanel`——桌面侧栏和移动抽屉共用 `App.vue` 里的 `sessionPanelBindings`，
不给移动端单独写一份列表，避免「手机上少做了某个操作」。

- **入口位置**：按钮放在 `.brand` 里而不是 `.topbar`。移动端聊天页的 `.topbar` 是隐藏的
  （`@media` 里 `.main:has(.composer) .topbar { display: none }`，矮屏容器查询
  `@container app-viewport (max-height: 420px)` 还会再隐藏一次），入口跟着消失就没法开抽屉了；`.brand` 在任何姿态下都在。
- **移动端聊天页没有顶栏**：会话标题已经让位给 `.brand`（`activeSessionTitle`），
  顶栏再留一条只是白吃高度，所以聊天视图下整体 `display: none`。
  **判据是「有 `.composer` = 聊天页」**——设置页必须留着顶栏，因为「关闭设置」按钮
  只长在 `.topbar-actions` 里，一起藏掉就出不了设置页了。
  ⚠️ 顶栏隐藏后 `.main` 只剩两个子项，`grid-template-rows` 必须同步改成
  `minmax(0, 1fr) auto`，否则 chat-shell 会掉进 `auto` 行被压扁在顶部。
  代价：`.topbar-actions` 里的 `SubAgentMenu` 在移动端聊天页不可达（子代理切换只在桌面/设置页可用）。
- **JS / CSS 必须同一个断点**：`App.vue` 的 `MOBILE_MEDIA_QUERY` 就是
  `(max-width: 820px), (hover: none) and (pointer: coarse)`，与 `base.css` 的媒体查询一字不差。
  `isMobileLayout` 同时控制入口按钮和抽屉的渲染；转回桌面布局时会自动收起抽屉，
  不留一个盖住界面的浮层。
- **层级**：抽屉 `z-index: 110` < `ConfirmDialog` 的 120 < toast 的 130。
  从抽屉里删除会话时确认框必须盖在抽屉上面。
- **交互收尾**：点会话 / 点「新建会话」都会收起抽屉（`leaveMobileDrawer()`），
  移动端还会顺手切回聊天视图；Esc、点遮罩、标题栏的 X 都能关。
  打开时焦点收进抽屉、关闭时还给触发按钮，Tab 在抽屉内循环。
- **触屏没有 hover**：桌面端行内按钮是 `opacity: 0` 悬停才出现，抽屉里改成常驻可见，
  并且触摸目标按 34px（按钮）给了；重命名输入框字号提到 16px
  （iOS 上聚焦字号 <16px 的输入框会把整页放大）。
- 删除确认框在移动端改成两列等宽 + `min-height: 44px`；
  结果提示抬高到输入框上方（`bottom: calc(78px + env(safe-area-inset-bottom))`），别压住发送按钮。
- 抽屉宽度 `min(84vw, 320px)`，并处理 `env(safe-area-inset-*)`（刘海屏）。

### `close` 事件不可信（已修复的历史 bug）

后端 `go.php` 会在处理下一条用户消息前，对残留的 `curr_message_id` 补发一次 `type: 'close'`。
而 `curr_message_id` 只有在最后一次 content/think 缓冲 flush **成功**时才会被清空，
一旦那次 flush 失败或该轮根本没有 content/think，这个 `close` 就会指向一个早已结束并渲染好的轮次。
早期前端收到 `close` 就按 messageId 把整条 assistant 消息删掉，于是出现了
「上一轮回复莫名整段消失」的偶发现象。

现在前端改成保守处理：已结束的轮次忽略 `close`；仍在 loading 且有产出的按 `stopped` 收尾并保留内容；
只有 loading 且什么都没产出的空壳才移除。

### 仍然存在的限制

`file://` 无法在浏览器里加载（开发态页面是 http 源，生产态是 file:// 源，Chrome 都拦），
`workspace_url` 目前也没有对应的 HTTP 文件服务。因此**纯路径产物只能看到路径提示**，
要真正预览后端文件，仍需后端提供 HTTP 文件服务，或改为通过 WS 回传文件字节。

不过只要 agent **把 `http(s)` 链接写进正文**，前端就能直接预览——这条路径不依赖后端改动，
也是目前唯一能真正看到后端工作区文件的通道。


## 关键字段

```json
{
  "type": "chat",
  "sessionId": "session-xxx",
  "messageId": "msg-xxx",
  "content": [
    { "type": "text", "text": "用户问题" }
  ],
  "createdAt": "2026-05-11T10:00:00.000Z"
}
```

带附件时：

```json
{
  "type": "chat",
  "sessionId": "session-xxx",
  "messageId": "msg-xxx",
  "content": [
    { "type": "text", "text": "用户问题" },
    { "type": "file", "file": { "filename": "notes.md", "mimeType": "text/markdown", "content": "IyBOb3Rlcy4uLg==" } },
    { "type": "file", "file": { "filename": "R.png", "mimeType": "image/png", "content": "iVBORw0KGgo..." } }
  ],
  "createdAt": "2026-05-11T10:00:00.000Z"
}
```

后端所有该问题的流式事件都应带回同一个 `messageId`。

完整协议参考 `WS_BACKEND_TEMPLATE.md`。
