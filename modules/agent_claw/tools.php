<?php

/**
 * Agent Claw Tools Metadata for AgentBee
 *
 * High-efficiency web data acquisition with noise reduction.
 * Optimized for LLM token usage.
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

namespace modules\agent_claw;

class tools
{
    public const META = [
        // 1. 原始 HTML
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchHtml',
                'description' => "获取网页原始 HTML 源代码。\n\n" .
                    "用途：分析 DOM 结构、查找特定标签、提取元数据（Meta Tags）。\n\n" .
                    "参数：\n" .
                    "- url (string, 必填): 完整 URL\n" .
                    "- timeout (int, 可选): 超时秒数，默认 30\n" .
                    "- headers (array, 可选): 自定义请求头，如 ['Authorization: Bearer xxx']\n\n" .
                    "返回：成功 → {'html': string, 'status': int, 'url': string}；失败 → {'error': string}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string', 'description' => '目标网页完整 URL'],
                        'timeout' => ['type' => 'integer', 'default' => 30],
                        'headers' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '自定义 HTTP 头，每项如 "Header: value"']
                    ],
                    'required'   => ['url']
                ]
            ]
        ],

        // 2. 纯文本（清洗后）
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchText',
                'description' => "提取网页纯文本，自动移除 script/style/HTML 标签，压缩空白字符。\n\n" .
                    "用途：快速了解页面大意，最大限度节省 Token。\n\n" .
                    "参数：url (string, 必填), timeout (int, 可选，默认 30)\n\n" .
                    "返回：成功 → {'text': string, 'status': int, 'url': string}；失败 → {'error': string}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'timeout' => ['type' => 'integer', 'default' => 30]
                    ],
                    'required'   => ['url']
                ]
            ]
        ],

        // 3. 正文内容（智能提取）
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchContent',
                'description' => "智能提取网页正文（标题 + 主体内容），剔除导航、页脚、头部等噪音。\n\n" .
                    "用途：深度阅读长文章、新闻或技术文档。\n\n" .
                    "参数：url (string, 必填), timeout (int, 可选，默认 30)\n\n" .
                    "返回：成功 → {'title': string, 'content': string, 'status': int, 'url': string}；失败 → {'error': string}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'timeout' => ['type' => 'integer', 'default' => 30]
                    ],
                    'required'   => ['url']
                ]
            ]
        ],

        // 4. 提取所有链接
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'extractLinks',
                'description' => "提取页面中所有超链接，并转换为绝对路径。\n\n" .
                    "用途：探索网站结构、寻找相关文章、深度爬取。\n\n" .
                    "参数：url (string, 必填), timeout (int, 可选，默认 30)\n\n" .
                    "返回：成功 → 链接数组（去重后的绝对 URL）；失败 → {'error': string}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'timeout' => ['type' => 'integer', 'default' => 30]
                    ],
                    'required'   => ['url']
                ]
            ]
        ],

        // 5. 提取资源（图片、文件）
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'extractAssets',
                'description' => "提取页面中的图片链接（<img src>）和常见文件下载链接（pdf, zip, docx, jpg, png 等）。\n\n" .
                    "参数：url (string, 必填), timeout (int, 可选，默认 30)\n\n" .
                    "返回：成功 → {'images': array, 'files': array, 'url': string}；失败 → {'error': string}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'timeout' => ['type' => 'integer', 'default' => 30]
                    ],
                    'required'   => ['url']
                ]
            ]
        ],

        // 6. JSON API 请求
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchJson',
                'description' => "请求 API 接口并返回解析后的 JSON 对象。\n\n" .
                    "行为规则：\n" .
                    "- 若 params 为空或未提供 → GET 请求，参数作为 QueryString\n" .
                    "- 若 params 非空 → POST 请求，Content-Type: application/json，params 自动编码为 JSON Body\n\n" .
                    "参数：\n" .
                    "- url (string, 必填): API 地址\n" .
                    "- params (object, 可选): 请求参数\n" .
                    "- timeout (int, 可选): 超时秒数，默认 30\n\n" .
                    "返回：成功 → 解析后的 JSON 数据（数组）；失败 → {'error': '...', 'raw_body': '...'}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'params'  => ['type' => 'object', 'description' => '请求参数，自动决定 GET/POST'],
                        'timeout' => ['type' => 'integer', 'default' => 30]
                    ],
                    'required'   => ['url']
                ]
            ]
        ],

        // 7. 下载文件
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'downloadFile',
                'description' => "下载远程文件并保存到本地绝对路径。\n\n" .
                    "用途：持久化存储素材、离线分析文件。\n\n" .
                    "参数：\n" .
                    "- url (string, 必填): 文件下载链接\n" .
                    "- save_to (string, 必填): 本地保存的绝对路径（含文件名）\n" .
                    "- timeout (int, 可选): 超时秒数，默认 30\n\n" .
                    "返回：成功 → {'saved': true, 'path': string, 'size': int, 'url': string}；失败 → {'saved': false, 'error': string}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'save_to' => ['type' => 'string'],
                        'timeout' => ['type' => 'integer', 'default' => 30]
                    ],
                    'required'   => ['url', 'save_to']
                ]
            ]
        ],
    ];
}