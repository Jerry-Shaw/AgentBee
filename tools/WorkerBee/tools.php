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
                'description' => '创建Worker子进程(独立LLM会话，可并行)。可共享/操作全局记忆(创建时请知晓)。异步，结果稍后推送。大型任务可启用多Worker分工协作。worker_name须唯一。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name'   => ['type' => 'string', 'description' => 'Worker名称，须唯一'],
                        'worker_role'   => ['type' => 'string', 'description' => 'Worker角色，如"代码审查员"'],
                        'system_prompt' => ['type' => 'string', 'description' => '系统指令：角色、目标、边界']
                    ],
                    'required'   => ['worker_name', 'worker_role', 'system_prompt']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'talk',
                'description' => '向Worker发送消息。异步，结果稍后推送。必须逐句发送并等待回复，禁止连续多条。若长时间无回复，可考虑重启进程。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => 'Worker名称'],
                        'message'     => ['type' => 'string', 'description' => '消息内容']
                    ],
                    'required'   => ['worker_name', 'message']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'close',
                'description' => '终止Worker进程并释放资源。建议任务完成或上下文过长时主动保存记忆后调用。若需继续，请重启同名Worker并发送摘要恢复上下文。',
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
                'description' => '列出所有Worker及其状态。异步，结果稍后推送。'
            ],
        ]
    ];
}