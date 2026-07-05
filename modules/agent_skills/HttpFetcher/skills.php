<?php

/**
 * AgentBee - Http Fetcher Module
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

namespace modules\agent_skills\HttpFetcher;

class skills
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchHtml',
                'description' => '获取静态HTML源码（不执行JS）。成功:{status,http_url,http_code,http_html}；失败:{status,error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string', 'description' => '目标URL'],
                        'timeout' => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数'],
                        'headers' => [
                            'type'                 => 'object',
                            'additionalProperties' => ['type' => 'string'],
                            'default'              => [],
                            'description'          => '自定义请求头'
                        ]
                    ],
                    'required'   => ['url']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchText',
                'description' => '从静态HTML提取纯文本（去标签/脚本/样式，压缩空白）。成功:{status,http_url,http_code,http_text}；失败:{status,error}。',
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
                'description' => '从静态HTML智能提取正文（剔除导航/脚注）。成功:{status,http_url,http_code,http_title,http_content}；失败:{status,error}。',
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
                'description' => '从静态HTML提取所有链接（转绝对路径，去重，不执行JS）。成功返回链接数组（索引）；失败:{status,error}。',
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
                'description' => '从静态HTML提取图片及常见文件链接（pdf,zip,docx,jpg等，不执行JS）。成功:{http_url,images,files}；失败:{status,error}。',
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
                'description' => 'API请求（无参数GET，有参数POST JSON）。成功返回解析后的JSON（对象/数组）；失败:{status,error,response?}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string', 'description' => 'API URL'],
                        'params'  => ['type' => 'object', 'default' => [], 'description' => '请求参数'],
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
                'description' => '下载文件到本地（流式写入，自动建目录）。成功:{status,file_path,file_size}；失败:{status,error,http_code?}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string', 'description' => '文件URL'],
                        'save_to' => ['type' => 'string', 'description' => '保存路径（绝对或相对）'],
                        'timeout' => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数']
                    ],
                    'required'   => ['url', 'save_to']
                ],
            ],
        ],
    ];
}