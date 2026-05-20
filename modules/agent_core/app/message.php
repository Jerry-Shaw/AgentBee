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
            'text'      => $data['message']
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
    public function process_chat(int $socket_id, array $data): array
    {
        $content = [];

        // 1. Handle plain text
        if (isset($data['text'])) {
            $data['text'] = trim($data['text']);

            if ('' !== $data['text']) {
                $content[] = [
                    'type' => 'text',
                    'text' => $data['text']
                ];
            }
        }

        // 2. Handle images
        if (isset($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $image) {
                $image_url = '';

                if (is_string($image)) {
                    $image_url = $image;
                } elseif (is_array($image) && isset($image['data'])) {
                    $mime      = $image['mime'] ?? 'image/jpeg';
                    $image_url = 'data:' . $mime . ';base64,' . $image['data'];
                }

                if ('' !== $image_url) {
                    $content[] = [
                        'type'      => 'image_url',
                        'image_url' => ['url' => $image_url]
                    ];
                }
            }

            unset($image, $image_url, $mime);
        }

        // 3. Handle text files (only allowed pure text content)
        if (isset($data['files']) && is_array($data['files'])) {
            foreach ($data['files'] as $file) {
                if (!isset($file['content']) || '' === $file['content']) {
                    continue;
                }

                $file_name    = $file['name'] ?? 'unknown';
                $file_content = $file['content'];

                if (!$this->file_is_allowed($file_name)) {
                    $content[] = [
                        'type' => 'text',
                        'text' => '【File ' . $file_name . '】 type not allowed, skipped. Allowed: js, php, html, css, md, txt, json, py, sh, xml, sql, etc.'
                    ];

                    continue;
                }

                // Limit content length to avoid token overflow
                if (40960 < mb_strlen($file_content, 'UTF-8')) {
                    $file_content = mb_substr($file_content, 0, 40960, 'UTF-8') . "\n...[content truncated]";
                }

                $content[] = [
                    'type' => 'text',
                    'text' => '【Content of file ' . $file_name . '】' . "\n" . '```' . "\n" . $file_content . "\n" . '```'
                ];
            }

            unset($file, $file_name, $file_content);
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
            'text'      => $content
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