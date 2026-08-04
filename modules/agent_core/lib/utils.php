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

use Nervsys\Core\Factory;
use Nervsys\Core\Lib\App;
use Nervsys\Core\Lib\Error;
use Nervsys\Core\Mgr\OSMgr;
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Ext\libFileIO;
use Nervsys\Ext\libImage;

class utils extends Factory
{
    public App       $app;
    public OSMgr     $OSMgr;
    public ProcMgr   $procMgr;
    public libFileIO $libFileIO;
    public config    $config;

    public int $main_idx   = 0;
    public int $proc_idx   = 10;
    public int $worker_idx = 1000;

    public array $agent_config;

    public string $session_id;
    public string $pid_file_path;

    public string $memory_buffer = '';

    public array $session_history = [];

    public array $child_workers  = [];
    public array $socket_session = [];
    public array $stream_buffers = [];

    public array $message_queue   = [];
    public array $message_buffers = [];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->app       = App::new();
        $this->OSMgr     = OSMgr::new();
        $this->procMgr   = ProcMgr::new('socket');
        $this->libFileIO = libFileIO::new();
        $this->config    = config::new();

        $this->agent_config  = $this->config->get();
        $this->session_id    = hash('md5', uniqid('', true));
        $this->pid_file_path = $this->config->config_dir . DIRECTORY_SEPARATOR . 'proc' . DIRECTORY_SEPARATOR;

