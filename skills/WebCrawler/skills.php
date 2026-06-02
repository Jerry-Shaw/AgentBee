<?php

/**
 * Agent Claw - Web Crawler Module
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

namespace skills\WebCrawler;

class skills
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchHtml',
                'description' => '获取原始HTML。返回{"html":"","status":int,"url":""}或{"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string', 'description' => '目标URL'],
                        'timeout' => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数'],
                        'headers' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => [], 'description' => '自定义HTTP头，如["Authorization: Bearer xxx"]']
                    ],
                    'required'   => ['url']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchText',
                'description' => '提取纯文本（去除HTML标签、script/style，压缩空白）。返回{"text":"","status":int,"url":""}或{"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string', 'description' => '目标URL'],
                        'timeout' => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数']
                    ],
                    'required'   => ['url']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchContent',
                'description' => '智能提取正文（剔除导航、脚注等）。返回{"title":"","content":"","status":int,"url":""}或{"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string', 'description' => '目标URL'],
                        'timeout' => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数']
                    ],
                    'required'   => ['url']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'extractLinks',
                'description' => '提取页面中所有超链接，转为绝对路径并去重。返回链接数组或{"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string', 'description' => '目标URL'],
                        'timeout' => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数']
                    ],
                    'required'   => ['url']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'extractAssets',
                'description' => '提取图片链接及常见文件链接(pdf,zip,docx,jpg等)。返回{"images":[],"files":[],"url":""}或{"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string', 'description' => '目标URL'],
                        'timeout' => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数']
                    ],
                    'required'   => ['url']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchJson',
                'description' => '请求API接口，自动根据params是否为空决定GET/POST。params为空时GET，非空时POST(JSON)。返回解析后的JSON或{"error":"...","raw_body":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string', 'description' => 'API URL'],
                        'params'  => ['type' => 'array', 'default' => [], 'description' => '请求参数数组（空数组时GET，非空时POST）'],
                        'timeout' => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数']
                    ],
                    'required'   => ['url']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'downloadFile',
                'description' => '下载文件到本地（流式写入，自动建目录）。沙箱路径会映射，最终保存路径以返回的path为准。返回{"status":"success","path":"...","size":N}或{"status":"error","error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string', 'description' => '文件URL'],
                        'save_to' => ['type' => 'string', 'description' => '期望保存路径（绝对或相对）'],
                        'timeout' => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数']
                    ],
                    'required'   => ['url', 'save_to']
                ],
            ],
        ],
    ];
}