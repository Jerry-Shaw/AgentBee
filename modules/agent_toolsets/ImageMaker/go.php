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
     * @param array  $images
     * @param string $mask_path
     * @param int    $n
     * @param string $size
     * @param string $quality
     * @param string $background
     * @param string $output_format
     * @param string $moderation
     *
     * @return array
     */
    public function edit(
        string $prompt,
        array  $images,
        string $mask_path = '',
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
            'images'        => $images,
            'mask_path'     => $mask_path,
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