# AgentBee - Open Source AI Agent Framework

<div align="center">

**Your Busy Bee!** 🐝

[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)](https://www.php.net/)
[![Nervsys](https://img.shields.io/badge/Framework-Nervsys-orange.svg)](https://github.com/Jerry-Shaw/Nervsys.git)

[中文版 | Chinese Version](./README_zh-CN.md)

</div>

---

## Overview

**AgentBee** is an open-source, lightweight AI Agent framework built with PHP. It provides full-stack capabilities for large language model (LLM) conversations and autonomous task execution through a modular architecture. Powered by the [Nervsys](https://github.com/Jerry-Shaw/Nervsys.git) framework, AgentBee supports OpenAI-compatible APIs and delivers real-time streaming responses via WebSocket — making it easy to integrate AI agents into any application or platform.

### Key Features

- **LLM Chat with Streaming** — Real-time token-by-token responses (content, reasoning/thinking, tool calls).
- **Autonomous Tool Execution** — The Agent can call tools dynamically during conversations (filesystem, web crawling, document processing, memory management, system commands, etc.).
- **Modular Architecture** — Every capability is a module. Add or replace modules with zero core changes via the built-in module system.
- **Multi-modal Input** — Support for text, images (Data URL / binary), and embedded text files in chat messages.
- **Four-layer Memory System** — `system`, `important`, `daily`, and `ram` levels backed by SQLite or JSONL storage with full-text search via FTS5.
- **Scheduled Tasks** — Built-in task scheduler for recurring or one-time jobs.
- **WebSocket-first Communication** — Designed from the ground up for real-time, bidirectional communication between frontend clients and the Agent backend.

---

## Architecture & Modules

AgentBee is composed of multiple independent modules under `modules/`. Each module has its own entry point (`go.php`) and optional tool definitions (`tools.php`).

| Module | Description |
|--------|-------------|
| **agent_bee** | Main application bootstrap — the entrypoint that ties everything together. |
| **agent_core** | Core runtime: WebSocket server, process manager (ProcMgr), socket management (SocketMgr), session history, system memory injection, tool execution pipeline (`execTools`), and the main message processing logic. |
| **agent_openai** | LLM integration module. Wraps `libOpenAI` for OpenAI-compatible API calls. Manages a separate worker process (`procWorker`) that handles streaming completions via shared memory (shmop). Supports content, reasoning/thinking blocks, and parallel tool calls. |
| **agent_claw** | Web crawler / HTTP tools — fetch HTML, extract clean article content, list links, download files, parse JSON APIs, and extract page assets. |
| **agent_doc** | Office document processing — read/write `.docx`, `.xlsx` (single or multi-sheet), and `.pptx` (with image support). EMU-based positioning for PPTX images. |
| **agent_mem_db** | SQLite-backed four-layer memory system with CRUD, FTS5 full-text search, and a task scheduler (add/remove/list/run tasks). |
| **agent_mem_file** | JSONL-based alternative memory system with the same interface as `agent_mem_db` — useful for file-system-only deployments. |
| **agent_tools** | Core utilities: exec system commands, get time, clean context window, read images (Data URL), and full filesystem operations (read/write/copy/move/delete files and directories). |

### Module Extension Model

Each module registers tools via a `META` constant in `tools.php`. The format follows the OpenAI function-calling schema:

```php
namespace modules\your_module;

class tools {
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'toolName',
                'description' => 'What this tool does.',
                'parameters'  => ['type' => 'object', /* JSON Schema */],
            ],
        ],
    ];
}
```

Register the module in `config/AgentBee.json` under `agent_tools.list`, and it becomes available to the LLM automatically. Modules can be hosted on Git repositories and installed via Nervsys's one-click mechanism — no core code modification required.

---

## 🚀 Developer Guide: Creating a Tool Module

Want to add new capabilities? You can create your own tool module by following the same pattern used by `agent_tools`.

### 1. Directory Structure
Create a new directory under `modules/` for your module. For example, if you are creating a weather tool:
```text
modules/agent_weather/
├── go.php       # Module entry point (Bootstrap)
└── tools.php    # Tool definitions and logic
```

### 2. Implementation Details

#### **Step A: Define the Tool Logic (`tools.php`)**
In your `tools.php`, you must define a `META` constant that describes the tool to the LLM, and implement the actual function logic.

```php
namespace modules\agent_weather;

class tools {
    // 1. The Metadata for LLM discovery (OpenAI Schema)
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_current_weather',
                'description' => 'Get the current weather in a given location',
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'location' => [
                            'type' => 'string',
                            'description' => 'The city and state, e.g. San Francisco, CA',
                        ],
                    ],
                    'required' => ['location'],
                ],
            ],
        ],
    ];

    // 2. The actual execution logic
    public function get_current_weather(string $location): string {
        // Your implementation (e.g., calling a third-party API)
        return "The weather in {$location} is sunny, 25°C.";
    }
}
```

#### **Step B: Module Bootstrap (`go.php`)**
The `go.php` file ensures the module is initialized when the Agent starts. For simple tool modules, this can be a placeholder or used to register specific startup tasks.

#### **Step C: Registration (`config/AgentBee.json`)**
To make your tool active, add your module name to the `agent_tools.list` in the global configuration file:

```json
{
  "agent_tools": {
    "list": [
      "modules\\agent_tools",
      "modules\\agent_weather" 
    ]
  }
}
```

### 3. Summary of Workflow
1. **Create folder** $\rightarrow$ 2. **Define `META` & Logic in `tools.php`** $\rightarrow$ 3. **Add to `config/AgentBee.json`** $\rightarrow$ **Run Agent!**

---

## WebSocket Integration Guide (Frontend)

AgentBee uses WebSockets for real-time communication. Frontends should establish a persistent connection and handle various message types.

### Connection Flow
1. **Handshake**: Client initiates WS connection to the server port (default `8686`).
2. **Chat Request**: Send user instructions containing necessary `socket_id`.
3. **Stream Response**: Server pushes streaming data packets.
4. **End Signal**: Once the end signal is received, the conversation loop completes.

### Message Protocol Format

**Client Sending (Request):**
```json
{
  "type": "chat",
  "payload": { "content": "How's the weather today?" },
  "socket_id": "unique_session_id"
}
```

**Server Pushing (Stream/Response):**
The `agent_openai` module pushes real-time data in the following format:

| Message Type (`type`) | Description | Payload Example |
| :--- | :--- | :--- |
| `content` | Standard text response | `{"data": "Today is sunny..."}` |
| `think` | Model reasoning/thinking chain | `{"data": "User asked for weather, I need to call tool..."}` |
| `tool_calls` | Tool execution instruction | `{"data": {"name": "get_weather", "args": {...}}}` |
| `end` | Conversation end signal | `{"data": null}` |

### Streaming Parameter Parsing (LLM Details)

During streaming, the underlying layer forwards LLM Delta data via `agent_openai`. Frontends should render based on the `type`:

- **Text Content**: Listen to `type: 'content'`, where `payload.data` is the current text chunk.
- **Reasoning Process**: Listen to `type: 'think'`, used to display model's thought logic (if supported).
- **Tool Execution**: When receiving `type: 'tool_calls'`, the frontend should pause text rendering and switch to a waiting state or display tool actions.

---

## Available Tools

### 📦 agent_tools (Core Utilities)

| Tool | Description |
|------|-------------|
| `agent_tools/exec` | Execute an external system command (`program` + `argv[]`). Supports timeout and working directory. |
| `agent_tools/getTime` | Return the current system datetime and Unix timestamp. |
| `agent_tools/cleanContext` | Prune conversation context: keep N recent messages + M tool-call pairs to control token usage. |
| `agent_tools/readImage` | Load an image file into a Data URL (base64). Useful for converting local images for LLM consumption. |
| `agent_tools/readFile` | Read text or binary files with offset and limit support. |
| `agent_tools/writeFile` | Write or append to a file (auto-creates directories). Max 4096 chars per write recommended. |
| `agent_tools/copyFile` | Copy one file to another, overwriting the destination if it exists. |
| `agent_tools/deleteFile` | Permanently delete a file from disk (dangerous — requires confirmation). |
| `agent_tools/searchFiles` | Glob-pattern search across directories (`*.php`, etc.), supports recursion. |
| `agent_tools/getFileSize` | Return the size of a file in bytes. |
| `agent_tools/listDirectory` | List directory contents (non-recursive) with file sizes and types. |
| `agent_tools/createDirectory` | Create a directory tree, automatically creating parent directories as needed. |
| `agent_tools/copyDirectory` | Recursively copy an entire directory to a new location (destination must not exist). |
| `agent_tools/deleteDirectory` | Recursively delete a directory and its contents (dangerous — requires confirmation). |

### 🕷️ agent_claw (Web Crawling & HTTP)

| Tool | Description |
|------|-------------|
| `agent_claw/fetchHtml` | Fetch raw HTML from any URL. Supports custom headers and timeout. |
| `agent_claw/fetchText` | Extract plain text from a webpage — strips tags and compresses whitespace. |
| `agent_claw/fetchContent` | Intelligent article extraction — removes navigation, footers, ads; returns `{title, content}` only. |
| `agent_claw/extractLinks` | Extract all absolute URLs from a page (deduplicated). |
| `agent_claw/extractAssets` | Identify and extract images (`.png`, `.jpg`) and files (`.pdf`, `.zip`, `.docx`, etc.) on a page. |
| `agent_claw/fetchJson` | Make an HTTP GET or POST request and return parsed JSON. Ideal for API integration. |
| `agent_claw/downloadFile` | Stream-download remote files to local disk with auto-directory creation. |

### 📄 agent_doc (Office Documents)

| Tool | Description |
|------|-------------|
| `agent_doc/readDocx` | Read text content from `.docx` files via absolute path. |
| `agent_doc/writeDocx` | Create or overwrite a `.docx`. Supports paragraphs and embedded images (`{type:"image", content:"path"}`). |
| `agent_doc/readXlsx` | Read `.xlsx` spreadsheets, returning all sheets as 2D arrays. |
| `agent_doc/writeXlsx` | Write Excel files. Supports single or multi-sheet mode (`[{"name":"Sheet1","rows":[[...]]}]`). |
| `agent_doc/readPptx` | Read `.pptx` slides, returning slide titles and text content. |
| `agent_doc/writePptx` | Create or overwrite a `.pptx`. Supports text and image insertion (using EMU units for positioning). |

---

## Configuration

All core configurations are stored in `config/AgentBee.json`, including:
- LLM API Key and Base URL.
- WebSocket service port.
- Enabled modules list (`agent_tools.list`).
- Memory database path and type.

---

## Development & Deployment

1. **Requirements**: PHP $\ge$ 8.2, SQLite3 extension, `shmop` (shared memory).
2. **Quick Start**: 
   ```bash
   # Enter project directory and run bootstrap
   php modules/agent_bee/go.php
   ```
3. **Frontend Integration**: Use standard WebSocket API to connect to the server port.

---

## 📅 Development Roadmap

- [x] **Phase 1: Tooling Era** — Basic atomic capabilities (Files, Web, Docs, Commands). *Current*
- [ ] **Phase 2: Skill Era** — Combining multiple tools into "Task Flows". E.g., `agent_skill/report_generator` automates "Fetch Data $\rightarrow$ Generate Chart $\rightarrow$ Write Docx".
- [ ] **Phase 3: Autonomous Era** — Integrating with Task Scheduler for time-based automation (e.g., daily news summaries at 9 AM).

---
*Built with ❤️ by AgentBee Team.*