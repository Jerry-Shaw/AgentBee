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
                'description' => "执行系统命令（危险）。\n\n" .
                    "⚠️ **核心限制**：\n" .
                    "- `program` 必须是**独立的可执行文件**（如 `powershell.exe`, `git`, `python`, `ls`, `cat`）。\n" .
                    "- **严禁使用 cmd 内置命令**：`dir`, `echo`, `type`, `cd`, `mkdir`, `copy`, `del`, `move`, `ren`, `rd` 等——它们不是独立的 .exe 文件，无法直接执行。\n" .
                    "- 如需使用 cmd 内置功能，必须通过 `powershell -Command \"...\"` 或 `cmd /c \"...\"` 包装。\n\n" .
                    "📝 **参数要求**：\n" .
                    "- `argv` 必须是**字符串数组**，每个参数单独一项。无参数时传 `[]`。\n" .
                    "- `timeout`（可选，默认300秒）：命令连续无输出超过此时间将被终止。0 表示无超时。\n" .
                    "- `work_path`（可选）：工作目录，默认为 Agent 工作区根目录。\n\n" .
                    "✅ **正确示例**：\n" .
                    "```json\n" .
                    "{\"program\": \"powershell\", \"argv\": [\"-Command\", \"Get-ChildItem\"], \"timeout\": 30}\n" .
                    "{\"program\": \"git\", \"argv\": [\"status\"], \"work_path\": \"/project\"}\n" .
                    "{\"program\": \"python\", \"argv\": [\"script.py\", \"--verbose\"]}\n" .
                    "```\n\n" .
                    "❌ **错误示例（禁止）**：\n" .
                    "```json\n" .
                    "{\"program\": \"dir\", \"argv\": []}        // dir 是内置命令\n" .
                    "{\"program\": \"echo\", \"argv\": [\"hello\"]} // echo 是内置命令\n" .
                    "{\"program\": \"cd\", \"argv\": [\"..\"]}      // cd 是内置命令\n" .
                    "```\n\n" .
                    "📤 **返回值格式**：\n" .
                    "```json\n" .
                    "{\"output\": \"命令标准输出\", \"error\": \"命令标准错误输出\"}\n" .
                    "```\n\n" .
                    "⚠️ 安全警告：禁止执行破坏性命令（rm -rf, del /f /s, format, shutdown 等）。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program'   => ['type' => 'string', 'description' => "必填：可执行文件路径或文件名（如 'powershell', 'git', 'python'）。不能是 cmd 内置命令。"],
                        'argv'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => "必填：参数数组，没有参数时传 []。"],
                        'timeout'   => ['type' => 'integer', 'default' => 300, 'description' => "可选：空闲超时秒数，默认300，0表示无超时。"],
                        'work_path' => ['type' => 'string', 'description' => "可选：工作目录，默认使用工作区路径。"]
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
                'description' => "读取文件内容。\n\n" .
                    "📝 **参数要求**：\n" .
                    "- `path`（必填）：文件路径。\n" .
                    "- `offset`（可选，默认0）：起始字节位置。\n" .
                    "- `limit`（可选，默认8192）：读取字节数，设为 0 表示读取整个文件。\n\n" .
                    "✅ **示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"C:/logs/app.log\", \"offset\": 100, \"limit\": 4096}\n" .
                    "```\n\n" .
                    "📤 **返回值格式**：\n" .
                    "```json\n" .
                    "{\"content\": \"文件内容字符串\"}\n" .
                    "```\n" .
                    "失败时返回 `{\"error\": \"错误描述\"}`。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => ['type' => 'string', 'description' => "必填：文件路径"],
                        'offset' => ['type' => 'integer', 'default' => 0, 'description' => "可选：起始偏移字节数"],
                        'limit'  => ['type' => 'integer', 'default' => 8192, 'description' => "可选：读取字节数，0表示全部"]
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
                'description' => "写入文件（自动创建父目录）。\n\n" .
                    "📝 **参数要求**：\n" .
                    "- `path`（必填）：目标文件路径。\n" .
                    "- `content`（必填）：要写入的字符串内容。\n" .
                    "- `append`（可选，默认 false）：设为 true 时追加内容，否则覆盖。\n\n" .
                    "✅ **示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"E:/Projects/output.txt\", \"content\": \"Hello World\", \"append\": false}\n" .
                    "```\n\n" .
                    "📤 **返回值格式**：\n" .
                    "```json\n" .
                    "{\"bytes_written\": 11}\n" .
                    "```\n" .
                    "失败时返回 `{\"error\": \"错误描述\"}`。\n\n" .
                    "⚠️ 注意：`arguments` 必须是一个完整的 JSON 对象，包含上述字段。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'    => ['type' => 'string', 'description' => "必填：文件路径"],
                        'content' => ['type' => 'string', 'description' => "必填：要写入的字符串内容"],
                        'append'  => ['type' => 'boolean', 'default' => false, 'description' => "可选：是否追加"]
                    ],
                    'required'   => ['path', 'content']
                ]
            ]
        ],

        // 删除文件
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => "永久删除文件（危险）。\n\n" .
                    "📝 **参数**：\n" .
                    "- `path`（必填）：要删除的文件路径。\n\n" .
                    "✅ **示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"C:/temp/old.log\"}\n" .
                    "```\n\n" .
                    "📤 **返回值**：\n" .
                    "```json\n" .
                    "{\"deleted\": true}\n" .
                    "```\n" .
                    "若文件不存在，返回 `{\"deleted\": false, \"message\": \"File does not exist\"}`。\n\n" .
                    "⚠️ 操作不可逆，使用前需向用户确认。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => "必填：文件路径"]],
                    'required'   => ['path']
                ]
            ]
        ],

        // 搜索文件
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'searchFiles',
                'description' => "使用 glob 模式搜索文件。\n\n" .
                    "📝 **参数**：\n" .
                    "- `path`（必填）：搜索起始目录。\n" .
                    "- `pattern`（必填）：文件名模式，支持通配符 `*` 和 `?`。\n" .
                    "- `recursive`（可选，默认 false）：是否递归子目录。\n\n" .
                    "✅ **示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"/home/user\", \"pattern\": \"*.txt\", \"recursive\": true}\n" .
                    "```\n\n" .
                    "📤 **返回值**：\n" .
                    "```json\n" .
                    "{\"files\": [\"/home/user/doc.txt\", \"/home/user/notes.txt\"]}\n" .
                    "```",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'      => ['type' => 'string', 'description' => "必填：搜索起始目录"],
                        'pattern'   => ['type' => 'string', 'description' => "必填：glob 模式，如 '*.php'"],
                        'recursive' => ['type' => 'boolean', 'default' => false, 'description' => "可选：是否递归子目录"]
                    ],
                    'required'   => ['path', 'pattern']
                ]
            ]
        ],

        // 获取文件大小
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getFileSize',
                'description' => "获取文件字节数。\n\n" .
                    "📝 **参数**：`path`（必填）。\n\n" .
                    "✅ **示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"C:/data/file.bin\"}\n" .
                    "```\n\n" .
                    "📤 **返回值**：\n" .
                    "```json\n" .
                    "{\"filesize\": 1048576}\n" .
                    "```\n" .
                    "失败返回 `{\"error\": \"File not found: path\"}`。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => "必填：文件路径"]],
                    'required'   => ['path']
                ]
            ]
        ],

        // 列出目录内容
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => "列出目录内容（非递归）。\n\n" .
                    "📝 **参数**：`path`（必填）。\n\n" .
                    "✅ **示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"/var/log\"}\n" .
                    "```\n\n" .
                    "📤 **返回值**：\n" .
                    "```json\n" .
                    "{\"contents\": [{\"filename\": \"file.txt\", \"filesize\": 1234, \"isFile\": true}, ...]}\n" .
                    "```",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => "必填：目录路径"]],
                    'required'   => ['path']
                ]
            ]
        ],

        // 创建目录
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => "创建目录（自动创建父目录）。\n\n" .
                    "📝 **参数**：`path`（必填）。\n\n" .
                    "✅ **示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"E:/Projects/newfolder/sub\"}\n" .
                    "```\n\n" .
                    "📤 **返回值**：\n" .
                    "```json\n" .
                    "{\"created_path\": \"E:/Projects/newfolder/sub\"}\n" .
                    "```",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => "必填：要创建的目录路径"]],
                    'required'   => ['path']
                ]
            ]
        ],

        // 复制目录
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => "递归复制目录（目标目录必须不存在）。\n\n" .
                    "📝 **参数**：\n" .
                    "- `src`（必填）：源目录路径。\n" .
                    "- `dst`（必填）：目标目录路径（不能已存在）。\n\n" .
                    "✅ **示例**：\n" .
                    "```json\n" .
                    "{\"src\": \"/backup/old\", \"dst\": \"/backup/new\"}\n" .
                    "```\n\n" .
                    "📤 **返回值**：\n" .
                    "```json\n" .
                    "{\"copied_files\": 42, \"destination\": \"/backup/new\"}\n" .
                    "```\n" .
                    "失败返回 `{\"error\": \"...\"}`。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src' => ['type' => 'string', 'description' => "必填：源目录路径"],
                        'dst' => ['type' => 'string', 'description' => "必填：目标目录路径，必须不存在"]
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
                'description' => "递归删除目录（危险）。\n\n" .
                    "📝 **参数**：`path`（必填）。\n\n" .
                    "✅ **示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"C:/temp/old_cache\"}\n" .
                    "```\n\n" .
                    "📤 **返回值**：\n" .
                    "```json\n" .
                    "{\"deleted\": true, \"files_removed\": 128}\n" .
                    "```\n" .
                    "若目录不存在，返回 `{\"deleted\": false, \"message\": \"Directory does not exist\"}`。\n\n" .
                    "⚠️ 操作不可逆，使用前必须向用户确认。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => "必填：要删除的目录路径"]],
                    'required'   => ['path']
                ]
            ]
        ]
    ];
}