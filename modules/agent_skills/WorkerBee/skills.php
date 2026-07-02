<?php

/**
 * Agent Worker module for AgentBee
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

namespace modules\agent_skills\WorkerBee;

class skills
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'start',
                'description' => '创建Worker子进程，异步。必须收到回复就绪后（状态为ready），才能调用talk发任务。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '唯一名称'],
                        'worker_role' => ['type' => 'string', 'description' => '角色，如"代码审查"'],
                        'init_prompt' => ['type' => 'string', 'description' => '启动后的首条指令，用于验证就绪或设定行为（勿填具体任务）']
                    ],
                    'required'   => ['worker_name', 'worker_role', 'init_prompt']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'talk',
                'description' => '向Worker进程发消息，异步，需等回复。Worker卡住/未响应时允许非ready状态打断，但禁连续多发（阻塞）。任务拆短防超时。回复自动推送。长时间无响应可重启。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => 'Worker名称'],
                        'content'     => ['type' => 'string', 'description' => '消息内容']
                    ],
                    'required'   => ['worker_name', 'content']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'close',
                'description' => '终止Worker进程。任务完成后正常关闭；长时间无响应先打断，等待无效再关；上下文过长，保存后再关。若需继续，重启Worker，可发送摘要恢复上下文。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => 'Worker名称']
                    ],
                    'required'   => ['worker_name']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'list',
                'description' => '列出所有Worker进程及其状态（ready/processing/calling_tools等）。仅限偶尔查看，禁止高频调用，以免阻塞通信。'
            ],
        ]
    ];
}