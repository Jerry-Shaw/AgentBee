# AgentBee - Your Busy Bee! 🐝

<div align="center">

**Your Busy Bee!** 🐝

[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)](https://www.php.net/)
[![Nervsys](https://img.shields.io/badge/Framework-Nervsys-orange.svg)](https://github.com/Jerry-Shaw/Nervsys.git)

[中文版 | Chinese Version](./README_zh-CN.md)

</div>

---

## Overview

**AgentBee** is an open-source, modular AI Agent framework built on the [Nervsys](https://github.com/Jerry-Shaw/Nervsys.git) PHP ecosystem. It provides full-stack capabilities for LLM conversations and autonomous task execution through a lightweight, auto-loading architecture. Powered by real-time WebSocket communication, intelligent four-tier memory management, and dynamic skill discovery, AgentBee is designed to integrate seamlessly into any application or platform.

### Key Features

- **Auto-Loading Skills & Tools** — Drop files into `skills/` or `tools/` directories at the project root. No registration required; the system automatically discovers and loads compliant modules on startup.
- **Four-Tier Memory System** — Persistent, role-based memory architecture (`system`, `important`, `daily`, `misc`) with intelligent context injection and automatic cleanup triggers.
- **Real-Time WebSocket Protocol** — Native bidirectional communication supporting plain text, multimodal inputs (images/files), binary streaming, and batched message processing (`\n` delimited).
- **Modular Business Logic** — All agent workflows reside in `go.php`. Capabilities are cleanly separated into definition layers (`skills/`) and execution tools (`tools/`).

---

## 📁 Directory Structure & Conventions

```text
AgentBee/
├── modules/                    # Plugin ecosystem (auto-loaded on compliance check, no config required)
│   └── agent_core/             # Main application bootstrap (Bootstrap)
│       ├── module.json         # Metadata (name must strictly match the folder name)
│       ├── go.php              # Core business logic, WebSocket handlers & state management
│       └── core.php            # Core business basic module
├── skills/                     # AI capability library / plugin directory (rich LLM expansion)
│   ├── OfficeSuite/            # Office document processing (.docx/.xlsx/.pptx)
│   └── WebCrawler/             # Web crawling, content extraction & asset download
└── tools/                      # Agent execution tools (same level as skills, auto-loaded)
    ├── Memory/                 # Memory/Task database operation tools
    └── System/                 # Shell commands, file I/O, context management etc.
```

### Core Rules
1. **`module.json.name`** must exactly match the folder name. Mismatched names will be skipped by the loader. Includes `version`, `description`, and `dependencies`. PHP environment requirements are omitted as they're handled by Nervsys core.
2. **Auto-Loading**: Both `tools/` and `skills/` support hot-plug discovery. No configuration center updates or manual registration steps are needed—just place the files there with valid syntax, and they become available immediately.
3. **Skills & Tools Relationship** — Skills act as complex tools; both are functionally similar at the application layer, serving solely to enrich LLM capabilities. All routing, session handling, memory injection, and task scheduling logic is written in `go.php`.

---## 🧠 Memory Architecture (4-Tier System)

The legacy JSONL format has been completely removed and replaced with a high-performance, persistent memory system divided into four distinct layers:

| Layer | Purpose | Persistence & TTL |
|-------|---------|------------------|
| **`system`** | Core persona, rules, boundaries, constraints. Highest priority in context injection. | Permanent (updated via `create_id`) |
| **`important`** | Facts, user preferences, identities, long-term state changes. Saved immediately on key events. | Permanent / Long-lived |
| **`daily`** | Date-stamped summaries, task results, daily logs. Auto-archived by YYYYMMDD. | Daily (auto-cleaned after retention period) |
| **`misc`** | Short-lived drafts, intermediate states, temporary data. Promoted to upper layers when deemed valuable. | Temporary / Auto-promote on value detection |

*Auto-Sync Triggers:* New user messages → check `daily`; time/person/event queries → search relevant levels; context gaps → inject "load today's memory" prompt automatically. The system follows a **"more is better"** principle, prioritizing data retention over aggressive pruning (unless token limits are hit).

---

## 🔌 WebSocket Protocol Specification

AgentBee communicates via standard WebSockets using JSON payloads. Multiple packets can be batched in a single frame by separating them with newline characters (`\n`). Each message must include a `type` field that routes to specific handlers within the core engine. Session tracking is managed through `sessionId` and unique per-request `messageId`.

### 1. Connection & Headers
- Standard WebSocket handshake required upon connection.
- Clients maintain state via `sessionId` (default: `"default"`) and generate a unique `messageId` for each request to correlate responses with prompts.

### 2. Request Types (`type`)

| Type | Content Structure | Behavior | Response Format |
|------|-------------------|----------|-----------------|
| **`setting`** | `{ "act": "getConfig"\|"saveConfig"\|"getDefaultConfig", "data": {...} }` | Direct execution, no LLM involved. Returns immediately. | `{"type":"setting","status":"success/error","act":"...","data":{}}` |
| **`system`** | `{ "act": "getVersion"\|"getModels" }` | System info retrieval (Agent version, Nervsys core ver, available LLM models). No LLM involved. | `{"type":"streaming", ...}` or error object |
| **`text`** | `{ "sessionId": "...", "messageId": "...", "content": { "text": "Hello!" } }` | Sends plain text to the LLM for processing. Supports full streaming responses (SSE/WS). | Streamed `payload.type: "text"` chunks → final `"end"` or tool calls array |
| **`chat`** | `{ "sessionId":"...", "messageId":"...", "content":[ {"type":"text","text":"..."}, {"type":"image_url",{"url":"data:..."}}, {"type":"file":{"filename":"x.txt","mimeType":"text/plain","content":"..."}} ] }` | Multimodal input (text, images via Data URL or `__BINARY__`, text files). Auto-parses allowed extensions and converts headers. Sent to LLM pipeline. | Same streaming format as `text`. Files are auto-converted with readable prefixes if plain-text compatible. |
| **`stop`** | `{ "sessionId":"...", "messageId":"..." }` (content optional) | Aborts the current LLM stream or tool execution chain immediately. State resets for next request. | Stream terminates, control returns to client. No extra payload needed. |

### 3. Response Formats
- **Direct Actions** (`setting`, `system`) & **LLM/Streaming**: The server pushes frames with the structure:  
  ```json
  {"type": "text|tool_calls|error|end", ...}
  ```
  *(Note: `socket_id` is only used internally to route to the correct client connection; the actual payload sent over WebSocket contains only the object above.)*

  - `text`: LLM token/text chunks (supports SSE or raw WS)
  - `tool_calls`: Array of tool invocation requests generated by the model
  - `history/add` & `sync`: Memory state updates injected during processing
  - `context`: Pending messages queued for the next cycle
- **Batched Messages**: If a client sends multiple lines (`\n`) in one frame, each line triggers independent routing. All packets are wrapped with `"type":"end"` upon batch completion to signal the final frame of that request chain.

### 4. Binary Support (Advanced)
For heavy images or files within `chat` type:
1. Client packs metadata + binary blocks into a single WS Frame.
2. Header: **4 bytes** (Big-Endian uint32) = length of the JSON metadata block following it.
3. Metadata contains `"content"` array with placeholders (`"__BINARY__"`) and an accompanying `"binary_sizes` list defining each chunk's byte size.
4. Server strips header, decodes JSON, replaces `__BINARY__` markers sequentially with actual binary data, then routes the payload to LLM or memory pipeline.

---

## 🚀 Quick Start & Development Guide

1. **Create Module**: Ensure your folder name matches exactly what you put in `module.json.name`.
2. **Drop Files**: Place custom skills/tools into their respective folders (`skills/` or `tools/`). No config center updates needed.
3. **Business Logic**: Implement core workflows, message routing, and state management inside `go.php`. Keep meta definitions clean and focused on capability exposure.
4. **Launch Server**: 
   ```bash
   php go.php -c=provider/worker_name
   ```
5. **Connect Frontend**: Establish a WebSocket connection to the configured host/port. Send initial `setting/getConfig` or directly start a `chat` session with valid identifiers.

---

## 📜 License & Credits
Apache 2.0 | © 2026 秋水之冰 & AgentBee