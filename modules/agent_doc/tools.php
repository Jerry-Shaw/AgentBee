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
        // DOCX
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readDocx',
                'description' => '读DOCX纯文本。参数: path(必填)。返回: {"status":"success","content":"文本"}',
                'parameters'  => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeDocx',
                'description' => '写DOCX。参数: path, data(数组), append(默认false)。data元素为段落文本(字符串)或图片对象{"type":"image","content":"绝对路径","width":px(默认200)}。append=true保留原文档全部内容(文字+图片)。示例: ["标题",{"type":"image","content":"/a.png"}]。返回: {"status":"success","path":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'data' => ['type' => 'array'], 'append' => ['type' => 'boolean', 'default' => false]], 'required' => ['path', 'data']],
            ],
        ],
        // XLSX
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readXlsx',
                'description' => '读XLSX。参数: path。返回: {"status":"success","sheets":{"Sheet1":[["A1","B1"],["A2","B2"]]}}',
                'parameters'  => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeXlsx',
                'description' => '写XLSX。参数: path, data(数组), append(默认false)。data单表: 二维数组，每行是一个数组，如[["姓名","年龄"],["张三",25]]。多表: [{"name":"Sheet1","rows":[[...]]}]。append=true 向现有工作表追加行或新建表。返回: {"status":"success","path":"...","sheets_count":N}',
                'parameters'  => ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'data' => ['type' => 'array'], 'append' => ['type' => 'boolean', 'default' => false]], 'required' => ['path', 'data']],
            ],
        ],
        // PPTX
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readPptx',
                'description' => '读PPTX文本。参数: path。返回: {"status":"success","slides":[{"number":1,"title":"...","content":"..."}]}',
                'parameters'  => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writePptx',
                'description' => '写PPTX。参数: path, data(数组), append(默认false)。data每项: {"title":"","content":"","image":"绝对路径可选","image_x":EMU(默认8000000),"image_y":500000,"image_width":2540000,"image_height":1905000}。EMU换算: 1像素 ≈ 9525 EMU。append=true追加幻灯片并保留原图(图片位置恢复默认)。返回: {"status":"success","path":"...","slides_count":N}',
                'parameters'  => ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'data' => ['type' => 'array'], 'append' => ['type' => 'boolean', 'default' => false]], 'required' => ['path', 'data']],
            ],
        ],
    ];
}