<?php

/**
 * Memory module for AgentBee - Four-layer memory management system
 *
 * Provides system/important/daily memory storage using JSONL format.
 *
 * Copyright 2026 秋水之冰 <27206617@qq.com>
 *  Copyright 2026 AgentBee self developed
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

namespace modules\agent_mem_file;

class tools
{
    public const META = [
        // addTask
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'addTask',
                'description' => "添加定时任务。参数：task_id（唯一标识）, task_prompt（触发提示）, run_at（Unix时间戳）, repeat（默认false）, repeat_interval（秒）。返回：{\"bytes_written\":N}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id'         => ['type' => 'string', 'description' => '任务唯一ID'],
                        'task_prompt'     => ['type' => 'string', 'description' => '触发时发送的提示词'],
                        'run_at'          => ['type' => 'integer', 'description' => '首次触发时间戳'],
                        'repeat'          => ['type' => 'boolean', 'default' => false, 'description' => '是否重复'],
                        'repeat_interval' => ['type' => 'integer', 'default' => 0, 'description' => '重复间隔秒数'],
                    ],
                    'required'   => ['task_id', 'task_prompt', 'run_at'],
                ],
            ],
        ],
        // removeTask
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'removeTask',
                'description' => "删除定时任务。参数：task_id。返回：{\"success\":true,\"message\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['task_id' => ['type' => 'string', 'description' => '任务ID']],
                    'required'   => ['task_id'],
                ],
            ],
        ],
        // listTasks
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listTasks',
                'description' => "列出所有任务。无参数。返回任务数组，每个含 task_id, task_prompt, run_at, repeat, repeat_interval, created_at。",
            ],
        ],
        // runTask
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'runTask',
                'description' => "手动触发任务调度，返回所有到期任务。无参数。返回任务数组（格式同 listTasks）。",
            ],
        ],
        // save
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save',
                'description' => "保存记忆。参数：level（system/important/daily/ram）, role（user/assistant/system/tool）, content（纯文本）。返回：{\"saved\":true,\"path\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'   => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram']],
                        'role'    => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool']],
                        'content' => ['type' => 'string', 'description' => '记忆内容'],
                    ],
                    'required'   => ['level', 'role', 'content'],
                ],
            ],
        ],
        // read
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read',
                'description' => "读取记忆。参数：level（system/important/daily/ram）, offset（默认0）, length（默认100，0=全部）, date（仅 daily，YYYYMMDD）。返回：{\"messages\":[...],\"total\":N}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'  => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram']],
                        'offset' => ['type' => 'integer', 'default' => 0],
                        'length' => ['type' => 'integer', 'default' => 100],
                        'date'   => ['type' => 'string', 'pattern' => '^\d{8}$', 'description' => 'YYYYMMDD'],
                    ],
                    'required'   => ['level'],
                ],
            ],
        ],
        // search
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search',
                'description' => "搜索记忆。参数：level（system/important/daily/ram/all）, keywords（字符串数组，至少一个）, mode（or/and，默认or）, offset, length, start_date, end_date（仅 daily）。返回同 read。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram', 'all']],
                        'keywords'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '关键词列表'],
                        'mode'       => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'],
                        'offset'     => ['type' => 'integer', 'default' => 0],
                        'length'     => ['type' => 'integer', 'default' => 100],
                        'start_date' => ['type' => 'string', 'pattern' => '^\d{8}$', 'description' => '起始日期 YYYYMMDD'],
                        'end_date'   => ['type' => 'string', 'pattern' => '^\d{8}$', 'description' => '结束日期 YYYYMMDD'],
                    ],
                    'required'   => ['level', 'keywords'],
                ],
            ],
        ],
        // delete
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'delete',
                'description' => "删除记忆条目，支持按关键词和/或时间范围删除。\n\n" .
                    "参数：\n" .
                    "- level (string, 必填): system | important | daily | ram | all\n" .
                    "- keywords (string, 可选, 默认空): 逗号分隔的关键词，匹配 content 内容\n" .
                    "- mode (string, 可选, 默认 or): or | and，关键词匹配模式\n" .
                    "- start_date (string, 可选): 起始日期 YYYYMMDD（仅 daily 层有效）\n" .
                    "- end_date (string, 可选): 结束日期 YYYYMMDD（仅 daily 层有效）\n" .
                    "- start_time (int, 可选, 默认 0): Unix 时间戳下限（0=不限）\n" .
                    "- end_time (int, 可选, 默认 0): Unix 时间戳上限（0=不限）\n\n" .
                    "至少需要提供 keywords、start_time/end_time、start_date/end_date 中的一组条件。\n\n" .
                    "返回：{\"deleted\": N} 表示删除条数；失败 → {\"error\": \"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram', 'all']],
                        'keywords'   => ['type' => 'string', 'description' => '逗号分隔的关键词', 'default' => ''],
                        'mode'       => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'],
                        'start_date' => ['type' => 'string', 'pattern' => '^\d{8}$', 'description' => '起始日期 YYYYMMDD'],
                        'end_date'   => ['type' => 'string', 'pattern' => '^\d{8}$', 'description' => '结束日期 YYYYMMDD'],
                        'start_time' => ['type' => 'integer', 'description' => 'Unix时间戳下限', 'default' => 0],
                        'end_time'   => ['type' => 'integer', 'description' => 'Unix时间戳上限', 'default' => 0],
                    ],
                    'required'   => ['level'],
                ],
            ],
        ],
    ];
}