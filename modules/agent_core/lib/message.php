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
                $content        = $data_content['memory']->read('misc', 0, $data_content['length'], 0, $data_content['create_id'] ?? 0);
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
                    'content'  => [['type' => 'text', 'content' => $text]],
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
     * - 'file' (array): list of text files, each as:
     *      ['filename' => ..., 'mimeType' => ..., 'content' => ...] (base64 content)
     *
     * @param string $socket_id
     * @param array  $data_content
     *
     * @return array Associative array with 'need_llm' (bool), 'content' (multimodal content array) and others.
     */
    public function process_chat(string $socket_id, array $data_content): array
    {
        $content = [];
        $errors  = [];
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
                    $content[] = ['type' => 'text', 'content' => $text];
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

                // 2.1 Process images
                if ('' !== $this->utils->getImageType($binary)) {
                    if (!$this->imageIsAllowed($data['file']['filename'])) {
                        $errors[] = $data['file']['filename'] . '：图片格式不支持';
                        continue;
                    }

                    try {
                        $data_url  = $this->utils->resizeImage($binary);
                        $content[] = ['type' => 'text', 'content' => $data['file']['filename']];
                        $content[] = ['type' => 'image', 'content' => $data_url];
                    } catch (\Throwable $throwable) {
                        $errors[] = $data['file']['filename'] . '：' . $throwable->getMessage();
                        unset($throwable);
                        continue;
                    }

                    continue;
                }

                // 2.2 Process plain text files
                if (!$this->fileIsAllowed($data['file']['filename']) || str_contains($binary, "\0")) {
                    $errors[] = $data['file']['filename'] . '：文件格式不支持';
                    continue;
                }

                try {
                    $detected  = mb_detect_encoding($binary, ['GB18030', 'UTF-8', 'BIG-5', 'ASCII'], true);
                    $file_text = iconv($detected ?: 'UTF-8', 'UTF-8//IGNORE', $binary) ?: '';

                    if ('' === $file_text) {
                        throw new \RuntimeException('文本提取失败');
                    }

                    $content[] = [
                        'type'    => 'text',
                        'content' => '--- 文件信息 ---' . "\n"
                            . '文件名: ' . $data['file']['filename'] . "\n"
                            . 'MIME类型: ' . $data['file']['mimeType'] . "\n"
                            . '--- 文件内容（开始） ---' . "\n"
                            . $file_text . "\n"
                            . '--- 文件内容（结束） ---'
                    ];
                } catch (\Throwable $throwable) {
                    $errors[] = $data['file']['filename'] . '：' . $throwable->getMessage();
                    unset($throwable);
                    continue;
                }
            }
        }

        if ([] !== $content) {
            $result = [
                'need_llm' => true,
                'content'  => $content,
                'errors'   => $errors,
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

        unset($socket_id, $data_content, $content, $errors, $saves, $reset, $data, $text, $binary, $data_url, $detected, $file_text);
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
     * Check if a file name has an allowed text file extension.
     *
     * @param string $filename
     *
     * @return bool
     */
    private function fileIsAllowed(string $filename): bool
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
}