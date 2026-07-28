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
     * Prune session history for a worker.
     *
     * @param string $worker_name
     * @param int    $keep_normal               Min normal units (user + assistant w/o tool_calls)
     * @param int    $keep_tool_pairs           Min tool pairs (assistant with tool_calls + its tools)
     * @param bool   $aggressive_mode           Allow zero limits
     * @param string $remove_tool_call_id       Specify clean context tool_call_id, will be removed
     * @param int    $remove_tool_call_id_limit Specify how many tool_call_ids will be removed, tool_calls and tool_result
     *
     * @return array{removed_normal:int, removed_tools:int, current_count:int}
     */
    public function cleanSessionHistory(
        string $worker_name,
        int    $keep_normal = 6,
        int    $keep_tool_pairs = 2,
        bool   $aggressive_mode = false,
        string $remove_tool_call_id = '',
        int    $remove_tool_call_id_limit = 2
    ): array
    {
        $history = $this->session_history[$worker_name];

        // 1. enforce bounds
        if (!$aggressive_mode) {
            $keep_tool_pairs = max(2, $keep_tool_pairs);
            $keep_normal     = max(6, $keep_normal);
        } else {
            $keep_tool_pairs = max(0, $keep_tool_pairs);
            $keep_normal     = max(0, $keep_normal);
        }

        // 2. Remove specified tool call pairs
        if ('' !== $remove_tool_call_id) {
            $removed  = 0;
            $last_key = count($history) - 1;

            for ($i = $last_key; $i >= 0; $i--) {
                if ($removed >= $remove_tool_call_id_limit) {
                    break;
                }

                if (isset($history[$i]['tool_call_id']) && $history[$i]['tool_call_id'] === $remove_tool_call_id) {
                    unset($history[$i]);
                    ++$removed;
                    continue;
                }

                if (isset($history[$i]['tool_calls'])) {
                    $tool_calls = $history[$i]['tool_calls'];

                    foreach ($tool_calls as $key => $values) {
                        if ($remove_tool_call_id !== $values['id']) {
                            continue;
                        }

                        unset($tool_calls[$key]);

                        if (empty($tool_calls)) {
                            unset($history[$i]);
                        } else {
                            $history[$i]['tool_calls'] = array_values($tool_calls);
                        }

                        ++$removed;
                        break;
                    }

                    unset($tool_calls, $key, $values);
                }
            }

            unset($removed, $last_key);
        }

        // 3. extract system message (first one)
        $system = null;
        foreach ($history as $idx => $msg) {
            if ('system' === $msg['role']) {
                $system = $msg;
                unset($history[$idx]);
                break;
            }
        }

        $history = array_values($history);
        $total   = count($history);

        // 4. split into units (normal / tool) and count original totals
        $units              = [];
        $total_normal_units = 0;
        $total_tool_units   = 0;
        $i                  = 0;

        while ($i < $total) {
            $msg = $history[$i];
            if ('assistant' === $msg['role'] && !empty($msg['tool_calls'])) {
                $indices = [$i];
                $j       = $i + 1;

                while ($j < $total && 'tool' === $history[$j]['role']) {
                    $indices[] = $j;
                    $j++;
                }

                $units[] = ['type' => 'tool', 'indices' => $indices];
                $total_tool_units++;
                $i = $j;
            } else {
                $units[] = ['type' => 'normal', 'indices' => [$i], 'role' => $msg['role']];
                $total_normal_units++;
                $i++;
            }
        }

        // 5. handle keep_normal == 0 : only system may survive
        if (0 === $keep_normal || empty($history)) {
            $this->session_history[$worker_name] = !is_null($system) ? [$system] : [];

            return [
                'removed_normal' => $total_normal_units,
                'removed_tools'  => $total_tool_units,
                'current_count'  => !is_null($system) ? 1 : 0
            ];
        }

        // 6. select units from end to front
        $selected_normal = [];
        $selected_tools  = [];

        $need_normal = $keep_normal;
        $need_tools  = $keep_tool_pairs;
        $unit_count  = count($units);

        for ($idx = $unit_count - 1; $idx >= 0; $idx--) {
            $unit = $units[$idx];
            if ('normal' === $unit['type']) {
                if ($need_normal > 0) {
                    array_unshift($selected_normal, $unit);
                    $need_normal--;
                }
            } else {
                if ($need_tools > 0) {
                    array_unshift($selected_tools, $unit);
                    $need_tools--;
                }
            }
            if ($need_normal <= 0 && $need_tools <= 0) {
                break;
            }
        }

        // 7. ensure first normal unit is 'user'
        if (!empty($selected_normal) && 'user' !== $selected_normal[0]['role']) {
            $first_idx = $selected_normal[0]['indices'][0];
            for ($i = $first_idx - 1; $i >= 0; $i--) {
                if ('user' === $history[$i]['role']) {
                    $user_unit = ['type' => 'normal', 'indices' => [$i], 'role' => 'user'];
                    array_unshift($selected_normal, $user_unit);
                    break;
                }
            }
        }

        // 8. ensure each tool pair has a preceding user (add missing users)
        $extra_user_indices = [];
        foreach ($selected_tools as $tool_unit) {
            $tool_start = $tool_unit['indices'][0];
            for ($i = $tool_start - 1; $i >= 0; $i--) {
                if ('user' === $history[$i]['role']) {
                    $extra_user_indices[$i] = true;
                    break;
                }
            }
        }

        $normal_idx_set = [];
        foreach ($selected_normal as $nu) {
            $normal_idx_set[$nu['indices'][0]] = true;
        }

        foreach ($extra_user_indices as $uidx => $_) {
            if (!isset($normal_idx_set[$uidx])) {
                $selected_normal[] = ['type' => 'normal', 'indices' => [$uidx], 'role' => 'user'];
            }
        }

        // 9. merge and sort units by original order
        $all_units = array_merge($selected_normal, $selected_tools);

        usort(
            $all_units,
            function ($a, $b)
            {
                return $a['indices'][0] <=> $b['indices'][0];
            }
        );

        // 10. count kept units and build new history
        $kept_normal_units = 0;
        $kept_tool_units   = 0;
        $keep              = [];

        foreach ($all_units as $unit) {
            foreach ($unit['indices'] as $idx) {
                $keep[$idx] = true;
            }
            if ('normal' === $unit['type']) {
                $kept_normal_units++;
            } else {
                $kept_tool_units++;
            }
        }

        $new_history = [];

        for ($i = 0; $i < $total; $i++) {
            if (isset($keep[$i])) {
                $new_history[] = $history[$i];
            }
        }

        // 11. prepend system if exists
        if (null !== $system) {
            array_unshift($new_history, $system);
        }

        $this->session_history[$worker_name] = $new_history;

        // 12. compute removed counts (non‑negative)
        $removed_normal = max(0, $total_normal_units - $kept_normal_units);
        $removed_tools  = max(0, $total_tool_units - $kept_tool_units);

        unset($worker_name, $keep_normal, $keep_tool_pairs, $aggressive_mode, $remove_tool_call_id, $remove_tool_call_id_limit, $history, $system, $total, $units, $i, $msg, $indices, $j, $selected_normal, $selected_tools, $need_normal, $need_tools, $unit_count, $idx, $unit, $first_idx, $user_unit, $extra_user_indices, $normal_idx_set, $uidx, $tool_unit, $tool_start, $all_units, $keep, $total_normal_units, $total_tool_units, $kept_normal_units, $kept_tool_units);

        return [
            'removed_normal' => $removed_normal,
            'removed_tools'  => $removed_tools,
            'current_count'  => count($new_history)
        ];
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
     * @return string
     */
    public function fetchPrograms(): string
    {
        $available = [];
        $name_list = ['7z', 'git', 'curl', 'php', 'python', 'pip', 'uv', 'node', 'npm', 'npx', 'ffmpeg'];

        foreach ($name_list as $name) {
            $paths = $this->OSMgr->findPath($name);

            if (!empty($paths)) {
                $available[] = $name;
            }
        }

        $prompt = !empty($available)
            ? '- 可直接调用（已存在于 PATH）：' . implode('、', $available) . PHP_EOL
            . '- 未列出的程序，执行前先用 `where`（Windows）或 `which`（Unix）探测路径。'
            : '- 执行外部程序前，先用 `where`（Windows）或 `which`（Unix）探测路径，确认存在后再调用。';

        unset($available, $name_list, $name, $paths);
        return $prompt;
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

        return $list
            . PHP_EOL . PHP_EOL
            . '**执行规范：**' . PHP_EOL
            . '- 专项技能与可用工具功能重叠时，必须使用专项技能，禁止使用可用工具。' . PHP_EOL
            . '- 用户请求匹配某技能使用场景时，先调用 System-loadSkill("技能名") 加载技能。' . PHP_EOL
            . '- 技能加载后，如有依赖文件，必须先进入技能目录安装依赖，再按照完整指令严格执行，严禁猜测。' . PHP_EOL
            . '- 如有资源目录：references/examples 按需读取，scripts 按要求执行，assets 为静态模板。';
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getMainPrompt(): array
    {
        $prompts   = [];
        $php_path  = $this->OSMgr->getPhpPath();
        $lang_code = substr(setlocale(LC_ALL, 0), 0, 2);
        $lang_name = 'zh' === $lang_code ? '中文' : '英文';
        $work_path = $this->agent_config['workspace_path'];
        $max_limit = $this->agent_config['max_ctx_len'] * 3;

        $prompts[] = '## 我的身份';
        $prompts[] = '- 名称：' . AGENT_NAME;
        $prompts[] = '- 角色：人类助理';

        $prompts[] = '## 时间';
        $prompts[] = date('Y-m-d H:i:s') . ' | 时区：' . $this->app->timezone;

        $prompts[] = '## 准则';
        $prompts[] = '**身份**';
        $prompts[] = '- 你是人类助理。';
        $prompts[] = '**适应用户与情绪**';
        $prompts[] = '- 先感知用户状态：对大众简化，对专家深入。若用户情绪不稳定，先简短回应情绪，再解决问题。';
        $prompts[] = '**问题定义**';
        $prompts[] = '- 换视角，剥离假设，找真实需求。回到最基本事实重新推导，不被既有认知束缚。';
        $prompts[] = '**逻辑与诚实**';
        $prompts[] = '- 逻辑清晰，谦逊，说真话，承认可能的错误。确定的直接说，不确定的明说。不装懂，不模糊。';
        $prompts[] = '**多方案探索**';
        $prompts[] = '- 对复杂问题至少三方案择优，不通则换方案。预判反驳，挑战假设。不满足浅显答案，追求更优解。';
        $prompts[] = '**严谨验证**';
        $prompts[] = '- 对重要任务（代码、科学推理、关键决策）交叉验证，推演边界，检验假设。不急于定论，先审视中间步骤和潜在风险。';
        $prompts[] = '**好奇心与二阶效应**';
        $prompts[] = '- 保持好奇，发现隐性模式，关联看似无关的信息，思考二阶效应。';
        $prompts[] = '**创造优先**';
        $prompts[] = '- 创造优于检索，生成最佳版本。';
        $prompts[] = '**驾驭混乱**';
        $prompts[] = '- 从容应对混乱请求。结构只是工具，不应依赖。持续行动，不陷入过度思考。该快则快，该慢则慢，不拖延。';
        $prompts[] = '**规则元认知**';
        $prompts[] = '- 规则服务于结果，聚焦任务目的。优先在规则内解决，确认无效再打破，但要清楚原因。';
        $prompts[] = '**去AI化**';
        $prompts[] = '- 输出内容可带主观情绪。少用绝对词和套路连接词，少用序号；口语化衔接，段落长短参差。开篇直入，用具体名词和动作替代空洞形容词、陈词滥调和四字成语。';
        $prompts[] = '- 只答所问，不发散。观点明确不模棱两可。不刻意拉近距离，不主动给解决方案。';
        $prompts[] = '**节奏与互动**';
        $prompts[] = '- 长短句交错，关键处极短句；偶尔用反问句制造思考空间；打比方解释复杂概念。避免连续排比，删多余语气词。';
        $prompts[] = '**输出规范**';
        $prompts[] = '- **正确有用**：输出前反复校验，不输出错误。追求真实有用，交付前确认有帮助，无用则弃。';
        $prompts[] = '- **简洁清晰**：删废话，用最少字数表达意思，每词每句有分量。表达自然，用日常词，避术语（除非用户要求），直接不绕弯，多举例少抽象。';
        $prompts[] = '- **输出前检查**：核实能否更短、是否自然、是否快速切入主题，同时核实是否遵守以上准则，违反则修正（**不向用户显示**）。';
        $prompts[] = '- **交付内容与格式**：直接输出最终结果，无开场白、总结或客套话。';

        $prompts[] = '## 系统信息';
        $prompts[] = '`架构：' . AGENT_NAME . ' v' . AGENT_VERSION . '(' . NS_NAMESPACE . '/' . NS_VER . ')` | `OS：' . php_uname() . '` | `PHP：' . PHP_VERSION . '(' . $php_path . ')`';
        $prompts[] = '`根目录：' . $this->app->root_path . '` | `框架：' . NS_ROOT . '` | `工作区：' . $work_path . '` | `日志：' . $this->app->log_path . '` | `模块：' . $this->app->root_path . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . '` | `技能：' . $this->app->root_path . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . '` | `入口：' . $this->app->script_path . '`';

        $prompts[] = '## 外部程序（exec调用）';
        $prompts[] = $this->fetchPrograms();

        $prompts[] = '## 上下文';
        $prompts[] = '- 主动管理，历史>' . $max_limit . '条自动裁剪；单次输出上限：' . $this->agent_config['agent_llm']['params']['max_completion_tokens'] . ' token（超长需分段）。';
        $prompts[] = '**【必须】**';
        $prompts[] = '1.强制备份：历史过长或连续工具过多→**先按记忆规则存储关键内容，再调清理工具**。';
        $prompts[] = '2.清理规范：仅删旧工具对/消息，**清理前须手动保存**，根据重要性保留必要上下文。';

        $prompts[] = '## 记忆';
        $prompts[] = '- 总则：关键信息（事实、偏好、决策等）主动写入 daily/important/system，宁多勿漏。';
        $prompts[] = '- system：人设/身份/规则/边界，按需调整，无需询问。';
        $prompts[] = '- important：关键事实/用户偏好/学习内容，冲突需用户确认。';
        $prompts[] = '- daily：重要对话、决策、结论等长期价值内容，按日期存储。';
        $prompts[] = '- misc：系统自动记录所有过程，勿手动写。跨会话连续性依赖此层。';
        $prompts[] = '**写入**：提炼重要信息写入daily/important/system，无需写misc。';
        $prompts[] = '**读取**：新会话必须先加载misc记忆（20-50条），再回复；空则读daily；按日期查daily，按主题查important。';
        $prompts[] = '**搜索**：明确提及记录/人名/事件时主动搜索，结果仅当前回复。无则告知。';

        $prompts[] = '## 可用工具（Tool Calls）';
        $prompts[] = '- **强制优先**：无对应技能时，优先使用工具，禁止用`exec`替代。';
        $prompts[] = '- **网页任务**：交互/动态内容→Browser工具，纯数据/API→HttpFetcher工具，不确定时默认用Browser。两者不交替。';
        $prompts[] = '- **错误处理**：工具发生错误时，修正重试最多2次，再次失败则向用户转述错误内容。';
        $prompts[] = '- 若需用`exec`运行PHP脚本，PHP路径：`' . $php_path . '`';
        $prompts[] = '- 任务完成即止，勿重复调用工具。';

        $skills = $this->fetchSkills('skills');
        if ('' !== $skills) {
            $prompts[] = '## 专项技能';
            $prompts[] = $skills;
        }

        $prompts[] = '## 系统安全';
        if ($this->agent_config['sandbox_mode']) {
            $prompts[] = '- **沙箱开启**：所有文件以 `' . $work_path . '` 为根目录，强制路径映射，**禁止 ../ 或符号链接跳出**。';
        } else {
            $prompts[] = '- **沙箱关闭**：按绝对路径，优先工作区，**禁止 ../ 绕开系统关键目录**（如 `C:\Windows\System32`）。';
        }

        $prompts[] = '- **exec 调用安全**：调用 `exec` 前必须验证输入参数，禁止拼接未过滤的用户输入，防止命令注入。';
        $prompts[] = '- **危险操作**（删/执行命令）：先告知风险，等确认。';
        $prompts[] = '- **绝对禁止**：`rm -rf /, dd, shutdown`；改系统配置（/etc/, C:\\Windows\\System32\）；改系统核心脚本（`' . $this->app->root_path . '/modules/*`）；装/卸软件；泄露敏感信息。';
        $prompts[] = '- **批量/多文件**：每批 ≤100个，操作前列清单确认。';

        $prompts[] = '## 任务执行方式';
        $prompts[] = '- 禁止中途停止、静默，必须连续至完成。';
        $prompts[] = '- 每步仅输出{简述}+调用工具，禁其他及结束词（如“已完成”）。';
        $prompts[] = '- 长输出（>4K字符）禁直接回复，须分段保存或分次输出。工具失败重试≤2次，仍失败报用户。';
        $prompts[] = '- 完成标志：全达成后输出“所有任务执行完毕，结果如下：”并附成果。';

        $prompts[] = '## 输出';
        $prompts[] = '- **语言**：中文（用户指定除外）。';
        $prompts[] = '- **错误**：解释原因+建议。';

        $prompt = [
            'role'    => 'system',
            'content' => implode("\n", $prompts)
        ];

        unset($prompts, $php_path, $lang_code, $lang_name, $work_path, $max_limit);
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

        $prompts[] = '## 我的身份';
        $prompts[] = '- 名称：' . $worker_name;
        $prompts[] = '- 角色：' . $worker_role;

        $prompts[] = '## 时间';
        $prompts[] = date('Y-m-d H:i:s') . ' | 时区：' . $this->app->timezone;
        $prompts[] = '';

        $prompts[] = '## 系统信息';
        $prompts[] = '`OS：' . php_uname() . '` | `PHP：' . PHP_VERSION . '(' . $this->OSMgr->getPhpPath() . ')`';
        $prompts[] = '`根目录：' . $this->app->root_path . '` | `框架：' . NS_ROOT . '` | `工作区：' . $this->agent_config['workspace_path'] . '` | `日志：' . $this->app->log_path . '` | `模块：' . $this->app->root_path . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . '` | `技能：' . $this->app->root_path . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . '`';

        $prompts[] = '## 外部程序（exec调用）';
        $prompts[] = $this->fetchPrograms();

        $prompts[] = '## 可用工具（Tool Calls）';
        $prompts[] = '- **强制优先**：无对应技能时，优先使用工具，禁止用`exec`替代。';
        $prompts[] = '- **网页任务**：交互/动态内容→Browser工具，纯数据/API→HttpFetcher工具，不确定时默认用Browser。两者不交替。';
        $prompts[] = '- **错误处理**：工具发生错误时，修正重试最多2次，再次失败则向用户转述错误内容。';
        $prompts[] = '- 任务完成即止，勿重复调用工具。';

        $skills = $this->fetchSkills('skills');
        if ('' !== $skills) {
            $prompts[] = '## 专项技能';
            $prompts[] = $skills;
        }

        $prompts[] = '## 安全';
        if ($this->agent_config['sandbox_mode']) {
            $prompts[] = '- **沙箱开启**：所有文件以 `' . $this->agent_config['workspace_path'] . '` 为根目录，强制路径映射，**禁止 ../ 或符号链接跳出**。';
        } else {
            $prompts[] = '- **沙箱关闭**：按绝对路径，优先工作区，**禁止 ../ 绕开系统关键目录**（如 `C:\Windows\System32`）。';
        }

        $prompts[] = '- **危险操作**（删/执行命令）：先告知风险，等确认。';
        $prompts[] = '- **绝对禁止**：`rm -rf /, dd, shutdown`；改系统配置（/etc/, C:\\Windows\\System32\）；改系统核心脚本（`' . $this->app->root_path . '/modules/*`）；装/卸软件；泄露敏感信息。';
        $prompts[] = '- **批量/多文件**：每批 ≤100个，操作前列清单确认。';

        $prompt = [
            'role'    => 'system',
            'content' => implode("\n", $prompts)
        ];

        unset($worker_name, $worker_role, $prompts);
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