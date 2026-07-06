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
                'description' => '启动浏览器实例。禁止连续调用。默认有头模式，除非用户明确要求无头。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例唯一名称'],
                        'headless'    => ['type' => 'boolean', 'description' => '无头模式（默认 false）'],
                    ],
                    'required'   => ['worker_name'],
                ],
            ],
        ],

        // === Navigation ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'navigate',
                'description' => '导航到指定 URL。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'url'         => ['type' => 'string', 'description' => '目标地址'],
                    ],
                    'required'   => ['worker_name', 'url'],
                ],
            ],
        ],

        // === Interaction ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'click',
                'description' => '点击 CSS 选择器匹配的元素。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'selector'    => ['type' => 'string', 'description' => 'CSS 选择器'],
                    ],
                    'required'   => ['worker_name', 'selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'type',
                'description' => '在输入框中输入文本。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'selector'    => ['type' => 'string', 'description' => 'CSS 选择器'],
                        'text'        => ['type' => 'string', 'description' => '要输入的文本'],
                    ],
                    'required'   => ['worker_name', 'selector', 'text'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'submit',
                'description' => '提交表单（默认 form 选择器）。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'selector'    => ['type' => 'string', 'description' => '表单选择器（默认 form）'],
                    ],
                    'required'   => ['worker_name'],
                ],
            ],
        ],

        // === Read-only ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getUrl',
                'description' => '获取当前页面 URL。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                    ],
                    'required'   => ['worker_name'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getTitle',
                'description' => '获取当前页面标题。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                    ],
                    'required'   => ['worker_name'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getContent',
                'description' => '获取页面内容（纯文本或 HTML）。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'html'        => ['type' => 'boolean', 'default' => false, 'description' => 'true 返回 HTML，否则纯文本'],
                    ],
                    'required'   => ['worker_name'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getValue',
                'description' => '获取表单元素的 value。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'selector'    => ['type' => 'string', 'description' => 'CSS 选择器'],
                    ],
                    'required'   => ['worker_name', 'selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getAttribute',
                'description' => '获取元素指定属性值。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'selector'    => ['type' => 'string', 'description' => 'CSS 选择器'],
                        'attribute'   => ['type' => 'string', 'description' => '属性名（如 href）'],
                    ],
                    'required'   => ['worker_name', 'selector', 'attribute'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'setAttribute',
                'description' => '设置元素指定属性值。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'selector'    => ['type' => 'string', 'description' => 'CSS 选择器'],
                        'attribute'   => ['type' => 'string', 'description' => '属性名'],
                        'value'       => ['type' => 'string', 'description' => '属性值'],
                    ],
                    'required'   => ['worker_name', 'selector', 'attribute', 'value'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'scrollIntoView',
                'description' => '滚动指定元素到可视区域。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'selector'    => ['type' => 'string', 'description' => 'CSS 选择器'],
                    ],
                    'required'   => ['worker_name', 'selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'selectOption',
                'description' => '在下拉框中按 value 选择选项。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'selector'    => ['type' => 'string', 'description' => '<select> 的 CSS 选择器'],
                        'value'       => ['type' => 'string', 'description' => '要选的 option value'],
                    ],
                    'required'   => ['worker_name', 'selector', 'value'],
                ],
            ],
        ],

        // === Advanced ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'evaluate',
                'description' => '执行自定义 JavaScript 代码。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name'     => ['type' => 'string', 'description' => '实例名称'],
                        'script'          => ['type' => 'string', 'description' => 'JS 代码'],
                        'return_by_value' => ['type' => 'boolean', 'default' => true, 'description' => '是否序列化返回'],
                    ],
                    'required'   => ['worker_name', 'script'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'screenshot',
                'description' => '截屏并保存为文件。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'save_path'   => ['type' => 'string', 'description' => '保存路径（含文件名）'],
                        'format'      => ['type' => 'string', 'enum' => ['jpeg', 'png'], 'default' => 'jpeg', 'description' => '图片格式'],
                        'quality'     => ['type' => 'integer', 'default' => 80, 'minimum' => 1, 'maximum' => 100, 'description' => '画质（仅 jpeg）'],
                    ],
                    'required'   => ['worker_name', 'save_path'],
                ],
            ],
        ],

        // === Waiting Methods ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'waitForSelector',
                'description' => '等待 CSS 选择器对应的元素出现在 DOM 中。超时报错。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'selector'    => ['type' => 'string', 'description' => 'CSS 选择器'],
                        'timeout'     => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数'],
                    ],
                    'required'   => ['worker_name', 'selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'waitForPageLoad',
                'description' => '等待页面完全加载。超时报错。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'timeout'     => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数'],
                    ],
                    'required'   => ['worker_name'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'waitForText',
                'description' => '等待页面中出现指定文本。超时报错。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'text'        => ['type' => 'string', 'description' => '要等待的文本'],
                        'timeout'     => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数'],
                    ],
                    'required'   => ['worker_name', 'text'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'waitForElementVisible',
                'description' => '等待 CSS 选择器对应的元素可见。超时报错。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'selector'    => ['type' => 'string', 'description' => 'CSS 选择器'],
                        'timeout'     => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数'],
                    ],
                    'required'   => ['worker_name', 'selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'waitForUrl',
                'description' => '等待当前 URL 包含指定字符串。超时报错。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'url_pattern' => ['type' => 'string', 'description' => '要匹配的 URL 片段'],
                        'timeout'     => ['type' => 'integer', 'default' => 30, 'description' => '超时秒数'],
                    ],
                    'required'   => ['worker_name', 'url_pattern'],
                ],
            ],
        ],

        // === Other Actions ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'hover',
                'description' => '悬停到指定元素（触发 hover 事件）。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'selector'    => ['type' => 'string', 'description' => 'CSS 选择器'],
                    ],
                    'required'   => ['worker_name', 'selector'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'pressKey',
                'description' => '模拟键盘按键（含修饰键）。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                        'key'         => ['type' => 'string', 'description' => '键名（如 Enter）'],
                        'modifiers'   => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['Control', 'Shift', 'Alt', 'Meta']], 'description' => '修饰键列表'],
                    ],
                    'required'   => ['worker_name', 'key'],
                ],
            ],
        ],

        // === Management ===
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'list',
                'description' => '列出所有浏览器实例及其状态。禁止高频调用，以免阻塞通信。',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'close',
                'description' => '关闭指定浏览器实例，释放资源。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'worker_name' => ['type' => 'string', 'description' => '实例名称'],
                    ],
                    'required'   => ['worker_name'],
                ],
            ],
        ],
    ];
}