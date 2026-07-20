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
    public utils $utils;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->utils = utils::new();
    }

    /**
     * @param string $socket_id
     * @param array  $data_content
     *
     * @return array|string[]
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function process_setting(string $socket_id, array $data_content): array
    {
        $act = $data_content['act'] ?? 'unknown';

        switch ($act) {
            case 'getConfig':
                $current = config::new()->get(false);
                $content = [
                    'status'  => 'success',
                    'act'     => $act,
                    'content' => $current
                ];

                unset($current);
                break;

            case 'saveConfig':
                $config_hash = config::new()->save($data_content['data']);
                $content     = [
                    'status'  => 'success',
                    'act'     => $act,
                    'content' => $config_hash,
                ];

                unset($config_hash);
                break;

            case 'getDefaultConfig':
                $defaults = config::new()->getDefault();
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

        unset($socket_id, $data_content, $act, $content);
        return $result;
    }

    /**
     * @param string $socket_id
     * @param array  $data_content
     *
     * @return array
     * @throws \ReflectionException
     */
    public function process_system(string $socket_id, array $data_content): array
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

        unset($socket_id, $data_content, $act, $content);
        return $result;
    }

    /**
     * @param string $socket_id
     * @param array  $data_content
     *
     * @return array
     * @throws \ReflectionException
     */
    public function process_memory(string $socket_id, array $data_content): array
    {
        $act = $data_content['act'] ?? 'unknown';

        switch ($act) {
            case 'read':
                $content        = $data_content['memory']->read(
                    $data_content['level'] ?? 'all',
                    $data_content['offset'] ?? 0,
                    $data_content['length'] ?? 20,
                    $data_content['date'] ?? 0
                );
                $content['act'] = $act;
                break;
            case 'delete':
                $content        = $data_content['memory']->delete(
                    $data_content['level'] ?? 'none',
                    $data_content['create_ids'] ?? []
                );
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

        unset($socket_id, $data_content, $act, $content);
        return $result;
    }

    /**
     * Normal text chat
     *
     * @param string $socket_id
     * @param array  $data_content
     *
     * @return array
     */
    public function process_text(string $socket_id, array $data_content): array
    {
        $text = trim($data_content['text'] ?? '');

        if ('' !== $text) {
            if ('/reset' !== $text) {
                $result = [
                    'need_llm' => true,
                    'content'  => [['type' => 'text', 'text' => $text]],
                    'saves'    => [$text],
                    'type'     => 'chat'
                ];
            } else {
                $result = [
                    'need_llm' => false,
                    'content'  => ['status' => 'success', 'act' => 'reset', 'content' => '好了，上下文已重置，我们继续吧。'],
                    'type'     => 'message'
                ];
            }
        } else {
            $result = [
                'need_llm' => false,
                'content'  => ['status' => 'success', 'act' => 'idle', 'content' => '我在呢。有需要就告诉我，没事也可以聊两句。'],
                'type'     => 'message'
            ];
        }

        unset($socket_id, $data_content, $text);
        return $result;
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
     * @return array Associative array with 'need_llm' (bool) and 'text' (multimodal content array).
     */
    public function process_chat(string $socket_id, array $data_content): array
    {
        $content = [];
        $saves   = [];
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
                    $saves[]   = $text;
                    $content[] = ['type' => 'text', 'text' => $text];
                } else {
                    $reset = true;
                }

                unset($text);
                continue;
            }

            // 2. Handle images
            if ('image_url' === $data['type']) {
                if (isset($data['image_url']['url']) && '' !== $data['image_url']['url']) {
                    try {
                        $base64    = $this->utils->resizeImage($data['image_url']['url']);
                        $content[] = ['type' => 'image_url', 'image_url' => ['url' => $base64]];
                    } catch (\Throwable $throwable) {
                        $this->utils->debug('Image format ERROR: ' . $throwable->getMessage());
                        unset($throwable);
                    }
                }

                continue;
            }

            // 3. Handle text files (only allowed pure text content)
            if ('file' === $data['type']) {
                if (
                    isset($data['file']['content'])
                    && '' !== $data['file']['content']
                    && $this->file_is_allowed($data['file']['filename'])
                ) {
                    $detected  = mb_detect_encoding($data['file']['content'], ['UTF-8', 'GB18030', 'GBK', 'BIG-5', 'ASCII'], true);
                    $file_text = mb_convert_encoding($data['file']['content'], 'UTF-8//IGNORE', $detected ?: 'auto');

                    if ('' === $file_text) {
                        continue;
                    }

                    $content[] = [
                        'type' => 'text',
                        'text' => '--- 文件开始 ---' . "\n"
                            . '文件名: ' . $data['file']['filename'] . "\n"
                            . 'MIME类型: ' . $data['file']['mimeType'] . "\n"
                            . '文件内容:' . "\n" . $file_text . "\n"
                            . '--- 文件结束 ---'
                    ];
                }
            }
        }

        if (!empty($content)) {
            $result = [
                'need_llm' => true,
                'content'  => $content,
                'saves'    => $saves,
                'type'     => 'chat'
            ];
        } else {
            $result = [
                'need_llm' => false,
                'content'  => $reset
                    ? ['status' => 'success', 'act' => 'reset', 'content' => '好了，上下文已重置，我们继续吧。']
                    : ['status' => 'success', 'act' => 'idle', 'content' => '我在呢。有需要就告诉我，没事也可以聊两句。'],
                'type'     => 'message'
            ];
        }

        unset($socket_id, $data_content, $content, $saves, $reset, $data, $base64, $detected, $file_text);
        return $result;
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
                    try {
                        $content[$key]['image_url']['url'] = $this->utils->resizeImage($binary);
                    } catch (\Throwable $throwable) {
                        $this->utils->debug('Image format ERROR: ' . $throwable->getMessage());
                        unset($content[$key], $throwable);
                    }
                } else {
                    unset($content[$key]);
                }

                continue;
            }

            // Handle file
            if ('file' === $item['type'] && isset($item['file']['content']) && str_starts_with($item['file']['content'], '__BINARY__')) {
                $binary = $binary_blocks[$block_idx++] ?? '';

                if ('' !== $binary) {
                    $detected = mb_detect_encoding($binary, ['UTF-8', 'GB18030', 'GBK', 'BIG-5', 'ASCII'], true);

                    $content[$key]['file']['content'] = mb_convert_encoding($binary, 'UTF-8//IGNORE', $detected ?: 'auto');
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
            // ----- 纯文本与日志 -----
            'txt', 'text', 'log', 'out', 'err', 'cfg', 'conf', 'ini', 'env',
            'gitignore', 'dockerfile', 'editorconfig', 'htaccess', 'htpasswd',
            'bashrc', 'zshrc', 'profile', 'vimrc', 'screenrc', 'tmux.conf',
            'inf', 'reg', 'url', 'desktop', 'directory', 'm3u', 'm3u8', 'pls',
            'cue', 'srt', 'vtt', 'ass', 'ssa', 'sub', 'idx',

            // ----- 标记语言与文档 -----
            'md', 'markdown', 'rst', 'adoc', 'asciidoc', 'tex', 'latex', 'bib', 'rtf',
            'pod', 'wiki', 'mediawiki', 'html', 'htm', 'xhtml', 'xml', 'xsl', 'xslt',
            'dtd', 'svg', 'rss', 'atom', 'json', 'jsonl', 'json5', 'yaml', 'yml',
            'toml', 'plist', 'csv', 'tsv', 'psv', 'dif', 'diff', 'patch',

            // ----- 脚本与编程语言源码 -----
            'sh', 'bash', 'zsh', 'fish', 'ksh', 'bat', 'cmd', 'ps1', 'psd1', 'psm1',
            'vbs', 'vba', 'js', 'javascript', 'ts', 'tsx', 'jsx', 'mjs', 'cjs',
            'php', 'php3', 'php4', 'php5', 'phpt', 'phtml', 'py', 'python', 'pyw',
            'pyi', 'r', 'rmd', 'pl', 'pm', 't', 'raku', 'rb', 'ru', 'go', 'rs',
            'swift', 'c', 'cpp', 'cc', 'cxx', 'h', 'hpp', 'hxx', 'inl', 'ipp',
            'java', 'kt', 'kts', 'groovy', 'scala', 'clj', 'cljs', 'edn', 'coffee',
            'lua', 'erl', 'hrl', 'ex', 'exs', 'elm', 'hs', 'lhs', 'ml', 'mli',
            'nim', 'cr', 'zig', 'd', 'v', 'f90', 'f95', 'f03', 'f08', 'for', 'f',
            'pas', 'pp', 'm', 'mm',

            // ----- Web 模板与前端组件 -----
            'vue', 'svelte', 'astro', 'njk', 'liquid', 'twig', 'hbs', 'mustache',
            'ejs', 'pug', 'jade', 'haml', 'slm', 'slim', 'erb', 'rhtml', 'jsp',
            'asp', 'aspx', 'cshtml', 'vbhtml', 'volt', 'smarty', 'tpl', 'latte',
            'blade.php',

            // ----- 配置文件（补充常见工具链）-----
            'config', 'properties', 'prop', 'pkl', 'hcl', 'tf', 'tfvars', 'nomad',
            'service', 'socket', 'target', 'link', 'prettierrc', 'eslintrc',
            'babelrc', 'stylelintrc', 'htmlhintrc', 'pylintrc', 'flake8',
            'mypy.ini', 'pytest.ini', 'tox.ini', 'clang-format', 'clang-tidy',
            'cmake', 'makefile', 'gnumakefile', 'containerfile', 'nginx.conf',
            'apache.conf', 'httpd.conf', 'php.ini', 'my.cnf', 'pg_hba.conf',
            'redis.conf', 'mongod.conf', 'logrotate.d', 'crontab', 'systemd',

            // ----- 数据库与查询 -----
            'sql', 'psql', 'mysql', 'sqlite', 'ddl', 'dml', 'plsql', 'pgsql', 'cql',
            'hql', 'graphql',

            // ----- 科学计算与数据交换（保留常见格式）-----
            'mat', 'jl', 'ipynb',

            // ----- 版本控制与忽略文件 -----
            'gitattributes', 'gitmodules', 'gitkeep', 'mailmap', 'dockerignore',
            'npmignore', 'eslintignore', 'prettierignore', 'stylelintignore',
            'jshintignore', 'cvsignore', 'hgignore', 'svnignore', 'bzrignore',
            'cfignore', 'slugignore',

            // ----- 其他日常或开发中可能遇到的格式 -----
            'bib', 'ris', 'rdf', 'ical', 'ics', 'vcard', 'vcf', 'eml'
        ];

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed   = in_array($extension, $whitelist, true);

        unset($filename, $whitelist, $extension);
        return $allowed;
    }

    /**
     * @param string $filename
     *
     * @return bool
     */
    private function image_is_allowed(string $filename): bool
    {
        $whitelist = ['jpg', 'jpeg', 'bmp', 'png', 'gif', 'webp'];
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

        if (str_starts_with($hex, '424d')) {
            return 'image/bmp';
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

        unset($binary, $magic, $hex);
        return 'image/jpeg';
    }
}