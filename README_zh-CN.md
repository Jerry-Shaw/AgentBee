# 蜂小秘（AgentBee）- 小蜜蜂般的 Agent 服务端

> **蜂小秘，来助力！**

## 关于蜂小秘

**蜂小秘** 是一款基于 PHP 开发的轻量级 Agent 服务端，为您提供基于大模型的 AI 对话与任务执行能力。  
项目底层采用 [Nervsys](https://github.com/Jerry-Shaw/Nervsys.git)
框架，前端使用 [BeeWeb](https://github.com/HarrisonDo/BeeWeb)（开源项目，欢迎参与前端开发）。

蜂小秘支持模块化扩展，开发者可以自由编写工具模块，不断丰富 Agent 的功能。我们也希望开发者能将自己的模块开源出来，方便大家安装使用。

> 🐝 **项目刚刚起步**，更多精彩功能正在路上。欢迎持续关注，也欢迎推荐新特性、提交 Bug 报告，一起让蜂小秘变得更强大！  
> 目前文档还不够完善，但请放心使用，我们会逐步补充。

## 环境要求

- **Git**：用于克隆项目仓库，以及后续的模块安装。请确保 Git 可执行文件所在目录已添加到系统 `PATH` 环境变量中。
- **PHP 环境**：PHP 8.2 或更高版本，并已将 PHP 可执行文件所在目录添加到系统 `PATH` 环境变量中。

## 安装步骤

1. 递归克隆项目仓库：
   ```bash
   git clone --recursive https://github.com/Jerry-Shaw/AgentBee.git
   ```

2. 进入项目目录：
   ```bash
   cd AgentBee
   ```

## 配置说明

启动前，请根据您的 OpenAI 兼容接口服务商，修改配置文件：
`AgentBee\config\AgentBee.json`

- **Token 与 API URL**：填写正确的访问令牌和接口地址。
- **工作区路径（重要）**：确保配置中的 `workspace` 路径在您的系统上真实存在。若不存在，应用将报错退出。

## 启动方式

### Windows 系统

使用提供的批处理脚本：

```cmd
AgentBee\bin\bee.cmd
```

### 其他操作系统 / 手动启动

直接通过命令行运行 PHP 脚本：

```bash
php ".\modules\agent_bee\go.php" agent_core/start
```

## 前端界面（BeeWeb）

蜂小秘的前端项目 [BeeWeb](https://github.com/HarrisonDo/BeeWeb) 目前正在积极开发中，欢迎广大开发者参与贡献，打造更完善的
Agent 交互界面。  
后端启动后，只需将前端配置连接到后端提供的 WebSocket 地址即可。

## 模块化扩展

蜂小秘基于 Nervsys 框架的模块化设计，开发者可以自行开发各类工具模块（如文件操作、命令执行、记忆管理、网络请求、邮件管理等），轻松扩展
Agent 的能力。你甚至可以尝试让蜂小秘自己编写模块代码，再由人工审核配置。  
模块可以托管在您自己的 Git 仓库中，自行维护版本。蜂小秘支持通过 Nervsys 的 `module` 安装方式进行一键安装，无需修改核心代码，即可动态扩展
Agent 的功能。  
欢迎提交模块至社区索引，共同丰富生态。

---

> **提示**：蜂小秘（AgentBee）底层框架为 [Nervsys](https://github.com/Jerry-Shaw/Nervsys.git)
> ，前端项目为 [BeeWeb](https://github.com/HarrisonDo/BeeWeb)。  
> **标语**：蜂小秘，来助力！ | AgentBee, your busy bee!
