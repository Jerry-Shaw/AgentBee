# AgentBee WebSocket Backend Template

This document describes the JSON protocol expected by the current Vue frontend.

Current backend URL:

```text
ws://192.168.254.10:8686
```

## Key Rule

Every user question has a unique `messageId`.

The backend should include the same `messageId` in all streamed events for that question. This lets the frontend render multiple concurrent questions without mixing replies.

## Client To Server

### Read Server Memory History

The frontend reads the latest 50 server records once after each successful WebSocket connection. Use `create_id: 0` for this initial request. It does not repeat the request on a timer, focus change, completed turn, or deletion. When the user reaches the top of the loaded history, pass the oldest loaded server record ID; the backend returns records whose IDs are lower than that cursor in pages of up to 30. Closing or refreshing the page clears browser-local chat data without deleting server memory.

```json
{
  "type": "memory",
  "content": {
    "act": "read",
    "length": 50,
    "create_id": 0
  }
}
```

### Delete Server Memory History

```json
{
  "type": "memory",
  "content": {
    "act": "delete",
    "create_ids": [1770000000000000, 1770000000000001]
  }
}
```

### User Message

The frontend now sends JSON only. All user messages use `type: "chat"` with a `content` array:

```json
{
  "type": "chat",
  "sessionId": "browser-local-session-id",
  "messageId": "question-message-id",
  "content": [
    {
      "type": "text",
      "text": "用户输入的原始文本"
    }
  ],
  "createdAt": "2026-05-11T10:00:00.000Z"
}
```

When attachments are uploaded, the frontend reads every attachment as raw Base64 and appends file parts to the same `content` array:

- Text parts use `{ "type": "text", "text": "..." }`.
- All attachment parts use `{ "type": "file", "file": { "filename": "...", "mimeType": "...", "content": "<base64>" } }`.

```json
{
  "type": "chat",
  "sessionId": "browser-local-session-id",
  "messageId": "question-message-id",
  "content": [
    {
      "type": "text",
      "text": "Please read these files."
    },
    {
      "type": "file",
      "file": {
        "filename": "notes.md",
        "mimeType": "text/markdown",
        "content": "IyBOb3Rlcy4uLg=="
      }
    },
    {
      "type": "file",
      "file": {
        "filename": "R.png",
        "mimeType": "image/png",
        "content": "iVBORw0KGgo..."
      }
    }
  ],
  "createdAt": "2026-05-11T10:00:00.000Z"
}
```

### Abort Request

中止某个会话正在跑的那一轮。`sessionId` 在**顶层且必填**：服务端拿它中止
该会话正在跑的任务，并把这个会话的状态置回 IDLE。

```json
{
  "type": "abort",
  "sessionId": "browser-local-session-id",
  "messageId": "question-message-id"
}
```

> 前端不再发 `"type": "stop"`，一律发 `abort`：服务端需要按 `abort` 分支处理。
> `messageId` 只给前端认领本地那一轮用，服务端可以不读。

## Server To Client

The frontend sends JSON strings ending with one newline character (`\n`).

All server messages should be JSON strings.

### Abort Receipt

`type: "abort"` 是服务端「已停止生成」的回执。前端收到后按「放弃这一轮」收尾：
保留已经流出来的内容并把状态置为 stopped，什么都没产出的空壳则删掉。
`sessionId` / `messageId` 必须带上——前者用来把回执路由回真正在跑的那个会话，
后者用来认出是哪一轮。

```json
{
  "create_id": 1790668633450829,
  "type": "abort",
  "data": { "message": "已停止生成" },
  "sender": "AgentBee",
  "isSubTalk": 0,
  "workerName": "AgentBee",
  "workerRole": "Assistant",
  "sessionId": "3523ec6d-9746-4d66-bf76-ae7af38dfe7c",
  "messageId": "e30827b6-cd13-4ab7-81c7-b405f36caedb",
  "WindowName": "蜂小秘 - AgentBee",
  "socket_id": "sock_74"
}
```

### Sub-Agent Support

Messages can be marked as sub-agent messages by adding the `isSubTalk` field. Sub-agent messages are displayed in a separate panel on the right side instead of the main chat area.

```json
{
  "type": "content",
  "sessionId": "session-id",
  "messageId": "message-id",
  "data": "Sub-agent message content",
  "workerName": "my_worker",
  "workerRole": "My Worker",
  "isSubTalk": 1
}
```

- `isSubTalk`: Set to `1` to mark as sub-agent message, `0` or omit for main agent messages
- `workerName`: Used to identify and group sub-agent messages (required for sub-agents)
- `workerRole`: Display name for the sub-agent (optional)

See `SUB_AGENT_FEATURE.md` for detailed documentation.

### Memory History Response

```json
{
  "type": "memory",
  "act": "read",
  "status": "success",
  "total": 2,
  "data": [
    {
      "role": "user",
      "level": "misc",
      "content": "介绍一下这个项目",
      "create_id": 1770000000000000,
      "create_time": "2026-02-03 10:00:00"
    },
    {
      "role": "assistant",
      "level": "misc",
      "content": "这是一个 WebSocket 前端聊天台。",
      "create_id": 1770000000000001,
      "create_time": "2026-02-03 10:00:01"
    }
  ]
}
```

Delete responses use the same `type` with `act: "delete"`, `status`, and the number of affected rows in `deleted`.

### Assistant Content

