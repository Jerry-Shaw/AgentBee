<?php

namespace modules\agent_claw;

class tools
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchHtml',
                'description' => "获取网页原始 HTML。参数：url（必填），timeout（默认30），headers（数组，如 ['Authorization: xxx']）。返回：{\"html\":\"...\",\"status\":int,\"url\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'timeout' => ['type' => 'integer', 'default' => 30],
                        'headers' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'HTTP头，每项 "Header: value"'],
                    ],
                    'required'   => ['url'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchText',
                'description' => "提取网页纯文本（去标签、压缩空白）。参数：url，timeout（默认30）。返回：{\"text\":\"...\",\"status\":int,\"url\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'timeout' => ['type' => 'integer', 'default' => 30],
                    ],
                    'required'   => ['url'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchContent',
                'description' => "智能提取网页正文（剔除导航/页脚）。参数：url，timeout。返回：{\"title\":\"...\",\"content\":\"...\",\"status\":int,\"url\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'timeout' => ['type' => 'integer', 'default' => 30],
                    ],
                    'required'   => ['url'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'extractLinks',
                'description' => "提取页面所有超链接（绝对路径）。参数：url，timeout。返回：链接数组（去重）或 {\"error\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'timeout' => ['type' => 'integer', 'default' => 30],
                    ],
                    'required'   => ['url'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'extractAssets',
                'description' => "提取页面图片和常见文件链接（pdf, zip, docx, jpg等）。参数：url，timeout。返回：{\"images\":[...],\"files\":[...],\"url\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'timeout' => ['type' => 'integer', 'default' => 30],
                    ],
                    'required'   => ['url'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'fetchJson',
                'description' => "请求 API 并返回 JSON。GET（params为空）或 POST（params非空）。参数：url，params（对象），timeout。返回：解析后的 JSON 或 {\"error\":\"...\",\"raw_body\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'params'  => ['type' => 'object', 'description' => '请求参数，自动决定GET/POST'],
                        'timeout' => ['type' => 'integer', 'default' => 30],
                    ],
                    'required'   => ['url'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'downloadFile',
                'description' => "下载文件到本地绝对路径。参数：url，save_to（本地路径含文件名），timeout（默认30）。返回：{\"saved\":true,\"path\":\"...\",\"size\":int} 或 {\"saved\":false,\"error\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => ['type' => 'string'],
                        'save_to' => ['type' => 'string'],
                        'timeout' => ['type' => 'integer', 'default' => 30],
                    ],
                    'required'   => ['url', 'save_to'],
                ],
            ],
        ],
    ];
}