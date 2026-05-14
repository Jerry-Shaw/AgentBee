<?php

/**
 * Agent Tools Metadata for AgentBee
 *
 * Provides tool definitions conforming to OpenAI's function-calling standard format.
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

namespace modules\agent_tools;

class tools
{
    public const META = [
        // 执行命令
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'exec',
                'description' => "执行系统命令（危险）。\n" .
                    "规则：program 只能是可执行文件，参数放 argv 数组，禁止内置命令。超时 timeout 秒（默认300）无输出则终止。\n" .
                    "示例：{\"program\":\"ls\", \"argv\":[\"-la\"], \"timeout\":10, \"work_path\":\"/home\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program'   => ['type' => 'string'],
                        'argv'      => ['type' => 'array', 'items' => ['type' => 'string']],
                        'timeout'   => ['type' => 'integer', 'default' => 300],
                        'work_path' => ['type' => 'string']
                    ],
                    'required'   => ['program', 'argv']
                ]
            ]
        ],

        // 读文件
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readFile',
                'description' => "读取文件内容。limit=0 读整个文件。返回 {\"content\":\"...\"}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => ['type' => 'string'],
                        'offset' => ['type' => 'integer', 'default' => 0],
                        'limit'  => ['type' => 'integer', 'default' => 8192]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],

        // 写文件
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeFile',
                'description' => "写入文件（自动创建目录）。append=true 追加。返回 {\"bytes_written\": N}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'    => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'append'  => ['type' => 'boolean', 'default' => false]
                    ],
                    'required'   => ['path', 'content']
                ]
            ]
        ],

        // 删文件
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => "永久删除文件（危险）。操作前需警告用户。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ],

        // 搜索文件
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'searchFiles',
                'description' => "glob 模式搜索文件。recursive=true 递归。返回 {\"files\":[...]}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'      => ['type' => 'string'],
                        'pattern'   => ['type' => 'string'],
                        'recursive' => ['type' => 'boolean', 'default' => false]
                    ],
                    'required'   => ['path', 'pattern']
                ]
            ]
        ],

        // 文件大小
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getFileSize',
                'description' => "获取文件字节数。返回 {\"filesize\": N}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ],

        // 列表目录
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => "列出目录内容。返回 {\"contents\":[{\"filename\":\"...\",\"filesize\":N,\"isFile\":bool}]}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ],

        // 创建目录
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => "创建目录（自动创建父目录）。返回 {\"created_path\":\"...\"}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ],

        // 复制目录
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => "递归复制目录，目标必须不存在。返回 {\"copied_files\":N, \"destination\":\"...\"}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src' => ['type' => 'string'],
                        'dst' => ['type' => 'string']
                    ],
                    'required'   => ['src', 'dst']
                ]
            ]
        ],

        // 删除目录
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteDirectory',
                'description' => "递归删除目录（危险）。操作前警告用户。返回 {\"deleted\":true, \"files_removed\":N}。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ]
    ];
}