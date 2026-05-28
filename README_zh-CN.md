# AgentBee - 开源 AI Agent 框架

<div align="center">

**蜂小秘，来助力！** 🐝

[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)](https://www.php.net/)
[![Nervsys](https://img.shields.io/badge/Framework-Nervsys-orange.svg)](https://github.com/Jerry-Shaw/Nervsys.git)

[English Version | 英文版](./README.md)

</div>

---

## 概述 (Overview)

**AgentBee (蜂小秘)** 是一个基于 PHP 开发的开源、轻量级 AI Agent 框架。它通过模块化架构，为大语言模型 (LLM) 对话和自主任务执行提供了全栈能力。依托 [Nervsys](https://github.com/Jerry-Shaw/Nervsys.git) 框架，AgentBee 支持 OpenAI 兼容协议，并通过 WebSocket 提供实时流式响应 —— 让开发者能够轻松地将 AI Agent 集成到任何应用或平台中。

### 核心特性

- **LLM 流式对话** — 实现逐 Token 的实时响应（支持正文、思考过程/推理、工具调用）。
- **自主工具执行** — Agent 在对话过程中可动态调用各种工具（文件系统、网页爬取、文档处理、记忆管理、系统命令等）。
- **模块化架构** — 能力即模块。通过内置的模块系统，无需修改核心代码即可添加或替换功能组件。
- **多模态输入** — 支持文本、图片 (Data URL / 二进制) 以及嵌入式文本文件作为对话内容。
- **四层记忆系统** — 提供 `system` (系统提示), `important` (重要事实), `daily` (每日摘要), 和 `ram` (临时缓存) 四个维度，基于 SQLite 或 JSONL 存储，支持 FTS5 全文检索。
- **定时任务调度** — 内置任务调度器，支持周期性或一次性的自动化作业。
- **WebSocket 原生设计** — 从底层构建，专为前端客户端与 Agent 后端之间的实时双向通信而设计。

---

## 架构与模块 (Architecture & Modules)

AgentBee 由 `modules/` 目录下的多个独立模块组成。每个模块拥有自己的入口点 (`go.php`) 和可选的工具定义 (`tools.php`)。

| 模块名称 | 功能描述 |
| :--- | :--- |
| **agent_bee** | 主程序引导模块 —— 连接所有组件的核心启动器。 |
| **agent_core** | 核心运行时：包含 WebSocket 服务端、进程管理器 (ProcMgr)、Socket 管理 (SocketMgr)、会话历史管理、系统记忆注入、工具执行流水线 (`execTools`) 及主消息处理逻辑。 |
| **agent_openai** | LLM 集成模块。封装 `libOpenAI` 用于调用 OpenAI 兼容接口。通过独立的工作进程 (`procWorker`) 并利用共享内存 (shmop) 处理流式响应，支持内容、思考块及并行工具调用。 |
| **agent_claw** | 网络爬虫与 HTTP 工具 —— 实现 HTML 获取、正文智能提取、链接列表解析、文件下载、JSON API 解析及页面资源提取。 |
| **agent_doc** | Office 文档处理模块 — 支持读取/写入 `.docx`、`.xlsx` (单表或多表) 及 `.pptx` (支持图片嵌入)。PPTX 图片采用 EMU 单位定位。 |
| **agent_mem_db** | 基于 SQLite 的四层记忆系统，提供 CRUD 接口、FTS5 全文检索及任务调度器（添加/删除/列出/执行任务）。 |
| **agent_mem_file** | 基于 JSONL 的备选记忆系统，具备与 `agent_mem_db` 相同的接口 —— 适用于仅依赖文件系统的部署环境。 |
| **agent_tools** | 基础工具集：执行系统命令、获取时间、清理上下文窗口、读取图片 (Data URL)、以及完整的物理文件/目录操作。 |

### 模块扩展模型

每个模块通过 `tools.php` 中的 `META` 常量注册工具，格式遵循 OpenAI 的 Function Calling Schema：

```php
namespace modules\your_module;

class tools {
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'toolName',
                'description' => '工具的功能描述',
                'parameters'  => ['type' => 'object', /* JSON Schema */],
            ],
        ],
    ];
}
```

只需在 `config/AgentBee.json` 的 `agent_tools.list` 中注册该模块，即可自动将其能力暴露给 LLM。支持通过 Git 仓库安装，无需改动核心代码。

---

## 🚀 开发指南：如何创建工具模块

想要添加新的功能？你可以按照 `agent_tools` 模块的模式来创建一个自己的工具模块。

### 1. 目录结构
在 `modules/` 目录下为你的模块创建一个新目录。例如，如果你要创建一个天气查询工具：
```text
modules/agent_weather/
├── go.php       # 模块入口点 (Bootstrap)
└── tools.php    # 工具定义与逻辑实现
```

### 2. 实现细节

#### **步骤 A: 定义工具逻辑 (`tools.php`)**
在你的 `tools.php` 中，必须定义一个 `META` 常量来向 LLM 描述该工具，并实现实际的函数逻辑。

```php
namespace modules\agent_weather;

class tools {
    // 1. 面向 LLM 的元数据 (OpenAI Schema)
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_current_weather',
                'description' => '获取指定位置的当前天气',
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'location' => [
                            'type' => 'string',
                            'description' => '城市名称，例如：北京市',
                        ],
                    ],
                    'required' => ['location'],
                ],
            ],
        ],
    ];

    // 2. 实际的执行逻辑
    public function get_current_weather(string $location): string {
        // 你的实现 (例如调用第三方天气 API)
        return "{$location} 的天气是晴天，25°C。";
    }
}
```

#### **步骤 B: 模块引导 (`go.php`)**
`go.php` 文件确保模块在 Agent 启动时被初始化。对于简单的工具模块，这可以是一个占位符，或者用于注册特定的启动任务。

#### **步骤 C: 注册模块 (`config/AgentBee.json`)**
要使你的工具生效，请将你的模块名称添加到全局配置文件 `config/AgentBee.json` 的 `agent_tools.list` 中：

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

### 3. 开发流程总结
1. **创建文件夹** $\rightarrow$ 2. **在 `tools.php` 中定义 `META` 与逻辑** $\rightarrow$ 3. **添加到 `config/AgentBee.json`** $\rightarrow$ **运行 Agent！**

---

## WebSocket 对接指南 (Frontend Integration)

AgentBee 使用 WebSocket 进行实时通信。前端应建立长连接并处理不同的消息类型。

### 连接流程
1. **Handshake**: 前端发起 WS 连接至服务端端口（默认 `8686`）。
2. **Chat Request**: 发送用户指令，包含必要的 `socket_id`。
3. **Stream Response**: 服务端推送流式数据包。
4. **End Signal**: 收到结束信号后，完成本次对话循环。

### 消息协议格式

**客户端发送 (Request):**
```json
{
  "type": "chat",
  "payload": { "content": "帮我查一下今天的天气" },
  "socket_id": "unique_session_id"
}
```

**服务端推送 (Stream/Response):**
服务端通过 `agent_openai` 模块实时推送以下格式的数据：

| 消息类型 (`type`) | 说明 | Payload 内容示例 |
| :--- | :--- | :--- |
| `content` | 普通文本回复 | `{"data": "今天天气晴朗..."}` |
| `think` | 模型思考过程/推理链 | `{"data": "用户询问天气，我需要调用工具..."}` |
| `tool_calls` | 工具调用指令 | `{"data": {"name": "get_weather", "args": {...}}}` |
| `end` | 对话结束信号 | `{"data": null}` |

### 流式参数解析 (LLM Streaming Details)

在流式传输过程中，底层通过 `agent_openai` 实时转发 LLM 的 Delta 数据。前端应根据 `type` 进行差异化渲染：

- **正文内容**: 监听 `type: 'content'`，其 `payload.data` 即为当前片段的文本。
- **推理过程**: 监听 `type: 'think'`，用于展示模型的思考逻辑（若模型支持）。
- **工具执行**: 当收到 `type: 'tool_calls'` 时，前端应停止渲染文本，转而进入等待状态或展示工具调用动作。

---

## 工具列表 (Available Tools)

### 📦 agent_tools (核心实用工具)

| 工具名称 | 功能描述 |
| :--- | :--- |
| `agent_tools/exec` | 执行外部系统命令 (`program` + `argv[]`)。支持超时设置与工作目录。 |
| `agent_tools/getTime` | 返回当前系统的日期时间及 Unix 时间戳。 |
| `agent_tools/cleanContext` | 清理上下文窗口：保留最近 N 条消息及 M 组工具调用对，控制 Token 使用量。 |
| `agent_tools/readImage` | 将图片文件加载为 Data URL (base64)。常用于将本地图片转为模型可读格式。 |
| `agent_tools/readFile` | 读取文本或二进制文件，支持 offset 和 limit 参数。 |
| `agent_tools/writeFile` | 写入或追加文件内容（自动创建目录）。单次建议 $\le$ 4096 字符。 |
| `agent_tools/copyFile` | 复制文件到目标位置，若目标已存在则覆盖。 |
| `agent_tools/deleteFile` | 永久删除磁盘上的文件（危险操作，需确认）。 |
| `agent_tools/searchFiles` | 使用 Glob 模式搜索目录下的文件 (如 `*.php`)，支持递归扫描。 |
| `agent_tools/getFileSize` | 获取指定文件的字节大小。 |
| `agent_tools/listDirectory` | 列出目录内容（非递归），包含文件大小与类型信息。 |
| `agent_tools/createDirectory` | 创建目录树，自动创建不存在的父级目录。 |
| `agent_tools/copyDirectory` | 递归复制整个目录到新位置（目标路径必须不存在）。 |
| `agent_tools/deleteDirectory` | 递归删除目录及其所有内容（危险操作，需确认）。 |

### 🕷️ agent_claw (网页爬取与 HTTP)

| 工具名称 | 功能描述 |
| :--- | :--- |
| `agent_claw/fetchHtml` | 获取指定 URL 的原始 HTML。支持自定义 Header 与超时设置。 |
| `agent_claw/fetchText` | 提取网页纯文本，自动去除标签并压缩空白符。 |
| `agent_claw/fetchContent` | 智能正文提取 —— 剔除导航、页脚及广告，仅返回 `{title, content}` 主体内容。 |
| `agent_claw/extractLinks` | 从页面中提取所有绝对路径的超链接（已去重）。 |
| `agent_claw/extractAssets` | 识别并提取页面中的图片 (`.png`, `.jpg`) 及文件 (`.pdf`, `.zip`, `.docx`)。 |
| `agent_claw/fetchJson` | 发起 HTTP GET/POST 请求并返回解析后的 JSON 对象，适用于 API 调用。 |
| `agent_claw/downloadFile` | 流式下载远程文件到本地磁盘，支持自动创建目录。 |

### 📄 agent_doc (文档处理)

| 工具名称 | 功能描述 |
| :--- | :--- |
| `agent_doc/readDocx` | 通过绝对路径读取 `.docx` 文档的文本内容。 |
| `agent_doc/writeDocx` | 创建或覆盖 `.docx` 文件。支持段落及图片嵌入 (`{type:"image", content:"path"}`)。 |
| `agent_doc/readXlsx` | 读取 `.xlsx` 表格，返回所有工作表的数据（二维数组格式）。 |
| `agent_doc/writeXlsx` | 写入 Excel 文件。支持单表或多表模式 (`[{"name":"Sheet1","rows":[[...]]}]`)。 |
| `agent_doc/readPptx` | 读取 `.pptx` 幻灯片，返回每张幻灯片的标题与文本内容。 |
| `agent_doc/writePptx` | 创建或覆盖 `.pptx` 文件。支持插入文字及图片（使用 EMU 单位定位）。 |

---

## 配置 (Configuration)

所有核心配置均存储在 `config/AgentBee.json` 中，包括：
- LLM API Key 及 Base URL。
- WebSocket 服务端口。
- 已启用的模块列表 (`agent_tools.list`)。
- 记忆库存储路径及数据库类型。

---

## 开发与部署 (Development)

1. **环境要求**: PHP $\ge$ 8.2, SQLite3 扩展, `shmop` (共享内存)。
2. **快速启动**: 
   ```bash
   # 进入项目目录并运行 bootstrap
   php modules/agent_bee/go.php
   ```
3. **前端接入**: 使用标准 WebSocket API 连接至服务端端口。

---

## 📅 开发路线图 (Development Roadmap)

- [x] **阶段 1: 工具集时代 (Tooling Era)** — 实现基础原子能力（文件、网页、文档、命令）。*当前进度*
- [ ] **阶段 2: 技能模块时代 (Skill Era)** — 将多个工具组合成“任务流”。例如：`agent_skill/report_generator` 可以自动完成“读取数据 $\rightarrow$ 生成图表 $\rightarrow$ 写入Docx”的闭环。
- [ ] **阶段 3: 自主调度时代 (Autonomous Era)** — 结合定时任务，实现基于时间的自动化触发（如：每天早上 9 点自动抓取新闻并整理成摘要）。

---
*Built with ❤️ by AgentBee Team.*