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
        [
            'type' => 'function',
            'function' => [
                'name' => 'addTask',
                'description' => '添加定时任务。参数:task_id, task_prompt, run_at(时间戳), repeat(默认false), repeat_interval(秒)。返回 {"bytes_written":N}',
                'parameters' => ['type' => 'object', 'properties' => ['task_id' => ['type' => 'string'], 'task_prompt' => ['type' => 'string'], 'run_at' => ['type' => 'integer'], 'repeat' => ['type' => 'boolean', 'default' => false], 'repeat_interval' => ['type' => 'integer', 'default' => 0]], 'required' => ['task_id', 'task_prompt', 'run_at']],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'removeTask',
                'description' => '删除定时任务。参数:task_id。返回 {"success":true,"message":"..."}',
                'parameters' => ['type' => 'object', 'properties' => ['task_id' => ['type' => 'string']], 'required' => ['task_id']],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'listTasks',
                'description' => '列出所有任务。无参数。返回任务数组 [{task_id,task_prompt,run_at,repeat,repeat_interval,created_at}]',
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'runTask',
                'description' => '手动触发任务调度,返回到期任务。无参数。返回任务数组(同listTasks)',
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'save',
                'description' => '保存记忆。参数:level(system|important|daily|ram),role(user|assistant|system|tool),content。返回 {"saved":true,"path":"..."}',
                'parameters' => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram']], 'role' => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool']], 'content' => ['type' => 'string']], 'required' => ['level', 'role', 'content']],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'read',
                'description' => '读取记忆。参数:level, offset(0), length(100,0=全部), date(仅daily,YYYYMMDD)。返回 {"messages":[...],"total":N}',
                'parameters' => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram']], 'offset' => ['type' => 'integer', 'default' => 0], 'length' => ['type' => 'integer', 'default' => 100], 'date' => ['type' => 'string', 'pattern' => '^\d{8}$']], 'required' => ['level']],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'search',
                'description' => '搜索记忆。参数:level(含all), keywords(数组), mode(or|and), offset, length, start_date, end_date(仅daily)。返回同read',
                'parameters' => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram', 'all']], 'keywords' => ['type' => 'array', 'items' => ['type' => 'string']], 'mode' => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'], 'offset' => ['type' => 'integer', 'default' => 0], 'length' => ['type' => 'integer', 'default' => 100], 'start_date' => ['type' => 'string', 'pattern' => '^\d{8}$'], 'end_date' => ['type' => 'string', 'pattern' => '^\d{8}$']], 'required' => ['level', 'keywords']],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'delete',
                'description' => '删除记忆。参数:level, keywords(逗号分隔), mode(or|and), start_date/end_date(YYYYMMDD), start_time/end_time(时间戳)。至少提供keywords或时间范围之一。返回 {"deleted":N} 或 {"error":"..."}',
                'parameters' => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'ram', 'all']], 'keywords' => ['type' => 'string', 'default' => ''], 'mode' => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'], 'start_date' => ['type' => 'string', 'pattern' => '^\d{8}$'], 'end_date' => ['type' => 'string', 'pattern' => '^\d{8}$'], 'start_time' => ['type' => 'integer', 'default' => 0], 'end_time' => ['type' => 'integer', 'default' => 0]], 'required' => ['level']],
            ],
        ],
    ];
}