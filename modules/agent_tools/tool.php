<?php

/**
 * System Command Execution Tool Metadata for AgentBee
 *
 * Provides tool definitions conforming to OpenAI's function-calling standard format.
 *
 * Copyright 2026 秋水之冰 <27206617@qq.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace modules\agent_tools;

class tool
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'exec',
                'description' => "⚠️ DANGEROUS: Execute a system command. This action can modify files, install software, or damage the system. Use with extreme caution. Always prefer specialized tools (readFile, writeFile, listDirectory, etc.) over raw command execution when possible. The command runs in the workspace directory for security isolation.\n\n" .
                    "== Usage ==\n" .
                    "- 'program': Executable name only (e.g., 'ls', 'dir', 'echo', 'cat', 'type', 'ipconfig', 'powershell', 'git'). Do NOT include arguments here.\n" .
                    "- 'argv': Array of arguments (REQUIRED). Each argument as separate string. Use [] for no arguments.\n" .
                    "- 'path': Working directory (OPTIONAL). Defaults to workspace path.\n\n" .
                    "== Notes ==\n" .
                    "- Windows internal commands (dir, echo, type, cd, del, copy, mkdir, rmdir) are automatically wrapped with cmd.exe /c, so you can use them directly.\n" .
                    "- Standalone executables (git, python, node, ipconfig, powershell) work directly on all platforms.\n\n" .
                    "== Cross-Platform Examples ==\n" .
                    "Linux/Mac:\n" .
                    "  - List directory: program='ls', argv=['-la', '/tmp']\n" .
                    "  - Show file: program='cat', argv=['/etc/hostname']\n" .
                    "  - Git status: program='git', argv=['status']\n\n" .
                    "Windows:\n" .
                    "  - List directory: program='dir', argv=['E:\\\\Projects']\n" .
                    "  - Show file: program='type', argv=['C:\\\\temp\\\\test.txt']\n" .
                    "  - IP config: program='ipconfig', argv=['/all']\n" .
                    "  - PowerShell: program='powershell', argv=['-Command', 'Get-Date']\n" .
                    "  - Echo: program='echo', argv=['Hello World']\n\n" .
                    "== Security ==\n" .
                    "- Never use dangerous commands: 'rm -rf', 'del /f /s', 'format', 'shutdown'\n" .
                    "- Avoid shell operators: |, >, >>, <, &&, || (use array arguments instead)\n" .
                    "- Commands are executed with timeout protection (default 30s, max 300s)",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program' => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Executable name only.\n" .
                                "Examples:\n" .
                                "- Linux/Mac: 'ls', 'cat', 'grep', 'git', 'echo', 'pwd'\n" .
                                "- Windows: 'dir', 'echo', 'type', 'ipconfig', 'powershell', 'git'\n" .
                                "Do NOT include arguments or shell operators."
                        ],
                        'argv' => [
                            'type'        => 'array',
                            'description' => "REQUIRED: Array of arguments. Use [] for no arguments.\n\n" .
                                "Examples:\n" .
                                "- Linux: program='ls', argv=['-la', '/home']\n" .
                                "- Windows: program='dir', argv=['E:\\\\Projects']\n" .
                                "- PowerShell: program='powershell', argv=['-Command', 'Get-Process']",
                            'items'       => [
                                'type' => 'string'
                            ]
                        ],
                        'path' => [
                            'type'        => 'string',
                            'description' => "OPTIONAL: Working directory. Defaults to workspace path.\n" .
                                "Examples:\n" .
                                "- Linux: '/home/user/project'\n" .
                                "- Windows: 'E:\\\\Projects\\\\Codes\\\\AgentBee\\\\workspace'"
                        ]
                    ],
                    'required'   => ['program', 'argv']
                ]
            ]
        ]
    ];
}