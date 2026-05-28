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

namespace modules\agent_doc;

class tools
{
    public const META = [
        // readDocx
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readDocx',
                'description' => '读取 DOCX 文档内容。参数：path(string, 必填, 绝对路径)。返回：{"status":"success","content":"文本内容"} 或 {"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string']
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        // writeDocx
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeDocx',
                'description' => '写入 DOCX 文档。参数：path(string, 必填), data(array, 必填, 每项为一段文字)。返回：{"status":"success","path":"..."} 或 {"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'data' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => '段落文本列表'
                        ]
                    ],
                    'required'   => ['path', 'data']
                ]
            ]
        ],
        // readXlsx
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readXlsx',
                'description' => '读取 XLSX 表格内容。参数：path(string, 必填)。返回：{"status":"success","sheets":{"Sheet1":[[row1],[row2],...]}} 或 {"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string']
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        // writeXlsx
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeXlsx',
                'description' => '写入 XLSX 表格。参数：path(string, 必填), data(array, 必填, 可接受简单二维数组或带 name/rows 的多工作表格式)。返回：{"status":"success","path":"..."} 或 {"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'data' => [
                            'type'        => 'array',
                            'description' => '数据，可单工作表二维数组或多工作表 [["name"=>"Sheet1","rows"=>[...]]]'
                        ]
                    ],
                    'required'   => ['path', 'data']
                ]
            ]
        ],
        // readPptx
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readPptx',
                'description' => '读取 PPTX 演示文稿内容。参数：path(string, 必填)。返回：{"status":"success","slides":[{"number":1,"title":"...","content":"..."}]} 或 {"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string']
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        // writePptx
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writePptx',
                'description' => '写入 PPTX 演示文稿。参数：path(string, 必填), data(array, 必填, 每项为幻灯片，可含 title(string)、content(string) 和可选 image(string)、image_x(int)、image_y(int)、image_width(int)、image_height(int) 字段，坐标和尺寸单位为 EMU（1英寸=914400，1cm=360000，1pt=12700）)。返回：{"status":"success","path":"..."} 或 {"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'data' => [
                            'type'        => 'array',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'title'        => ['type' => 'string'],
                                    'content'      => ['type' => 'string'],
                                    'image'        => ['type' => 'string'],
                                    'image_x'      => ['type' => 'integer', 'description' => '图片左上角X坐标(EMU)'],
                                    'image_y'      => ['type' => 'integer', 'description' => '图片左上角Y坐标(EMU)'],
                                    'image_width'  => ['type' => 'integer', 'description' => '图片宽度(EMU)'],
                                    'image_height' => ['type' => 'integer', 'description' => '图片高度(EMU)'],
                                ]
                            ],
                            'description' => '幻灯片列表'
                        ]
                    ],
                    'required'   => ['path', 'data']
                ]
            ]
        ]
    ];
}