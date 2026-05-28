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
                'description' => '读取 DOCX。参数：path(string,必填,绝对路径)。返回：{"status":"success","content":"文本"}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ],
        // writeDocx (text + image)
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeDocx',
                'description' => '写入 DOCX。参数：path(string,必填), data(array,必填)。data每项：string(段落) 或 {"type":"image","content":"路径","width":px(默认200),"height":px(可选,自动)}。返回：{"status":"success","path":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'data' => [
                            'type'  => 'array',
                            'items' => [
                                'anyOf' => [
                                    ['type' => 'string'],
                                    [
                                        'type'       => 'object',
                                        'properties' => [
                                            'type'    => ['type' => 'string', 'enum' => ['image']],
                                            'content' => ['type' => 'string'],
                                            'width'   => ['type' => 'integer'],
                                            'height'  => ['type' => 'integer']
                                        ],
                                        'required'   => ['type', 'content']
                                    ]
                                ]
                            ]
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
                'description' => '读取 XLSX。参数：path(string,必填)。返回：{"status":"success","sheets":{"Sheet1":[[行]]}}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ],
        // writeXlsx
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeXlsx',
                'description' => '写入 XLSX。参数：path(string,必填), data(array,必填)。data：二维数组(单表) 或 [{"name":"Sheet1","rows":[[行]]}] (多表)。返回：{"status":"success","path":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'data' => ['type' => 'array']
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
                'description' => '读取 PPTX。参数：path(string,必填)。返回：{"status":"success","slides":[{"number":1,"title":"...","content":"..."}]}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path']
                ]
            ]
        ],
        // writePptx (supports image, EMU units)
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writePptx',
                'description' => '写入 PPTX。参数：path(string,必填), data(array,必填)。每张幻灯片：{"title":"(可选)","content":"(可选)","image":"路径(可选)","image_x":整数(默认8000000),"image_y":整数(默认500000),"image_width":整数(默认2540000),"image_height":整数(默认1905000)}。单位EMU：1英寸=914400，1厘米=360000。返回：{"status":"success","path":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'data' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'title'        => ['type' => 'string'],
                                    'content'      => ['type' => 'string'],
                                    'image'        => ['type' => 'string'],
                                    'image_x'      => ['type' => 'integer'],
                                    'image_y'      => ['type' => 'integer'],
                                    'image_width'  => ['type' => 'integer'],
                                    'image_height' => ['type' => 'integer']
                                ]
                            ]
                        ]
                    ],
                    'required'   => ['path', 'data']
                ]
            ]
        ]
    ];
}