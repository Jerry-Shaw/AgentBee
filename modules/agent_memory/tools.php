<?php

/**
 * Agent Memory Metadata for AgentBee
 *
 *  Provides system/important/daily memory storage using JSONL format.
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
        // 保存记忆
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save',
                'description' => "保存记忆到 system/important/daily 层（JSONL 追加）。\n\n" .
                    "规则：只保存提炼后的核心信息，不保存“你好/谢谢”等无意义内容。\n" .
                    "system：角色设定/行为规则，永久。important：用户关键信息（偏好/配置），永久。daily：日常要点（按日期分文件）。\n" .
                    "参数：level(string), role(user/assistant/system), content(string)。\n" .
                    "注意：role 的值需与 level 匹配—— level=system 时 role 必须为 system；level=important 时 role 应为 user；level=daily 时 role 可为 user/assistant。\n" .
                    "示例：{\"level\":\"important\",\"role\":\"user\",\"content\":\"用户偏好 Python\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'   => ['type' => 'string', 'enum' => ['system', 'important', 'daily']],
                        'role'    => ['type' => 'string', 'enum' => ['user', 'assistant', 'system']],
                        'content' => ['type' => 'string']
                    ],
                    'required'   => ['level', 'role', 'content']
                ]
            ]
        ],

        // 读取记忆
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read',
                'description' => "读取指定层级的记忆，支持分页（offset/length）和 daily 日期过滤。\n" .
                    "返回 {\"messages\":[...], \"total\": N}，messages 可直接作为 LLM 上下文。\n" .
                    "示例：{\"level\":\"daily\", \"date\":\"20260514\", \"offset\":0, \"length\":20}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'  => ['type' => 'string', 'enum' => ['system', 'important', 'daily']],
                        'offset' => ['type' => 'integer', 'default' => 0],
                        'length' => ['type' => 'integer', 'default' => 100],
                        'date'   => ['type' => 'string', 'pattern' => '^\d{8}$']
                    ],
                    'required'   => ['level']
                ]
            ]
        ],

        // 搜索记忆
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search',
                'description' => "搜索记忆（跨层级，关键词数组，mode='or'/'and'，日期范围可选）。\n" .
                    "返回 {\"messages\":[...], \"total\": N}。不区分大小写。\n" .
                    "示例：{\"level\":\"all\", \"keywords\":[\"Python\",\"配置\"], \"start_date\":\"20260501\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'all']],
                        'keywords'   => ['type' => 'array', 'items' => ['type' => 'string']],
                        'mode'       => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'],
                        'offset'     => ['type' => 'integer', 'default' => 0],
                        'length'     => ['type' => 'integer', 'default' => 100],
                        'start_date' => ['type' => 'string', 'pattern' => '^\d{8}$'],
                        'end_date'   => ['type' => 'string', 'pattern' => '^\d{8}$']
                    ],
                    'required'   => ['level', 'keywords']
                ]
            ]
        ]
    ];
}