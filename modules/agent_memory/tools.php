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
        // 保存记忆
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save',
                'description' => "保存记忆到指定层级（system/important/daily/ram），JSONL 格式追加。\n\n" .
                    "规则：只保存提炼后的核心信息，不保存“你好/谢谢”等无意义内容。\n\n" .
                    "层级说明：\n" .
                    "- system：系统级设定/行为规则，角色强制为 system，永久存储（文件）。\n" .
                    "- important：用户关键信息（偏好/配置/身份），角色强制为 user，永久存储（文件）。\n" .
                    "- daily：日常对话要点，按日期分文件存储，角色可为 user/assistant/tool，用于构建短期/中期记忆。\n" .
                    "- ram：内存存储，高速读写，不持久化，重启后丢失。适合高频临时记忆、会话内上下文保持。ram 层不区分日期，角色可为任意合法值（user/assistant/system/tool）。\n\n" .
                    "角色说明：\n" .
                    "- user：用户的输入或提问。\n" .
                    "- assistant：助手的回复或思考。\n" .
                    "- system：系统指令或元信息（仅在 system 层强制使用）。\n" .
                    "- tool：工具调用的执行结果（如 API 返回、计算输出等）。\n\n" .
                    "参数说明：\n" .
                    "- level(string): 记忆层级，可选 system/important/daily/ram。\n" .
                    "- role(string): 消息角色，必须根据实际发言人设置（user/assistant/system/tool）。注意：system/important 层级会强制覆盖为固定值（无需调用方担心）。\n" .
                    "- content(string): 记忆内容（纯文本）。\n\n" .
                    "返回结果：{\"saved\": true, \"path\": \"文件路径或ram://memory\", \"role\": \"实际存储的角色\"}\n" .
                    "建议：高频写入（如每轮对话的工具结果）使用 ram 层；长期重要信息使用 important 或 system 层。\n\n" .
                    "示例1（重要偏好）：{\"level\":\"important\",\"role\":\"user\",\"content\":\"用户偏好 Python 开发，使用 VSCode\"}\n" .
                    "示例2（日常对话）：{\"level\":\"daily\",\"role\":\"assistant\",\"content\":\"用户询问了关于 RAM 存储的实现细节\"}\n" .
                    "示例3（工具调用结果存入 ram）：{\"level\":\"ram\",\"role\":\"tool\",\"content\":\"天气 API 返回：北京 25°C，晴\"}\n" .
                    "示例4（高速临时记忆）：{\"level\":\"ram\",\"role\":\"user\",\"content\":\"本轮对话中用户想要解决某个 Bug，已给出建议\"}",
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

        // 读取记忆
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read',
                'description' => "读取指定层级的记忆，支持分页（offset/length），daily 层级可按日期过滤。\n\n" .
                    "返回格式：{\"messages\": [{\"role\":\"...\", \"content\":\"...\"}], \"total\": N}\n" .
                    "每条消息均包含 role（user/assistant/system/tool），可直接作为 LLM 上下文。\n\n" .
                    "层级读取行为：\n" .
                    "- system：读取 system.txt 全部条目（永久）。\n" .
                    "- important：读取 important.txt 全部条目（永久）。\n" .
                    "- daily：读取指定日期的文件（默认为当天），无文件时返回空数组。\n" .
                    "- ram：读取内存数组中的所有记录（不持久化，按写入顺序返回）。\n\n" .
                    "参数说明：\n" .
                    "- level(string): 必选，system/important/daily/ram。\n" .
                    "- offset(int): 可选，偏移量（从0开始），默认0。\n" .
                    "- length(int): 可选，返回最大条数，0表示全部，默认100。\n" .
                    "- date(string): 可选，仅对 daily 层有效，格式 YYYYMMDD，默认为今天。对其他层级忽略。\n\n" .
                    "示例1（读取今日日常）：{\"level\":\"daily\", \"offset\":0, \"length\":50}\n" .
                    "示例2（读取最近重要记忆）：{\"level\":\"important\", \"length\":0}\n" .
                    "示例3（读取 ram 全部）：{\"level\":\"ram\", \"length\":0}",
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

        // 搜索记忆
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search',
                'description' => "在记忆中进行关键词搜索，支持跨层级、AND/OR 模式、日期范围（仅 daily 层）。不区分大小写。\n\n" .
                    "返回格式同 read()：{\"messages\": [{\"role\":\"...\", \"content\":\"...\"}], \"total\": N}\n\n" .
                    "层级说明：\n" .
                    "- level 可为 system, important, daily, ram, 或 all（同时搜索所有层级）。\n" .
                    "- daily 层支持按日期范围过滤（start_date / end_date，格式 YYYYMMDD），默认搜索所有 daily 文件。\n" .
                    "- ram 层直接对内存中的消息进行字符串匹配（搜索整个 JSON 字符串，高效）。\n\n" .
                    "搜索模式：\n" .
                    "- mode='or'：任意一个关键词匹配即返回（默认）。\n" .
                    "- mode='and'：所有关键词都必须匹配。\n\n" .
                    "分页参数：offset（偏移量），length（最大返回数，0=全部）。\n\n" .
                    "示例1（跨层搜索任意关键词）：{\"level\":\"all\", \"keywords\":[\"Python\",\"配置\"], \"mode\":\"or\"}\n" .
                    "示例2（仅 daily 层且同时包含两个词）：{\"level\":\"daily\", \"keywords\":[\"登录\",\"失败\"], \"mode\":\"and\", \"start_date\":\"20260501\", \"end_date\":\"20260515\"}\n" .
                    "示例3（仅 ram 层，返回全部匹配结果）：{\"level\":\"ram\", \"keywords\":[\"Bug\"], \"length\":0}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram', 'all']],
                        'keywords'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '关键词列表，至少一个'],
                        'mode'       => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'],
                        'offset'     => ['type' => 'integer', 'default' => 0],
                        'length'     => ['type' => 'integer', 'default' => 100],
                        'start_date' => ['type' => 'string', 'pattern' => '^\\d{8}$', 'description' => '仅 daily 层，起始日期'],
                        'end_date'   => ['type' => 'string', 'pattern' => '^\\d{8}$', 'description' => '仅 daily 层，结束日期']
                    ],
                    'required'   => ['level', 'keywords']
                ]
            ]
        ]
    ];
}