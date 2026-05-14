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
        // ==================== 保存记忆 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save',
                'description' => "保存一条记忆到指定的层级（追加写入，JSONL 格式）。\n\n" .
                    "**【重要】记忆浓缩原则（务必遵守）**：\n" .
                    "1. 无意义的对话（如“你好”、“谢谢”、“好的”、“知道了”）**不要保存**。\n" .
                    "2. 保存前必须**提炼浓缩**，只保留核心信息，丢弃冗余和无关内容。\n" .
                    "3. 对于 system 和 important 层，要保存的是**可复用的规则、关键事实**，而不是对话流水账。\n" .
                    "4. 对于 daily 层，也应总结多轮对话的要点，而不是逐字逐句存储。\n" .
                    "5. 示例：\n" .
                    "   - 原始对话：“用户说他的服务器是 CentOS 7，内存 8G，硬盘 100G，之前装过 Nginx 但配置不对。”\n" .
                    "   - 浓缩后保存：“用户服务器：CentOS 7, 8G RAM, 100G HDD, Nginx 配置异常。”\n" .
                    "   - 无意义内容：“用户说好的”、“明白了”、“谢谢” → **不保存**。\n\n" .
                    "**各层级说明**：\n" .
                    "- system：系统级记忆，永久保存。存放 AI 的角色设定、行为准则、全局指令等。应尽量精简。\n" .
                    "- important：重要记忆，永久保存。存放用户的个人身份、项目配置、关键决策、代码规范等。必须提炼。\n" .
                    "- daily：日常记忆，按日期分文件。存放对话要点、任务记录、临时上下文。也要提炼。\n\n" .
                    "**成功返回示例**：{\"saved\": true, \"path\": \"/path/to/system.txt\"}\n" .
                    "**失败返回示例**：{\"error\": \"Invalid level: xxx\"}\n\n" .
                    "**参数说明**：\n" .
                    "- level：必填，记忆层级，可选值 'system', 'important', 'daily'。\n" .
                    "- role：必填，消息角色，可选值 'user', 'assistant', 'system'。\n" .
                    "- content：必填，**经过提炼后的**记忆内容（纯文本）。\n\n" .
                    "**正确示例**：\n" .
                    "- 保存用户偏好：{\"level\":\"important\", \"role\":\"user\", \"content\":\"用户偏好 Python，不喜 Java\"}\n" .
                    "- 保存系统规则：{\"level\":\"system\", \"role\":\"system\", \"content\":\"回答优先提供代码示例\"}\n" .
                    "- 保存对话要点：{\"level\":\"daily\", \"role\":\"assistant\", \"content\":\"用户询问 SQL 优化，建议添加索引\"}\n\n" .
                    "**错误示例（不要这样做）**：\n" .
                    "- 保存无意义内容：{\"level\":\"daily\", \"role\":\"user\", \"content\":\"好的谢谢\"}\n" .
                    "- 保存冗长原文：直接保存原始对话日志。\n\n" .
                    "**注意**：\n" .
                    "- 不要保存重复或冗余的信息，避免记忆膨胀。\n" .
                    "- 系统级记忆应尽量精简，只放核心行为规则。\n" .
                    "- 如果一条信息已经存在于记忆中，且没有变化，不要重复保存相同内容。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'   => [
                            'type'        => 'string',
                            'description' => "记忆层级：'system'（系统规则/角色设定）、'important'（重要信息）、'daily'（日常要点）",
                            'enum'        => ['system', 'important', 'daily']
                        ],
                        'role'    => [
                            'type'        => 'string',
                            'description' => "消息角色：'user'（用户）、'assistant'（AI）、'system'（系统）",
                            'enum'        => ['user', 'assistant', 'system']
                        ],
                        'content' => [
                            'type'        => 'string',
                            'description' => "经过提炼浓缩的记忆内容（纯文本），只保留核心信息"
                        ]
                    ],
                    'required'   => ['level', 'role', 'content']
                ]
            ]
        ],

        // ==================== 读取记忆 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read',
                'description' => "读取指定层级的记忆内容，支持分页和按日期查询（仅 daily 有效）。\n\n" .
                    "**使用场景**：\n" .
                    "- 在对话开始时，读取 system 和 important 层的记忆，获取全局规则和用户关键信息。\n" .
                    "- 当需要回顾最近的对话要点或任务记录时，读取 daily 层（可指定日期）。\n" .
                    "- 当需要获取大量历史记忆时，建议使用分页参数（offset + length）避免一次返回过多内容。\n\n" .
                    "**成功返回示例**：\n" .
                    "{\n" .
                    "  \"messages\": [\n" .
                    "    {\"role\": \"user\", \"content\": \"用户偏好 Python，不喜 Java\"},\n" .
                    "    {\"role\": \"assistant\", \"content\": \"已记住偏好\"}\n" .
                    "  ],\n" .
                    "  \"total\": 150\n" .
                    "}\n" .
                    "**失败返回示例**：{\"error\": \"Cannot open file: ...\"}\n\n" .
                    "**参数说明**：\n" .
                    "- level：必填，记忆层级（system / important / daily）。\n" .
                    "- offset：可选，起始行号（从0开始），默认 0。用于分页。\n" .
                    "- length：可选，最大返回行数，默认 100。如果设为 0，则返回全部（请谨慎使用）。\n" .
                    "- date：可选，仅当 level='daily' 时有效。日期格式 YYYYMMDD，例如 20260514。默认当天。\n\n" .
                    "**示例**：\n" .
                    "读取最近的10条日常要点：{\"level\":\"daily\", \"length\":10}\n" .
                    "读取2026年5月14日的日常记忆第20-29条：{\"level\":\"daily\", \"date\":\"20260514\", \"offset\":20, \"length\":10}\n" .
                    "读取所有重要记忆：{\"level\":\"important\", \"length\":0}\n\n" .
                    "**注意**：\n" .
                    "- 返回的 messages 数组可以直接作为 LLM 的上下文参数使用。\n" .
                    "- 对于 system 和 important 层，因为通常内容不多，建议一次性读取全部（length=0）。\n" .
                    "- 对于 daily 层，建议使用分页或限定日期，避免上下文过长。\n" .
                    "- 由于记忆已经过提炼，读取后可直接理解核心信息。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'  => [
                            'type'        => 'string',
                            'description' => "记忆层级：'system', 'important', 'daily'",
                            'enum'        => ['system', 'important', 'daily']
                        ],
                        'offset' => [
                            'type'        => 'integer',
                            'description' => "起始偏移量（行号），默认 0",
                            'default'     => 0
                        ],
                        'length' => [
                            'type'        => 'integer',
                            'description' => "最大返回行数，默认 100；设为 0 表示返回全部",
                            'default'     => 100
                        ],
                        'date'   => [
                            'type'        => 'string',
                            'description' => "仅当 level='daily' 时有效，日期 YYYYMMDD，默认当天",
                            'pattern'     => '^\d{8}$'
                        ]
                    ],
                    'required'   => ['level']
                ]
            ]
        ],

        // ==================== 搜索记忆 ====================
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search',
                'description' => "按关键词搜索记忆（支持跨层级、AND/OR 模式、日期范围过滤）。\n\n" .
                    "**使用场景**：\n" .
                    "- 当需要查找某个主题或关键词的历史记录时，使用此方法。\n" .
                    "- 例如：查找过去关于“数据库优化”的所有对话要点。\n" .
                    "- 例如：查找用户曾经提到的“喜欢的颜色”或“年龄”。\n" .
                    "- 当不确定信息保存在哪一层时，可将 level 设为 'all' 进行全局搜索。\n\n" .
                    "**成功返回示例**：\n" .
                    "{\n" .
                    "  \"messages\": [\n" .
                    "    {\"role\": \"user\", \"content\": \"我的年龄是 25 岁\"},\n" .
                    "    {\"role\": \"assistant\", \"content\": \"已记住您的年龄\"}\n" .
                    "  ],\n" .
                    "  \"total\": 8\n" .
                    "}\n" .
                    "**失败返回示例**：{\"error\": \"Keywords cannot be empty\"}\n\n" .
                    "**参数说明**：\n" .
                    "- level：必填，搜索范围。可选值 'system', 'important', 'daily', 'all'（全部层级）。\n" .
                    "- keywords：必填，关键词数组。例如 [\"Python\", \"配置\"]。\n" .
                    "- mode：可选，匹配模式。'or'（任一关键词匹配，默认）或 'and'（所有关键词必须同时匹配）。\n" .
                    "  推荐使用 'or' 模式提高命中率；当需要精确匹配时使用 'and'。\n" .
                    "- offset：可选，结果偏移量，默认 0。用于分页。\n" .
                    "- length：可选，最大返回结果数，默认 100；设为 0 表示返回全部。\n" .
                    "- start_date：可选，仅对 daily 和 all 有效。起始日期 YYYYMMDD，包含该日期。\n" .
                    "- end_date：可选，仅对 daily 和 all 有效。结束日期 YYYYMMDD，包含该日期。\n\n" .
                    "**示例**：\n" .
                    "搜索所有记忆中包含“性能”或“慢查询”的：\n" .
                    "  {\"level\":\"all\", \"keywords\":[\"性能\",\"慢查询\"], \"mode\":\"or\"}\n" .
                    "搜索重要记忆中同时包含“密码”和“不能泄露”的：\n" .
                    "  {\"level\":\"important\", \"keywords\":[\"密码\",\"不能泄露\"], \"mode\":\"and\"}\n" .
                    "搜索2026年5月1日至5月7日的日常记忆中提及“bug”的记录：\n" .
                    "  {\"level\":\"daily\", \"keywords\":[\"bug\"], \"start_date\":\"20260501\", \"end_date\":\"20260507\"}\n\n" .
                    "**注意**：\n" .
                    "- 搜索不区分大小写。\n" .
                    "- 返回的 messages 数组可直接作为 LLM 上下文使用。\n" .
                    "- 搜索返回的结果已经按时间顺序排列（从旧到新）。\n" .
                    "- 当搜索结果较多时，建议使用 offset 和 length 分页获取。\n" .
                    "- 如果明确知道信息存储的层级，请指定 level 而非 'all'，以提高效率。\n" .
                    "- 由于记忆已浓缩，搜索结果更加精准。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => [
                            'type'        => 'string',
                            'description' => "搜索范围：'system', 'important', 'daily', 'all'",
                            'enum'        => ['system', 'important', 'daily', 'all']
                        ],
                        'keywords'   => [
                            'type'        => 'array',
                            'description' => "关键词数组，例如 [\"Python\", \"优化\"]",
                            'items'       => ['type' => 'string']
                        ],
                        'mode'       => [
                            'type'        => 'string',
                            'description' => "匹配模式：'or'（任一匹配，默认）或 'and'（全部匹配）",
                            'enum'        => ['or', 'and'],
                            'default'     => 'or'
                        ],
                        'offset'     => [
                            'type'        => 'integer',
                            'description' => "结果偏移量，用于分页，默认 0",
                            'default'     => 0
                        ],
                        'length'     => [
                            'type'        => 'integer',
                            'description' => "最大返回结果数，默认 100；0 表示全部",
                            'default'     => 100
                        ],
                        'start_date' => [
                            'type'        => 'string',
                            'description' => "起始日期 YYYYMMDD，仅对 daily/all 有效（包含）",
                            'pattern'     => '^\d{8}$'
                        ],
                        'end_date'   => [
                            'type'        => 'string',
                            'description' => "结束日期 YYYYMMDD，仅对 daily/all 有效（包含）",
                            'pattern'     => '^\d{8}$'
                        ]
                    ],
                    'required'   => ['level', 'keywords']
                ]
            ]
        ]
    ];
}