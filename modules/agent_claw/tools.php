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

namespace modules\agent_claw;

class tools
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchHtml',
                'description' => '获取原始 HTML。参数:url(必填),timeout(默认30),headers(数组,如["Authorization: xxx"])。返回 {"html":"","status":int,"url":""}',
                'parameters'  => ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'timeout' => ['type' => 'integer', 'default' => 30], 'headers' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'HTTP头 "Header: value"']], 'required' => ['url']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchText',
                'description' => '提取纯文本(去标签,压缩空白)。参数:url,timeout(默认30)。返回 {"text":"","status":int,"url":""}',
                'parameters'  => ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'timeout' => ['type' => 'integer', 'default' => 30]], 'required' => ['url']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchContent',
                'description' => '智能提取正文(剔除导航/页脚)。参数:url,timeout。返回 {"title":"","content":"","status":int,"url":""}',
                'parameters'  => ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'timeout' => ['type' => 'integer', 'default' => 30]], 'required' => ['url']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'extractLinks',
                'description' => '提取所有绝对路径超链接(去重)。参数:url,timeout。返回 链接数组或 {"error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'timeout' => ['type' => 'integer', 'default' => 30]], 'required' => ['url']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'extractAssets',
                'description' => '提取图片及常见文件链接(pdf,zip,docx,jpg等)。参数:url,timeout。返回 {"images":[],"files":[],"url":""}',
                'parameters'  => ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'timeout' => ['type' => 'integer', 'default' => 30]], 'required' => ['url']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchJson',
                'description' => '请求 API 返回 JSON。GET(params为空)或POST(params非空)。参数:url,params(对象),timeout。返回 解析的JSON 或 {"error":"...","raw_body":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'params' => ['type' => 'object', 'description' => '请求参数(自动决定GET/POST)'], 'timeout' => ['type' => 'integer', 'default' => 30]], 'required' => ['url']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'downloadFile',
                'description' => '下载文件到本地(自动建目录,流式写)。沙箱下路径均映射,最终保存路径以返回的path为准。返回 {"status":"success","path":"...","size":12345} 或 {"status":"error","error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'description' => '下载URL'], 'save_to' => ['type' => 'string', 'description' => '期望保存路径(绝对/相对),沙箱开启时可能映射,以返回path为准'], 'timeout' => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数']], 'required' => ['url', 'save_to']],
            ],
        ],
    ];
}