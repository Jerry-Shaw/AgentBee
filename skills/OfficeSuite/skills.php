<?php

/**
 * Agent Doc Module - Unified Office Document Processing Module META file
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

namespace skills\OfficeSuite;

class skills
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readDocx',
                'description' => '读取DOCX文件纯文本内容。返回{"status":"success","content":"文本","file":"文件名"}或{"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'DOCX文件路径']
                    ],
                    'required'   => ['path']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeDocx',
                'description' => '写入DOCX文件。data数组内可混合字符串(段落文本)和图片对象。图片对象格式:{"type":"image","content":"绝对路径","width":px(默认200)}。append=true保留原文档全部内容。示例: ["标题",{"type":"image","content":"/a.png"},"正文"]。返回{"status":"success","path":"...","message":"..."}或{"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => ['type' => 'string', 'description' => '输出文件路径'],
                        'data'   => ['type' => 'array', 'description' => '内容数组，可混合文本字符串和图片对象'],
                        'append' => ['type' => 'boolean', 'default' => false, 'description' => '是否追加到现有文档']
                    ],
                    'required'   => ['path', 'data']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readXlsx',
                'description' => '读取XLSX文件所有工作表。返回{"status":"success","sheets":{"Sheet1":[[...]]}}或{"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'XLSX文件路径']
                    ],
                    'required'   => ['path']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeXlsx',
                'description' => '写入XLSX文件。data支持两种格式: 1)单表:二维数组,每行数组如[["姓名","年龄"],["张三",25]]；2)多表:[{"name":"Sheet1","rows":[...]}]。append=true向现有工作表追加行或新建表。返回{"status":"success","path":"...","sheets_count":N}或{"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => ['type' => 'string', 'description' => '输出文件路径'],
                        'data'   => ['type' => 'array', 'description' => '工作表数据(二维数组或多表对象数组)'],
                        'append' => ['type' => 'boolean', 'default' => false, 'description' => '是否追加到现有文件']
                    ],
                    'required'   => ['path', 'data']
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readPptx',
                'description' => '读取PPTX所有幻灯片文本和图片。返回{status,slides[{number,title,content,images[{path,width,height,x,y,ext}]}],images_temp_dir}或{error}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'PPTX文件路径']
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writePptx',
                'description' => '写入PPTX文件。data每项为幻灯片对象:{"title":"","content":"","image":"绝对路径可选","image_x":EMU(默认8000000),"image_y":500000,"image_width":2540000,"image_height":1905000}。EMU与像素换算:1px≈9525 EMU。append=true追加幻灯片并保留原图(图片位置恢复默认)。返回{"status":"success","path":"...","slides_count":N}或{"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => ['type' => 'string', 'description' => '输出文件路径'],
                        'data'   => ['type' => 'array', 'description' => '幻灯片数据数组，每项为对象'],
                        'append' => ['type' => 'boolean', 'default' => false, 'description' => '是否追加到现有演示文稿']
                    ],
                    'required'   => ['path', 'data']
                ],
            ],
        ],
    ];
}