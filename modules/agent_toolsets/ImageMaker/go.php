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

use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;

class go extends Factory
{
    /**
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @param string $prompt
     * @param int    $n
     * @param string $size
     * @param string $quality
     * @param string $background
     * @param string $output_format
     * @param string $moderation
     *
     * @return array
     */
    public function create(
        string $prompt,
        int    $n = 1,
        string $size = 'auto',
        string $quality = 'auto',
        string $background = 'auto',
        string $output_format = 'png',
        string $moderation = 'low'
    ): array
    {
        return [
            'async'         => false,
            'action'        => __FUNCTION__,
            'prompt'        => $prompt,
            'n'             => $n,
            'size'          => $size,
            'quality'       => $quality,
            'background'    => $background,
            'output_format' => $output_format,
            'moderation'    => $moderation,
            'handler'       => handler::class
        ];
    }

    /**
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @param string $prompt
     * @param array  $edit_images
     * @param string $mask_image
     * @param int    $n
     * @param string $size
     * @param string $quality
     * @param string $background
     * @param string $output_format
     * @param string $moderation
     *
     * @return array
     * @throws \ReflectionException
     */
    public function edit(
        string $prompt,
        array  $edit_images,
        string $mask_image = '',
        int    $n = 1,
        string $size = 'auto',
        string $quality = 'auto',
        string $background = 'auto',
        string $output_format = 'png',
        string $moderation = 'low'
    ): array
    {
        $utils = utils::new();

        // Security image file path
        foreach ($edit_images as $key => $path) {
            if ('' === $path) {
                unset($edit_images[$key]);
                continue;
            }

            $path = $utils->securePath($path);

            if (!is_file($path) || !is_readable($path)) {
                unset($edit_images[$key]);
                continue;
            }

            $edit_images[$key] = $path;
        }

        if ([] === $edit_images) {
            return ['status' => 'error', 'error' => '未找到可编辑的图片。请检查沙箱限制和文件路径。'];
        }

        $edit_images = array_values($edit_images);

        // Security mask-image file path
        if ('' !== $mask_image) {
            $mask_image = $utils->securePath($mask_image);

            if (!is_file($mask_image) || !is_readable($mask_image)) {
                return ['status' => 'error', 'error' => '未找到蒙版图片。请检查沙箱限制和文件路径。'];
            }
        }

        unset($utils, $key, $path);

        return [
            'async'         => false,
            'action'        => __FUNCTION__,
            'prompt'        => $prompt,
            'edit_images'   => $edit_images,
            'mask_image'    => $mask_image,
            'n'             => $n,
            'size'          => $size,
            'quality'       => $quality,
            'background'    => $background,
            'output_format' => $output_format,
            'moderation'    => $moderation,
            'handler'       => handler::class
        ];
    }
}