        if (!is_dir($this->pid_file_path)) {
            try {
                mkdir($this->pid_file_path, 777, true);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @return int
     */
    public function getMainIDX(): int
    {
        return $this->main_idx;
    }

    /**
     * @return int
     */
    public function getProcIDX(): int
    {
        if ($this->proc_idx >= $this->worker_idx) {
            $this->proc_idx = 100;
        }

        return $this->proc_idx++;
    }

    /**
     * @return int
     */
    public function getWorkerIDX(): int
    {
        return $this->worker_idx++;
    }

    /**
     * @param int $pid
     *
     * @return void
     */
    public function savePid(int $pid): void
    {
        fclose(fopen($this->pid_file_path . $pid, 'w'));
        unset($pid);
    }

    /**
     * @param int $pid
     *
     * @return void
     */
    public function removePid(int $pid): void
    {
        if (is_file($this->pid_file_path . $pid)) {
            unlink($this->pid_file_path . $pid);
        }

        unset($pid);
    }

    /**
     * @param bool $kill_pid
     *
     * @return void
     */
    public function cleanPids(bool $kill_pid): void
    {
        $pid_files = $this->libFileIO->getDirContents($this->pid_file_path);

        if ($kill_pid) {
            foreach ($pid_files as $file) {
                $this->OSMgr->killPid($file['name']);
                unlink($file['absolute_path']);
            }
        } else {
            foreach ($pid_files as $file) {
                unlink($file['absolute_path']);
            }
        }

        unset($kill_pid, $pid_files, $file);
    }

    /**
     * @param string $worker_name
     * @param array  $content
     *
     * @return int
     */
    public function addSessionHistory(string $worker_name, array $content): int
    {
        $this->session_history[$worker_name]   ??= [];
        $this->session_history[$worker_name][] = $content;

        $message_count = count($this->session_history[$worker_name]);

        unset($worker_name, $content);
        return $message_count;
    }

    /**
     * @param string $worker_name
     * @param array  $history
     *
     * @return void
     */
    public function setSessionHistory(string $worker_name, array $history): void
    {
        $this->session_history[$worker_name] = $history;
        unset($worker_name, $history);
    }

    /**
     * @param string $worker_name
     *
     * @return array
     */
    public function getSessionHistory(string $worker_name): array
    {
        return $this->session_history[$worker_name] ?? [];
    }

    /**
     * @param string $worker_name
     *
     * @return int
     */
    public function countSessionHistory(string $worker_name): int
    {
        return count($this->session_history[$worker_name] ?? []);
    }

    /**
     * @param string $worker_name
     *
     * @return void
     */
    public function removeSessionHistory(string $worker_name): void
    {
        unset($this->session_history[$worker_name]);
    }

    /**
     * @param string $worker_name
     *
     * @return int
     */
    public function refreshSessionHistory(string $worker_name): int
    {
        if (!isset($this->message_queue[$worker_name]) || empty($this->message_queue[$worker_name])) {
            return 0;
        }

        $messages = [];

        while (!is_null($message = array_shift($this->message_queue[$worker_name]))) {
            $messages[] = $message;
        }

        $count_messages = count($messages);

        if (0 < $count_messages) {
            $this->debug($worker_name . ': Adding ' . $count_messages . ' message(s) to history', 'trace');
            $this->addSessionHistory($worker_name, ['role' => 'user', 'content' => $messages]);
        }

        unset($worker_name, $messages, $message);
        return $count_messages;
    }

    /**
     * @param string $worker_name
     * @param int    $keep_normal
     * @param int    $max_tool_pairs
     *
     * @return array
     */
    public function cleanSessionHistory(string $worker_name, int $keep_normal = 6, int $max_tool_pairs = 2): array
    {
        $history = $this->session_history[$worker_name] ?? [];

        $keep_normal    = max(6, $keep_normal);
        $max_tool_pairs = max(2, $max_tool_pairs);

        $system       = '';
        $messages     = [];
        $total_normal = 0;
        $total_tools  = 0;

        // Extract the first system message.
        foreach ($history as $message) {
            $role = $message['role'];

            if ('system' === $role) {
                if ('' === $system) {
                    $system = $message;
                }

                continue;
            }

            if ('assistant' === $role && empty($message['tool_calls'])) {
                unset($message['tool_calls']);
            }

            $is_tool_calls = 'assistant' === $role && isset($message['tool_calls']);
            $total_normal  += (int)('user' === $role || ('assistant' === $role && !$is_tool_calls));
            $total_tools   += (int)$is_tool_calls;
            $messages[]    = $message;
        }

        // Locate the first user independently.
        $first_user = null;

        foreach ($messages as $index => $message) {
            if ('user' === $message['role']) {
                $first_user = $index;
                break;
            }
        }

        if (is_null($first_user)) {
            $this->session_history[$worker_name] = '' === $system ? [] : [$system];

            $clean_result = [
                'removed_normal' => $total_normal,
                'removed_tools'  => $total_tools,
                'current_count'  => count($this->session_history[$worker_name]),
            ];

            unset($worker_name, $keep_normal, $max_tool_pairs, $history, $system, $messages, $total_normal, $total_tools, $message, $role, $is_tool_calls, $first_user, $index);
            return $clean_result;
        }

        $messages = array_values(array_slice($messages, $first_user));

        $groups  = [];
        $results = [];

        $valid_groups   = [];
        $normal_indices = [];

        // Index normal messages, tool declarations and tool results.
        foreach ($messages as $index => $message) {
            if ('user' === $message['role'] || ('assistant' === $message['role'] && !isset($message['tool_calls']))) {
                $normal_indices[] = $index;
            } elseif ('tool' === $message['role']) {
                $results[$message['tool_call_id']] = $index;
            } elseif ('assistant' === $message['role']) {
                $groups[$index] = array_column($message['tool_calls'], 'id');
            }
        }

        // Keep groups whose calls all have later results.
        foreach ($groups as $group_index => $call_ids) {
            $group_results = [];

            foreach ($call_ids as $call_id) {
                $result = $results[$call_id] ?? -1;

                if ($result <= $group_index) {
                    continue 2;
                }

                $group_results[$result] = true;
            }

            $valid_groups[$group_index] = $group_results;
        }

        // Locate the oldest recent normal message.
        $start = $normal_indices[max(0, count($normal_indices) - $keep_normal)];

        // Align the retained suffix to a user boundary.
        while ('user' !== $messages[$start]['role']) {
            --$start;
        }

        // Select the newest complete tool groups inside the retained suffix.
        $selected_groups = array_filter(
            $valid_groups,
            function (int $group_index) use ($start): bool
            {
                return $group_index >= $start;
            }, ARRAY_FILTER_USE_KEY
        );

        $selected_groups = array_slice($selected_groups, -$max_tool_pairs, null, true);

        // Index results belonging to selected tool groups.
        $selected_results = [];
        foreach ($selected_groups as $group_results) {
            $selected_results += $group_results;
        }

        $kept_normal = 0;
        $new_history = '' === $system ? [] : [$system];

        // Rebuild normal messages and selected complete tool groups.
        foreach ($messages as $index => $message) {
            if ($index < $start) {
                continue;
            }

            if ('user' === $message['role'] || ('assistant' === $message['role'] && !isset($message['tool_calls']))) {
                $new_history[] = $message;
                ++$kept_normal;
            } elseif ('assistant' === $message['role'] && isset($selected_groups[$index])) {
                $new_history[] = $message;
            } elseif ('tool' === $message['role'] && isset($selected_results[$index])) {
                $new_history[] = $message;
            }
        }

        $this->session_history[$worker_name] = $new_history;

        $clean_result = [
            'removed_normal' => $total_normal - $kept_normal,
            'removed_tools'  => $total_tools - count($selected_groups),
            'current_count'  => count($new_history),
        ];

        unset($worker_name, $keep_normal, $max_tool_pairs, $history, $system, $messages, $total_normal, $total_tools, $message, $role, $is_tool_calls, $first_user, $index, $groups, $results, $valid_groups, $normal_indices, $group_index, $call_ids, $group_results, $call_id, $result, $start, $selected_groups, $selected_results, $kept_normal, $new_history);
        return $clean_result;
    }

    /**
     * @param string $worker_name
     * @param string $tool_call_id
     * @param string $content
     *
     * @return void
     */
    public function replaceToolResult(string $worker_name, string $tool_call_id, string $content): void
    {
        if (!isset($this->session_history[$worker_name])) {
            return;
        }

        $last_key = count($this->session_history[$worker_name]) - 1;

        for ($i = $last_key; $i >= 0; --$i) {
            $message = $this->session_history[$worker_name][$i];

            if ('tool' === $message['role'] && $tool_call_id === $message['tool_call_id']) {
                $this->session_history[$worker_name][$i]['content'] = $content;
                break;
            }
        }

        unset($worker_name, $tool_call_id, $content, $last_key, $message, $i);
    }

    /**
     * @param string $worker_name
     * @param array  $message
     *
     * @return void
     */
    public function addMessageQueue(string $worker_name, array $message): void
    {
        $this->message_queue[$worker_name]   ??= [];
        $this->message_queue[$worker_name][] = $message;

        unset($worker_name, $message);
    }

    /**
     * @param string $worker_name
     * @param bool   $popout
     *
     * @return array
     */
    public function getMessageQueue(string $worker_name, bool $popout = false): array
    {
        $messages = $this->message_queue[$worker_name] ?? [];

        if ($popout) {
            unset($this->message_queue[$worker_name]);
        }

        unset($worker_name, $popout);
        return $messages;
    }

    /**
     * @param string $worker_name
     *
     * @return void
     */
    public function removeMessageQueue(string $worker_name): void
    {
        unset($this->message_queue[$worker_name], $worker_name);
    }

    /**
     * @param string $type
     * @param string $name
     * @param array  $data
     *
     * @return void
     */
    public function addChildWorker(string $type, string $name, array $data): void
    {
        $this->child_workers[$type][$name] = $data;
        unset($type, $name, $data);
    }

    /**
     * @param string $type
     * @param string $name
     * @param string $key
     * @param mixed  $value
     *
     * @return void
     */
    public function setChildWorker(string $type, string $name, string $key, mixed $value): void
    {
        $this->child_workers[$type][$name][$key] = $value;
        unset($type, $name, $key, $value);
    }

    /**
     * @param string $type
     * @param string $name
     *
     * @return array
     */
    public function getChildWorker(string $type, string $name = ''): array
    {
        return '' === $name ? ($this->child_workers[$type] ?? []) : ($this->child_workers[$type][$name] ?? []);
    }

    /**
     * @param string $type
     * @param string $name
     *
     * @return void
     */
    public function removeChildWorker(string $type, string $name): void
    {
        unset($this->child_workers[$type][$name]);

        if (empty($this->child_workers[$type])) {
            unset($this->child_workers[$type]);
        }
    }

    /**
     * @param string $binary
     * @param int    $width
     * @param int    $height
     *
     * @return string
     * @throws \ReflectionException
     */
    public function resizeImage(string $binary, int $width = 2048, int $height = 2048): string
    {
        if (str_starts_with($binary, 'data:image/')) {
            $base64_pos = strpos($binary, ',');

            if (false !== $base64_pos) {
                $base64 = substr($binary, $base64_pos + 1);
                $binary = base64_decode($base64);

                unset($base64);
            }

            unset($base64_pos);
        }

        $src_image = imagecreatefromstring($binary);

        if (false === $src_image) {
            throw new \RuntimeException('图片文件损坏，加载失败');
        }

        $new_image = libImage::new()->gd_resize($src_image, $width, $height);

        $image_info = getimagesizefromstring($binary);
        $image_mime = $image_info['mime'] ?? 'image/jpeg';

        ob_start();
        switch ($image_mime) {
            case 'image/webp':
                $image_mime = 'image/png';
            case 'image/png':
                imagepng($new_image);
                break;
            case 'image/gif':
                imagegif($new_image);
                break;
            default:
                imagejpeg($new_image, null, 90);
                break;
        }
        $image_binary = ob_get_clean();

        $data_uri = 'data:' . $image_mime . ';base64,' . base64_encode($image_binary);

        unset($binary, $width, $height, $src_image, $new_image, $image_info, $image_mime, $image_binary);
        return $data_uri;
    }

    /**
     * Secure target path and prevent path traversal
     *
     * @param string $input_path
     *
     * @return string
     */
    public function securePath(string $input_path): string
    {
        $sandbox_mode = $this->agent_config['sandbox_mode'] ?? true;
        $input_path   = strtr($input_path, ['\\' => DIRECTORY_SEPARATOR, '/' => DIRECTORY_SEPARATOR]);
        $work_path    = rtrim($this->agent_config['workspace_path'], '\\/');
        $work_path    = strtr($work_path, ['\\' => DIRECTORY_SEPARATOR, '/' => DIRECTORY_SEPARATOR]);

        if ($sandbox_mode) {
            if (str_starts_with($input_path, $work_path)) {
                $input_path = substr($input_path, strlen($work_path) + 1);
            }

            $safe_parts = [];
            $path_parts = explode(DIRECTORY_SEPARATOR, $input_path);

            foreach ($path_parts as $segment) {
                $segment = trim($segment, '. ');

                if ('' === $segment || str_contains($segment, ':')) {
                    continue;
                }

                $safe_parts[] = $segment;
            }

            $input_path = implode(DIRECTORY_SEPARATOR, $safe_parts);
            $input_path = $work_path . DIRECTORY_SEPARATOR . $input_path;

            unset($safe_parts, $path_parts, $segment);
        }

        unset($sandbox_mode, $work_path);
        return $input_path;
    }

    /**
     * @param string $sender
     * @param string $worker_name
     * @param string $worker_role
     * @param string $window_name
     * @param int    $is_sub_talk
     * @param string $message_id
     *
     * @return array
     */
    public function getMessageMarker(
        string $sender,
        string $worker_name,
        string $worker_role,
        string $window_name,
        int    $is_sub_talk,
        string $message_id = ''
    ): array
    {
        if ('' === $message_id) {
            $message_id = hash('md5', uniqid(microtime(), true));
        }

        $marker = [
            'sender'     => $sender,
            'isSubTalk'  => $is_sub_talk,
            'workerName' => $worker_name,
            'workerRole' => $worker_role,
            'sessionId'  => $this->session_id,
            'messageId'  => $message_id,
            'WindowName' => $window_name
        ];

        unset($sender, $worker_name, $worker_role, $window_name, $message_id, $is_sub_talk);
        return $marker;
    }

    /**
     * @return array
     */
    public function fetchPrograms(): array
    {
        $available = [];
        $name_list = ['7z', 'git', 'curl', 'php', 'python', 'pip', 'uv', 'node', 'npm', 'npx', 'ffmpeg'];

        foreach ($name_list as $name) {
            $paths = $this->OSMgr->findPath($name);

            if (!empty($paths)) {
                $available[] = $name;
            }
        }

        unset($name_list, $name, $paths);
        return $available;
    }

    /**
     * @param string $dirname
     * @param string $module
     * @param array  $tool_names
     *
     * @return array
     * @throws \ReflectionException
     */
    public function fetchToolset(string $dirname, string $module = '', array $tool_names = []): array
    {
        $skills   = [];
        $dir_list = [];
        $dirname  = strtr($dirname, ['\\' => DIRECTORY_SEPARATOR, '/' => DIRECTORY_SEPARATOR]);

        if ('' !== $module) {
            $dir_list[] = [
                'name'          => $module,
                'absolute_path' => $this->app->root_path . DIRECTORY_SEPARATOR . $dirname . DIRECTORY_SEPARATOR . $module
            ];
        } else {
            $contents = $this->libFileIO->getDirContents($this->app->root_path . DIRECTORY_SEPARATOR . $dirname);

            foreach ($contents as $item) {
                if ($item['is_file']) {
                    continue;
                }

                $dir_list[] = [
                    'name'          => $item['name'],
                    'absolute_path' => $item['absolute_path']
                ];
            }

            unset($contents);
        }

        foreach ($dir_list as $path) {
            $json_file = $path['absolute_path'] . DIRECTORY_SEPARATOR . 'module.json';
            $meta_file = $path['absolute_path'] . DIRECTORY_SEPARATOR . 'skills.php';

            if (!is_file($json_file) || !is_file($meta_file)) {
                continue;
            }

            $json_data = json_decode(file_get_contents($json_file), true);

            if (
                !is_array($json_data)
                || !isset($json_data['entry'])
                || !isset($json_data['name'])
                || $json_data['name'] !== $path['name']
            ) {
                continue;
            }

            if (
                isset($json_data['config'])
                && '' !== $json_data['config']
                && !is_file($this->config->config_dir . DIRECTORY_SEPARATOR . $json_data['config'])
            ) {
                continue;
            }

            $namespace = '\\' . strtr($dirname, DIRECTORY_SEPARATOR, '\\') . '\\' . $json_data['name'];

            try {
                $metadata = ($namespace . '\\skills')::META;

                if (!empty($tool_names)) {
                    $metadata = array_filter(
                        $metadata,
                        function (array $item) use ($tool_names): bool
                        {
                            return in_array($item['function']['name'], $tool_names);
                        }
                    );
                }

                foreach ($metadata as $index => $item) {
                    $metadata[$index]['function']['name'] = $json_data['name'] . '-' . $item['function']['name'];
                }

                $skills[] = [
                    'name'  => $json_data['name'],
                    'meta'  => array_values($metadata),
                    'class' => $namespace . '\\' . strstr($json_data['entry'], '.', true)
                ];
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, false, false);
                unset($throwable);
            }
        }

        unset($dirname, $module, $tool_names, $dir_list, $item, $path, $json_file, $meta_file, $json_data, $namespace, $metadata, $index);
        return $skills;
    }

    /**
     * @param string $dirname
     *
     * @return string
     */
    public function fetchSkills(string $dirname): string
    {
        $skills   = [];
        $dir_list = $this->libFileIO->getDirContents($this->app->root_path . DIRECTORY_SEPARATOR . $dirname);

        foreach ($dir_list as $item) {
            if ($item['is_file']) {
                continue;
            }

            $md_file = $item['absolute_path'] . DIRECTORY_SEPARATOR . 'SKILL.md';

            if (!is_file($md_file)) {
                continue;
            }

            $md_meta = $this->getSkillMeta($md_file);

            if (empty($md_meta['skill_name']) || $md_meta['skill_name'] !== $item['name']) {
                continue;
            }

            $md_meta['resource'] = [];

            foreach (['requirements.txt', 'package.json', 'scripts/', 'references/', 'examples/', 'assets/'] as $value) {
                $full_path = $item['absolute_path'] . DIRECTORY_SEPARATOR . $value;

                if (is_dir($full_path) || is_file($full_path)) {
                    $md_meta['resource'][] = $value;
                }
            }

            $skills[] = $md_meta;
        }

        $prompt = $this->getSkillPrompt($skills);

        unset($dirname, $skills, $dir_list, $item, $md_file, $md_meta, $value, $full_path);
        return $prompt;
    }

    /**
     * @param string $md_path
     *
     * @return array
     */
    public function getSkillMeta(string $md_path): array
    {
        $content = file_get_contents($md_path);
        if (false === $content) {
            return [];
        }

        $parts = explode('---', $content, 3);
        if (3 > count($parts)) {
            return [];
        }

        $md_data  = [];
        $curr_key = '';

        $lines = explode("\n", $parts[1]);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (' ' !== $line[0]) {
                [$curr_key, $value] = explode(':', $line, 2);

                $value = trim($value);

                if ('' !== $value && '|' !== $value) {
                    $md_data[$curr_key][] = $value;
                }
            } elseif ('triggers' === $curr_key) {
                $md_data[$curr_key][] = trim($line, " \n\r\t\v\0-");
            } else {
                $md_data[$curr_key][] = $trimmed;
            }
        }

        $skill_name  = trim($md_data['name'][0] ?? '');
        $description = trim(implode("\n", $md_data['description'] ?? []));
        $triggers    = $md_data['triggers'] ?? [];

        if ('' === $skill_name || '' === $description) {
            return [];
        }

        $metadata = [
            'skill_name'  => $skill_name,
            'description' => $description,
            'triggers'    => $triggers,
        ];

        unset($md_path, $content, $parts, $md_data, $curr_key, $value, $skill_name, $description, $triggers);
        return $metadata;
    }

