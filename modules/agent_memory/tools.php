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
                'description' => '添加/更新定时任务。参数:task_prompt(必填),run_at(时间戳,<当前则修正),repeat(默认false),repeat_interval(秒,repeat=true时>0)。返回{"bytes_written":1,"create_at":整数(微秒时间戳)}或{"error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['task_prompt' => ['type' => 'string'], 'run_at' => ['type' => 'integer'], 'repeat' => ['type' => 'boolean', 'default' => false], 'repeat_interval' => ['type' => 'integer', 'default' => 0]], 'required' => ['task_prompt', 'run_at']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'removeTask',
                'description' => '删除定时任务。参数:create_at(整数,微秒时间戳,任务创建时返回)。返回{"success":true,"message":"..."}或{"success":false,"message":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['create_at' => ['type' => 'integer']], 'required' => ['create_at']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listTasks',
                'description' => '列出所有任务。无参数。返回任务数组，每个任务包含:create_at,run_at,repeat,interval,prompt,以及辅助字段run_time和create_time(人类可读时间)。',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'runTask',
                'description' => '手动触发任务调度，返回到期任务的prompt数组(索引数组，格式["prompt1","prompt2"])。无参数。',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save',
                'description' => '保存记忆。参数:level(system|important|daily|misc),role(user|assistant|system|tool),content(文本)。misc层级具有3天有效期自动清理。返回{"saved":true,"create_at":微秒时间戳,"role":"..."}或{"error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc']], 'role' => ['type' => 'string', 'enum' => ['user', 'assistant', 'system', 'tool']], 'content' => ['type' => 'string']], 'required' => ['level', 'role', 'content']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'read',
                'description' => '读取记忆。参数:level(system|important|daily|misc|all),offset(默认0),length(默认100,0=全部),date(仅daily/misc有效,YYYYMMDD,默认今天)。level=all时返回所有层级按时间升序。返回{"messages":[{"role":"","content":"","create_time":"Y-m-d H:i:s"}],"total":N}',
                'parameters'  => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all']], 'offset' => ['type' => 'integer', 'default' => 0], 'length' => ['type' => 'integer', 'default' => 100], 'date' => ['type' => 'string', 'pattern' => '^\d{8}$']], 'required' => ['level']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search',
                'description' => '搜索记忆。参数:level(含all),keywords(数组),mode(or|and,默认or),offset,length,start_date,end_date(YYYYMMDD)。返回同read()格式。',
                'parameters'  => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all']], 'keywords' => ['type' => 'array', 'items' => ['type' => 'string']], 'mode' => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'], 'offset' => ['type' => 'integer', 'default' => 0], 'length' => ['type' => 'integer', 'default' => 100], 'start_date' => ['type' => 'string', 'pattern' => '^\d{8}$'], 'end_date' => ['type' => 'string', 'pattern' => '^\d{8}$']], 'required' => ['level', 'keywords']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'delete',
                'description' => '删除记忆。参数:level(必填),keywords(逗号分隔,可选),mode(or|and),start_date/end_date(YYYYMMDD),start_time/end_time(Unix秒时间戳)。至少提供关键词或日期范围之一。返回{"deleted":N}或{"error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['level' => ['type' => 'string', 'enum' => ['system', 'important', 'daily', 'misc', 'all']], 'keywords' => ['type' => 'string', 'default' => ''], 'mode' => ['type' => 'string', 'enum' => ['or', 'and'], 'default' => 'or'], 'start_date' => ['type' => 'string', 'pattern' => '^\d{8}$'], 'end_date' => ['type' => 'string', 'pattern' => '^\d{8}$'], 'start_time' => ['type' => 'integer', 'default' => 0], 'end_time' => ['type' => 'integer', 'default' => 0]], 'required' => ['level']],
            ],
        ],
    ];
}