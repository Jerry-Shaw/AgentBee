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

namespace modules\agent_core\lib;

use modules\agent_openai\go;
use Nervsys\Core\Factory;

class message extends Factory
{
    public utils  $utils;
    public config $config;

    public string $upload_path;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->utils  = utils::new();
        $this->config = config::new();

        $this->upload_path = rtrim($this->config->config['workspace_path'], '\\/') . DIRECTORY_SEPARATOR . 'UploadFiles';
    }

    /**
     * @param array $data_content
     *
     * @return array|string[]
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function process_setting(array $data_content): array
    {
        $act = $data_content['act'] ?? 'unknown';

        switch ($act) {
            case 'getConfig':
                $current = $this->config->get(false);
                $content = [
                    'status'  => 'success',
                    'act'     => $act,
                    'content' => $current
                ];

                unset($current);
                break;

            case 'saveConfig':
                $config_hash = $this->config->save($data_content['data']);
                $content     = [
                    'status'  => 'success',
                    'act'     => $act,
                    'content' => $config_hash,
                ];

                unset($config_hash);
                break;

            case 'getDefaultConfig':
                $defaults = $this->config->getDefault();
                $content  = [
                    'status'  => 'success',
                    'act'     => $act,
                    'content' => $defaults
                ];

                unset($defaults);
                break;

            default:
                $content = [
                    'status' => 'error',
                    'error'  => 'Unsupported act: ' . $data_content['act']
                ];
                break;
        }

        $result = [
            'need_llm' => false,
            'content'  => $content,
            'type'     => 'setting'
        ];

        unset($data_content, $act, $content);
        return $result;
    }

    /**
     * @param array $data_content
     *
     * @return array
     * @throws \ReflectionException
     */
    public function process_system(array $data_content): array
    {
        $act = $data_content['act'] ?? 'unknown';

        switch ($act) {
            case 'getVersion':
                $content = [
                    'AGENT'   => AGENT_NAME . ' v' . AGENT_VERSION,
                    'Nervsys' => NS_NAMESPACE . ' v' . NS_VER,
                ];
                break;

            case 'getModels':
                $content        = go::new()->getModels();
                $content['act'] = $act;
                break;

            case 'reasoningEfforts':
                $content        = [
                    'status' => 'success',
                    'data'   => ['none', 'minimal', 'low', 'medium', 'high', 'xhigh']
                ];
                $content['act'] = $act;
                break;

            default:
                $content = [
                    'status' => 'error',
                    'error'  => 'Unsupported act: ' . $data_content['act']
                ];
                break;
        }

        $result = [
            'need_llm' => false,
            'content'  => $content,
            'type'     => 'system'
        ];

        unset($data_content, $act, $content);
        return $result;
    }

    /**
     * @param array  $data_content
     * @param string $session_id
     *
     * @return array
     * @throws \ReflectionException
     */
    public function process_memory(array $data_content, string $session_id): array
    {
        $act = $data_content['act'] ?? 'unknown';

        switch ($act) {
            case 'read':
                $content = $data_content['memory']->read('misc', 0, 0, $data_content['length'], $session_id, $data_content['create_id'] ?? 0, true);

                $content['act'] = $act;
                break;

            case 'delete':
                $content = [] !== $data_content['create_ids']
                    ? $data_content['memory']->delete('misc', $data_content['create_ids'])
                    : ['status' => 'error', 'error' => '缺少目标记忆ID'];

                $content['act'] = $act;
                break;

            case 'listSessions':
                $content = $data_content['memory']->listSessions(1, '', true);

                $content['act'] = $act;
                break;

            case 'renameSession':
                if ('' !== $session_id && '' !== ($data_content['session_name'] ?? '')) {
                    $session_name = mb_substr($data_content['session_name'], 0, 32, 'UTF-8');

                    $content = [] === $data_content['memory']->listSessions(0, $session_id, true)['sessions']
                        ? $data_content['memory']->createSession($session_id, $session_name)
                        : $data_content['memory']->renameSession($session_id, $session_name);
                } else {
                    $content = ['status' => 'error', 'error' => '缺少会话ID'];
                }

                $content['act'] = $act;
                break;

            case 'deleteSession':
                if ('' !== $session_id) {
                    $context = context::new();
                    $context->removeHistory($session_id, WORKER_MAIN);
                    $context->removeMessageQueue($session_id, WORKER_MAIN);
                    $content = $data_content['memory']->deleteSession($session_id);
                } else {
                    $content = ['status' => 'error', 'error' => '缺少会话ID'];
                }

                $content['act'] = $act;
                break;

            default:
                $content = [
                    'status' => 'error',
                    'error'  => 'Unsupported act: ' . $data_content['act']
                ];
                break;
        }

        $result = [
            'need_llm' => false,
            'content'  => $content,
            'type'     => 'memory'
        ];

        unset($data_content, $session_id, $act, $content);
        return $result;
    }

    /**
     * Multimodal content chat
     *
     * Expected input $data structure:
     * - 'text' (string): plain text message
     * - 'file' (array): list of text files, each as:
     *      ['filename' => ..., 'mimeType' => ..., 'content' => ...] (base64 content)
     *
     * @param array $data_content
     *
     * @return array Associative array with 'need_llm' (bool), 'content' (multimodal content array) and others.
     */
    public function process_chat(array $data_content): array
    {
        $user_text   = [];
        $user_files  = [];
        $user_images = [];

        $content = [];
        $errors  = [];
        $files   = [];
        $reset   = false;

        foreach ($data_content as $data) {
            if (!isset($data['type'])) {
                continue;
            }

            // 1. Handle plain text
            if ('text' === $data['type']) {
                $text = trim($data['text'] ?? '');

                if ('' === $text) {
                    continue;
                }

                if ('/reset' !== $text) {
                    $user_text[] = $text;
                    $content[]   = ['type' => 'text', 'content' => $text];
                } else {
                    $reset = true;
                }

                continue;
            }

            // 2. Handle files/images
            if ('file' === $data['type']) {
                if (!isset($data['file']['filename']) || !isset($data['file']['content']) || '' === $data['file']['content']) {
                    continue;
                }

                $binary = base64_decode($data['file']['content']);

                // 2.1 Process image files
                if ('' !== $this->utils->getImageType($binary)) {
                    if (!$this->imageIsAllowed($data['file']['filename'])) {
                        $errors[] = $data['file']['filename'] . '：图片格式不支持';
                        continue;
                    }

                    try {
                        $save_file   = $this->saveUploadFile($data['file']['filename'], $binary);
                        $prompt_text = '"' . $data['file']['filename'] . '"：图片已附在上下文中，可直接查看，无需读取文件。';

                        if ($save_file['uploaded']) {
                            $prompt_text .= "\n" . '原图路径：`' . $save_file['file_path'] . '`，需要时可读取。';
                        }

                        $content[] = ['type' => 'text', 'content' => $prompt_text];
                        $content[] = ['type' => 'image', 'content' => $this->utils->resizeImage($binary)];

                        $user_images[] = [
                            'filename'   => $data['file']['filename'],
                            'file_path'  => $save_file['file_path'] ?? '',
                            'img_base64' => $this->utils->resizeImage($binary, 320, 320)
                        ];
                    } catch (\Throwable $throwable) {
                        $errors[] = $data['file']['filename'] . '：' . $throwable->getMessage();
                        unset($throwable);
                        continue;
                    }

                    continue;
                }

                // 2.2 Process plain-text files
                if ($this->fileIsText($data['file']['filename']) && !str_contains($binary, "\0")) {
                    try {
                        $detected  = mb_detect_encoding($binary, ['GB18030', 'UTF-8', 'BIG-5', 'ASCII'], true);
                        $file_text = iconv($detected ?: 'UTF-8', 'UTF-8//IGNORE', $binary) ?: '';

                        if ('' === $file_text) {
                            throw new \RuntimeException('文本提取失败');
                        }

                        $save_file   = $this->saveUploadFile($data['file']['filename'], $binary);
                        $prompt_text = '--- 文件信息 ---' . "\n"
                            . '文件名: ' . $data['file']['filename'] . "\n"
                            . 'MIME类型: ' . $data['file']['mimeType'] . "\n";

                        if ($save_file['uploaded']) {
                            $prompt_text .= '文件路径：`' . $save_file['file_path'] . '`，需要时可读取。' . "\n";
                        }

                        $prompt_text .= '--- 文件内容（开始） ---' . "\n"
                            . $file_text . "\n"
                            . '--- 文件内容（结束） ---';

                        $content[] = [
                            'type'    => 'text',
                            'content' => $prompt_text
                        ];

                        $user_files[] = [
                            'filename'  => $data['file']['filename'],
                            'file_path' => $save_file['file_path'] ?? ''
                        ];
                    } catch (\Throwable $throwable) {
                        $errors[] = $data['file']['filename'] . '：' . $throwable->getMessage();
                        unset($throwable);
                    }

                    continue;
                }

                // 2.3 Process other type of files
                $save_file = $this->saveUploadFile($data['file']['filename'], $binary);

                if ($save_file['uploaded']) {
                    $files[] = [
                        'filename' => $data['file']['filename'],
                        'filepath' => $save_file['file_path']
                    ];

                    $user_files[] = [
                        'filename'  => $data['file']['filename'],
                        'file_path' => $save_file['file_path']
                    ];
                }
            }
        }

        if ([] !== $files) {
            $file_list = '';

            foreach ($files as $i => $file) {
                $file_list .= ($i + 1) . '. 文件名: ' . $file['filename'] . "\n" . '   文件路径: ' . $file['filepath'] . "\n";
            }

            $content[] = [
                'type'    => 'text',
                'content' => '--- 文件信息 ---' . "\n"
                    . '共 ' . count($files) . ' 个文件：' . "\n"
                    . $file_list
                    . '--- 任务说明 ---' . "\n"
                    . '用户上传了以上文件，请根据各文件类型调用合适工具读取内容，并按用户要求进行分析。若未找到合适工具，请告知用户；若用户未提出具体要求，请主动询问其意图。'
            ];

            unset($file_list, $i, $file);
        }

        if ([] !== $content) {
            $full_chat = [];
            $full_text = [] !== $user_text ? implode(' ', $user_text) : '';

            if ('' !== $full_text) {
                $full_chat['content'] = $full_text;
            }
            if ([] !== $user_images) {
                $full_chat['images'] = $user_images;
            }
            if ([] !== $user_files) {
                $full_chat['files'] = $user_files;
            }

            $result = [
                'type'     => 'chat',
                'need_llm' => true,
                'content'  => $content,
                'errors'   => $errors,
                'memory'   => $full_text,
                'chat'     => $full_chat
            ];

            unset($full_chat, $full_text);
        } else {
            $result = [
                'type'     => 'message',
                'need_llm' => false,
                'content'  => $reset
                    ? ['status' => 'success', 'act' => 'reset', 'content' => '好了，上下文已重置，我们继续吧。']
                    : ['status' => 'success', 'act' => 'idle', 'content' => '我在呢。有需要就告诉我，没事也可以聊两句。']
            ];
        }

        unset($data_content, $user_text, $user_files, $user_images, $content, $errors, $files, $reset, $data, $text, $binary, $save_file, $prompt_text, $detected, $file_text);
        return $result;
    }

    /**
     * @param array $data_content
     * @param array $msg_marker
     *
     * @return array
     */
    public function buildFeedback(array $data_content, array $msg_marker): array
    {
        $msg_list = [];

        foreach ($data_content as $data) {
            if (!isset($data['type']) || 'text' !== $data['type']) {
                continue;
            }

            $text = trim($data['text'] ?? '');

            if ('' === $text || '/reset' === $text) {
                continue;
            }

            $msg_list[] = $text;
        }

        $message = [] !== $msg_list
            ? $msg_marker + ['type' => 'feedback', 'data' => implode("\n", $msg_list)]
            : [];

        unset($data_content, $msg_marker, $msg_list, $data, $text);
        return $message;
    }

    /**
     * @param string $filename
     * @param string $binary
     *
     * @return array|false[]
     */
    private function saveUploadFile(string $filename, string $binary): array
    {
        $upload_path = $this->upload_path . DIRECTORY_SEPARATOR . date('Ymd');

        if (!is_dir($upload_path)) {
            try {
                mkdir($upload_path, 0777, true);
            } catch (\Throwable) {
            }
        }

        $file_path  = $upload_path . DIRECTORY_SEPARATOR . $filename;
        $save_bytes = file_put_contents($file_path, $binary);

        $result = false !== $save_bytes && 0 < $save_bytes
            ? ['uploaded' => true, 'file_path' => $file_path]
            : ['uploaded' => false];

        unset($filename, $binary, $upload_path, $file_path, $save_bytes);
        return $result;
    }

    /**
     * @param string $filename
     *
     * @return bool
     */
    private function imageIsAllowed(string $filename): bool
    {
        $whitelist = ['jpg', 'jpeg', 'tif', 'tiff', 'webp', 'png', 'bmp', 'gif'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed   = in_array($extension, $whitelist, true);

        unset($filename, $whitelist, $extension);
        return $allowed;
    }

    /**
     * Check plain text file extension
     *
     * @param string $filename
     *
     * @return bool
     */
    private function fileIsText(string $filename): bool
    {
        $whitelist = [
            // 文本
            'txt', 'log', 'md', 'rst', 'csv', 'tsv', 'diff', 'patch', 'markdown',

            // 网页
            'html', 'htm', 'xhtml', 'xml', 'svg',
            'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx',
            'css', 'scss', 'sass', 'less', 'vue', 'svelte',

            // 配置
            'ini', 'env', 'cfg', 'cnf', 'conf', 'json', 'jsonl', 'yml', 'yaml', 'toml', 'properties',

            // 系统脚本
            'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1',

            // 编程源码
            'php', 'py', 'r', 'rb', 'go', 'rs', 'swift', 'cs', 'dart',
            'java', 'kt', 'kts', 'scala', 'groovy', 'lua',
            'c', 'cpp', 'cc', 'cxx', 'h', 'hpp',

            // 数据库
            'sql', 'graphql',

            // 字幕
            'srt', 'vtt', 'ass', 'ssa', 'lrc', 'sub', 'idx',
        ];

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed   = in_array($extension, $whitelist, true);

        unset($filename, $whitelist, $extension);
        return $allowed;
    }
}