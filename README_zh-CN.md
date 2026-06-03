# AgentBee - 蜂小秘，来助力! 🐝

<div align="center">

**蜂小秘，来助力!** 🐝

[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)](https://www.php.net/)
[![Nervsys](https://img.shields.io/badge/Framework-Nervsys-orange.svg)](https://github.com/Jerry-Shaw/Nervsys.git)

[English Version | 英文版](./README.md)

</div>

---

## 概述 (Overview)

**AgentBee (蜂小秘)** 是一个基于 [Nervsys](https://github.com/Jerry-Shaw/Nervsys.git) PHP 生态构建的开源、模块化 AI Agent 框架。它通过轻量级的自动加载架构，为大语言模型 (LLM) 对话和自主任务执行提供了全栈能力。依托实时 WebSocket 通信协议、智能四级记忆管理系统以及动态技能发现机制，AgentBee 能够无缝集成到各类应用或平台中。

### 核心特性

- **全自动加载技能与工具** — 将文件放入 `skills/` 或 `tools/` 目录即可自动生效（位于项目根目录）。无需修改配置中心或手动注册，系统启动时会自动检测并加载所有合规模块。
- **四级记忆架构** — 采用持久化角色分层存储 (`system`, `important`, `daily`, `misc`)，内置智能上下文注入与自动清理触发器。
- **原生 WebSocket 通信** — 支持纯文本、多模态输入（图片/文件）、二进制流式传输以及批量消息处理（`\n` 分隔符）。
- **模块化业务逻辑** — 所有智能体工作流均写在 `go.php` 中。能力定义 (`skills/`) 与执行工具 (`tools/`) 清晰分离，避免将复杂逻辑散落在元数据里。

---

## 📁 目录结构与核心规范

```text
AgentBee/
├── modules/                    # 插件生态体系（合规检测后自动加载，无需配置）
│   └── agent_core/             # 主程序引导模块 (Bootstrap)
│       ├── module.json         # 模块元数据（name必须严格等于当前文件夹名）
│       ├── go.php              # 核心业务逻辑、WebSocket处理器与状态管理
│       └── core.php            # 核心业务基础模块
├── skills/                     # AI能力库 / 插件目录（丰富的LLM能力扩展）
│   ├── OfficeSuite/            # Office文档处理 (.docx/.xlsx/.pptx)
│   └── WebCrawler/             # 网页抓取、内容提取与资源下载
└── tools/                      # Agent执行工具目录（与skills同级，同样全自动加载）
    ├── Memory/                 # 记忆库/定时任务相关操作工具
    └── System/                 # Shell命令、文件I/O、上下文管理等底层操作
```

### 核心规则说明
1. **`module.json.name`**：必须与所在文件夹名称完全一致。若不匹配，模块加载器将直接跳过该目录。需包含 `version`（版本）、`description`（描述）和 `dependencies`（依赖项）。PHP环境要求已交由 Nervsys 核心统一管控，故不再在文件中声明。
2. **自动加载机制**：`tools/` 与 `skills/` 均支持热插拔发现模式。无需向配置中心注册或执行额外的初始化步骤，只需将符合语法规范的代码放入对应目录，即可即时生效。
3. **Skills & Tools 关系说明** — Skills相当于复杂的Tools，两者在应用层类似，都是丰富LLM的能力。所有的路由分发、会话状态管理、记忆注入及定时任务调度逻辑全部编写在 `go.php` 中。

---## 🧠 四级记忆系统 (4-Tier Memory System)

旧版 JSONL 格式已被彻底移除，取而代之的是一个高性能、支持持久化的四层角色分级存储架构：

| 层级 | 用途说明 | 生命周期与保留策略 |
|------|----------|------------------|
| **`system`** | 核心人设、系统规则、交互边界与约束条件。上下文注入时优先级最高。 | 永久保存（通过 `create_id` 更新） |
| **`important`** | 关键事实、用户偏好、身份特征、长期状态变更。关键点发生后立即写入防丢失。 | 永久 / 长周期保留 |
| **`daily`** | 按日期归档的摘要、任务执行结果与每日日志（自动按 `YYYYMMDD` 归类）。 | 每日滚动，过期自动清理 |
| **`misc`** | 短期中间态数据、草稿或临时变量。系统会自动评估其价值并升级至上层持久化目录。 | 短周期 / 遇有价值自动升层 |

*智能触发逻辑*：收到新消息 → 检查 `daily`；提及时间/人物/事件 → 定向检索对应层级；上下文不足时 → 自动注入“请加载今日记忆”提示。系统遵循 **“宁多勿漏”** 原则，优先保证关键信息不丢失（仅在 Token 超限或连续工具调用过多时触发裁剪）。

---

## 🔌 WebSocket 协议详解 (Protocol Specification)

AgentBee 采用标准 WebSocket 进行双向通信，载荷格式为 JSON。客户端支持在单个帧内发送多条消息（使用换行符 `\n` 分隔）。每条请求必须包含 `type` 字段用于核心引擎的路由分发。会话状态通过 `sessionId` 与每次请求生成的唯一 `messageId` 绑定追踪。

### 1. 连接与会话初始化
- 建立标准 WS 握手即可接入服务。
- 客户端需维护 `sessionId`（首次可传 `"default"`）并为每轮对话生成独立 `messageId`，服务端据此关联上下文与记忆索引。

### 2. 消息类型 (`type`) 路由行为

| Type | Content 结构示例 | 处理逻辑 | 返回格式 |
|------|------------------|----------|----------|
| **`setting`** | `{ "act": "getConfig"\|"saveConfig"\|"getDefaultConfig", "data": {...} }` | 直接读取/保存配置，不经过 LLM。同步执行并立即返回结果。 | `{"type":"setting","status":"success/error","act":"...","data":{}}` |
| **`system`** | `{ "act": "getVersion"\|"getModels" }` | 获取系统版本或当前可用的 LLM 模型列表。纯元数据查询，无需调用大模型。 | `{"type":"streaming", ...}` 或错误对象 |
| **`text`** | `{ "sessionId":"...", "messageId":"...", "content": { "text": "你好" } }` | 发送纯文本至 LLM 处理。支持完整的流式输出（SSE/WS双模）。 | 持续推送 `payload.type: "text"` → 最终返回 `"end"` 或工具调用请求数组 |
| **`chat`** | `{ "sessionId":"...", "messageId":"...", "content":[ {"type":"text","text":"..."}, {"type":"image_url",{"url":"data:..."}}, {"type":"file":{"filename":"x.txt","mimeType":"text/plain","content":"..."}} ] }` | 多模态输入（文本、图片Data URL或二进制占位符 `__BINARY__`、纯文本文件）。自动过滤非法后缀，合并后送入 LLM。 | 同 `text` 流式协议。文件头会自动添加“--- 文件开始 ---”等标识便于模型理解上下文。 |
| **`stop`** | `{ "sessionId":"...", "messageId":"..." }` (content可选) | 立即中断当前正在进行的 LLM 生成或工具执行链。状态重置，等待下一轮输入。 | 无额外载荷，流式通道关闭并将控制权交还给客户端。 |

### 3. 服务端响应结构
- **同步指令**（`setting`/`system`) & **异步/流式处理**：服务端推送帧格式如下：  
  ```json
  {"type": "text|tool_calls|error|end", ...}
  ```
  *(注：`socket_id` 仅用于内部路由确定发送对象，实际通过 WebSocket 发送给客户端的内容仅有上述 Payload 部分。)*

  - `text`: LLM 生成的文本分片（支持逐字渲染）
  - `tool_calls`: 模型请求调用的工具列表及对应参数
  - `history/add` & `sync`: 处理过程中动态同步的上下文记忆快照
  - `context`: 排队等待下一轮处理的待发消息队列
- **批量消息**：客户端若通过 `\n` 发送多行，每行独立路由。全部处理完毕后，最后一帧包裹 `"type":"end"` 标识当前批次结束（非 close）。

### 4. 二进制传输优化 (Advanced Binary Support)
针对大体积图片或文件场景，支持零拷贝/低内存占用的批量上传机制：
1. 客户端将 JSON 头与原始二进制流打包为单个 WS Frame。
2. 前 **4字节** (Big-Endian Uint32) = JSON 元数据部分的长度。
3. 元数据内 `content` 数组使用 `"__BINARY__"` 作为占位符，并通过 `binary_sizes` 声明各二进制块的精确尺寸（单位：Byte）。
4. 服务端剥离头部、解析JSON后按顺序将真实二进制数据替换进对应位置，随后送入 LLM 或记忆流水线处理。

---

## 🚀 快速接入指南 (Quick Start)

1. **创建模块**：确保文件夹名称与 `module.json.name` 严格一致（否则加载器跳过）。
2. **放入文件**：将自定义技能/工具直接拖入对应目录（无需编写注册表或修改配置中心，语法合规即热插拔生效）。
3. **实现逻辑**：所有工作流、消息路由与状态管理均写在 `go.php` 中。保持元数据简洁专注。
4. **启动服务**：  
   ```bash
   php go.php -c=provider/worker_name
   ```
5. **前端接入**：连接至配置的 WebSocket 地址。首次可调用 `setting/getConfig` 验证连通性，或直接发送带有效 `sessionId` 的 `chat` 请求开始对话。

---

## 📜 许可协议 (License)
Apache 2.0 | © 2026 秋水之冰 & AgentBee