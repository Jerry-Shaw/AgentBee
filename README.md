# AgentBee - Your Busy Bee! 🐝

<div align="center">

**Your Busy Bee!** 🐝

[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-8.1+-green.svg)](https://www.php.net/)
[![Nervsys](https://img.shields.io/badge/Framework-Nervsys-orange.svg)](https://github.com/Jerry-Shaw/Nervsys.git)

[中文版 | Chinese Version](./README_zh-CN.md)

</div>

---

## Overview

**AgentBee** is an open-source, modular AI Agent framework built on the [Nervsys](https://github.com/Jerry-Shaw/Nervsys.git) PHP ecosystem. It provides full-stack capabilities for LLM conversations and autonomous task execution through a lightweight, auto-loading architecture. Powered by real-time WebSocket communication, intelligent four-tier memory management, and dynamic skill discovery, AgentBee is designed to integrate seamlessly into any application or platform.

### Key Features

- **Auto-Loading Skills** — Drop files into `skills/` directory at the project root. No registration required; the system automatically discovers and loads compliant modules on startup.
- **Four-Tier Memory System** — Persistent, role-based memory architecture (`system`, `important`, `daily`, `misc`) with intelligent context injection and automatic cleanup triggers.
- **Real-Time WebSocket Communication** — Native bidirectional communication supporting plain text, multimodal inputs (images/files), and batched message processing (`\n` delimited).
- **Modular Business Logic** — All agent workflows reside in `go.php`. Capabilities are cleanly separated into definition layers (`skills/`) and execution tools.

---

## 📁 Directory Structure & Conventions

```text
AgentBee/
├── bin/                      # Startup scripts (bee / bee.cmd)
│   ├── bee                   # Linux/Mac shell script
│   └── bee.cmd               # Windows batch file
├── config/                   # Configuration files
│   └── AgentBee.json         # Main configuration (server, LLM, memory, etc.)
├── modules/                  # Plugin ecosystem (auto-loaded on compliance check)
│   ├── agent_core/           # Main application bootstrap
│   │   ├── core.php          # Core basic module
│   │   ├── go.php            # Business logic, WebSocket handlers & state management
│   │   ├── module.json       # Module metadata
│   │   └── lib/              # Internal libraries (config, message, utils)
│   ├── agent_skills/         # Built-in skill modules (auto-loaded on startup)
│   │   ├── Browser/          # Headless browser automation
│   │   ├── Memory/           # Memory & task management
│   │   ├── OfficeSuite/      # Office document processing (.docx/.xlsx/.pptx)
│   │   ├── System/           # Shell commands, file I/O, context management
│   │   ├── WebCrawler/       # Web crawling, content extraction & asset download
│   │   └── WorkerBee/        # Sub-process worker management (async tasks)
│   └── agent_openai/         # OpenAI-compatible LLM adapter
├── skills/                   # Third-party skill packages (hot-plug discovery)
├── memory/                   # Persistent memory storage for all Agent conversations
├── workspace/                # Default working directory for file operations
├── logs/                     # Application log files
├── Nervsys/                  # Nervsys framework (Git submodule)
└── .gitmodules               # Git submodules configuration
```

### Core Rules

1. **`module.json.name`** must exactly match the folder name, and **`entry`** must point to the module's main entry file (e.g., `go.php`). Both fields are required; missing either one will cause the loader to skip the module entirely. The JSON also includes `version`, `description`, and optional `dependencies`. PHP environment requirements are omitted as they're handled by Nervsys core.
2. **Auto-Loading**: The `skills/` directory supports hot-plug discovery. No configuration center updates or manual registration steps are needed—just place the files there with valid syntax, and they become available immediately.
3. **Skills & Tools Relationship** — Skills act as complex tools; both are functionally similar at the application layer, serving solely to enrich LLM capabilities. All routing, session handling, memory injection, and task scheduling logic is written in `go.php`.

---

## 🧠 Memory Architecture (4-Tier System)

The legacy JSONL format has been completely removed and replaced with a high-performance, persistent memory system divided into four distinct layers:

| Layer | Purpose | Persistence & TTL |
|-------|---------|------------------|
| **`system`** | Core persona, rules, boundaries, constraints. Highest priority in context injection. | Permanent (updated via `create_id`) |
| **`important`** | Facts, user preferences, identities, long-term state changes. Saved immediately on key events. | Permanent / Long-lived |
| **`daily`** | Date-stamped summaries, task results, daily logs. Auto-archived by YYYYMMDD. | Daily (auto-cleaned after retention period) |
| **`misc`** | Short-lived drafts, intermediate states, temporary data. Promoted to upper layers when deemed valuable. | Temporary / Auto-promote on value detection |

*Auto-Sync Triggers:* New user messages → check `daily`; time/person/event queries → search relevant levels; context gaps → inject "load today's memory" prompt automatically. The system follows a **"more is better"** principle, prioritizing data retention over aggressive pruning (unless token limits are hit).

**Memory Persistence:** All Agent conversation memories are stored in the `memory/` directory at the project root. This data persists across updates — upgrading the code does not affect or delete existing memory records. You can safely maintain long-term conversation history without fear of losing important context during development iterations.

---

## 🛠️ Built-in Skill Modules

AgentBee comes with six built-in skill modules that provide comprehensive capabilities out of the box:

### Browser
Headless browser automation for web scraping and UI testing. Supports page navigation, element interaction (click, type, select), screenshot capture, JavaScript evaluation, DOM manipulation, and multi-tab management. All actions are exposed as callable tools to the LLM.

### Memory
Persistent memory database with four-tier architecture (system/important/daily/misc). Provides full CRUD operations, task scheduling (add/remove/list cron jobs), full-text search by keywords or date range, and automatic context injection based on conversation state.

### OfficeSuite
Document processing suite for creating and editing Office files. Supports DOCX (headings, paragraphs, images, formatting options like bold/italic/font size/alignment), XLSX (single/multi-sheet read-write with append mode), PPTX (slide creation with text/images). Can also read existing documents from all three formats.

### System
Low-level system operations including file I/O (read/write/copy/delete/search/list directories), directory management, image reading, time retrieval, process execution (`exec`) with timeout and working directory support, context cleanup for memory optimization, and batch file operations with safety checks (no dangerous commands like `rm -rf /`).

### WebCrawler
Web content extraction engine. Provides HTML fetching with custom headers, plain text extraction (removes script/style tags), intelligent content extraction (headers, body, title) for article parsing, link discovery with deduplication, asset extraction (images/files like PDF/ZIP/etc.), JSON API calls (auto GET/POST based on params), and file downloading with streaming write to local storage.

### WorkerBee
Sub-process worker management for parallel task execution. Supports creating named workers with custom roles and initialization prompts, sending async messages to ready workers only, listing all workers with status monitoring (ready/processing/calling_tools/etc.), and graceful shutdown. Ideal for CPU-intensive or long-running tasks that need isolation from the main thread.

---

## 🔌 WebSocket Protocol Specification

AgentBee communicates via standard WebSockets using JSON payloads. Multiple packets can be batched in a single frame by separating them with newline characters (`\n`). Each message must include a `type` field that routes to specific handlers within the core engine. Session tracking is managed through `sessionId` and unique per-request `messageId`.

### 1. Connection & Headers
- Standard WebSocket handshake required upon connection.
- Clients maintain state via `sessionId` (default: `"default"`) and generate a unique `messageId` for each request to correlate responses with prompts.

### 2. Request Types (`type`)

| Type | Content Structure | Behavior | Response Format |
|------|-------------------|----------|-----------------|
| **`setting`** | `{ "act": "getConfig"|"saveConfig" }` | Direct execution, no LLM involved. Returns immediately. | `{"type":"setting","status":"success/error","act":"...","data":{}}` |
| **`system`** | `{ "act": "getVersion"|"getModels" }` | System info retrieval (Agent version, Nervsys core ver, available LLM models). No LLM involved. | `{"type":"streaming", ...}` or error object |
| **`text`** | `{ "sessionId": "...", "messageId": "...", "content": { "text": "Hello!" } }` | Sends plain text to the LLM for processing. Supports full streaming responses (SSE/WS). | Streamed `payload.type: "text"` chunks → final `"end"` or tool calls array |
| **`chat`** | `{ "sessionId":"...", "messageId":"...", "content":[ {"type":"text","text":"..."}, {"type":"image_url",{"url":"data:..."}}, {"type":"file":{"filename":"x.txt","mimeType":"text/plain","content":"..."}} ] }` | Multimodal input (text, images via Data URL or `__BINARY__`, text files). Auto-parses allowed extensions and converts headers. Sent to LLM pipeline. | Same streaming format as `text`. Files are auto-converted with readable prefixes if plain-text compatible. |
| **`stop`** | `{ "sessionId":"...", "messageId":"..." } (content optional) | Aborts the current LLM stream or tool execution chain immediately. State resets for next request. | Stream terminates, control returns to client. No extra payload needed. |

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

---

## 🌐 Frontend: BeeWeb

For an out-of-the-box web interface, use the open-source frontend project **[BeeWeb](https://github.com/HarrisonDo/BeeWeb)**.

### Quick Start with BeeWeb

1. **Launch AgentBee** (see [Quick Start & Development Guide](#-quick-start--development-guide) below).
2. Open `BeeWeb\dist\BeeWeb-standalone.html` in your browser.
3. Configure the API and WebSocket connection settings:
   - Set your **API URL** to point to an OpenAI-compatible LLM endpoint (e.g., `http://127.0.0.1:60300/v1`)
   - Set your **API Key** for authentication
   - Set the **WebSocket URL**: `ws://127.0.0.127:8686`
4. Click "Save" — AgentBee will automatically fetch available models from your API. You can freely switch between them in the input box without restarting.
5. That's it — you're ready to chat with AgentBee directly in your browser.

---

## 🚀 Quick Start & Development Guide

1. **Create Module**: Ensure your folder name matches exactly what you put in `module.json.name`, and set `entry` to point to the main file (e.g., `go.php`). Both fields are mandatory for loading.
2. **Drop Files**: Place custom skills into the `skills/` directory. No config center updates needed.
3. **Business Logic**: Implement core workflows, message routing, and state management inside `go.php`. Keep meta definitions clean and focused on capability exposure.
4. **Launch Server**: 
   ```bash
   php "AgentBeePath\modules\agent_bee.php" agent_core/start
   # Or simply run: ./bin/bee (Linux/Mac) or bee.cmd (Windows)
   ```
5. **Configure LLM**: Edit `config/AgentBee.json` with your API URL and Key, or use BeeWeb's built-in settings dialog.
6. **Connect Frontend**: Establish a WebSocket connection to the configured host/port, or use [BeeWeb](https://github.com/HarrisonDo/BeeWeb) for a ready-made UI.

---

## 📜 License & Credits

Apache 2.0 | © 2026 秋水之冰 & AgentBee