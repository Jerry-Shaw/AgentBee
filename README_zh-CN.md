# AgentBee - 蜂小秘，来助力！ 🐝

<div align="center">

**蜂小秘，来助力！** 🐝

[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-8.1+-green.svg)](https://www.php.net/)
[![Nervsys](https://img.shields.io/badge/Framework-Nervsys-orange.svg)](https://github.com/Jerry-Shaw/Nervsys.git)

[English Version | 英文版](./README.md)

</div>

---

## 概述 (Overview)

**AgentBee (蜂小秘)** 是一个基于 [Nervsys](https://github.com/Jerry-Shaw/Nervsys.git) PHP 生态构建的开源、模块化 AI Agent 框架。它通过轻量级的自动加载架构，为大语言模型 (LLM) 对话和自主任务执行提供了全栈能力。依托实时 WebSocket 通信协议、智能四级记忆管理系统以及动态技能发现机制，AgentBee 能够无缝集成到各类应用或平台中。

### 核心特性

- **全自动加载技能** — 将文件放入 `skills/` 目录即可自动生效（位于项目根目录）。无需修改配置中心或手动注册，系统启动时会自动检测并加载所有合规模块。
- **四级记忆架构** — 采用持久化角色分层存储 (`system`, `important`, `daily`, `misc`)，内置智能上下文注入与自动清理触发器。
- **原生 WebSocket 通信** — 支持纯文本、多模态输入（图片/文件）、批量消息处理（`\n` 分隔符）。
- **模块化业务逻辑** — 所有智能体工作流均写在 `go.php` 中。能力定义 (`skills/`) 与执行工具清晰分离，避免将复杂逻辑散落在元数据里。

---

## 📁 目录结构与核心规范

```text
AgentBee/
├── bin/                      # 启动脚本 (bee / bee.cmd)
│   ├── bee                   # Linux/Mac shell 脚本
│   └── bee.cmd               # Windows 批处理文件
├── config/                   # 配置文件目录
│   └── AgentBee.json         # 主配置（服务器、LLM、内存等）
├── modules/                  # 插件生态体系（合规检测后自动加载）
│   ├── agent_core/           # 主程序引导模块 (Bootstrap)
│   │   ├── core.php          # 核心业务基础模块
│   │   ├── go.php            # 核心业务逻辑、WebSocket处理器与状态管理
│   │   ├── module.json       # 模块元数据（name必须严格等于当前文件夹名）
│   │   └── lib/              # 内部库文件 (config, message, utils)
│   ├── agent_toolsets/         # 内置技能模块（启动时自动加载）
│   │   ├── Browser/          # 无头浏览器自动化
│   │   ├── Memory/           # 记忆与定时任务管理
│   │   ├── OfficeSuite/      # Office文档处理 (.docx/.xlsx/.pptx)
│   │   ├── System/           # Shell命令、文件I/O、上下文管理等底层操作
│   │   ├── HttpFetcher/       # 网页抓取、内容提取与资源下载
│   │   └── WorkerBee/        # 子进程Worker管理（异步任务）
│   └── agent_openai/         # OpenAI兼容LLM适配器
├── skills/                   # 第三方技能包存放路径（热插拔发现）
├── memory/                   # 持久化记忆存储（首次使用时自动创建）
├── workspace/                # 默认工作目录（文件操作目标位置）
├── logs/                     # 应用程序日志文件
├── Nervsys/                  # Nervsys框架（Git子模块）
└── .gitmodules               # Git子模块配置
```

### 核心规则说明

1. **`module.json.name`**：必须与所在文件夹名称完全一致，同时 **`entry`** 必须指向模块的主入口文件（如 `go.php`）。两者缺一不可，缺少任何一个都会导致加载器跳过该模块。JSON中还包含 `version`（版本）、`description`（描述），可选的 `dependencies`（依赖项）。PHP环境要求已交由 Nervsys 核心统一管控，故不再在文件中声明。
2. **自动加载机制**：`skills/` 支持热插拔发现模式。无需向配置中心注册或执行额外的初始化步骤，只需将符合语法规范的代码放入对应目录，即可即时生效。
3. **Skills & Tools 关系说明** — Skills相当于复杂的Tools，两者在应用层类似，都是丰富LLM的能力。所有的路由分发、会话状态管理、记忆注入及定时任务调度逻辑全部编写在 `go.php` 中

---

## 🧠 四级记忆系统 (4-Tier Memory System)

旧版 JSONL 格式已被彻底移除，取而代之的是一个高性能、支持持久化的四层角色分级存储架构：

| 层级 | 用途说明 | 生命周期与保留策略 |
|------|----------|------------------|
| **`system`** | 核心人设、系统规则、交互边界与约束条件。上下文注入时优先级最高。 | 永久保存（通过 `create_id` 更新） |
| **`important`** | 关键事实、用户偏好、身份特征、长期状态变更。关键点发生后立即写入防丢失。 | 永久 / 长周期保留 |
| **`daily`** | 按日期归档的摘要、任务执行结果与每日日志（自动按 `YYYYMMDD` 归类）。 | 每日滚动，过期自动清理 |
| **`misc`** | 短期中间态数据、草稿或临时变量。系统会自动评估其价值并升级至上层持久化目录。 | 短周期 / 遇有价值自动升层 |

*智能触发逻辑*：收到新消息 → 检查 `daily`；提及时间/人物/事件 → 定向检索对应层级；上下文不足时 → 自动注入"请加载今日记忆"提示。系统遵循 **"宁多勿漏"** 原则，优先保证关键信息不丢失（仅在 Token 超限或连续工具调用过多时触发裁剪）。

**记忆持久化：** Agent 的所有对话记忆均存储在 `memory/` 目录下。升级代码不会影响这些数据——你可以安全地保留长期的对话历史和重要上下文，不用担心在开发迭代中丢失关键信息。

---

## 🛠️ 内置技能模块 (Built-in Skills)

AgentBee 预置了六大内置技能模块，开箱即用：

### Browser
无头浏览器自动化。支持页面导航、元素交互（点击/输入/下拉选择）、截图保存、JavaScript执行与DOM操作、多标签页管理，适用于网页抓取和UI测试场景。所有操作均作为可调用的工具暴露给LLM。

### Memory
持久化记忆数据库，采用四级架构（system/important/daily/misc）。提供完整的CRUD操作、定时任务调度（添加/删除/列出cron任务）、全文关键词搜索和日期范围过滤，并基于对话状态自动注入上下文记忆。

### OfficeSuite
Office文档处理套件。支持DOCX标题/段落/图片插入与格式控制（加粗/斜体/字号/对齐等）、XLSX单表/多表读写与追加行、PPTX幻灯片创建（含文本与图片）。也支持读取所有格式的已有文档。

### System
底层系统操作工具集。涵盖文件I/O（读取/写入/复制/删除）、目录管理（创建/列出/递归复制/删除）、图片读取、时间获取、进程执行(exec)带超时控制、上下文清理优化，以及批量操作的安全限制与沙箱路径保护（禁止危险命令如`rm -rf /`）。

### HttpFetcher
网页内容提取引擎。支持HTML抓取(自定义Header)、纯文本提取(去除script/style标签)、智能正文提取(标题+正文)用于文章解析、页面超链接提取去重、资源文件提取(图片/PDF/ZIP等)、JSON API请求(自动GET/POST和流式文件下载)。

### WorkerBee
子进程Worker管理。支持创建带角色/初始化提示的独立Worker实例，通过异步消息通信（仅ready状态可发送），提供状态监控(list)和稳定关闭(close)。适用于CPU密集型或长时间运行的任务隔离执行。

---

## 🔌 WebSocket 协议详解 (Protocol Specification)

AgentBee 采用标准 WebSocket 进行双向通信，载荷格式为 JSON。客户端支持在单个帧内发送多条消息（使用换行符 `\n` 分隔）。每条请求必须包含 `type` 字段用于核心引擎的路由分发。会话状态通过 `sessionId`与每次请求生成的唯一 `messageId` 绑定追踪。

### 1. 连接与会话初始化
- 建立标准 WS 握手即可接入服务。
- 客户端需维护 `sessionId`（首次可传 `"default"`)并为每轮对话生成独立 `messageId`，服务端据此关联上下文与记忆索引。

### 2. 消息类型 (`type`) 路由行为

| Type | Content 结构示例 | 处理逻辑 | 返回格式 |
|------|------------------|----------|----------|
| **`setting`** | `{ "act": "getConfig"|"saveConfig" }` | 直接读取/保存配置，不经过 LLM。同步执行并立即返回结果。 | `{"type":"setting","status":"success/error","act":"...","data":{}}` |
| **`system`** | `{ "act": "getVersion"|"getModels" }` | 获取系统版本或当前可用的 LLM 模型列表。纯元数据查询，无需调用大模型。 | `{"type":"streaming", ...} 或错误对象 |
| **`text`** | `{ "sessionId":"...", "messageId":"...", "content": { "text": "你好" } }` | 发送纯文本至 LLM 处理。支持完整的流式输出（SSE/WS双模）。 | 持续推送 `payload.type: "text"` → 最终返回 `"end"` 或工具调用请求数组 |
| **`chat`** | `{ "sessionId":"...", "messageId":"...", "content":[ {"type":"text","text":"..."}, {"type":"image_url",{"url":"data:..."}}, {"type":"file":{"filename":"x.txt","mimeType":"text/plain","content":"..."}} ] }` | 多模态输入（文本、图片Data URL或二进制占位符 `__BINARY`、纯文本文件。自动过滤非法后缀，合并后送入 LLM。 | 同 `text` 流式协议。文件头会自动添加"--- 文件开始 ---等标识便于模型理解上下文。 |
| **`stop`** | `{ "sessionId":"...", "messageId":"..." } (content可选) | 立即中断当前正在进行的 LLM 生成或工具执行链。状态重置，等待下一轮输入。 | 无额外载荷，流式通道关闭并将控制权交还给客户端。 |

### 3. 服务端响应结构
- **同步指令（`setting`/`system`) & **异步/流式处理**：服务端推送帧格式如下：  
  ```json
  {"type": "text|tool_calls|error|end", ...}
  ```
  *(注：`socket_id` 仅用于内部路由确定发送对象，实际通过 WebSocket 发送给客户端的内容仅有上述 Payload 部分。)

  - `text`: LLM 生成的文本分片（支持逐字渲染）
  - `tool_calls`: 模型请求调用的工具列表及对应参数
  - `history/add` & `sync`: 处理过程中动态同步的上下文记忆快照
  - `context`: 排队等待下一轮处理的待发消息队列

- **批量消息**：客户端若通过 `\n` 发送多行，每行独立路由。全部处理完毕后，最后一帧包裹 `"type":"end"` 标识当前批次结束（非 close）。

---

## 🌐 前端项目：BeeWeb

为提供开箱即用的 Web 界面，推荐使用开源前端项目 **[BeeWeb](https://github.com/HarrisonDo/BeeWeb)**。

### BeeWeb 快速上手

1. **启动 AgentBee**（详见下方[快速接入指南]）
2. 在浏览器中打开 `BeeWeb\dist\BeeWeb-standalone.html`
3. 配置 API 与 WebSocket 连接参数：
   - 设置你的 **API URL** 指向一个 OpenAI 兼容的 LLM 端点（例如 `http://127.0.0.1:60300/v1`）
   - 设置你的 **API Key** 用于身份验证
   - 设置 **WebSocket 地址**：`ws://127.0.0.1:8686`
4. 点击"保存"——AgentBee 会自动从你的 API 获取可用模型列表。你可以在输入框中自由切换，无需重启。
5. 完成！你就可以直接在浏览器中体验 AgentBee 的对话能力了。

---

## 🚀 快速接入指南 (Quick Start)

1. **创建模块**：确保文件夹名称与 `module.json.name` 严格一致，同时设置 `entry` 指向主入口文件（如 `go.php`）。两者缺一不可否则无法加载。
2. **放入文件**：将自定义技能直接拖入 `skills/` 目录（无需编写注册表或修改配置中心，语法合规即热插拔生效)。
3. **实现逻辑**：所有工作流、消息路由与状态管理均写在 `go.php` 中。保持元数据简洁专注。
4. **启动服务**：  
   ```bash
   php "AgentBeePath\modules\agent_bee.php" agent_core/start
   # 或简化运行: ./bin/bee (Linux/Mac) 或 bee.cmd (Windows)
   ```
5. **配置 LLM**：编辑 `config/AgentBee.json` 设置 API URL 和 Key，或使用 BeeWeb 内置的设置对话框。
6. **前端接入**：连接至配置的 WebSocket 地址，或使用 [BeeWeb](https://github.com/HarrisonDo/BeeWeb) 获取开箱即用的 Web UI。

---

## 📜 许可协议 (License)

Apache 2.0 | © 2026 秋水之冰 & AgentBee