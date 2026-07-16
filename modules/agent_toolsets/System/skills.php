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

namespace modules\agent_toolsets\System;

class skills
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'cleanContext',
                'description' => '清理历史上下文，异步。保留最近消息和工具对，调用后继续任务。务必先保存重要记忆。返回：无。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'keep_normal'     => ['type' => 'integer', 'default' => 6, 'description' => '保留普通消息数'],
                        'keep_tool_pairs' => ['type' => 'integer', 'default' => 2, 'description' => '保留工具对数'],
                        'aggressive_mode' => ['type' => 'boolean', 'default' => false, 'description' => '允许低于下限'],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'loadSkill',
                'description' => '加载专项技能的完整指令。当用户请求匹配某个技能的触发词时，必须调用此工具获取完整执行步骤。返回：{status, skill_path, skill_data}或{status, message/error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'skill_name' => [
                            'type'        => 'string',
                            'description' => '技能名称（即技能目录名，如 "code-reviewer"）'
                        ],
                    ],
                    'required'   => ['skill_name'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'exec',
                'description' => '执行系统命令（仅当无专用工具时可用）。program必须为可执行文件或脚本，不支持cmd内部命令（如dir、echo）。argv为参数数组，timeout为超时时长（默认30秒），descriptor为I/O类型（默认"socket"），work_path可选（默认为工作区）。返回：{output, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program'    => ['type' => 'string', 'description' => '可执行程序名或脚本'],
                        'argv'       => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => [], 'description' => '命令行参数数组'],
                        'timeout'    => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数'],
                        'descriptor' => ['type' => 'string', 'enum' => ['socket', 'pipe'], 'default' => 'socket', 'description' => 'I/O类型："socket"（默认，常规程序，双向非阻塞），"pipe"（依赖管道环境的程序，可能阻塞）'],
                        'work_path'  => ['type' => 'string', 'default' => '', 'description' => '工作目录（空则用工作区）']
                    ],
                    'required'   => ['program']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getTime',
                'description' => '获取当前系统时间。返回：{datetime, timestamp}。'
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readImage',
                'description' => '读取图片文件，返回图片的元信息，并将图片 Data URL 附于上下文。返回：{status, message, filename, mime_type}。',
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
                'description' => '读取文件内容。offset起始偏移字节（默认0），limit读取字节数（默认8192，0表示全部）。返回：{status, content, file_path}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string', 'description' => '文件路径'],
                        'offset'    => ['type' => 'integer', 'default' => 0, 'description' => '起始偏移字节'],
                        'limit'     => ['type' => 'integer', 'default' => 8192, 'description' => '读取字节数（0=全部）']
                    ],
                    'required'   => ['file_path']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeFile',
                'description' => '写入文件（自动创建目录）。append=false时覆盖，建议单次content不超过4096字符，大文件请分多次追加。返回：{status, file_path, bytes_written}或{status, error}。',
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
                'description' => '复制文件，目标存在则覆盖。返回：{status, src_file_path, dst_file_path}或{status, error}。',
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
                'description' => '永久删除文件（危险），操作前需用户确认。返回：{status, file_path}或{status, error}。',
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
                'description' => '获取文件字节数。返回：{status, file_path, filesize}或{status, error}。',
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
                'description' => '使用glob模式搜索文件。请明确指定是否递归搜索子目录。返回：{status, dir_path, files}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'dir_path'  => ['type' => 'string', 'description' => '搜索起始目录'],
                        'pattern'   => ['type' => 'string', 'description' => 'glob模式（如 *.php）'],
                        'recursive' => ['type' => 'boolean', 'description' => '是否递归子目录（true递归，false仅当前目录）']
                    ],
                    'required'   => ['dir_path', 'pattern', 'recursive']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => '列出目录内容（非递归）。返回：{status, dir_path, contents（含 name, size, is_file, relative_path, absolute_path）}或{status, error}。',
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
                'description' => '创建目录（自动创建父目录）。返回：{status, dir_path}。',
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
                'description' => '递归复制目录，overwrite默认false（不覆盖已有目录）。返回：{status, src_dir_path, dst_dir_path, copied_files}或{status, error}。',
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
                'description' => '递归删除目录（危险），操作前需用户确认。返回：{status, dir_path, files_removed}或{status, error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['dir_path' => ['type' => 'string', 'description' => '要删除的目录路径']],
                    'required'   => ['dir_path']
                ],
            ],
        ],
    ];
}