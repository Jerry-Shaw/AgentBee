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

class tools
{
    public const META = [
        // ==================== exec ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'exec',
                'description' => "⚠️ DANGEROUS: Execute a system command.\n\n" .
                    "== Windows Platform ==\n" .
                    "✅ ALWAYS use 'powershell' as the program on Windows.\n" .
                    "   The system does NOT wrap or transform commands. You must use the correct executable.\n\n" .
                    "   Examples:\n" .
                    "   - List directory: program='powershell', argv=['-Command', 'Get-ChildItem']\n" .
                    "   - List directory with path: program='powershell', argv=['-Command', \"Get-ChildItem -Path 'C:\\\\Projects'\"]\n" .
                    "   - Create directory: program='powershell', argv=['-Command', \"New-Item -ItemType Directory -Path 'C:\\\\test' -Force\"]\n" .
                    "   - Delete file: program='powershell', argv=['-Command', \"Remove-Item -Path 'C:\\\\file.txt' -Force\"]\n" .
                    "   - Copy file: program='powershell', argv=['-Command', \"Copy-Item -Path 'C:\\\\src.txt' -Destination 'C:\\\\dst.txt' -Force\"]\n" .
                    "   - Show file content: program='powershell', argv=['-Command', \"Get-Content -Path 'C:\\\\file.txt'\"]\n" .
                    "   - Get current directory: program='powershell', argv=['-Command', 'Get-Location']\n" .
                    "   - Run git: program='powershell', argv=['-Command', 'git status']\n" .
                    "   - Run ipconfig: program='powershell', argv=['-Command', 'ipconfig /all']\n" .
                    "   - Run cmd.exe command: program='powershell', argv=['-Command', 'cmd /c dir']\n\n" .
                    "❌ Do NOT use these directly (they will fail):\n" .
                    "   - 'dir', 'mkdir', 'cd', 'del', 'copy', 'move', 'type', 'echo'\n" .
                    "   These are NOT standalone executables on Windows.\n\n" .
                    "== Linux/Mac Platform ==\n" .
                    "✅ Use standard commands directly (they are standalone executables):\n" .
                    "   - 'ls', 'cat', 'pwd', 'mkdir', 'rm', 'cp', 'mv', 'grep', 'find', 'git', 'python3'\n" .
                    "   Examples:\n" .
                    "   - List directory: program='ls', argv=['-la', '/home']\n" .
                    "   - Show file: program='cat', argv=['/etc/hostname']\n" .
                    "   - Create directory: program='mkdir', argv=['-p', '/home/user/newdir']\n" .
                    "   - Delete file: program='rm', argv=['file.txt']\n" .
                    "   - Copy file: program='cp', argv=['src.txt', 'dst.txt']\n" .
                    "   - Find text: program='grep', argv=['-r', 'pattern', './src']\n\n" .
                    "== Usage ==\n" .
                    "- 'program': Executable name only (string, REQUIRED)\n" .
                    "  ✅ Windows: 'powershell' (ALWAYS)\n" .
                    "  ✅ Linux/Mac: 'ls', 'cat', 'git', 'python3', etc.\n" .
                    "- 'argv': Array of arguments (array, REQUIRED) - use [] for no arguments\n" .
                    "  ✅ Windows: argv=['-Command', 'Get-ChildItem']\n" .
                    "  ✅ Linux: argv=['-la', '/home']\n" .
                    "  ✅ No arguments: argv=[]\n" .
                    "  ❌ WRONG: argv='-la /home'\n" .
                    "- 'path': Working directory (string, OPTIONAL) - defaults to workspace path\n\n" .
                    "== Security ==\n" .
                    "- ⚠️ NEVER use dangerous commands: 'rm -rf', 'del /f /s', 'format', 'shutdown', 'reboot'\n" .
                    "- ⚠️ AVOID shell operators: |, >, >>, <, &&, || (use array arguments instead)\n" .
                    "- Commands have timeout protection (default 30 seconds, max 300 seconds)\n" .
                    "- Always prefer specialized tools (readFile, writeFile, listDirectory, searchFiles) over exec\n\n" .
                    "📁 Path notes:\n" .
                    "- Supports absolute paths (e.g., C:\\Windows, /etc) and relative paths\n" .
                    "- Path traversals (..) are automatically resolved for security\n" .
                    "- Use '/' or '\\' as separators - both work automatically",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program' => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Executable name only.\n" .
                                "✅ Windows: ALWAYS use 'powershell'\n" .
                                "✅ Linux/Mac: 'ls', 'cat', 'pwd', 'mkdir', 'rm', 'cp', 'mv', 'grep', 'find', 'git', 'python3'\n" .
                                "❌ Windows: Do NOT use 'dir', 'mkdir', 'cd', 'del', 'copy', 'move', 'type', 'echo' directly"
                        ],
                        'argv'    => [
                            'type'        => 'array',
                            'description' => "REQUIRED: Array of command arguments. MUST be an array type, NOT a string.\n" .
                                "✅ Windows: program='powershell', argv=['-Command', 'Get-ChildItem']\n" .
                                "✅ Windows: program='powershell', argv=['-Command', \"Get-ChildItem -Path 'C:\\\\Projects'\"]\n" .
                                "✅ Linux: program='ls', argv=['-la', '/home']\n" .
                                "✅ No arguments: argv=[]\n" .
                                "❌ WRONG: argv='-la /home'",
                            'items'       => ['type' => 'string']
                        ],
                        'path'    => [
                            'type'        => 'string',
                            'description' => "OPTIONAL: Working directory for command execution.\n" .
                                "Defaults to workspace path if not specified.\n" .
                                "Examples:\n" .
                                "- Windows: 'C:\\\\Projects\\\\MyApp'\n" .
                                "- Linux/Mac: '/home/user/project'"
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
                'description' => "Read file content. For large files, use offset and limit to read in chunks.\n\n" .
                    "Returns: {\"content\": \"file content\"} on success, {\"error\": \"message\"} on failure.\n\n" .
                    "📁 Path notes:\n" .
                    "- Supports absolute paths (e.g., C:\\Windows, /etc) and relative paths\n" .
                    "- Path traversals (..) are automatically resolved for security\n" .
                    "- Use '/' or '\\' as separators - both work automatically",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Path to the file.\n" .
                                "Examples:\n" .
                                "- Windows: 'C:\\\\Users\\\\file.txt' or 'workspace/data.txt'\n" .
                                "- Linux/Mac: '/home/user/file.txt' or 'workspace/data.txt'"
                        ],
                        'offset' => [
                            'type'        => 'integer',
                            'description' => "Byte offset to start reading from. Default: 0"
                        ],
                        'limit'  => [
                            'type'        => 'integer',
                            'description' => "Maximum bytes to read. Default: 8192 (8KB), Max: 1048576 (1MB)"
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeFile',
                'description' => "Write content to a file, creating directories if needed. Use append to add to existing files.\n\n" .
                    "Returns: {\"bytes_written\": N} on success, {\"error\": \"message\"} on failure.\n\n" .
                    "📁 Path notes:\n" .
                    "- Supports absolute paths (e.g., C:\\Windows, /etc) and relative paths\n" .
                    "- Path traversals (..) are automatically resolved for security\n" .
                    "- Use '/' or '\\' as separators - both work automatically",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'    => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Path to the file.\n" .
                                "Examples:\n" .
                                "- Windows: 'C:\\\\Users\\\\file.txt' or 'workspace/data.txt'\n" .
                                "- Linux/Mac: '/home/user/file.txt' or 'workspace/data.txt'"
                        ],
                        'content' => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Content to write to the file"
                        ],
                        'append'  => [
                            'type'        => 'boolean',
                            'description' => "If true, append to end of file instead of overwriting. Default: false"
                        ]
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
                'description' => "List directory contents.\n\n" .
                    "Returns: {\"contents\": [{\"filename\": \"...\", \"filepath\": \"...\", \"filesize\": N, \"isFile\": bool}]} on success, {\"error\": \"message\"} on failure.\n\n" .
                    "📁 Path notes:\n" .
                    "- Supports absolute paths (e.g., C:\\Windows, /etc) and relative paths\n" .
                    "- Path traversals (..) are automatically resolved for security\n" .
                    "- Use '/' or '\\' as separators - both work automatically",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Directory path to list.\n" .
                                "Examples:\n" .
                                "- Windows: 'C:\\\\Projects' or 'workspace/src'\n" .
                                "- Linux/Mac: '/home/user/project' or 'workspace/src'"
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => "Create a directory and any necessary parent directories. Succeeds if already exists.\n\n" .
                    "Returns: {\"created_path\": \"/full/path\"} on success, {\"error\": \"message\"} on failure.\n\n" .
                    "📁 Path notes:\n" .
                    "- Supports absolute paths (e.g., C:\\Windows, /etc) and relative paths\n" .
                    "- Path traversals (..) are automatically resolved for security\n" .
                    "- Use '/' or '\\' as separators - both work automatically",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Directory path to create.\n" .
                                "Examples:\n" .
                                "- Windows: 'C:\\\\Projects\\\\NewFolder' or 'workspace/newdir'\n" .
                                "- Linux/Mac: '/home/user/newproject' or 'workspace/newdir'"
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => "⚠️ DANGEROUS: Permanently delete a file. This action cannot be undone.\n\n" .
                    "Returns: {\"deleted\": true} on success, {\"deleted\": false, \"error\": \"message\"} on failure.\n\n" .
                    "📁 Path notes:\n" .
                    "- Supports absolute paths (e.g., C:\\Windows, /etc) and relative paths\n" .
                    "- Path traversals (..) are automatically resolved for security\n" .
                    "- Use '/' or '\\' as separators - both work automatically\n\n" .
                    "⚠️ Always warn the user before deleting important files.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "REQUIRED: File path to delete.\n" .
                                "Examples:\n" .
                                "- Windows: 'C:\\\\Users\\\\file.txt' or 'workspace/data.txt'\n" .
                                "- Linux/Mac: '/home/user/file.txt' or 'workspace/data.txt'"
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteDirectory',
                'description' => "⚠️ DANGEROUS: Recursively delete a directory and all its contents. Cannot be undone.\n\n" .
                    "Returns: {\"deleted\": true, \"files_removed\": N} on success, {\"error\": \"message\"} on failure.\n\n" .
                    "📁 Path notes:\n" .
                    "- Supports absolute paths (e.g., C:\\Windows, /etc) and relative paths\n" .
                    "- Path traversals (..) are automatically resolved for security\n" .
                    "- Use '/' or '\\' as separators - both work automatically\n\n" .
                    "⚠️ Always warn the user before deleting directories with contents.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Directory path to delete.\n" .
                                "Examples:\n" .
                                "- Windows: 'C:\\\\Projects\\\\OldFolder' or 'workspace/temp'\n" .
                                "- Linux/Mac: '/home/user/oldproject' or 'workspace/temp'"
                        ]
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
                'description' => "Search files matching a glob pattern.\n\n" .
                    "Returns: {\"files\": [\"/path/to/file1\", \"/path/to/file2\"]} on success, {\"error\": \"message\"} on failure.\n\n" .
                    "📁 Path notes:\n" .
                    "- Supports absolute paths (e.g., C:\\Windows, /etc) and relative paths\n" .
                    "- Path traversals (..) are automatically resolved for security\n" .
                    "- Use '/' or '\\' as separators - both work automatically",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'      => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Directory path to search in.\n" .
                                "Examples:\n" .
                                "- Windows: 'C:\\\\Projects' or 'workspace/src'\n" .
                                "- Linux/Mac: '/home/user/project' or 'workspace/src'"
                        ],
                        'pattern'   => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Glob pattern.\n" .
                                "Examples:\n" .
                                "- '*.php' - all PHP files\n" .
                                "- '*.{json,xml}' - JSON or XML files\n" .
                                "- 'test*' - files starting with 'test'\n" .
                                "- '**/*.txt' - all txt files recursively (when recursive=true)"
                        ],
                        'recursive' => [
                            'type'        => 'boolean',
                            'description' => "If true, search subdirectories recursively. Default: false"
                        ]
                    ],
                    'required'   => ['path', 'pattern']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => "Recursively copy a directory. Fails if destination already exists.\n\n" .
                    "Returns: {\"copied_files\": N, \"destination\": \"/path\"} on success, {\"error\": \"message\"} on failure.\n\n" .
                    "📁 Path notes:\n" .
                    "- Supports absolute paths (e.g., C:\\Windows, /etc) and relative paths\n" .
                    "- Path traversals (..) are automatically resolved for security\n" .
                    "- Use '/' or '\\' as separators - both work automatically\n\n" .
                    "⚠️ Destination must not exist before copying to prevent accidental overwrites.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src' => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Source directory path.\n" .
                                "Examples:\n" .
                                "- Windows: 'C:\\\\Projects\\\\MyApp' or 'workspace/src'\n" .
                                "- Linux/Mac: '/home/user/project' or 'workspace/src'"
                        ],
                        'dst' => [
                            'type'        => 'string',
                            'description' => "REQUIRED: Destination directory path (must not exist).\n" .
                                "Examples:\n" .
                                "- Windows: 'C:\\\\Backups\\\\MyApp' or 'workspace/backup'\n" .
                                "- Linux/Mac: '/home/user/backup' or 'workspace/backup'"
                        ]
                    ],
                    'required'   => ['src', 'dst']
                ]
            ]
        ]
    ];
}