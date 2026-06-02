<?php

/**
 * Memory module for AgentBee - Tools Meta Definition
 *
 * This module provides high-efficiency web data acquisition tools for Agents,
 * focusing on noise reduction and structural extraction to optimize LLM token usage.
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

namespace tools\Memory;

class tools
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'addTask',
                'description' => '添加定时任务。参数:task_prompt,run_at(秒),repeat(默认false),repeat_interval(秒)。返回:成功{"status":"success","create_id":微秒ID},失败{"status":"error","error":str}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'task_prompt'     => ['type' => 'string', 'description' => '任务提示词'],
                        'run_at'          => ['type' => 'integer', 'description' => '执行时间戳(秒)'],
                        'repeat'          => ['type' => 'boolean', 'default' => false, 'description' => '是否重复'],
                        'repeat_interval' => ['type' => 'integer', 'default' => 0, 'description' => '重复间隔(秒)']
                    ],
                    'required'   => ['task_prompt', 'run_at']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'removeTask',
                'description' => '删除任务。参数:create_id(微秒ID)。返回:成功{"status":"success","message":str},失败{"status":"error","error":str}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'create_id' => ['type' => 'integer', 'description' => '任务的微秒ID']
                    ],
                    'required'   => ['create_id']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listTasks',
                'description' => '列出所有任务。返回{"status":"success","tasks":[{"create_id":微秒ID,"run_at":秒,"repeat":0/1,"interval":秒,"prompt":str,"run_time":"Y-m-d H:i:s","create_time":"Y-m-d H:i:s"}]}',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'runTask',
                'description' => '触发到期任务。返回prompt数组:["p1","p2"]',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save',
                'description' => '保存记忆。参数:level(system|important|daily|misc),role(user|assistant|system|tool),content。system强制role=system,misc层3天TTL。返回:成功{"status":"success","create_id":微秒ID},失败{"status":"error","error":str}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'   => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc'], 'description' => '记忆层级'],
                        'role'    => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool'], 'description' => '对话角色'],
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
                'description' => '更新记忆。参数:create_id(微秒ID),role,content。system强制role=system。返回:成功{"status":"success","affected_rows":0/1},失败{"status":"error","error":str}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'create_id' => ['type' => 'integer', 'description' => '记忆的微秒ID'],
                        'role'      => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool'], 'description' => '新角色'],
                        'content'   => ['type' => 'string', 'description' => '新内容']
                    ],
                    'required'   => ['create_id', 'role', 'content']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read',
                'description' => '读取记忆。参数:level(含all),offset(0),length(默认100,0=全部),date(仅daily/misc,YYYYMMDD)。返回:成功{"status":"success","data":[{"role":str,"content":str,"create_id":微秒ID,"create_time":"Y-m-d H:i:s"}],"total":int},失败{"status":"error","error":str}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'  => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all'], 'description' => '记忆层级(含all)'],
                        'offset' => ['type' => 'integer', 'default' => 0, 'description' => '分页偏移'],
                        'length' => ['type' => 'integer', 'default' => 100, 'description' => '读取条数(0=全部)'],
                        'date'   => ['type' => 'string', 'default' => '', 'pattern' => '^\d{8}$', 'description' => '日期YYYYMMDD(仅daily/misc)']
                    ],
                    'required'   => ['level']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search',
                'description' => '全文搜索记忆。参数:level(含all),keywords(数组),mode(or|and),offset,length,start_date,end_date(YYYYMMDD)。返回同read()',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all'], 'description' => '记忆层级'],
                        'keywords'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '关键词数组'],
                        'mode'       => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or', 'description' => '匹配模式'],
                        'offset'     => ['type' => 'integer', 'default' => 0, 'description' => '分页偏移'],
                        'length'     => ['type' => 'integer', 'default' => 100, 'description' => '返回条数'],
                        'start_date' => ['type' => 'string', 'default' => '', 'pattern' => '^\d{8}$', 'description' => '起始日期YYYYMMDD'],
                        'end_date'   => ['type' => 'string', 'default' => '', 'pattern' => '^\d{8}$', 'description' => '结束日期YYYYMMDD']
                    ],
                    'required'   => ['level', 'keywords']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'delete',
                'description' => '删除记忆。参数:level,keywords(逗号分隔),mode(or|and),start_date/end_date(YYYYMMDD),start_time/end_time(秒)。至少一组条件。返回:成功{"status":"success","deleted":int},失败{"status":"error","error":str}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level'      => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all'], 'description' => '记忆层级'],
                        'keywords'   => ['type' => 'string', 'default' => '', 'description' => '关键词(逗号分隔)'],
                        'mode'       => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or', 'description' => '匹配模式'],
                        'start_date' => ['type' => 'string', 'default' => '', 'pattern' => '^\d{8}$', 'description' => '起始日期YYYYMMDD'],
                        'end_date'   => ['type' => 'string', 'default' => '', 'pattern' => '^\d{8}$', 'description' => '结束日期YYYYMMDD'],
                        'start_time' => ['type' => 'integer', 'default' => 0, 'description' => '起始时间戳(秒)'],
                        'end_time'   => ['type' => 'integer', 'default' => 0, 'description' => '结束时间戳(秒)']
                    ],
                    'required'   => ['level']
                ],
            ],
        ],
    ];
}