```json
{
  "type": "content",
  "sessionId": "browser-local-session-id",
  "messageId": "question-message-id",
  "data": "流式正文片段"
}
```

Alternative content fields supported by the frontend:

```json
{
  "type": "content",
  "messageId": "question-message-id",
  "text": "流式正文片段"
}
```

```json
{
  "type": "content",
  "messageId": "question-message-id",
  "content": "流式正文片段"
}
```

### Thinking Or Status

`think` is rendered as a collapsible thinking block. Long thinking text is collapsed to about three lines by default.

```json
{
  "type": "status",
  "sessionId": "browser-local-session-id",
  "messageId": "question-message-id",
  "data": "Agent is thinking..."
}
```

Also supported:

```json
{
  "type": "think",
  "messageId": "question-message-id",
  "data": "模型思考或中间状态"
}
```

### Tool Calls

`tool_calls` is rendered inside the related assistant message as a collapsible tool-call block. The frontend reads the display content from `data`.

```json
{
  "type": "tool_calls",
  "sessionId": "browser-local-session-id",
  "messageId": "question-message-id",
  "data": [
    {
      "id": "155385943",
      "type": "function",
      "function": {
        "name": "tool_fileio/listDirectory",
        "arguments": "{\"path\":\"D:/Programs\"}"
      }
    }
  ]
}
```

### Tool Result

`tool_result` is rendered inside the related assistant message as a collapsible tool-result block. The frontend reads the display content from `data`.

```json
{
  "type": "tool_result",
  "sessionId": "browser-local-session-id",
  "messageId": "question-message-id",
  "data": {
    "tool_call_id": "155385943",
    "function_name": "tool_fileio/listDirectory",
    "result": "{\"success\":true,\"path\":\"D:/Programs\",\"contents\":[]}"
  }
}
```

### Image

`image` renders a base64 picture inside the related assistant message, with the prompt shown as a caption below it.

```json
{
  "type": "image",
  "sessionId": "browser-local-session-id",
  "messageId": "question-message-id",
  "data": {
    "url": "iVBORw0KGgo...",
    "mimeType": "image/png",
    "prompt": "一只在键盘上睡觉的猫"
  }
}
```

- `url`: required. Raw base64 without the `data:` prefix. A full `data:` URL or an `http(s)` URL is also accepted and used as-is. Also read from `base64`, `data` or `image`.
- `mimeType`: optional, defaults to `image/png`. Also accepted as `mime_type`.
- `prompt`: optional caption text shown under the image. Also accepted as `text`, or as the top-level `text` / `message` field.
- `data` may also be a plain base64 string when there is no prompt.

Multiple `image` events with the same `messageId` stack in order inside the same assistant reply, and can be mixed with `content`, `think` and tool events. The frontend scales images down to fit the bubble, so any resolution is safe to send.

### File / HTML / Document

`file`, `html`, and `document` events render as a desktop preview card. The payload may contain file bytes, a browser URL, or a workspace path:

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

Supported fields are `filename` / `name`, `mimeType` / `mime_type`, `content` / `body` / `text`, `encoding` (`text` or `base64`), `url` / `href`, and `path`. For binary content, use base64 and set `encoding` to `base64`. A path below the configured `workspace_path` is mapped through `workspace_url`; other local paths are shown as `file://` references with a browser limitation notice. `workspace_url` must be backed by an HTTP(S) file server.

### Error

```json
{
  "type": "error",
  "sessionId": "browser-local-session-id",
  "messageId": "question-message-id",
  "message": "错误原因"
}
```

### Close Assistant Display

Use `close` when the frontend should remove the assistant display for a message. This does not remove the user message.

```json
{
  "type": "close",
  "sessionId": "browser-local-session-id",
  "messageId": "question-message-id"
}
```

### End

```json
{
  "type": "end",
  "sessionId": "browser-local-session-id",
  "messageId": "question-message-id"
}
```

Also supported:

```json
{
  "type": "done",
  "messageId": "question-message-id"
}
```

```json
{
  "type": "finish",
  "messageId": "question-message-id"
}
```

## Recommended Minimal Stream

Client sends:

```json
{
  "type": "chat",
  "sessionId": "session-1",
  "messageId": "msg-1",
  "content": [
    {
      "type": "text",
      "text": "用 Markdown 回复一个列表"
    }
  ],
  "createdAt": "2026-05-11T10:00:00.000Z"
}
```

Server streams:

```json
{"type":"status","sessionId":"session-1","messageId":"msg-1","data":"Agent is thinking..."}
```

```json
{"type":"content","sessionId":"session-1","messageId":"msg-1","data":"## 示例回复\n\n"}
```

```json
{"type":"content","sessionId":"session-1","messageId":"msg-1","data":"- 第一项\n- 第二项\n\n```js\nconsole.log('hello')\n```"}
```

```json
{"type":"end","sessionId":"session-1","messageId":"msg-1"}
```

## Concurrent Message Demo

When two questions are running at the same time, return each stream with its own `messageId`.

```json
{"type":"content","sessionId":"session-1","messageId":"msg-A","data":"A 的第一段"}
```

```json
{"type":"content","sessionId":"session-1","messageId":"msg-B","data":"B 的第一段"}
```

```json
{"type":"end","sessionId":"session-1","messageId":"msg-B"}
```

```json
{"type":"end","sessionId":"session-1","messageId":"msg-A"}
```

The frontend will render A and B into different assistant bubbles.
