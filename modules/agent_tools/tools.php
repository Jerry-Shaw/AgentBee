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
                'description' => "执行系统命令（危险）。\n\n" .
                    "⚠️ **核心限制**：\n" .
                    "- `program` 必须是**独立的可执行文件**（如 `powershell.exe`, `git`, `python`, `ls`, `cat`）。\n" .
                    "- **严禁使用 cmd 内置命令**：`dir`, `echo`, `type`, `cd`, `mkdir`, `copy`, `del`, `move`, `ren`, `rd`——它们不是独立的 .exe 文件。\n" .
                    "- 如需使用内置功能，必须通过 `powershell -Command \"...\"` 或 `cmd /c \"...\"` 包装。\n\n" .
                    "📝 **参数要求**：\n" .
                    "- `argv` 必须是**字符串数组**，每个参数单独一项。无参数时传 `[]`。\n" .
                    "- **禁止将整个命令写成单个字符串**（如 `\"ls -la\"` 错误，应为 `[\"ls\", \"-la\"]`）。\n" .
                    "- `timeout`（可选，默认300秒）：空闲超时，0=无超时。\n" .
                    "- `work_path`（可选）：工作目录，默认为工作区根目录。\n\n" .
                    "✅ **正确示例**：\n" .
                    "```json\n" .
                    "{\"program\": \"powershell\", \"argv\": [\"-Command\", \"Get-ChildItem\"], \"timeout\": 30}\n" .
                    "{\"program\": \"git\", \"argv\": [\"status\"], \"work_path\": \"/project\"}\n" .
                    "```\n\n" .
                    "❌ **错误示例**：\n" .
                    "```json\n" .
                    "{\"program\": \"dir\", \"argv\": []}                       // dir 是内置命令\n" .
                    "{\"program\": \"powershell\", \"argv\": \"-Command Get-ChildItem\"}  // argv 必须是数组\n" .
                    "\"\"                                                   // 空 arguments，缺少所有字段\n" .
                    "```\n\n" .
                    "📤 **返回值**：`{\"output\": \"stdout\", \"error\": \"stderr\"}`\n\n" .
                    "⚠️ 禁止执行破坏性命令（rm -rf, del /f /s, format, shutdown 等）。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program'   => ['type' => 'string', 'description' => "必填：可执行文件路径或文件名，不能为空"],
                        'argv'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => "必填：参数数组，如 [\"-l\", \"-a\"]，不能是字符串"],
                        'timeout'   => ['type' => 'integer', 'default' => 300, 'description' => "可选：空闲超时秒数"],
                        'work_path' => ['type' => 'string', 'description' => "可选：工作目录"]
                    ],
                    'required'   => ['program', 'argv']
                ]
            ]
        ],

        // ==================== readFile ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readFile',
                'description' => "读取文件内容。\n\n" .
                    "📝 **参数要求**：\n" .
                    "- `path`（必填）：文件路径，不能为空。\n" .
                    "- `offset`（可选，默认0）：起始字节位置。\n" .
                    "- `limit`（可选，默认8192）：读取字节数，0=整个文件。\n\n" .
                    "✅ **正确示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"/var/log/app.log\", \"offset\": 100, \"limit\": 4096}\n" .
                    "```\n\n" .
                    "❌ **错误示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"\"}          // path 不能为空\n" .
                    "{\"offset\": 10}         // 缺少 path\n" .
                    "\"\"                     // 空 arguments\n" .
                    "```\n\n" .
                    "📤 **成功返回**：`{\"content\": \"文件内容\"}`  \n" .
                    "📤 **失败返回**：`{\"error\": \"错误描述\"}`",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => ['type' => 'string', 'description' => "必填：文件路径，不能为空"],
                        'offset' => ['type' => 'integer', 'default' => 0, 'description' => "可选：起始偏移字节数"],
                        'limit'  => ['type' => 'integer', 'default' => 8192, 'description' => "可选：读取字节数，0表示全部"]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],

        // ==================== writeFile ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeFile',
                'description' => "写入文件（自动创建父目录）。\n\n" .
                    "📝 **参数要求**：\n" .
                    "- `path`（必填）：目标文件路径，不能为空。\n" .
                    "- `content`（必填）：要写入的字符串内容（可以是空字符串）。\n" .
                    "- `append`（可选，默认false）：true=追加，false=覆盖。\n\n" .
                    "⚠️ **极其重要**：`arguments` 必须是一个**完整的 JSON 对象字符串**，绝对不能是空字符串 `\"\"`。\n\n" .
                    "✅ **正确示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"E:/output.txt\", \"content\": \"Hello\", \"append\": false}\n" .
                    "```\n\n" .
                    "❌ **错误示例**：\n" .
                    "```json\n" .
                    "\"\"                                 // 空 arguments\n" .
                    "{\"path\": \"/tmp/x.txt\"}             // 缺少 content\n" .
                    "{\"content\": \"data\"}                // 缺少 path\n" .
                    "```\n\n" .
                    "📤 **成功返回**：`{\"bytes_written\": N}`  \n" .
                    "📤 **失败返回**：`{\"error\": \"错误描述\"}`",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'    => ['type' => 'string', 'description' => "必填：文件路径，不能为空"],
                        'content' => ['type' => 'string', 'description' => "必填：要写入的字符串内容"],
                        'append'  => ['type' => 'boolean', 'default' => false, 'description' => "可选：是否追加"]
                    ],
                    'required'   => ['path', 'content']
                ]
            ]
        ],

        // ==================== deleteFile ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => "永久删除文件（危险）。\n\n" .
                    "📝 **参数**：`path`（必填），不能为空。\n\n" .
                    "✅ **正确示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"C:/temp/old.log\"}\n" .
                    "```\n\n" .
                    "❌ **错误示例**：`{\"path\": \"\"}` 或 `{}` 或 `\"\"`\n\n" .
                    "📤 **成功返回**：`{\"deleted\": true}`  \n" .
                    "📤 **文件不存在**：`{\"deleted\": false, \"message\": \"File does not exist\"}`\n\n" .
                    "⚠️ 操作不可逆，使用前必须向用户确认。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => "必填：文件路径，不能为空"]],
                    'required'   => ['path']
                ]
            ]
        ],

        // ==================== searchFiles ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'searchFiles',
                'description' => "使用 glob 模式搜索文件。\n\n" .
                    "📝 **参数**：\n" .
                    "- `path`（必填）：搜索起始目录。\n" .
                    "- `pattern`（必填）：文件名模式，如 `*.php`。\n" .
                    "- `recursive`（可选，默认false）：是否递归子目录。\n\n" .
                    "✅ **正确示例**：\n" .
                    "```json\n" .
                    "{\"path\": \"/home/user\", \"pattern\": \"*.txt\", \"recursive\": true}\n" .
                    "```\n\n" .
                    "❌ **错误示例**：`{\"pattern\": \"*.txt\"}`（缺少path）或空arguments\n\n" .
                    "📤 **返回**：`{\"files\": [\"/path/to/file1\", ...]}`",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'      => ['type' => 'string', 'description' => "必填：搜索起始目录"],
                        'pattern'   => ['type' => 'string', 'description' => "必填：glob 模式"],
                        'recursive' => ['type' => 'boolean', 'default' => false, 'description' => "可选：是否递归"]
                    ],
                    'required'   => ['path', 'pattern']
                ]
            ]
        ],

        // ==================== getFileSize ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getFileSize',
                'description' => "获取文件字节数。\n\n" .
                    "📝 **参数**：`path`（必填）。\n\n" .
                    "✅ **示例**：`{\"path\": \"C:/data/file.bin\"}`\n\n" .
                    "❌ **错误**：空arguments或缺少path\n\n" .
                    "📤 **成功**：`{\"filesize\": 12345}`  \n" .
                    "📤 **失败**：`{\"error\": \"File not found: path\"}`",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => "必填：文件路径"]],
                    'required'   => ['path']
                ]
            ]
        ],

        // ==================== listDirectory ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => "列出目录内容（非递归）。\n\n" .
                    "📝 **参数**：`path`（必填）。\n\n" .
                    "✅ **示例**：`{\"path\": \"/var/log\"}`\n\n" .
                    "❌ **错误**：空arguments或缺少path\n\n" .
                    "📤 **返回**：`{\"contents\": [{\"filename\":\"...\", \"filesize\":123, \"isFile\":true}, ...]}`",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => "必填：目录路径"]],
                    'required'   => ['path']
                ]
            ]
        ],

        // ==================== createDirectory ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => "创建目录（自动创建父目录）。\n\n" .
                    "📝 **参数**：`path`（必填）。\n\n" .
                    "✅ **示例**：`{\"path\": \"E:/Projects/newfolder/sub\"}`\n\n" .
                    "❌ **错误**：空arguments或缺少path\n\n" .
                    "📤 **返回**：`{\"created_path\": \"E:/Projects/newfolder/sub\"}`",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => "必填：要创建的目录路径"]],
                    'required'   => ['path']
                ]
            ]
        ],

        // ==================== copyDirectory ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => "递归复制目录（目标目录必须不存在）。\n\n" .
                    "📝 **参数**：`src`（源），`dst`（目标），均必填。\n\n" .
                    "✅ **示例**：`{\"src\": \"/backup/old\", \"dst\": \"/backup/new\"}`\n\n" .
                    "❌ **错误**：缺少src或dst，或空arguments\n\n" .
                    "📤 **成功**：`{\"copied_files\": 42, \"destination\": \"/backup/new\"}`  \n" .
                    "📤 **失败**：`{\"error\": \"...\"}`",
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

        // ==================== deleteDirectory ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteDirectory',
                'description' => "递归删除目录（危险）。\n\n" .
                    "📝 **参数**：`path`（必填）。\n\n" .
                    "✅ **示例**：`{\"path\": \"C:/temp/old_cache\"}`\n\n" .
                    "❌ **错误**：空arguments或缺少path\n\n" .
                    "📤 **成功**：`{\"deleted\": true, \"files_removed\": 128}`  \n" .
                    "📤 **目录不存在**：`{\"deleted\": false, \"message\": \"Directory does not exist\"}`\n\n" .
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