<?php

/**
 * Agent Calculator tools example for AgentBee core
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

namespace skills\Calculator;

class skills
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'add',
                'description' => '计算两个整数的和。成功返回 {status, message, data:{result}}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'a' => ['type' => 'integer', 'description' => '第一个加数'],
                        'b' => ['type' => 'integer', 'description' => '第二个加数'],
                    ],
                    'required'   => ['a', 'b'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'subtract',
                'description' => '计算两个整数的差。成功返回 {status, message, data:{result}}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'a' => ['type' => 'integer', 'description' => '被减数'],
                        'b' => ['type' => 'integer', 'description' => '减数'],
                    ],
                    'required'   => ['a', 'b'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'multiply',
                'description' => '计算两个整数的乘积。成功返回 {status, message, data:{result}}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'a' => ['type' => 'integer', 'description' => '第一个乘数'],
                        'b' => ['type' => 'integer', 'description' => '第二个乘数'],
                    ],
                    'required'   => ['a', 'b'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'divide',
                'description' => '计算两个整数相除的商。除数不得为零；除零时返回 {status:"error", error}，否则返回 {status, message, data:{result}}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'a' => ['type' => 'integer', 'description' => '被除数'],
                        'b' => ['type' => 'integer', 'description' => '除数，不得为零'],
                    ],
                    'required'   => ['a', 'b'],
                ],
            ],
        ],
    ];
}