<?php

/**
 * Memory module for AgentBee - SQLite-based four-layer memory management system
 *
 * Provides system/important/daily/ram memory storage using SQLite database.
 * All CRUD operations use libSQLite QueryBuilder with parameter binding.
 * Full-text search via FTS5 with LIKE fallback.
 *
 * Copyright 2026 AgentBee self developed
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

namespace modules\agent_mem_db;

class tools
{
    public const META = [
        // addTask
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'addTask',
                'description' => "添加或更新一个定时任务。\n\n" .
                    "参数：\n" .
                    "- task_id (string, 必填): 任务唯一标识符\n" .
                    "- task_prompt (string, 必填): 触发时发送给 LLM 的提示词\n" .
                    "- run_at (int, 必填): Unix 时间戳，首次触发时间（若小于当前时间，自动修正为当前时间）\n" .
                    "- repeat (bool, 可选, 默认 false): 是否重复执行\n" .
                    "- repeat_interval (int, 可选, 默认 0): 重复间隔秒数（repeat=true 时必填且 >0）\n\n" .
                    "返回：成功 → {\"bytes_written\": 1}；失败 → {\"error\": \"错误信息\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id'         => ['type' => 'string', 'description' => '任务唯一ID'],
                        'task_prompt'     => ['type' => 'string', 'description' => '触发时的提示词'],
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
                'description' => "删除一个定时任务。\n\n" .
                    "参数：task_id (string, 必填)\n\n" .
                    "返回：\n" .
                    "- 成功 → {\"success\": true, \"message\": \"Task removed.\"}\n" .
                    "- 任务不存在 → {\"success\": false, \"message\": \"Task not found.\"}\n" .
                    "- 失败 → {\"success\": false, \"message\": \"错误信息\"}",
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
                'description' => "列出所有已调度的任务。无参数。\n\n" .
                    "返回：任务数组，每个任务包含 task_id, task_prompt, run_at, repeat, repeat_interval, created_at。若无任务，返回空数组 []。",
            ],
        ],
        // runTask
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'runTask',
                'description' => "手动触发任务调度，返回所有到期的任务。无参数。\n\n" .
                    "使用场景：系统心跳自动调用，或 LLM 主动调用用于调试/手动触发。\n\n" .
                    "返回：到期任务数组（格式同 listTasks），若无到期任务返回空数组 []。",
            ],
        ],
        // save
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save',
                'description' => "保存记忆到指定层级。\n\n" .
                    "参数：\n" .
                    "- level (string, 必填): system | important | daily | ram\n" .
                    "- role (string, 必填): user | assistant | system | tool\n" .
                    "- content (string, 必填): 记忆内容（纯文本）\n\n" .
                    "注意：\n" .
                    "- system 和 important 层级会强制覆盖 role（system → system, important → user）\n" .
                    "- ram 层仅存在于内存，重启后丢失，读写极快\n\n" .
                    "返回：成功 → {\"saved\": true, \"path\": \"...\", \"role\": \"实际存储的角色\"}；失败 → {\"error\": \"...\"}",
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
                'description' => "读取指定层级的记忆，支持分页和 daily 日期过滤。\n\n" .
                    "参数：\n" .
                    "- level (string, 必填): system | important | daily | ram\n" .
                    "- offset (int, 可选, 默认 0): 偏移量\n" .
                    "- length (int, 可选, 默认 100): 返回条数，0 表示全部\n" .
                    "- date (string, 可选): 仅 daily 层有效，格式 YYYYMMDD，默认为今天\n\n" .
                    "返回：{\"messages\": [{\"role\": \"...\", \"content\": \"...\"}], \"total\": N}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'  => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram']],
                        'offset' => ['type' => 'integer', 'default' => 0],
                        'length' => ['type' => 'integer', 'default' => 100],
                        'date'   => ['type' => 'string', 'pattern' => '^\\d{8}$', 'description' => 'YYYYMMDD，仅 daily 层'],
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
                'description' => "在记忆中搜索关键词，支持跨层级、AND/OR、日期范围。\n\n" .
                    "参数：\n" .
                    "- level (string, 必填): system | important | daily | ram | all\n" .
                    "- keywords (array, 必填): 字符串数组，至少一个关键词\n" .
                    "- mode (string, 可选, 默认 or): or | and\n" .
                    "- offset (int, 可选, 默认 0): 偏移量\n" .
                    "- length (int, 可选, 默认 100): 返回条数，0 表示全部\n" .
                    "- start_date (string, 可选): 仅 daily 层，起始日期 YYYYMMDD\n" .
                    "- end_date (string, 可选): 仅 daily 层，结束日期 YYYYMMDD\n\n" .
                    "返回：同 read()：{\"messages\": [...], \"total\": N}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram', 'all']],
                        'keywords'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '关键词列表'],
                        'mode'       => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'],
                        'offset'     => ['type' => 'integer', 'default' => 0],
                        'length'     => ['type' => 'integer', 'default' => 100],
                        'start_date' => ['type' => 'string', 'pattern' => '^\\d{8}$', 'description' => '起始日期 YYYYMMDD'],
                        'end_date'   => ['type' => 'string', 'pattern' => '^\\d{8}$', 'description' => '结束日期 YYYYMMDD'],
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
                        'start_date' => ['type' => 'string', 'pattern' => '^\\d{8}$', 'description' => '起始日期 YYYYMMDD'],
                        'end_date'   => ['type' => 'string', 'pattern' => '^\\d{8}$', 'description' => '结束日期 YYYYMMDD'],
                        'start_time' => ['type' => 'integer', 'description' => 'Unix时间戳下限', 'default' => 0],
                        'end_time'   => ['type' => 'integer', 'description' => 'Unix时间戳上限', 'default' => 0],
                    ],
                    'required'   => ['level'],
                ],
            ],
        ],
    ];
}