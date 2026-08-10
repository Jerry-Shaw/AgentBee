<?php

/**
 * Agent Browser module for AgentBee core
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

namespace modules\agent_toolsets\Browser;

class skills
{
    public const META = [
        // === Instance Management ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'start',
                'description' => '启动浏览器实例（单例模式，已存在则复用，通过标签页进行操作）。默认有头模式，无头需用户明确。返回：{message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'headless' => ['type' => 'boolean', 'default' => false, 'description' => '无头模式（默认false）'],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'close',
                'description' => '关闭浏览器实例，释放所有资源。返回：{message}。',
            ],
        ],

        // === Tab Management ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createTab',
                'description' => '在当前浏览器实例中创建新标签页，并自动切换到该标签页。返回：{status, data: {target_id}, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'default' => 'about:blank', 'description' => '初始 URL'],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'switchTab',
                'description' => '切换到指定target_id的标签页。返回：{status, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'target_id' => ['type' => 'string', 'description' => '标签页 target_id（从 listTabs 获取）'],
                    ],
                    'required'   => ['target_id'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listTabs',
                'description' => '列出当前浏览器实例的所有标签页。返回：{status, data: [{target_id, url, title, status, is_current}]}。',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'closeTab',
                'description' => '关闭指定标签页。若关闭的是当前标签页，自动切换到第一个可用标签页。返回：{status, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'target_id' => ['type' => 'string', 'description' => '标签页 target_id'],
                    ],
                    'required'   => ['target_id'],
                ],
            ],
        ],

        // === Navigation ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'navigate',
                'description' => '导航到指定URL。返回：{status, data, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => '目标地址'],
                    ],
                    'required'   => ['url'],
                ],
            ],
        ],

        // === Interaction ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'click',
                'description' => '点击CSS选择器匹配的元素。返回：{status, data, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'selector' => ['type' => 'string', 'description' => 'CSS 选择器'],
                    ],
                    'required'   => ['selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'type',
                'description' => '在输入框中输入文本。返回：{status, data, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'selector' => ['type' => 'string', 'description' => 'CSS 选择器'],
                        'text'     => ['type' => 'string', 'description' => '要输入的文本'],
                    ],
                    'required'   => ['selector', 'text'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'submit',
                'description' => '提交表单（默认form选择器）。返回：{status, data, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'selector' => ['type' => 'string', 'default' => 'form', 'description' => '表单选择器'],
                    ],
                ],
            ],
        ],

        // === Read-only ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getUrl',
                'description' => '获取当前页面URL。返回：{status, data: url, message}。',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getTitle',
                'description' => '获取当前页面标题。返回：{status, data: title, message}。',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getContent',
                'description' => '获取页面内容（纯文本或HTML）。返回：{status, data: content, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'html' => ['type' => 'boolean', 'default' => false, 'description' => 'true 返回 HTML，否则纯文本'],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getValue',
                'description' => '获取表单元素的value。返回：{status, data: value, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'selector' => ['type' => 'string', 'description' => 'CSS 选择器'],
                    ],
                    'required'   => ['selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getAttribute',
                'description' => '获取元素指定属性值。返回：{status, data: attribute_value, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'selector'  => ['type' => 'string', 'description' => 'CSS 选择器'],
                        'attribute' => ['type' => 'string', 'description' => '属性名（如 href）'],
                    ],
                    'required'   => ['selector', 'attribute'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'setAttribute',
                'description' => '设置元素指定属性值。返回：{status, data, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'selector'  => ['type' => 'string', 'description' => 'CSS 选择器'],
                        'attribute' => ['type' => 'string', 'description' => '属性名'],
                        'value'     => ['type' => 'string', 'description' => '属性值'],
                    ],
                    'required'   => ['selector', 'attribute', 'value'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'scrollIntoView',
                'description' => '滚动指定元素到可视区域。返回：{status, data, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'selector' => ['type' => 'string', 'description' => 'CSS 选择器'],
                    ],
                    'required'   => ['selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'selectOption',
                'description' => '在下拉框中按value选择选项。返回：{status, data, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'selector' => ['type' => 'string', 'description' => '<select> 的 CSS 选择器'],
                        'value'    => ['type' => 'string', 'description' => '要选的 option value'],
                    ],
                    'required'   => ['selector', 'value'],
                ],
            ],
        ],

        // === Advanced ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'evaluate',
                'description' => '执行自定义JavaScript代码。如需返回值，请将代码写为表达式或使用IIFE（立即执行函数），例如：(function(){ return document.title; })()。返回：{status, data: result, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'script'          => ['type' => 'string', 'description' => 'JS 代码'],
                        'return_by_value' => ['type' => 'boolean', 'default' => true, 'description' => '是否序列化返回'],
                    ],
                    'required'   => ['script'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'screenshot',
                'description' => '截屏并保存为文件。返回：{status, data: {saved_path}, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'save_path' => ['type' => 'string', 'description' => '保存路径（含文件名）'],
                        'format'    => ['type' => 'string', 'enum' => ['jpeg', 'png'], 'default' => 'jpeg', 'description' => '图片格式'],
                        'quality'   => ['type' => 'integer', 'default' => 80, 'minimum' => 1, 'maximum' => 100, 'description' => '画质（仅 jpeg）'],
                    ],
                    'required'   => ['save_path'],
                ],
            ],
        ],

        // === Waiting Methods ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'waitForSelector',
                'description' => '等待CSS选择器对应的元素出现在DOM中，超时报错。返回：{status, data: boolean, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'selector' => ['type' => 'string', 'description' => 'CSS 选择器']
                    ],
                    'required'   => ['selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'waitForPageLoad',
                'description' => '等待页面完全加载，超时报错。返回：{status, data: boolean, message}。'
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'waitForText',
                'description' => '等待页面中出现指定文本，超时报错。返回：{status, data: boolean, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => '要等待的文本']
                    ],
                    'required'   => ['text'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'waitForElementVisible',
                'description' => '等待CSS选择器对应的元素可见，超时报错。返回：{status, data: boolean, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'selector' => ['type' => 'string', 'description' => 'CSS 选择器']
                    ],
                    'required'   => ['selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'waitForUrl',
                'description' => '等待当前URL包含指定字符串，超时报错。返回：{status, data: boolean, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url_pattern' => ['type' => 'string', 'description' => '要匹配的 URL 片段']
                    ],
                    'required'   => ['url_pattern'],
                ],
            ],
        ],

        // === Other Actions ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'hover',
                'description' => '悬停到指定元素（触发hover事件）。返回：{status, data, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'selector' => ['type' => 'string', 'description' => 'CSS 选择器'],
                    ],
                    'required'   => ['selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'pressKey',
                'description' => '模拟键盘按键事件，支持普通按键、功能键、方向键及组合键；仅模拟按键，不用于输入文字，文字输入请使用type。返回：{status, data, message}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'key'       => ['type' => 'string', 'description' => '按键名称，如 Enter、Escape、Tab、ArrowUp、ArrowDown、Backspace、Delete、A、1 等。'],
                        'modifiers' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['Control', 'Shift', 'Alt', 'Meta']], 'default' => [], 'description' => '可选修饰键列表；无修饰键时可省略或传空数组。'],
                    ],
                    'required'   => ['key'],
                ],
            ],
        ]
    ];
}