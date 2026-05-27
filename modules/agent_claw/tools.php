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
                'description' => '下载文件到本地（自动建目录，流式写入）。沙箱下任何路径均会被映射，最终保存路径以返回的 path 为准。返回：{"status":"success","path":"...","size":12345} 或 {"status":"error","error":"..."}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url'     => [
                            'type'        => 'string',
                            'description' => '要下载的文件 URL'
                        ],
                        'save_to' => [
                            'type'        => 'string',
                            'description' => '期望的保存路径（含文件名），可以是绝对路径或相对路径。沙箱开启时可能会映射到安全目录，请以返回的 path 为准。'
                        ],
                        'timeout' => [
                            'type'        => 'integer',
                            'description' => '超时秒数，默认 30',
                            'default'     => 30
                        ]
                    ],
                    'required'   => ['url', 'save_to']
                ]
            ]
        ]
    ];
}