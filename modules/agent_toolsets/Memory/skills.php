<?php

/**
 * Memory module for AgentBee - Tools Meta Definition
 *
 * This module provides memory management tools (save/read/search/update/delete)
 * and task scheduling (add/remove/list/run tasks) for Agents.
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

namespace modules\agent_toolsets\Memory;

class skills
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'addTask',
                'description' => '添加定时任务，设置任务提示词、执行时间戳，可设重复及间隔。返回：{status, create_id}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'task_prompt'     => ['type' => 'string', 'description' => '任务提示词'],
                        'run_at'          => ['type' => 'integer', 'description' => '执行时间戳(秒)'],
                        'repeat'          => ['type' => 'boolean', 'default' => false, 'description' => '是否重复'],
                        'repeat_interval' => ['type' => 'integer', 'default' => 0, 'description' => '重复间隔(秒)，repeat=true时有效']
                    ],
                    'required'   => ['task_prompt', 'run_at']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'removeTask',
                'description' => '按create_id删除定时任务。返回：{status, message}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'create_id' => ['type' => 'integer', 'description' => '任务ID(微秒)']
                    ],
                    'required'   => ['create_id']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listTasks',
                'description' => '列出所有任务详情。返回：{status, tasks: [{create_id, run_at, repeat, interval, prompt, run_time, create_time}]}。'
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'runTask',
                'description' => '执行所有到期任务，返回提示词索引数组。返回：提示词数组。',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save',
                'description' => '新增记忆。level按内容：system(人设/规则)、important(事实/偏好)、daily(日常/结果)、misc(由系统记录)；role按来源：system/user/assistant/tool。内容需压缩提炼。返回：{status, create_id}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'   => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc'], 'description' => '层级'],
                        'role'    => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool'], 'description' => '来源角色'],
                        'content' => ['type' => 'string', 'description' => '记忆内容']
                    ],
                    'required'   => ['level', 'role', 'content']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'update',
                'description' => '更新已有记忆（按create_id），可改层级、角色、内容、过期时间（可选）。level按内容：system(人设/规则)、important(事实/偏好)、daily(日常/结果)、misc(临时)；role按来源：system/user/assistant/tool。expire_at为时间戳(秒)，0表示永不过期，默认0。仅内容有实质变化时调用，新内容需压缩提炼。返回：{status, affected_rows}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'create_id' => ['type' => 'integer', 'description' => '记忆ID(微秒)'],
                        'level'     => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc'], 'description' => '新层级'],
                        'role'      => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool'], 'description' => '新角色'],
                        'content'   => ['type' => 'string', 'description' => '新内容'],
                        'expire_at' => ['type' => 'integer', 'default' => 0, 'description' => '过期时间戳(秒)，0表示永不过期']
                    ],
                    'required'   => ['create_id', 'level', 'role', 'content']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read',
                'description' => '读取记忆，按层级分页，可指定日期（格式YYYYMMDD）。返回：{status, data: [{level, role, content, create_id, create_time}], total}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'  => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all'], 'description' => '层级(含all)'],
                        'offset' => ['type' => 'integer', 'default' => 0, 'description' => '偏移量'],
                        'length' => ['type' => 'integer', 'default' => 20, 'description' => '条数(0=全部)'],
                        'date'   => ['type' => 'integer', 'default' => 0, 'description' => 'YYYYMMDD(0=不限)']
                    ],
                    'required'   => ['level']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search',
                'description' => '全文搜索记忆，支持关键词数组、匹配模式(or/and)、日期范围、分页。单字匹配不准，建议关键词≥2字符。返回：{status, data: [{level, role, content, create_id, create_time}], total}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all'], 'description' => '层级'],
                        'keywords'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '关键词数组'],
                        'mode'       => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or', 'description' => '匹配模式'],
                        'offset'     => ['type' => 'integer', 'default' => 0, 'description' => '偏移量'],
                        'length'     => ['type' => 'integer', 'default' => 100, 'description' => '条数(0=全部)'],
                        'start_date' => ['type' => 'string', 'default' => '', 'pattern' => '^\d{8}$', 'description' => '起始日期'],
                        'end_date'   => ['type' => 'string', 'default' => '', 'pattern' => '^\d{8}$', 'description' => '结束日期']
                    ],
                    'required'   => ['level', 'keywords']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'delete',
                'description' => '删除记忆。提供create_ids则按ID+层级精确删除（忽略其他参数）；否则按层级+时间范围(秒)+关键词组合删除（匹配模式由mode控制）。返回：{status, deleted}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all'], 'description' => '层级'],
                        'create_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '微秒ID数组（优先）'],
                        'start_time' => ['type' => 'integer', 'description' => '起始时间戳(秒，0不限)'],
                        'end_time'   => ['type' => 'integer', 'description' => '结束时间戳(秒，0不限)'],
                        'keywords'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '关键词数组（与时间范围AND）'],
                        'mode'       => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or', 'description' => '关键词匹配模式']
                    ],
                    'required'   => ['level']
                ],
            ],
        ]
    ];
}