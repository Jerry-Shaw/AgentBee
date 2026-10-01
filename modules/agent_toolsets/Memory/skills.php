<?php

/**
 * Memory module for AgentBee - Tools Meta Definition
 *
 * This module provides memory management tools (save/read/search/update/delete),
 * session management (save/read/update session) and task scheduling
 * (add/remove/list/run tasks) for Agents.
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
                'name'        => 'saveSession',
                'description' => '新建会话。仅当用户明确提出"新建会话"并提供会话名称时才可调用。返回：{status, session_id}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'session_id'   => ['type' => 'string', 'description' => '会话ID (标准 UUID v4 格式字符串)'],
                        'session_name' => ['type' => 'string', 'description' => '会话名称']
                    ],
                    'required'   => ['session_id', 'session_name']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readSession',
                'description' => '读取会话列表，获取会话ID和名称。用户提及历史会话/切换话题/查阅其他会话记忆时调用。返回：{status, sessions: [{session_id, session_name, session_status, create_time}]}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'session_status' => ['type' => 'integer', 'default' => 1, 'description' => '会话状态：1=启用(默认)；2=已删除；0=全部']
                    ],
                    'required'   => []
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'updateSession',
                'description' => '按会话ID更新会话名称与状态。返回：{status, affected_rows}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'session_id'     => ['type' => 'string', 'description' => '会话ID'],
                        'session_name'   => ['type' => 'string', 'default' => '', 'description' => '新会话名称(可选，为空表示不修改)'],
                        'session_status' => ['type' => 'integer', 'default' => 0, 'description' => '会话状态：1=启用；2=删除；0=不修改(默认)']
                    ],
                    'required'   => ['session_id']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save',
                'description' => '新增记忆。level按内容：system(配置/人设/规则/权限/边界，全局)、important(事实/偏好/知识/长期规划，全局)、daily(决策/结论/进展/待办/工具结果，会话隔离)；role按来源：user(用户陈述/确认/事实)、assistant(助手推导/结论)、system(系统配置/规则)、tool(工具原始结果)。用户事实即使由助手归纳仍用user。内容需压缩提炼。返回：{status, create_id}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily'], 'description' => '层级'],
                        'role'       => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool'], 'description' => '来源角色'],
                        'content'    => ['type' => 'string', 'description' => '记忆内容'],
                        'date'       => ['type' => 'integer', 'default' => 0, 'description' => '日期：YYYYMMDD (0=当天)'],
                        'session_id' => ['type' => 'string', 'default' => '', 'description' => '会话ID']
                    ],
                    'required'   => ['level', 'role', 'content']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'update',
                'description' => '更新记忆(按create_id)，可改level/role/content/date/expire_at。仅实质变化时调用，内容需压缩提炼。返回：{status, affected_rows}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'create_id' => ['type' => 'integer', 'description' => '记忆ID(微秒)'],
                        'level'     => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc'], 'description' => '新层级'],
                        'role'      => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool'], 'description' => '新角色'],
                        'content'   => ['type' => 'string', 'description' => '新内容'],
                        'date'      => ['type' => 'integer', 'default' => 0, 'description' => '日期：YYYYMMDD (0=保留原值)'],
                        'expire_at' => ['type' => 'string', 'default' => '', 'description' => '过期时间：YYYY-mm-dd HH:ii:ss']
                    ],
                    'required'   => ['create_id', 'level', 'role', 'content']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read',
                'description' => '读取记忆。参数：level指定层级；date(YYYYMMDD)按日读取，0=不限；session_id限定会话；offset起始位置，length条数(0=全部)；create_id游标，仅取小于此值的记录。结果较多时，用offset跳跃采样(如总数1/2、1/3位置)，勿只读开头，以覆盖更完整的时间段。禁止重复读取。返回：{status, data: [{level, role, content, date, create_id, create_time, session_id}], total}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all'], 'description' => '层级(含all)'],
                        'date'       => ['type' => 'integer', 'default' => 0, 'description' => '指定日期：YYYYMMDD (0=不限)'],
                        'offset'     => ['type' => 'integer', 'default' => 0, 'description' => '偏移量'],
                        'length'     => ['type' => 'integer', 'default' => 10, 'description' => '条数（0=全部，建议5-20）'],
                        'session_id' => ['type' => 'string', 'default' => '', 'description' => '会话ID'],
                        'create_id'  => ['type' => 'integer', 'default' => 0, 'description' => '游标：仅取create_id小于此值的记录(0=不限)']
                    ],
                    'required'   => ['level']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search',
                'description' => '全文搜索记忆。参数：keywords关键词1-5个(多个为AND，全部命中)；level指定层级，默认all；date_start/date_end限定日期范围(0=不限)；session_id限定会话；offset起始位置，length条数(0=全部)。结果较多时，用offset跳跃采样(如总数1/2、1/3位置)，或按日期分段查询，勿只取开头，以覆盖不同时间段。禁止重复搜索。返回：{status, data: [{level, role, content, date, create_id, create_time, session_id}], total}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'keywords'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '关键词数组（多个关键词间为AND）'],
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all'], 'default' => 'all', 'description' => '层级，默认all'],
                        'date_start' => ['type' => 'integer', 'default' => 0, 'description' => '起始日期：YYYYMMDD (0=不限)'],
                        'date_end'   => ['type' => 'integer', 'default' => 0, 'description' => '结束日期：YYYYMMDD (0=不限)'],
                        'offset'     => ['type' => 'integer', 'default' => 0, 'description' => '偏移量'],
                        'length'     => ['type' => 'integer', 'default' => 20, 'description' => '条数（0=全部，建议10-30）'],
                        'session_id' => ['type' => 'string', 'default' => '', 'description' => '会话ID']
                    ],
                    'required'   => ['level', 'keywords']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'delete',
                'description' => '删除记忆。传create_ids按ID精确删；否则按层级+时间字符串(start/end_time)+关键词+session_id组合删（system/important不受会话过滤，daily/misc受会话过滤）。返回：{status, deleted}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all'], 'description' => '层级'],
                        'create_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '微秒ID数组（优先）'],
                        'start_time' => ['type' => 'string', 'description' => '开始时间：YYYY-mm-dd HH:ii:ss'],
                        'end_time'   => ['type' => 'string', 'description' => '结束时间：YYYY-mm-dd HH:ii:ss'],
                        'keywords'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '关键词数组（与时间范围AND）'],
                        'mode'       => ['type' => 'string', 'enum' => ['and', 'or'], 'default' => 'and', 'description' => '关键词匹配模式'],
                        'session_id' => ['type' => 'string', 'default' => '', 'description' => '会话ID(可选，默认空=不限)']
                    ],
                    'required'   => ['level']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'addTask',
                'description' => '添加定时任务，设置任务提示词、执行时间字符串，可设重复及间隔。返回：{status, create_id, run_time}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'session_id'      => ['type' => 'string', 'description' => '会话ID'],
                        'task_prompt'     => ['type' => 'string', 'description' => '任务提示词'],
                        'run_at'          => ['type' => 'string', 'description' => '执行时间：YYYY-mm-dd HH:ii:ss'],
                        'repeat'          => ['type' => 'boolean', 'default' => false, 'description' => '是否重复'],
                        'repeat_interval' => ['type' => 'integer', 'default' => 0, 'description' => '重复间隔(秒)，repeat=true时有效']
                    ],
                    'required'   => ['session_id', 'task_prompt', 'run_at']
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
                'description' => '列出当前会话所有任务详情。返回：{status, tasks: [{create_id, repeat, interval, prompt, run_time, create_time}]}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'session_id' => ['type' => 'string', 'description' => '会话ID'],
                    ],
                    'required'   => ['session_id']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'runTask',
                'description' => '获取当前会话所有到期任务。返回：[prompt, ...]。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'session_id' => ['type' => 'string', 'description' => '会话ID'],
                    ],
                    'required'   => ['session_id']
                ],
            ],
        ]
    ];
}