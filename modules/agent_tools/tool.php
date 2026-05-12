<?php

/**
 * Agent Tools Metadata for AgentBee
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
        // ==================== exec ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'exec',
                'description' => "⚠️ DANGEROUS: Execute a system command. This action can modify files, install software, or damage the system. Use with extreme caution. Always prefer specialized tools (readFile, writeFile, listDirectory, etc.) over raw command execution when possible.\n\n" .
                    "== Usage ==\n" .
                    "- 'program': Executable name only (e.g., 'ls', 'dir', 'echo', 'cat', 'type', 'ipconfig', 'powershell', 'git'). Do NOT include arguments.\n" .
                    "- 'argv': Array of arguments (REQUIRED). Use [] for no arguments.\n" .
                    "- 'path': Working directory (OPTIONAL). Defaults to workspace path.\n\n" .
                    "== Notes ==\n" .
                    "- Windows internal commands (dir, echo, type, cd, del, copy, mkdir, rmdir) are auto-wrapped with cmd.exe /c.\n" .
                    "- Standalone executables (git, python, node, ipconfig, powershell) work directly.\n\n" .
                    "== Security ==\n" .
                    "- Never use: 'rm -rf', 'del /f /s', 'format', 'shutdown'\n" .
                    "- Avoid shell operators: |, >, >>, <, &&, ||\n" .
                    "- Commands have timeout protection (default 30s, max 300s)",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program' => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Executable name only. Examples: 'ls', 'dir', 'cat', 'type', 'git', 'powershell'"
                        ],
                        'argv'    => [
                            'type'        => 'array',
                            'description' => "REQUIRED: Array of arguments. Use [] for no arguments.",
                            'items'       => ['type' => 'string']
                        ],
                        'path'    => [
                            'type'        => 'string',
                            'description' => "OPTIONAL: Working directory. Defaults to workspace path."
                        ]
                    ],
                    'required'   => ['program', 'argv']
                ]
            ]
        ],

        // ==================== File Read/Write ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readFile',
                'description' => 'Read file content. For large files, use offset and limit to read in chunks.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => ['type' => 'string', 'description' => 'REQUIRED: Path to the file'],
                        'offset' => ['type' => 'integer', 'description' => 'Byte offset to start reading from. Default: 0'],
                        'limit'  => ['type' => 'integer', 'description' => 'Maximum bytes to read. Default: 8192, Max: 1048576']
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeFile',
                'description' => 'Write content to a file, creating it if it does not exist. Use append to add to existing files.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'    => ['type' => 'string', 'description' => 'REQUIRED: Path to the file'],
                        'content' => ['type' => 'string', 'description' => 'REQUIRED: Content to write'],
                        'append'  => ['type' => 'boolean', 'description' => 'If true, append instead of overwrite. Default: false']
                    ],
                    'required'   => ['path', 'content']
                ]
            ]
        ],

        // ==================== Directory Operations ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => 'List directory contents. Returns array with file/directory info.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'REQUIRED: Directory path to list']
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => 'Create a directory and any necessary parent directories. Succeeds if already exists.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'REQUIRED: Directory path to create']
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => '⚠️ DANGEROUS: Permanently delete a file. This action cannot be undone.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'REQUIRED: File path to delete']
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteDirectory',
                'description' => '⚠️ DANGEROUS: Recursively delete a directory and all its contents. Cannot be undone.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'REQUIRED: Directory path to delete']
                    ],
                    'required'   => ['path']
                ]
            ]
        ],

        // ==================== Search & Copy ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'searchFiles',
                'description' => 'Search files matching a glob pattern. Returns array of file paths.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'      => ['type' => 'string', 'description' => 'REQUIRED: Directory to search in'],
                        'pattern'   => ['type' => 'string', 'description' => 'REQUIRED: Glob pattern (e.g., "*.php", "*.{json,xml}")'],
                        'recursive' => ['type' => 'boolean', 'description' => 'Search subdirectories. Default: false']
                    ],
                    'required'   => ['path', 'pattern']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => 'Recursively copy a directory. Fails if destination already exists.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src' => ['type' => 'string', 'description' => 'REQUIRED: Source directory path'],
                        'dst' => ['type' => 'string', 'description' => 'REQUIRED: Destination directory path']
                    ],
                    'required'   => ['src', 'dst']
                ]
            ]
        ]
    ];
}