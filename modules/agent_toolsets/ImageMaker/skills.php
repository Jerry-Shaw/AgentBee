<?php

/**
 * Agent ImageMaker tools for AgentBee
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

namespace modules\agent_toolsets\ImageMaker;

class skills
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'create',
                'description' => '根据文本描述生成图片（文生图）。自动保存到工作区，同步返回保存路径和生成状态。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt'        => ['type' => 'string', 'description' => '详细的图片描述文本（必填）'],
                        'n'             => ['type' => 'integer', 'default' => 1, 'description' => '生成图片张数，取值范围：1-10'],
                        'size'          => ['type' => 'string', 'default' => 'auto', 'description' => '图片尺寸。固定预设：auto、1024x1024、1536x1024、1024x1536；自定义需宽高均被16整除，宽高比1:3~3:1，最大3840x2160'],
                        'quality'       => ['type' => 'string', 'default' => 'auto', 'description' => '质量档位：low/medium/high/auto'],
                        'background'    => ['type' => 'string', 'default' => 'auto', 'description' => '背景模式：transparent（透明）/opaque（不透明）/auto'],
                        'output_format' => ['type' => 'string', 'default' => 'png', 'description' => '输出图片格式，仅支持png/jpeg/webp'],
                        'moderation'    => ['type' => 'string', 'default' => 'low', 'description' => '安全审核级别：low（宽松）/auto（自动）'],
                    ],
                    'required'   => ['prompt'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'edit',
                'description' => '基于本地图片和文本指令进行图片编辑或扩展（图生图）。自动保存到工作区，支持蒙版指定区域编辑，同步返回保存路径和生成状态。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt'        => ['type' => 'string', 'description' => '描述如何修改图片的文本（必填）'],
                        'edit_images'   => ['type' => 'array', 'description' => '要编辑的本地图片绝对路径（必填，可多张，数量：1-16）'],
                        'mask_image'    => ['type' => 'string', 'default' => '', 'description' => '蒙版图片的绝对路径（可选，留空表示全图编辑），用于指定需要修改的区域'],
                        'n'             => ['type' => 'integer', 'default' => 1, 'description' => '生成图片张数，取值范围：1-10'],
                        'size'          => ['type' => 'string', 'default' => 'auto', 'description' => '图片尺寸。固定预设：auto、1024x1024、1536x1024、1024x1536；自定义需宽高均被16整除，宽高比1:3~3:1，最大3840x2160'],
                        'quality'       => ['type' => 'string', 'default' => 'auto', 'description' => '质量档位：low/medium/high/auto'],
                        'background'    => ['type' => 'string', 'default' => 'auto', 'description' => '背景模式：transparent（透明）/opaque（不透明）/auto'],
                        'output_format' => ['type' => 'string', 'default' => 'png', 'description' => '输出图片格式，仅支持png/jpeg/webp'],
                        'moderation'    => ['type' => 'string', 'default' => 'low', 'description' => '安全审核级别：low（宽松）/ auto（自动）'],
                    ],
                    'required'   => ['prompt', 'edit_images'],
                ],
            ],
        ],
    ];
}