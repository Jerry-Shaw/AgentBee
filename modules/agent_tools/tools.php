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
        // ==================== 执行系统命令 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'exec',
                'description' => "⚠️ 危险操作：执行系统命令\n\n" .
                    "🔴 核心规则：\n" .
                    "1. 'program' 只能是可执行文件的路径或文件名，如 'powershell'、'ls'、'git'\n" .
                    "2. 禁止在 'program' 中写参数，也禁止使用 cmd 内置命令（如 dir、echo、type、cd）\n" .
                    "3. 所有参数必须放在 'argv' 数组中\n\n" .
                    "✅ 正确示例：\n" .
                    "   - program='powershell', argv=['-Command', 'Get-ChildItem']\n" .
                    "   - program='ls', argv=['-la', '/home']\n" .
                    "   - program='git', argv=['status']\n\n" .
                    "❌ 错误示例（会导致执行失败）：\n" .
                    "   - program='powershell -Command Get-ChildItem'  ← 参数写进了 program\n" .
                    "   - program='dir'                               ← dir 是 cmd 内置命令，不是可执行文件\n" .
                    "   - program='echo hello'                         ← echo 不是可执行文件\n\n" .
                    "📌 Windows 请始终使用 'powershell' 作为 program\n" .
                    "📌 Linux/Mac 请使用标准命令如 'ls', 'cat', 'git', 'python3' 等\n\n" .
                    "其他参数：\n" .
                    "- 'path'：可选，工作目录，默认使用工作区路径\n\n" .
                    "安全规则：\n" .
                    "- 禁止使用危险命令：rm -rf, del /f /s, format, shutdown\n" .
                    "- 避免使用管道符：|, >, <, &&, ||\n" .
                    "- 优先使用专用工具（readFile、writeFile、listDirectory 等）",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program' => [
                            'type'        => 'string',
                            'description' => "必填：可执行文件路径或文件名，不能是 cmd 内置命令，不能带参数\n" .
                                "✅ 正确：'powershell', 'ls', 'git', 'python3'\n" .
                                "❌ 错误：'dir', 'echo', 'type', 'cd', 'mkdir'"
                        ],
                        'argv'    => [
                            'type'        => 'array',
                            'description' => "必填：参数数组，没有参数时传 []\n" .
                                "✅ 正确：['-Command', 'Get-ChildItem']\n" .
                                "✅ 正确：['-la', '/home']\n" .
                                "❌ 错误：把参数写在 program 里",
                            'items'       => ['type' => 'string']
                        ],
                        'path'    => [
                            'type'        => 'string',
                            'description' => "可选：工作目录，默认使用工作区路径"
                        ]
                    ],
                    'required'   => ['program', 'argv']
                ]
            ]
        ],

        // ==================== 读取文件 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readFile',
                'description' => "读取文件内容\n\n" .
                    "成功返回：{\"content\": \"文件内容\"}\n" .
                    "失败返回：{\"error\": \"错误信息\"}\n\n" .
                    "参数：\n" .
                    "- 'path'：必填，文件路径\n" .
                    "- 'offset'：可选，从第几个字节开始读，默认 0\n" .
                    "- 'limit'：可选，最多读多少字节，默认 8192（8KB）",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => [
                            'type'        => 'string',
                            'description' => "必填：文件路径"
                        ],
                        'offset' => [
                            'type'        => 'integer',
                            'description' => "可选，从第几个字节开始读，默认 0"
                        ],
                        'limit'  => [
                            'type'        => 'integer',
                            'description' => "可选，最多读多少字节，默认 8192（8KB）"
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],

        // ==================== 写入文件 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeFile',
                'description' => "写入内容到文件，目录不存在会自动创建\n\n" .
                    "成功返回：{\"bytes_written\": 写入字节数}\n" .
                    "失败返回：{\"error\": \"错误信息\"}\n\n" .
                    "参数：\n" .
                    "- 'path'：必填，文件路径\n" .
                    "- 'content'：必填，要写入的内容\n" .
                    "- 'append'：可选，是否追加到文件末尾，默认 false（覆盖写入）",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'    => [
                            'type'        => 'string',
                            'description' => "必填：文件路径"
                        ],
                        'content' => [
                            'type'        => 'string',
                            'description' => "必填：要写入的内容"
                        ],
                        'append'  => [
                            'type'        => 'boolean',
                            'description' => "可选，是否追加到文件末尾，默认 false"
                        ]
                    ],
                    'required'   => ['path', 'content']
                ]
            ]
        ],

        // ==================== 列出目录 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => "列出目录内容\n\n" .
                    "成功返回：{\"contents\": [{\"filename\": \"文件名\", \"filepath\": \"完整路径\", \"filesize\": 大小, \"isFile\": 是否文件}]}\n" .
                    "失败返回：{\"error\": \"错误信息\"}\n\n" .
                    "参数：\n" .
                    "- 'path'：必填，目录路径",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "必填：目录路径"
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],

        // ==================== 创建目录 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => "创建目录，会自动创建父目录\n\n" .
                    "成功返回：{\"created_path\": \"创建的完整路径\"}\n" .
                    "失败返回：{\"error\": \"错误信息\"}\n\n" .
                    "参数：\n" .
                    "- 'path'：必填，目录路径",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "必填：目录路径"
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],

        // ==================== 删除文件 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => "⚠️ 危险操作：永久删除文件，无法撤销\n\n" .
                    "成功返回：{\"deleted\": true}\n" .
                    "失败返回：{\"deleted\": false, \"error\": \"错误信息\"}\n\n" .
                    "参数：\n" .
                    "- 'path'：必填，文件路径\n\n" .
                    "⚠️ 删除重要文件前必须警告用户",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "必填：文件路径"
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],

        // ==================== 删除目录 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteDirectory',
                'description' => "⚠️ 危险操作：递归删除整个目录及所有内容，无法撤销\n\n" .
                    "成功返回：{\"deleted\": true, \"files_removed\": 删除文件数}\n" .
                    "失败返回：{\"error\": \"错误信息\"}\n\n" .
                    "参数：\n" .
                    "- 'path'：必填，目录路径\n\n" .
                    "⚠️ 删除有内容的目录前必须警告用户",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => "必填：目录路径"
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],

        // ==================== 搜索文件 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'searchFiles',
                'description' => "用 glob 模式搜索文件\n\n" .
                    "成功返回：{\"files\": [\"文件路径1\", \"文件路径2\"]}\n" .
                    "失败返回：{\"error\": \"错误信息\"}\n\n" .
                    "参数：\n" .
                    "- 'path'：必填，搜索的目录路径\n" .
                    "- 'pattern'：必填，glob 模式，如 '*.php'、'*.{json,xml}'\n" .
                    "- 'recursive'：可选，是否递归搜索子目录，默认 false",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'      => [
                            'type'        => 'string',
                            'description' => "必填：搜索的目录路径"
                        ],
                        'pattern'   => [
                            'type'        => 'string',
                            'description' => "必填：glob 模式，如 '*.php'、'*.{json,xml}'"
                        ],
                        'recursive' => [
                            'type'        => 'boolean',
                            'description' => "可选，是否递归搜索子目录，默认 false"
                        ]
                    ],
                    'required'   => ['path', 'pattern']
                ]
            ]
        ],

        // ==================== 复制目录 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => "递归复制整个目录，目标目录必须不存在\n\n" .
                    "成功返回：{\"copied_files\": 复制文件数, \"destination\": \"目标路径\"}\n" .
                    "失败返回：{\"error\": \"错误信息\"}\n\n" .
                    "参数：\n" .
                    "- 'src'：必填，源目录路径\n" .
                    "- 'dst'：必填，目标目录路径（必须不存在）\n\n" .
                    "⚠️ 目标目录必须不存在，防止意外覆盖",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src' => [
                            'type'        => 'string',
                            'description' => "必填：源目录路径"
                        ],
                        'dst' => [
                            'type'        => 'string',
                            'description' => "必填：目标目录路径（必须不存在）"
                        ]
                    ],
                    'required'   => ['src', 'dst']
                ]
            ]
        ]
    ];
}