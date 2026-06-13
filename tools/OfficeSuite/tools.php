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

namespace tools\OfficeSuite;

class tools
{
    public const META = [
        // DOCX 读取
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readDocx',
                'description' => '读取 DOCX 文件。返回 {"content":"文本","images":[{"path":"/tmp/...","width":200,"height":150}],"images_temp_dir":"/tmp/..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            ],
        ],
        // DOCX 初始化
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'initDocx',
                'description' => '初始化 DOCX 文档缓冲区。调用后可用 addDocxHeading/addDocxParagraph/addDocxImage 添加内容。'
            ],
        ],
        // 添加标题
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'addDocxHeading',
                'description' => '添加标题（自动加粗，1~6级）。示例：{"level":1,"text":"第一章"}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6],
                        'text'  => ['type' => 'string'],
                    ],
                    'required'   => ['level', 'text'],
                ],
            ],
        ],
        // 添加段落（完整样式）
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'addDocxParagraph',
                'description' => '添加段落。示例：{"text":"正文","bold":true,"fontSize":14,"align":"center"}。支持样式：bold,italic,fontSize(8-72),align,firstLineIndent(twip),lineSpacing(倍数,可小于1如0.9),beforeSpacing,afterSpacing,fontFamily,fontFamilyEastAsia,color(十六进制如FF0000),underline(single/double/dash/dot/wave)。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'text'               => ['type' => 'string'],
                        'bold'               => ['type' => 'boolean', 'default' => false],
                        'italic'             => ['type' => 'boolean', 'default' => false],
                        'fontSize'           => ['type' => 'integer', 'minimum' => 8, 'maximum' => 72],
                        'align'              => ['type' => 'string', 'enum' => ['left', 'center', 'right', 'justify'], 'default' => 'left'],
                        'firstLineIndent'    => ['type' => 'integer', 'description' => '首行缩进(twip) 720≈0.5英寸'],
                        'lineSpacing'        => ['type' => 'number', 'description' => '行距倍数，如1.5或0.9（大于0即可）'],
                        'beforeSpacing'      => ['type' => 'integer', 'description' => '段前间距(twip)'],
                        'afterSpacing'       => ['type' => 'integer', 'description' => '段后间距(twip)'],
                        'fontFamily'         => ['type' => 'string', 'description' => '英文字体如Arial'],
                        'fontFamilyEastAsia' => ['type' => 'string', 'description' => '中文字体如宋体'],
                        'color'              => ['type' => 'string', 'description' => '十六进制颜色，如FF0000'],
                        'underline'          => ['type' => 'string', 'enum' => ['single', 'double', 'dash', 'dot', 'wave']],
                    ],
                    'required'   => ['text'],
                ],
            ],
        ],
        // 添加图片
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'addDocxImage',
                'description' => '添加图片。示例：{"path":"/a.png","width":200,"align":"center"}。height为可选，不指定则自动等比缩放。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => ['type' => 'string'],
                        'width'  => ['type' => 'integer', 'default' => 200],
                        'height' => ['type' => 'integer', 'description' => '高度(像素)，可选'],
                        'align'  => ['type' => 'string', 'enum' => ['left', 'center', 'right'], 'default' => 'center'],
                    ],
                    'required'   => ['path'],
                ],
            ],
        ],
        // 追加 DOCX
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'appendDocx',
                'description' => '向现有 DOCX 文件追加内容（保留原有内容和图片）。items 格式与 writeDocx 相同（支持 heading/paragraph/image）。返回 {"status":"success","path":"..."} 或 {"error":"..."}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'  => ['type' => 'string'],
                        'items' => ['type' => 'array', 'description' => '要追加的内容数组，每个元素可以是标题、段落或图片对象'],
                    ],
                    'required'   => ['path', 'items'],
                ],
            ],
        ],
        // 保存 DOCX
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'saveDocx',
                'description' => '将当前缓冲区保存为 DOCX 文件（覆盖）。示例：{"path":"/out.docx"}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path'],
                ],
            ],
        ],

        // XLSX 工具
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readXlsx',
                'description' => '读取 XLSX 文件所有工作表。返回 {"sheets":{"Sheet1":[[...]]}}',
                'parameters'  => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeXlsx',
                'description' => '写入 XLSX 文件（覆盖）。示例（单表）：{"path":"/a.xlsx","data":[["A","B"],[1,2]]}。示例（多表）：{"path":"/a.xlsx","data":[{"name":"Sheet1","rows":[[...]]},{"name":"Sheet2","rows":[[...]]}]}，可指定 sheet_name（仅当 data 为二维数组时生效，若 data 已是多表格式则 sheet_name 被忽略）。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'       => ['type' => 'string'],
                        'data'       => ['type' => 'array'],
                        'sheet_name' => ['type' => 'string', 'description' => '当 data 为二维数组时指定工作表名'],
                    ],
                    'required'   => ['path', 'data'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'appendXlsxRows',
                'description' => '向现有工作表追加行。示例：{"path":"/a.xlsx","sheet_name":"Sheet1","rows":[["新行1","值"]]}。工作表不存在则自动创建。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'       => ['type' => 'string'],
                        'sheet_name' => ['type' => 'string'],
                        'rows'       => ['type' => 'array', 'items' => ['type' => 'array']],
                    ],
                    'required'   => ['path', 'sheet_name', 'rows'],
                ],
            ],
        ],

        // PPTX 工具
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readPptx',
                'description' => '读取 PPTX 所有幻灯片文本和图片。返回 {"slides":[{"title":"...","content":"...","images":[...]}],"images_temp_dir":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'initPptx',
                'description' => '初始化 PPTX 缓冲区。后跟 addPptxSlide / savePptx。'
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'addPptxSlide',
                'description' => '添加幻灯片。示例：{"title":"标题","paragraphs":["第一段","第二段"],"image_path":"/a.png"}。图片尺寸单位为 EMU（1英寸=914400 EMU），默认值适合普通大小。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title'        => ['type' => 'string'],
                        'paragraphs'   => ['type' => 'array', 'items' => ['type' => 'string']],
                        'image_path'   => ['type' => 'string'],
                        'image_width'  => ['type' => 'integer', 'default' => 2540000],
                        'image_height' => ['type' => 'integer', 'default' => 1905000],
                        'image_x'      => ['type' => 'integer', 'default' => 8000000],
                        'image_y'      => ['type' => 'integer', 'default' => 500000],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'appendPptx',
                'description' => '向现有 PPTX 文件追加幻灯片（保留原内容）。示例：{"path":"/a.pptx","slides":[{"title":"新页","paragraphs":["内容"]}]}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => ['type' => 'string'],
                        'slides' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'title'        => ['type' => 'string'],
                                    'paragraphs'   => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'image_path'   => ['type' => 'string'],
                                    'image_width'  => ['type' => 'integer', 'default' => 2540000],
                                    'image_height' => ['type' => 'integer', 'default' => 1905000],
                                    'image_x'      => ['type' => 'integer', 'default' => 8000000],
                                    'image_y'      => ['type' => 'integer', 'default' => 500000],
                                ],
                            ],
                        ],
                    ],
                    'required'   => ['path', 'slides'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'savePptx',
                'description' => '将当前缓冲区保存为 PPTX 文件（覆盖）。示例：{"path":"/out.pptx"}',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string']],
                    'required'   => ['path'],
                ],
            ],
        ],
    ];
}