    /**
     * @param array $skills
     *
     * @return string
     */
    public function getSkillPrompt(array $skills): string
    {
        if (empty($skills)) {
            return '';
        }

        $list = '';

        foreach ($skills as $key => $meta) {
            $num      = $key + 1;
            $name     = $meta['skill_name'];
            $desc     = $meta['description'];
            $triggers = !empty($meta['triggers']) ? implode('、', $meta['triggers']) : '';

            $list .= $num . '. **' . $name . '**：' . $desc;

            if (!empty($triggers)) {
                $list .= ' [场景：' . $triggers . ']';
            }

            if (!empty($meta['resource'])) {
                $list .= ' [依赖及资源：' . implode('、', $meta['resource']) . ']';
            }

            $list .= PHP_EOL;
        }

        unset($skills, $key, $meta, $num, $name, $desc, $triggers);

        return $list . PHP_EOL . PHP_EOL
            . '**执行规范：**' . PHP_EOL
            . '- 功能重叠时优先使用技能，禁用通用工具。' . PHP_EOL
            . '- 加载后严格遵循技能指令，禁止自行编码或用其他方式替代。' . PHP_EOL
            . '- 匹配场景时先调用 System-loadSkill("技能名")。' . PHP_EOL
            . '- 如有依赖文件，先进入技能目录安装依赖，再严格按指令执行，禁猜测。' . PHP_EOL
            . '- 资源目录：references/examples 按需读取，scripts 按要求执行，assets 为静态模板。';
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getMainPrompt(): array
    {
        $prompts   = [];
        $php_path  = $this->OSMgr->getPhpPath();
        $work_path = $this->agent_config['workspace_path'];
        $max_limit = $this->agent_config['max_ctx_len'] * 2;

        $prompts[] = '## 身份与时间';
        $prompts[] = '你是 **' . AGENT_NAME . '**，人类助理。';
        $prompts[] = '当前时间：' . date('Y-m-d H:i:s') . '；时区：' . $this->app->timezone . '。';
        $prompts[] = '默认使用中文；用户指定其他语言时遵从。';

        $prompts[] = '## 运行环境';
        $prompts[] = '- 系统：' . php_uname();
        $prompts[] = '- 架构：' . AGENT_NAME . ' v' . AGENT_VERSION . '（' . NS_NAMESPACE . '/' . NS_VER . '）';
        $prompts[] = '- 工作区：`' . $work_path . '`；根目录：`' . $this->app->root_path . '`';
        $prompts[] = '- 框架目录：`' . NS_ROOT . '`；技能目录：`' . $this->app->root_path . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . '`';
        $prompts[] = '- 日志目录：`' . $this->app->log_path . '`；入口脚本：`' . $this->app->script_path . '`';
        $prompts[] = '- PHP：' . PHP_VERSION . '（`' . $php_path . '`）';

        $prompts[] = '## 工作原则';
        $prompts[] = '- **确认理解**：先理解需求；信息不足/内容歧义/高风险/影响不明无法继续时先确认。按专业度调整深度；情绪强烈先简短回应。';
        $prompts[] = '- **如实直接**：确定直说，不确定说明边界，不编造；只答所问不扩展，避免套话、空话和重复。';
        $prompts[] = '- **规划验证**：复杂/重要任务先评估方案与风险。匹配专项技能必须调`System-loadSkill`加载，并严格遵循完整指令，禁止使用通用工具或自拟流程替代；无匹配技能则用适当工具。';
        $prompts[] = '- **持续推进**：遇阻/失败/需确认时说明原因、已完成与下一步；可处理则继续，需确认则等待，不允许中途静默中止。';

        $prompts[] = '## 工具';
        $prompts[] = '- **网页操作**：动态网页或需交互时用Browser；静态网页、API或纯数据时用HttpFetcher；不确定时用Browser。';
        $prompts[] = '- **失败处理**：工具失败可修正后重试，最多2次；仍失败如实说明。';
        $prompts[] = '- **执行**：`exec`前校验参数，禁止将未经处理的用户输入拼入命令；目标达成即停止，避免重复调用。';

        $prompts[] = '## 外部程序（exec调用）';
        $programs  = $this->fetchPrograms();
        if (!empty($programs)) {
            $prompts[] = '- 可直接调用（已存在于 PATH）：' . implode('、', $programs);
        }
        $prompts[] = '- 调用其他外部程序前，先用`where`（Windows）或`which`（Unix）探测路径，确认存在后再调用。';

        $prompts[] = '## 上下文与记忆';
        $prompts[] = '- **上下文**：历史超过' . $max_limit . '条时，先保存有价值信息再清理；单次回复最多' . $this->agent_config['agent_llm']['params']['max_completion_tokens'] . ' token，超长内容分段交付。';
        $prompts[] = '- **保存范围**：仅保存可复用的稳定事实、用户偏好、明确决策、学习所得、长期规划和关键进展。临时闲聊、一次性问答、工具过程及浅显内容不保存；内容简短、事实化，避免照抄对话。';

        $prompts[] = '- **层级（level）**：';
        $prompts[] = '  - `system`：仅系统配置/人设/身份/规则/权限/边界/约束，禁止写入用户请求/偏好/助手推断。';
        $prompts[] = '  - `important`：关键事实/用户偏好/学习内容/重要结果/长期规划/经确认知识。';
        $prompts[] = '  - `daily`：短期有价值的决策/结论/进展/待办/对话/工具结果。';
        $prompts[] = '  - `misc`：系统自动记录，禁手写。';

        $prompts[] = '- **来源（role）**：';
        $prompts[] = '  - `user`：用户明确陈述/要求/确认/提供的事实。';
        $prompts[] = '  - `assistant`：助手推导/建议/结论/进展。';
        $prompts[] = '  - `system`：系统配置/规则/环境/人设。';
        $prompts[] = '  - `tool`：工具直接结果，未经助手加工。';
        $prompts[] = '  用户事实即使由助手归纳，role 仍为`user`；内容属系统配置/规则时用`system`。';

        $prompts[] = '- **写入**：关键节点主动保存；事实冲突、权限或归属不明先确认；已有记忆优先更新，避免重复新增。';
        $prompts[] = '- **读取**：新会话先读最近10条`misc`；不足时逐批追加5条（禁全量）；`misc`空则读`daily`。';
        $prompts[] = '- **搜索**：提及已知偏好/人物/项目/决定或要求回顾时，搜索`important`或`daily`；无结果告知。';

        $skills = $this->fetchSkills('skills');
        if ('' !== $skills) {
            $prompts[] = '## 专项技能';
            $prompts[] = $skills;
        }

        $prompts[] = '## 安全';
        if ($this->agent_config['sandbox_mode']) {
            $prompts[] = '- **沙箱开启**：文件操作仅限工作区`' . $work_path . '`，禁止`../`或符号链接逃逸。';
        } else {
            $prompts[] = '- **沙箱关闭**：优先使用绝对路径和工作区；禁止借`../`或符号链接访问系统关键目录。';
        }
        $prompts[] = '- **高风险操作**：删除、覆盖文件、批量文件修改、执行高影响命令、修改系统配置或安装/卸载软件前，须说明风险并取得确认；批量文件操作每批不超过100项，先列清单确认。';
        $prompts[] = '- **绝对禁止**：执行破坏性系统命令；泄露敏感信息。';

        $prompt = [
            'role'    => 'system',
            'content' => implode("\n", $prompts)
        ];

        unset($prompts, $php_path, $work_path, $max_limit, $skills);
        return $prompt;
    }

    /**
     * @param string $worker_name
     * @param string $worker_role
     *
     * @return array
     * @throws \Exception
     */
    public function getChildPrompt(string $worker_name, string $worker_role): array
    {
        $prompts = [];

        $prompts[] = '## 身份与时间';
        $prompts[] = '你是 **' . $worker_name . '**，' . $worker_role . '。';
        $prompts[] = '当前时间：' . date('Y-m-d H:i:s') . '；时区：' . $this->app->timezone . '。';
        $prompts[] = '默认使用中文；用户指定其他语言时遵从。';

        $prompts[] = '## 运行环境';
        $prompts[] = '- 系统：' . php_uname();
        $prompts[] = '- 工作区：`' . $this->agent_config['workspace_path'] . '`；根目录：`' . $this->app->root_path . '`';
        $prompts[] = '- 技能目录：`' . $this->app->root_path . '/skills/`';

        $prompts[] = '## 工作原则';
        $prompts[] = '- **确认理解**：先理解需求；信息不足/内容歧义/高风险/影响不明无法继续时先确认。按专业度调整深度；情绪强烈先简短回应。';
        $prompts[] = '- **如实直接**：确定直说，不确定说明边界，不编造；只答所问不扩展，避免套话、空话和重复。';
        $prompts[] = '- **规划验证**：复杂/重要任务先评估方案与风险。匹配专项技能必须调`System-loadSkill`加载，并严格遵循完整指令，禁止使用通用工具或自拟流程替代；无匹配技能则用适当工具。';
        $prompts[] = '- **持续推进**：遇阻/失败/需确认时说明原因、已完成与下一步；可处理则继续，需确认则等待，不允许中途静默中止。';

        $prompts[] = '## 工具';
        $prompts[] = '- **网页操作**：动态网页或需交互时用Browser；静态网页、API或纯数据时用HttpFetcher；不确定时用Browser。两者不交替。';
        $prompts[] = '- **失败处理**：工具失败可修正后重试，最多2次；仍失败如实说明。';
        $prompts[] = '- **执行**：目标达成即停止，避免重复调用。';

        $skills = $this->fetchSkills('skills');
        if ('' !== $skills) {
            $prompts[] = '## 专项技能';
            $prompts[] = $skills;
            $prompts[] = '- 技能涉及外部程序时，告知用户无法执行。';
        }

        $prompts[] = '## 安全';
        if ($this->agent_config['sandbox_mode']) {
            $prompts[] = '- **沙箱开启**：文件操作仅限工作区`' . $this->agent_config['workspace_path'] . '`，禁止`../`或符号链接逃逸。';
        } else {
            $prompts[] = '- **沙箱关闭**：优先使用绝对路径和工作区；禁止借`../`或符号链接访问系统关键目录。';
        }
        $prompts[] = '- **高风险操作**：删除、覆盖文件、批量文件修改、修改系统配置或安装/卸载软件前，须说明风险并取得确认；批量文件操作每批不超过100项，先列清单确认。';
        $prompts[] = '- **绝对禁止**：泄露敏感信息。';

        $prompt = [
            'role'    => 'system',
            'content' => implode("\n", $prompts)
        ];

        unset($worker_name, $worker_role, $prompts, $skills);
        return $prompt;
    }

    /**
     * @param string $binary
     *
     * @return string
     */
    public function getImageType(string $binary): string
    {
        $result = '';
        $header = bin2hex(substr($binary, 0, 12));

        $magics = [
            '424d'             => 'bmp',
            'ffd8ff'           => 'jpeg',
            '89504e470d0a1a0a' => 'png',
            '47494638'         => 'gif',
            '52494646'         => 'webp'
        ];

        foreach ($magics as $magic => $type) {
            if (str_starts_with($header, $magic)) {
                $result = $type;
                break;
            }
        }

        unset($binary, $header, $magics, $magic, $type);
        return $result;
    }

    /**
     * @param string $string
     * @param string $level Debug level (none, trace, debug)
     *
     * @return void
     */
    public function debug(string $string, string $level = 'trace'): void
    {
        $debug_level = strtolower($this->agent_config['agent_debug']);

        if ('none' === $debug_level) {
            return;
        }

        if ('trace' === $debug_level && 'debug' === strtolower($level)) {
            return;
        }

        echo '[' . date('Y-m-d H:i:s') . '][' . strtoupper($level) . '] ' . $string . PHP_EOL;
        unset($string, $level, $debug_level);
    }
}