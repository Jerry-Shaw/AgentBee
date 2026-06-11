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

namespace tools\WorkerBee;

class tools
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'start',
                'description' => '创建Worker子进程(独立LLM会话，可并行)。异步，结果稍后推送。建议按需分配角色协同完成任务。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'name'   => ['type' => 'string', 'description' => 'Worker名称，如reviewer'],
                        'role'   => ['type' => 'string', 'description' => 'Worker角色，如"代码审查员"'],
                        'prompt' => ['type' => 'string', 'description' => '系统指令：角色、目标、边界']
                    ],
                    'required'   => ['name', 'role', 'prompt']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'talk',
                'description' => '向Worker发送消息。异步，结果稍后推送。建议逐句发送并等待回复，避免连续多条导致崩溃。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_id' => ['type' => 'string', 'description' => 'Worker ID'],
                        'message'   => ['type' => 'string', 'description' => '消息内容']
                    ],
                    'required'   => ['worker_id', 'message']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'close',
                'description' => '终止Worker进程并释放资源。建议任务完成或上下文过长时主动保存记忆后调用。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_id' => ['type' => 'string', 'description' => 'Worker ID']
                    ],
                    'required'   => ['worker_id']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'list',
                'description' => '列出所有Worker及其状态。异步，结果稍后推送。'
            ],
        ]
    ];
}