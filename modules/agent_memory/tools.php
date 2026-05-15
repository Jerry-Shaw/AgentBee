<?php

/**
 * Agent Memory Metadata for AgentBee
 *
 * Provides system/important/daily/ram memory storage using JSONL format (RAM for high-frequency).
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

namespace modules\agent_memory;

class tools
{
    public const META = [
        // ==================== save ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save',
                'description' => "保存记忆到指定层级。\n\n" .
                    "📝 **参数要求**：\n" .
                    "- `level`（必填）：`system`, `important`, `daily`, `ram`\n" .
                    "- `role`（必填）：`user`, `assistant`, `system`, `tool`\n" .
                    "- `content`（必填）：记忆内容（纯文本），不能为空字符串。\n\n" .
                    "⚠️ **注意**：`arguments` 必须是完整的 JSON 对象，不能是空字符串。\n\n" .
                    "✅ **正确示例**：\n" .
                    "```json\n" .
                    "{\"level\":\"important\", \"role\":\"user\", \"content\":\"用户偏好 Python\"}\n" .
                    "{\"level\":\"ram\", \"role\":\"tool\", \"content\":\"API 返回结果：{...}\"}\n" .
                    "```\n\n" .
                    "❌ **错误示例**：\n" .
                    "```json\n" .
                    "\"\"                           // 空 arguments\n" .
                    "{\"level\":\"daily\"}           // 缺少 role 和 content\n" .
                    "{\"role\":\"user\",\"content\":\"...\"}  // 缺少 level\n" .
                    "```\n\n" .
                    "📤 **返回**：`{\"saved\": true, \"path\": \"文件路径或ram://memory\", \"role\": \"实际存储的角色\"}`",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'   => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram']],
                        'role'    => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool']],
                        'content' => ['type' => 'string']
                    ],
                    'required'   => ['level', 'role', 'content']
                ]
            ]
        ],

        // ==================== read ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read',
                'description' => "读取指定层级的记忆，支持分页和 daily 日期过滤。\n\n" .
                    "📝 **参数**：\n" .
                    "- `level`（必填）：`system`, `important`, `daily`, `ram`\n" .
                    "- `offset`（可选，默认0）\n" .
                    "- `length`（可选，默认100，0=全部）\n" .
                    "- `date`（可选，仅 daily，格式 YYYYMMDD）\n\n" .
                    "✅ **正确示例**：\n" .
                    "```json\n" .
                    "{\"level\":\"daily\", \"offset\":0, \"length\":50}\n" .
                    "{\"level\":\"ram\", \"length\":0}\n" .
                    "```\n\n" .
                    "❌ **错误**：`{\"level\":\"invalid\"}` 或空 arguments\n\n" .
                    "📤 **返回**：`{\"messages\": [{\"role\":\"...\",\"content\":\"...\"}], \"total\": N}`",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'  => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram']],
                        'offset' => ['type' => 'integer', 'default' => 0],
                        'length' => ['type' => 'integer', 'default' => 100],
                        'date'   => ['type' => 'string', 'pattern' => '^\\d{8}$', 'description' => '仅 daily 层使用']
                    ],
                    'required'   => ['level']
                ]
            ]
        ],

        // ==================== search ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search',
                'description' => "在记忆中搜索关键词，支持跨层级、AND/OR、日期范围。\n\n" .
                    "📝 **参数**：\n" .
                    "- `level`（必填）：`system`,`important`,`daily`,`ram`,`all`\n" .
                    "- `keywords`（必填）：字符串数组，至少一个关键词，不能为空数组\n" .
                    "- `mode`（可选，`or`/`and`，默认`or`）\n" .
                    "- `offset`, `length`（分页）\n" .
                    "- `start_date`, `end_date`（仅 daily 层）\n\n" .
                    "✅ **正确示例**：\n" .
                    "```json\n" .
                    "{\"level\":\"all\", \"keywords\":[\"Python\",\"配置\"], \"mode\":\"or\"}\n" .
                    "{\"level\":\"daily\", \"keywords\":[\"登录\",\"失败\"], \"mode\":\"and\", \"start_date\":\"20260501\"}\n" .
                    "```\n\n" .
                    "❌ **错误**：\n" .
                    "```json\n" .
                    "\"\"                               // 空 arguments\n" .
                    "{\"level\":\"daily\"}               // 缺少 keywords\n" .
                    "{\"keywords\":[\"test\"]}          // 缺少 level\n" .
                    "{\"level\":\"all\", \"keywords\":[]} // keywords 不能为空数组\n" .
                    "```\n\n" .
                    "📤 **返回**：同 `read()`：`{\"messages\": [...], \"total\": N}`",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram', 'all']],
                        'keywords'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '关键词列表，至少一个'],
                        'mode'       => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'],
                        'offset'     => ['type' => 'integer', 'default' => 0],
                        'length'     => ['type' => 'integer', 'default' => 100],
                        'start_date' => ['type' => 'string', 'pattern' => '^\\d{8}$', 'description' => '仅 daily 层'],
                        'end_date'   => ['type' => 'string', 'pattern' => '^\\d{8}$', 'description' => '仅 daily 层']
                    ],
                    'required'   => ['level', 'keywords']
                ]
            ]
        ]
    ];
}