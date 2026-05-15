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
        // 执行命令
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'exec',
                'description' => "执行系统命令（危险）。\n" .
                    "⚠️ 核心规则：\n" .
                    "1. `program` 必须是可执行文件的路径或文件名（如 'powershell', 'ls', 'git', 'python3'），**不能是 cmd 内置命令**。\n" .
                    "2. 禁止使用 cmd 内置命令：`dir`, `echo`, `type`, `cd`, `mkdir`, `copy`, `del` 等，它们不是独立可执行文件。\n" .
                    "3. 所有参数必须放在 `argv` 数组中。\n\n" .
                    "✅ 正确示例：\n" .
                    "   {\"program\":\"ls\", \"argv\":[\"-la\"], \"timeout\":10, \"work_path\":\"/home\"}\n" .
                    "   {\"program\":\"powershell\", \"argv\":[\"-Command\", \"Get-ChildItem\"], \"timeout\":30}\n" .
                    "   {\"program\":\"git\", \"argv\":[\"status\"]}\n\n" .
                    "❌ 错误示例（会导致执行失败）：\n" .
                    "   {\"program\":\"dir\", \"argv\":[]}                    ← dir 是内置命令\n" .
                    "   {\"program\":\"echo\", \"argv\":[\"hello\"]}            ← echo 是内置命令\n" .
                    "   {\"program\":\"cd\", \"argv\":[\"..\"]}                 ← cd 是内置命令\n\n" .
                    "⏱️ 超时控制：参数 `timeout`（默认300秒），命令连续无输出超过此时间将被强制终止。设置为 0 表示无超时。\n" .
                    "📁 工作目录：参数 `work_path`（可选），默认使用工作区路径。\n\n" .
                    "⚠️ 安全警告：禁止执行危险命令（rm -rf, del /f /s, format, shutdown 等），优先使用专用工具。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program'   => ['type' => 'string', 'description' => "必填：可执行文件路径或文件名，不能是 cmd 内置命令"],
                        'argv'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => "必填：参数数组，没有参数时传 []"],
                        'timeout'   => ['type' => 'integer', 'default' => 300, 'description' => "可选：空闲超时秒数，默认300，0表示无超时"],
                        'work_path' => ['type' => 'string', 'description' => "可选：工作目录，默认使用工作区路径"]
                    ],
                    'required'   => ['program', 'argv']
                ]
            ]
        ],

        // 读文件
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readFile',
                'description' => "读取文件内容。limit=0 读整个文件。返回 {\"content\":\"...\"}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => ['type' => 'string'],
                        'offset' => ['type' => 'integer', 'default' => 0],
                        'limit'  => ['type' => 'integer', 'default' => 8192]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],

        // 写文件
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeFile',
                'description' => "写入文件（自动创建目录）。append=true 追加。返回 {\"bytes_written\": N}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'    => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'append'  => ['type' => 'boolean', 'default' => false]
                    ],
                    'required'   => ['path', 'content']
                ]
            ]
        ],

        // 删文件
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => "永久删除文件（危险）。操作前需警告用户。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ],

        // 搜索文件
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'searchFiles',
                'description' => "glob 模式搜索文件。recursive=true 递归。返回 {\"files\":[...]}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'      => ['type' => 'string'],
                        'pattern'   => ['type' => 'string'],
                        'recursive' => ['type' => 'boolean', 'default' => false]
                    ],
                    'required'   => ['path', 'pattern']
                ]
            ]
        ],

        // 文件大小
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getFileSize',
                'description' => "获取文件字节数。返回 {\"filesize\": N}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ],

        // 列表目录
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => "列出目录内容。返回 {\"contents\":[{\"filename\":\"...\",\"filesize\":N,\"isFile\":bool}]}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ],

        // 创建目录
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => "创建目录（自动创建父目录）。返回 {\"created_path\":\"...\"}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ],

        // 复制目录
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => "递归复制目录，目标必须不存在。返回 {\"copied_files\":N, \"destination\":\"...\"}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src' => ['type' => 'string'],
                        'dst' => ['type' => 'string']
                    ],
                    'required'   => ['src', 'dst']
                ]
            ]
        ],

        // 删除目录
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteDirectory',
                'description' => "递归删除目录（危险）。操作前警告用户。返回 {\"deleted\":true, \"files_removed\":N}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ]
    ];
}