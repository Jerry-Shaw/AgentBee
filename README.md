# AgentBee (AI Assistant) - Your little Agent Server

> **AgentBee, your busy bee!**

## About AgentBee

**AgentBee** is a lightweight Agent server built with PHP, providing AI conversation and task execution capabilities
powered by large language models.  
The project is based on the [Nervsys](https://github.com/Jerry-Shaw/Nervsys.git) framework, and the frontend
uses [BeeWeb](https://github.com/HarrisonDo/BeeWeb) (open source – contributions welcome).

AgentBee supports modular extension. Developers can freely create tool modules to enrich the Agent’s functionality. We
also hope developers open‑source their own modules so others can install and use them.

> 🐝 **The project is just getting started** – more exciting features are on the way. Stay tuned, and feel free to
> suggest new features or submit bug reports to help make AgentBee even better!  
> Documentation is still incomplete, but feel free to start using it – we will gradually improve it.

## Requirements

- **Git**: Required for cloning the repository and for future module installation. Make sure the Git executable
  directory is added to your system `PATH`.
- **PHP Environment**: PHP 8.2 or higher, with the PHP executable directory added to your system `PATH`.

## Installation

1. Clone the repository recursively:
   ```bash
   git clone --recursive https://github.com/Jerry-Shaw/AgentBee.git
   ```

2. Enter the project directory:
   ```bash
   cd AgentBee
   ```

## Configuration

Before starting, modify the configuration file according to your OpenAI‑compatible API provider:
`AgentBee\config\AgentBee.json`

- **Token & API URL**: Fill in the correct access token and API endpoint.
- **Workspace Path (Important)**: Ensure the `workspace` path defined in the configuration actually exists on your
  system; otherwise, the application will exit with an error.

## Starting the Server

### Windows

Use the provided batch script:

```cmd
AgentBee\bin\bee.cmd
```

### Other OS / Manual

Run the PHP script directly from the command line:

```bash
php ".\modules\agent_bee\go.php" agent_core/start
```

## Frontend (BeeWeb)

AgentBee’s frontend project, [BeeWeb](https://github.com/HarrisonDo/BeeWeb), is under active development. Contributions
are welcome to create a better Agent interaction interface.  
Once the backend is running, simply configure your frontend to connect to the WebSocket address provided by the backend.

## Modular Extension

AgentBee is built on the modular design of the Nervsys framework. Developers can create various tool modules (e.g., file
operations, command execution, memory management, HTTP requests, email management, etc.) to easily extend the Agent’s
capabilities. You can even try to let AgentBee write its own module code, then review and configure it manually.  
Modules can be hosted in your own Git repository and maintained by yourself. AgentBee supports one‑click installation
via Nervsys’s `module` mechanism, allowing you to dynamically extend the Agent’s functionality without modifying core
code.  
We welcome you to submit modules to the community index and together enrich the ecosystem.

---

> **Note**: AgentBee is built on the [Nervsys](https://github.com/Jerry-Shaw/Nervsys.git) framework, and the frontend
> project is [BeeWeb](https://github.com/HarrisonDo/BeeWeb).  
> **Slogan**: AgentBee, your busy bee! | 蜂小秘，来助力！
