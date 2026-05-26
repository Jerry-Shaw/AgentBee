<?php

/**
 * Agent Core module for AgentBee
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

namespace modules\agent_core\app;

use Nervsys\Core\Factory;

class message extends Factory
{
    /**
     * Normal text chat
     *
     * @param string $socket_id
     * @param array  $data
     *
     * @return array
     */
    public function process_text(string $socket_id, array $data): array
    {
        return [
            'agent_llm' => true,
            'content'   => $data['text'] ?? $data['message'] ?? ''
        ];
    }

    /**
     * Multimodal content chat
     *
     * Expected input $data structure:
     * - 'text' (string): plain text message
     * - 'images' (array): list of images, each can be:
     *      - full Data URL (e.g. "data:image/png;base64,...")
     *      - associative array ['name' => ..., 'mime' => ..., 'data' => ...] (base64 data)
     * - 'files' (array): list of text files, each as:
     *      ['name' => ..., 'mime' => ..., 'content' => ...] (raw text content)
     *
     * @param string $socket_id
     * @param array  $data_content
     *
     * @return array Associative array with 'agent_llm' (bool) and 'text' (multimodal content array).
     */
    public function process_chat(string $socket_id, array $data_content): array
    {
        $content = [];

        foreach ($data_content as $data) {
            if (!isset($data['type'])) {
                continue;
            }

            // 1. Handle plain text
            if ('text' === $data['type']) {
                $content[] = ['type' => 'text', 'text' => trim($data['text'] ?? '')];
            }

            // 2. Handle images
            if ('image_url' === $data['type']) {
                if (isset($data['image_url']['url']) && '' !== $data['image_url']['url']) {
                    $content[] = ['type' => 'image_url', 'image_url' => ['url' => $data['image_url']['url']]];
                }
            }

            // 3. Handle text files (only allowed pure text content)
            if ('file' === $data['type']) {
                if (
                    isset($data['file']['content'])
                    && '' !== $data['file']['content']
                    && $this->file_is_allowed($data['file']['filename'])
                ) {
                    $content[] = [
                        'type' => 'text',
                        'text' => '--- 文件开始 ---' . "\n"
                            . '文件名: ' . $data['file']['filename'] . "\n"
                            . 'MIME类型: ' . $data['file']['mimeType'] . "\n\n"
                            . '文件内容:' . "\n" . $data['file']['content'] . "\n"
                            . '--- 文件结束 ---' . "\n\n"
                    ];
                }
            }
        }

        // Ensure there is at least one content item
        if (empty($content)) {
            $content[] = [
                'type' => 'text',
                'text' => '(User did not provide any valid message)'
            ];
        }

        unset($socket_id, $data);

        return [
            'agent_llm' => true,
            'content'   => $content
        ];
    }

    /**
     * Process binary data with multimodal content
     *
     * @param string $socket_id
     * @param string $data Binary data from WebSocket
     *
     * @return string JSON string that can be processed by onMessage
     * @throws \Exception
     */
    public function process_binary(string $socket_id, string $data): string
    {
        if (4 > strlen($data)) {
            throw new \Exception('Binary packet too short', E_USER_NOTICE);
        }

        $meta_len = unpack('N', substr($data, 0, 4))[1];

        if (0 >= $meta_len || $meta_len > strlen($data) - 4) {
            throw new \Exception('Invalid metadata length', E_USER_NOTICE);
        }

        $meta = json_decode(substr($data, 4, $meta_len), true);

        if (!is_array($meta) || !isset($meta['content'])) {
            throw new \Exception('Invalid JSON metadata', E_USER_NOTICE);
        }

        // Extract binary blocks
        $binary_offset = 0;
        $binary_blocks = [];
        $binary_data   = substr($data, 4 + $meta_len);

        if (isset($meta['binary_sizes']) && is_array($meta['binary_sizes'])) {
            foreach ($meta['binary_sizes'] as $size) {
                $binary_blocks[] = substr($binary_data, $binary_offset, $size);
                $binary_offset   += $size;
            }
        } elseif (!empty($binary_data)) {
            $binary_blocks[] = $binary_data;
        }

        // Replace placeholders
        $content   = $meta['content'];
        $block_idx = 0;

        foreach ($content as $key => $item) {
            if (!isset($item['type'])) {
                continue;
            }

            // Handle image_url
            if ('image_url' === $item['type'] && isset($item['image_url']['url']) && str_starts_with($item['image_url']['url'], '__BINARY__')) {
                $binary = $binary_blocks[$block_idx++] ?? '';

                if ('' !== $binary) {
                    $content[$key]['image_url']['url'] = 'data:' . $this->get_image_mime_type($binary) . ';base64,' . base64_encode($binary);
                } else {
                    unset($content[$key]);
                }
            }

            // Handle file
            if ('file' === $item['type'] && isset($item['file']['content']) && str_starts_with($item['file']['content'], '__BINARY__')) {
                $binary = $binary_blocks[$block_idx++] ?? '';

                if ('' !== $binary) {
                    $content[$key]['file']['content'] = !mb_check_encoding($binary, 'UTF-8')
                        ? mb_convert_encoding($binary, 'UTF-8', 'auto')
                        : $binary;
                } else {
                    unset($content[$key]);
                }
            }
        }

        $meta['content'] = array_values($content);

        unset($meta['binary_sizes']);

        $result = json_encode($meta, JSON_UNESCAPED_UNICODE);

        unset($socket_id, $data, $meta_len, $meta, $binary_data, $binary_offset, $binary_blocks, $content, $block_idx);
        return $result;
    }

    /**
     * Check if a file name has an allowed text file extension.
     *
     * @param string $filename
     *
     * @return bool
     */
    private function file_is_allowed(string $filename): bool
    {
        $whitelist = [
            'txt', 'md', 'markdown',
            'js', 'javascript', 'ts', 'jsx', 'tsx',
            'php', 'php3', 'php4', 'php5', 'phpt',
            'html', 'htm', 'xhtml',
            'css', 'scss', 'sass', 'less',
            'json', 'xml', 'yaml', 'yml',
            'py', 'python',
            'sh', 'bash', 'zsh',
            'sql', 'plsql',
            'c', 'cpp', 'h', 'hpp', 'java', 'go', 'rs', 'rb', 'swift',
            'conf', 'cfg', 'ini', 'env', 'gitignore', 'dockerfile'
        ];

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed   = in_array($extension, $whitelist, true);

        unset($filename, $whitelist, $extension);
        return $allowed;
    }

    /**
     * Detect image MIME type from binary data using magic bytes
     *
     * @param string $binary
     *
     * @return string
     */
    private function get_image_mime_type(string $binary): string
    {
        if (empty($binary)) {
            return 'image/jpeg';
        }

        $magic = substr($binary, 0, 12);
        $hex   = bin2hex($magic);

        if (str_starts_with($hex, 'ffd8')) {
            return 'image/jpeg';
        }

        if (str_starts_with($hex, '89504e47')) {
            return 'image/png';
        }

        if (str_starts_with($hex, '47494638')) {
            return 'image/gif';
        }

        if (str_starts_with($hex, '52494646') && str_contains($hex, '57454250')) {
            return 'image/webp';
        }

        if (str_starts_with($hex, '424d')) {
            return 'image/bmp';
        }

        unset($binary, $magic, $hex);
        return 'image/jpeg';
    }
}