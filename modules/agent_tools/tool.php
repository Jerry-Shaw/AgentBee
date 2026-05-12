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
                'description' => '⚠️ DANGEROUS: Execute a system command. This action can modify files, install software, or damage the system. Use with extreme caution. Always prefer specialized tools (readFile, writeFile, listDirectory, etc.) over raw command execution when possible. The command runs in the workspace directory for security isolation.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'command'        => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: The system command to execute. Supports both Windows and Unix/Linux commands. Examples: "ls -la" (Linux/Mac), "dir" (Windows), "git status", "pwd" (Linux/Mac), "cd" (Windows), "echo Hello World", "cat file.txt" (Linux/Mac), "type file.txt" (Windows). Do not include shell pipes (|) or redirections (>, >>, <) as they may not work properly across platforms.'
                        ],
                        'argv'           => [
                            'type'        => 'array',
                            'description' => 'Additional command arguments as an array. Each argument will be automatically escaped. Use this instead of concatenating strings to the command for better security. Example: ["-la", "/tmp"] or ["/R", "C:\\Users"].',
                            'items'       => [
                                'type' => 'string'
                            ]
                        ],
                        'workspace_path' => [
                            'type'        => 'string',
                            'description' => 'Working directory for command execution. Defaults to the configured workspace directory. Use this to run commands in a specific subdirectory. Accepts both Unix-style (e.g., "/home/user/project") and Windows-style (e.g., "C:\\Projects\\MyApp") paths.'
                        ]
                    ],
                    'required'   => ['command']
                ]
            ]
        ]
    ];
}