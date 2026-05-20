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
     * @param int   $socket_id
     * @param array $data
     *
     * @return array
     */
    public function process_text(int $socket_id, array $data): array
    {
        return [
            'agent_llm' => true,
            'text'      => $data['text']
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
     * @param array $data Incoming message data from frontend.
     *
     * @return array Associative array with 'agent_llm' (bool) and 'text' (multimodal content array).
     */
    public function process_chat(int $socket_id, array $data_content): array
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
                if (isset($data['file']['text']) && '' !== $data['file']['text']) {
                    $content[] = ['type' => 'text', 'text' => $data['file']['text']];
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
}