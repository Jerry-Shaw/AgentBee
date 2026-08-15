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

namespace modules\agent_toolsets\WorkerBee;

class skills
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'start',
                'description' => '创建异步Worker子进程。进程回复“已就绪”后，才可调用talk进行交互。所有回复异步推送，按需调用talk保持互动，直至完成。适用于对抗式辩论/多进程协作等场景。返回{message}。',
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
                'description' => '向Worker子进程发送消息，回复将异步推送。收到回复后必须根据回复内容判断是否延续对话，期间可处理其他任务，无需等待。长消息建议拆分，禁止重发。返回：{message}。',
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
                'description' => '终止Worker子进程并释放资源。用于任务完成、进程无响应或上下文过长需重置时。大量输出时关闭可能偶发短暂阻塞。如需继续，重新启动并传入摘要。返回 {message}。',
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
                'description' => '获取所有活跃Worker子进程的状态（ready/busy/streaming/calling_tools）。仅调试用，禁止连续调用影响通信。返回状态列表。'
            ],
        ]
    ];
}