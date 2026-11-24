<?php

/**
 * Memory module for AgentBee - Tools Meta Definition
 *
 * This module provides memory management tools (save/read/search/update/delete),
 * session management (save/read/update session) and task scheduling
 * (add/remove/list tasks) for Agents.
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
                'name'        => 'createSession',
                'description' => '新建会话，用户明确要求并提供名称时调用。返回session_id或error。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'session_id'   => ['type' => 'string', 'description' => '新会话ID，UUID v4'],
                        'session_name' => ['type' => 'string', 'description' => '用户指定的会话名称，不可为空']
                    ],
                    'required'   => ['session_id', 'session_name']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listSessions',
                'description' => '查询会话信息，定位跨会话记忆。返回sessions或error。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'session_status' => ['type' => 'integer', 'enum' => [0, 1, 2], 'default' => 1, 'description' => '状态：1=启用，2=已删除，0=全部；传session_id时忽略'],
                        'session_id'     => ['type' => 'string', 'default' => '', 'description' => '指定ID查询，不受状态过滤；空=按状态列出']
                    ],
                    'required'   => []
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'renameSession',
                'description' => '修改会话名称。返回affected_rows或error。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'session_id'   => ['type' => 'string', 'description' => '会话ID'],
                        'session_name' => ['type' => 'string', 'description' => '新会话名称，不可为空'],
                    ],
                    'required'   => ['session_id', 'session_name']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteSession',
                'description' => '软删除会话，保留记忆，可恢复。返回affected_rows或error。',
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
                'name'        => 'restoreSession',
                'description' => '恢复软删除的会话。返回affected_rows或error。',
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
                'name'        => 'save',
                'description' => '保存新记忆，包括新增进展或跨层提炼，仅保存有信息价值的内容；返回create_id或error。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily'], 'description' => 'system=配置/规则/档案/偏好/关系；important=事实/规划/知识/教训/长期成果；daily=会话决策/进展/待办/经历/工具结果'],
                        'role'       => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool'], 'description' => '来源：user=用户事实/要求（含助手归纳）；assistant=助手推导；system=系统配置/规则；tool=工具原始结果（如已总结存储则不再保存）'],
                        'content'    => ['type' => 'string', 'description' => '提炼后的事实与结论，保留关键细节'],
                        'date'       => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '归档日期YYYYMMDD；0=当天'],
                        'session_id' => ['type' => 'string', 'default' => '', 'description' => 'daily必传所属会话ID；system/important不传（全局）']
                    ],
                    'required'   => ['level', 'role', 'content']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'update',
                'description' => '按ID修正记忆，内容需总结提炼。返回affected_rows或error。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'create_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '已核实的记忆ID'],
                        'role'      => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool'], 'description' => '省略保留原值；来源：user=用户事实/要求（含助手归纳）；assistant=助手推导；system=系统配置/规则；tool=工具原始结果'],
                        'content'   => ['type' => 'string', 'description' => '完整的新内容'],
                        'date'      => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '归档日期YYYYMMDD；0=保留原值']
                    ],
                    'required'   => ['create_id', 'content']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read',
                'description' => '按层级、日期读取记忆。返回data和匹配总数total或error。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all'], 'description' => '记忆层级；all=全局层＋指定会话记录'],
                        'date'       => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '归档日期YYYYMMDD；0=不限'],
                        'offset'     => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '从最新记录起跳过的条数；页内按ID正序'],
                        'length'     => ['type' => 'integer', 'minimum' => 0, 'default' => 10, 'description' => '读取条数；0=全部并忽略offset'],
                        'session_id' => ['type' => 'string', 'default' => '', 'description' => 'daily/misc/all必传目标会话ID；system/important不传'],
                        'create_id'  => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '游标：仅取create_id小于此值的记录(0=不限)']
                    ],
                    'required'   => ['level']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search',
                'description' => '上下文不足且主题明确时搜索记忆。返回data和匹配总数total或error。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'keywords'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '建议1–5个特征词，全部匹配；勿用表情或纯符号'],
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all'], 'description' => '记忆层级；all=全局层＋指定会话记录'],
                        'date_start' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '起始归档日期YYYYMMDD（含）；0=不限'],
                        'date_end'   => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '结束归档日期YYYYMMDD（含）；0=不限'],
                        'offset'     => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '从最新记录起跳过的条数；页内按ID倒序'],
                        'length'     => ['type' => 'integer', 'minimum' => 0, 'default' => 20, 'description' => '读取条数；0=全部并忽略offset，建议10–30'],
                        'session_id' => ['type' => 'string', 'default' => '', 'description' => 'daily/misc/all必传目标会话ID；system/important不传']
                    ],
                    'required'   => ['level', 'keywords']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'delete',
                'description' => '永久删除已确认的记忆。非空create_ids优先且忽略其他条件；否则按指定范围删除。返回deleted或error。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'create_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'default' => [], 'description' => '已核实的记忆ID数组；空=范围删除'],
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc'], 'description' => '范围删除必传的单个层级'],
                        'session_id' => ['type' => 'string', 'default' => '', 'description' => '范围删除daily/misc必传；system/important为全局层，不传'],
                        'start_time' => ['type' => 'string', 'default' => '', 'description' => '范围删除必传：创建时间起点YYYY-MM-DD HH:mm:ss，系统时区，含该秒'],
                        'end_time'   => ['type' => 'string', 'default' => '', 'description' => '范围删除必传：创建时间终点YYYY-MM-DD HH:mm:ss，系统时区，含该秒']
                    ],
                    'required'   => []
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'addTask',
                'description' => '为指定会话添加定时任务，可重复。返回create_id、run_time或error。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'session_id'      => ['type' => 'string', 'description' => '会话ID'],
                        'task_prompt'     => ['type' => 'string', 'description' => '到期执行的完整指令，包含必要上下文，不可为空'],
                        'run_at'          => ['type' => 'string', 'description' => '执行时间YYYY-MM-DD HH:mm:ss，早于当前时间会立即执行'],
                        'repeat'          => ['type' => 'boolean', 'default' => false, 'description' => '是否重复'],
                        'repeat_interval' => ['type' => 'integer', 'default' => 0, 'description' => '重复间隔（秒）；repeat=true时须大于0']
                    ],
                    'required'   => ['session_id', 'task_prompt', 'run_at']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'removeTasks',
                'description' => '按任务ID数组取消定时任务。返回affected_rows或error。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'create_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'description' => '已核实的任务ID数组']
                    ],
                    'required'   => ['create_ids']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listTasks',
                'description' => '查询指定会话的定时任务。返回tasks或error。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'session_id' => ['type' => 'string', 'description' => '会话ID'],
                    ],
                    'required'   => ['session_id']
                ],
            ],
        ],
    ];
}