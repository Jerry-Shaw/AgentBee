<?php

/**
 * Agent tools module for AgentBee core
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

namespace tools\System;

class tools
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'exec',
                'description' => '执行系统命令。program为可执行文件(如powershell,git,php)，禁止cmd内置命令。argv数组(默认[])。timeout秒(默认30)，work_path可选。返回{"output":"","error":""}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program'   => ['type' => 'string', 'description' => '可执行程序名'],
                        'argv'      => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => [], 'description' => '命令行参数数组'],
                        'timeout'   => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数'],
                        'work_path' => ['type' => 'string', 'default' => '', 'description' => '工作目录(空则用配置)']
                    ],
                    'required'   => ['program']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getTime',
                'description' => '获取当前系统时间。返回{"datetime":"YYYY-MM-DD HH:MM:SS","timestamp":秒数}'
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'cleanContext',
                'description' => '清理对话上下文。保留最近max_dialog_messages条普通消息(默认10)和keep_tool_pairs组工具对(默认2)。force_clean=true允许全清(谨慎)。调用前需用记忆工具保存重要内容。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'max_dialog_messages' => ['type' => 'integer', 'default' => 10, 'description' => '保留的普通消息最大条数'],
                        'keep_tool_pairs'     => ['type' => 'integer', 'default' => 2, 'description' => '保留的最近工具调用对数量'],
                        'force_clean'         => ['type' => 'boolean', 'default' => false, 'description' => '是否强制全清(允许0)']
                    ]
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readImage',
                'description' => '读取图片返回Data URL。返回status,content,filename,mime_type等。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['file_path' => ['type' => 'string', 'description' => '图片文件路径']],
                    'required'   => ['file_path']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readFile',
                'description' => '读取文件内容。offset(默认0), limit(默认8192,0=全部)。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string', 'description' => '文件路径'],
                        'offset'    => ['type' => 'integer', 'default' => 0, 'description' => '起始偏移字节'],
                        'limit'     => ['type' => 'integer', 'default' => 8192, 'description' => '读取字节数(0=全部)']
                    ],
                    'required'   => ['file_path']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeFile',
                'description' => '写入文件(自动建目录)。append=false覆盖。单次content≤4096字符，大文件分多次追加。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string', 'description' => '文件路径'],
                        'content'   => ['type' => 'string', 'description' => '要写入的内容'],
                        'append'    => ['type' => 'boolean', 'default' => false, 'description' => '是否追加模式']
                    ],
                    'required'   => ['file_path', 'content']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyFile',
                'description' => '复制文件，目标存在则覆盖。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src_file_path' => ['type' => 'string', 'description' => '源文件路径'],
                        'dst_file_path' => ['type' => 'string', 'description' => '目标文件路径']
                    ],
                    'required'   => ['src_file_path', 'dst_file_path']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => '永久删除文件(危险)，操作前需用户确认。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['file_path' => ['type' => 'string', 'description' => '要删除的文件路径']],
                    'required'   => ['file_path']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getFileSize',
                'description' => '获取文件字节数。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['file_path' => ['type' => 'string', 'description' => '文件路径']],
                    'required'   => ['file_path']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'searchFiles',
                'description' => 'glob模式搜索文件，recursive默认false。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'dir_path'  => ['type' => 'string', 'description' => '搜索起始目录'],
                        'pattern'   => ['type' => 'string', 'description' => 'glob模式(如*.php)'],
                        'recursive' => ['type' => 'boolean', 'default' => false, 'description' => '是否递归子目录']
                    ],
                    'required'   => ['dir_path', 'pattern']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => '列出目录内容(非递归)。返回每个文件/文件夹的: name(名称), size(字节), is_file(是否文件), relative_path(相对目录的路径), absolute_path(绝对路径)。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['dir_path' => ['type' => 'string', 'description' => '目录路径']],
                    'required'   => ['dir_path']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => '创建目录(自动建父目录)。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['dir_path' => ['type' => 'string', 'description' => '目录路径']],
                    'required'   => ['dir_path']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => '递归复制目录，overwrite默认false(不覆盖已有目录)。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src_dir_path' => ['type' => 'string', 'description' => '源目录路径'],
                        'dst_dir_path' => ['type' => 'string', 'description' => '目标目录路径'],
                        'overwrite'    => ['type' => 'boolean', 'default' => false, 'description' => '是否覆盖已有目标目录']
                    ],
                    'required'   => ['src_dir_path', 'dst_dir_path']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteDirectory',
                'description' => '递归删除目录(危险)，操作前需用户确认。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['dir_path' => ['type' => 'string', 'description' => '要删除的目录路径']],
                    'required'   => ['dir_path']
                ],
            ],
        ],
    ];
}