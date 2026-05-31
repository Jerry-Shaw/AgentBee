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

namespace modules\agent_memory;

class tools
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'addTask',
                'description' => '添加定时任务。参数:task_prompt,run_at(秒时间戳),repeat(默认false),repeat_interval(秒)。返回{"bytes_written":1,"create_at":微秒时间戳}',
                'parameters'  => ['type' => 'object', 'properties' => ['task_prompt' => ['type' => 'string'], 'run_at' => ['type' => 'integer', 'description' => '秒时间戳'], 'repeat' => ['type' => 'boolean', 'default' => false], 'repeat_interval' => ['type' => 'integer', 'default' => 0]], 'required' => ['task_prompt', 'run_at']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'removeTask',
                'description' => '删除任务。参数:create_at(微秒时间戳)。返回{"status":"success","message":str}或{"status":"error","error":str}',
                'parameters'  => ['type' => 'object', 'properties' => ['create_at' => ['type' => 'integer', 'description' => '微秒时间戳']], 'required' => ['create_at']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listTasks',
                'description' => '列出所有任务。无参数。返回任务数组，含create_at(微秒),run_at(秒),repeat,interval,prompt,run_time,create_time(Y-m-d H:i:s)',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'runTask',
                'description' => '触发到期任务。无参数。返回prompt数组["prompt1","prompt2"]',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save',
                'description' => '保存记忆。参数:level(system|important|daily|misc),role(user|assistant|system|tool),content。misc层3天TTL。返回{"saved":true,"create_at":微秒,"role":str}',
                'parameters'  => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc']], 'role' => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool']], 'content' => ['type' => 'string']], 'required' => ['level', 'role', 'content']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read',
                'description' => '读取记忆。参数:level(含all),offset(0),length(默认100,0=全部),date(仅daily/misc,YYYYMMDD)。返回{"messages":[{"role","content","create_at":微秒,"create_time":"Y-m-d H:i:s"}],"total":N}',
                'parameters'  => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all']], 'offset' => ['type' => 'integer', 'default' => 0], 'length' => ['type' => 'integer', 'default' => 100], 'date' => ['type' => 'string', 'pattern' => '^\d{8}$']], 'required' => ['level']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search',
                'description' => '搜索记忆。参数:level(含all),keywords(数组),mode(or|and),offset,length,start_date,end_date(YYYYMMDD,对所有层级生效)。返回同read()',
                'parameters'  => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all']], 'keywords' => ['type' => 'array', 'items' => ['type' => 'string']], 'mode' => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'], 'offset' => ['type' => 'integer', 'default' => 0], 'length' => ['type' => 'integer', 'default' => 100], 'start_date' => ['type' => 'string', 'pattern' => '^\d{8}$'], 'end_date' => ['type' => 'string', 'pattern' => '^\d{8}$']], 'required' => ['level', 'keywords']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'delete',
                'description' => '删除记忆。参数:level,keywords(逗号分隔),mode(or|and),start_date/end_date(YYYYMMDD),start_time/end_time(秒时间戳)。至少一组条件。返回{"deleted":N}',
                'parameters'  => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all']], 'keywords' => ['type' => 'string', 'default' => ''], 'mode' => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'], 'start_date' => ['type' => 'string', 'pattern' => '^\d{8}$'], 'end_date' => ['type' => 'string', 'pattern' => '^\d{8}$'], 'start_time' => ['type' => 'integer', 'default' => 0], 'end_time' => ['type' => 'integer', 'default' => 0]], 'required' => ['level']],
            ],
        ],
    ];
}