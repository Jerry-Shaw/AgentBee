<?php

/**
 * Agent System toolset for AgentBee core
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
                'description' => '上下文过长时清理较早的消息，保留摘要及近期消息；返回history衔接摘要。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'history_summary' => ['type' => 'string', 'description' => '续接摘要：目标、背景、约束、进展、结果、决策、问题、风险、下一步；调用前先保存重要记忆。排除系统设定、工具流水、记忆操作和清理过程。'],
                        'keep_normal'     => ['type' => 'integer', 'default' => 6, 'description' => '保留最近普通消息条数，至少6条。'],
                        'max_tool_pairs'  => ['type' => 'integer', 'default' => 2, 'description' => '保留最近工具调用及对应结果的对数，至少2条。']
                    ],
                    'required'   => ['history_summary']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'loadSkill',
                'description' => '加载任务匹配的专项技能；返回skill_path、skill_data，按需附dependencies、resources及install_guide。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'skill_name' => ['type' => 'string', 'description' => '技能准确目录名，如SkillCreator；不传路径或文件名。']
                    ],
                    'required'   => ['skill_name']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'notification',
                'description' => '主动提醒任务完成、定时事项或异常时发送桌面通知；返回结果文本。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title'   => ['type' => 'string', 'description' => '事件标题，建议≤20字符。'],
                        'message' => ['type' => 'string', 'description' => '简短纯文本，说明事件、结果或待办行动。'],
                        'level'   => ['type' => 'string', 'description' => 'info=一般（蓝），warning=注意（黄），error=错误（红），success=完成（绿）；默认info，配色仅Windows有效。']
                    ],
                    'required'   => ['title', 'message']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'exec',
                'description' => '运行外部程序；沙箱关闭时可用。返回stdout、stderr；status不代表程序退出码。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program'    => ['type' => 'string', 'description' => '可执行程序路径或PATH名称；参数放argv。脚本用解释器运行，脚本路径放argv首项；不直接传shell内建命令或整段命令行。'],
                        'argv'       => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => [], 'description' => '有序参数数组，每项一个完整参数；含空格也不拆分、不加shell引号。管道、重定向、命令连接符不自动解析。'],
                        'timeout'    => ['type' => 'integer', 'default' => 30, 'description' => '连续无标准/错误输出的超时秒数，非负；收到输出重置，超时终止。0=无限，非总运行时限。'],
                        'descriptor' => ['type' => 'string', 'enum' => ['socket', 'pipe'], 'default' => 'socket', 'description' => 'I/O通道：socket适合常规程序；pipe供依赖管道的程序使用，可能阻塞。'],
                        'work_path'  => ['type' => 'string', 'default' => '', 'description' => '已存在的进程工作目录，相对路径以此解析；空=默认工作区。']
                    ],
                    'required'   => ['program']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readFile',
                'description' => '读取/搜索文本、载入图片或读取二进制。文本自动转UTF-8，支持LF/CRLF，单行≤16MB。返回file_type；文本含带行号content、total_lines，搜索另含total_matches；二进制含十六进制content、total_bytes；图片载入视觉上下文。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'file_path'     => ['type' => 'string', 'description' => '本地文件路径。'],
                        'read_range'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'default' => [1, 200], 'description' => '文本：[起始行,结束行]，从1开始，含首尾。二进制：[偏移,字节数]，非负；偏移从0开始，字节数省略或0=至末尾，默认值跳过首字节。[]=全文，越界截至EOF；图片忽略。'],
                        'pattern'       => ['type' => 'string', 'default' => '', 'description' => '范围内逐行字面搜索，忽略大小写，禁止换行/NUL；空=读取。二进制须为空，图片忽略。'],
                        'context_lines' => ['type' => 'array', 'items' => ['type' => 'integer'], 'default' => [3, 3], 'description' => '搜索上下文[前行数,后行数]，须为两个非负整数；限于read_range，重叠去重。普通文本读取仍校验；图片、二进制忽略。'],
                        'render_image'  => ['type' => 'boolean', 'default' => false, 'description' => '图片是否同时展示给用户；不影响模型载入，其他文件忽略。']
                    ],
                    'required'   => ['file_path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'editFile',
                'description' => '顺序编辑单个UTF-8文本文件，后项基于前项结果；整批成功才提交，失败不改目标文件。原文及输入禁NUL，LF/CRLF统一为LF，单行≤16MiB。返回bytes_written（最终字节数）、edits（逐项结果）、replacements（仅replace计数之和）。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string', 'description' => '目标文件路径；不存在时首项须为write，自动创建缺失目录。'],
                        'edits'     => [
                            'type'        => 'array', 'minItems' => 1,
                            'description' => '非空列表，每项必填op/new，禁止无关字段。行号从1起，按前项结果计算；空文件0行，末尾换行不另计一行。按行编辑前先读取核对。',
                            'items'       => [
                                'type'       => 'object', 'additionalProperties' => false,
                                'properties' => [
                                    'op'    => ['type' => 'string', 'enum' => ['write', 'replace', 'insert_lines', 'replace_lines'], 'description' => 'write全文写入；replace字面替换，另需old、可选limit；insert_lines行前插入，另需line；replace_lines替换闭区间行，另需start/end。'],
                                    'old'   => ['type' => 'string', 'description' => '仅replace必填：非空原文，区分大小写的跨行字面匹配，换行归一为LF；无匹配则整批失败。'],
                                    'new'   => ['type' => 'string', 'description' => '写入文本，字面转义不解码；按行编辑必要时补换行。空串：write清空，replace/replace_lines删除，insert_lines不插入。'],
                                    'limit' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '仅replace可用：从头非重叠替换上限，0=全部。'],
                                    'line'  => ['type' => 'integer', 'minimum' => 1, 'description' => '仅insert_lines必填：1≤line≤当前总行数+1，末行+1为追加。'],
                                    'start' => ['type' => 'integer', 'minimum' => 1, 'description' => '仅replace_lines必填：起始行，含本行。'],
                                    'end'   => ['type' => 'integer', 'minimum' => 1, 'description' => '仅replace_lines必填：结束行，含本行；start≤end≤当前总行数，越界整批失败。']
                                ],
                                'required'   => ['op', 'new']
                            ]
                        ]
                    ],
                    'required'   => ['file_path', 'edits']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyFile',
                'description' => '复制单个文件，用于创建副本或覆盖目标文件。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src_file_path' => ['type' => 'string', 'description' => '已有源文件路径，不能是目录。'],
                        'dst_file_path' => ['type' => 'string', 'description' => '目标文件路径（含文件名），父目录须存在；目标已存在则覆盖，不得与源文件相同。']
                    ],
                    'required'   => ['src_file_path', 'dst_file_path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => '永久删除单个文件，不进回收站。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'file_path' => ['type' => 'string', 'description' => '待删除的文件路径，不接受目录。']
                    ],
                    'required'   => ['file_path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'searchFiles',
                'description' => '按文件名或扩展名定位文件；返回files路径列表。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'dir_path'  => ['type' => 'string', 'description' => '已存在的搜索起始目录。'],
                        'pattern'   => ['type' => 'string', 'description' => '文件名glob，如*.php或完整文件名；*=零或多个字符，?=单字符。非正则，不搜内容。'],
                        'recursive' => ['type' => 'boolean', 'description' => 'true=含各级子目录；false=仅起始目录。']
                    ],
                    'required'   => ['dir_path', 'pattern', 'recursive']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => '查看目录直属内容，不递归；返回contents子项列表，含名称、大小、文件标记及相对/绝对路径。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'dir_path' => ['type' => 'string', 'description' => '待查看的已有目录路径。']
                    ],
                    'required'   => ['dir_path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => '创建目录及缺失的父目录，用于准备存放位置。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'dir_path' => ['type' => 'string', 'description' => '目标目录路径，非文件路径；目录已存在则保留内容。']
                    ],
                    'required'   => ['dir_path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => '递归复制目录树或合并至已有目录；返回copied_files文件数。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src_dir_path' => ['type' => 'string', 'description' => '已有源目录路径，复制后保留。'],
                        'dst_dir_path' => ['type' => 'string', 'description' => '目标目录路径，源内容直接复制至此，不追加源目录名；不存在则创建，不得指向源目录或其内部。'],
                        'overwrite'    => ['type' => 'boolean', 'default' => false, 'description' => 'false=目标目录已存在即报错；true=合并并覆盖同名文件，保留目标独有文件。']
                    ],
                    'required'   => ['src_dir_path', 'dst_dir_path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteDirectory',
                'description' => '永久删除整个目录树，不进回收站；返回files_removed文件数。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'dir_path' => ['type' => 'string', 'description' => '已有目标目录路径；删除目录本身及全部子目录、文件。']
                    ],
                    'required'   => ['dir_path']
                ]
            ]
        ],
    ];
}