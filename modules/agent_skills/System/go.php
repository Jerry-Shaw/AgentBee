<?php

/**
 * Agent tools module for AgentBee core
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

namespace modules\agent_skills\System;

use modules\agent_core\core;
use Nervsys\Core\Factory;
use Nervsys\Ext\libFileIO;

class go extends Factory
{
    public core $core;

    public libFileIO $libFileIO;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core      = core::new();
        $this->libFileIO = libFileIO::new();

        $this->core->initCore();
    }

    /**
     * @param string       $program
     * @param array|string $argv
     * @param int          $timeout
     * @param string       $work_path
     *
     * @return array|string[]
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function exec(string $program, array|string $argv = [], int $timeout = 30, string $work_path = ''): array
    {
        $result = [
            'output' => '',
            'error'  => ''
        ];

        $work_path = '' === $work_path
            ? $this->core->agent_config['workspace_path']
            : $this->core->securePath($work_path);

        if (empty($argv) && str_contains($program, ' ')) {
            [$program, $args] = explode(' ', $program, 2);
            $argv = explode(' ', $args);
        }

        if (is_string($argv)) {
            $parsed = json_decode($argv, true);

            if (is_array($parsed)) {
                $argv = $parsed;
            } else {
                $argv = str_contains($argv, ' ') ? explode(' ', $argv) : [$argv];
            }

            unset($parsed);
        }

        $active  = time();
        $procMgr = $this->core->procMgr;

        $proc_idx = $procMgr
            ->command([$program, ...$argv])
            ->setWorkDir($work_path)
            ->run();

        $proc_pid = $procMgr->getPid($proc_idx);

        $procMgr->awaitProc(
            $proc_idx,
            function (string $output) use (&$active, &$result): void
            {
                $active           = time();
                $result['output'] .= $output;
                unset($output);
            },
            function (string $output) use (&$active, &$result): void
            {
                $active          = time();
                $result['error'] .= $output;
                unset($output);
            },
            function () use ($active, $timeout, $proc_pid, $procMgr, &$result): void
            {
                if (0 === $timeout) {
                    return;
                }

                if (time() - $active > $timeout) {
                    $this->core->OSMgr->killProc($proc_pid);
                    $result['error'] .= 'Process has been killed due to timeout reached.';
                }
            }
        );

        $procMgr->exit();

        unset($program, $argv, $timeout, $work_path, $active, $procMgr, $proc_pid);
        return $result;
    }

    /**
     * @return array
     */
    public function getTime(): array
    {
        $timestamp = time();
        $datetime  = date('Y-m-d H:i:s', $timestamp);

        return ['datetime' => $datetime, 'timestamp' => $timestamp];
    }

    /**
     * Clean context: remove old tool call pairs and trim dialog messages.
     *
     * Does NOT save any summary; the model must save important content separately before calling.
     * When $clean_force is false (default), the method enforces minimum retention (keep_tool_pairs≥1, max_dialog_messages≥2).
     * When $clean_force is true, it allows complete cleanup (0 values allowed) for a fresh start.
     *
     * @param int  $max_dialog_messages Max number of normal messages (user + assistant without tool_calls) to keep
     * @param int  $keep_tool_pairs     Number of recent tool pairs to keep (0 = delete all, but min 1 if not forced)
     * @param bool $force_clean         If true, allow deletion of all tool pairs and dialog messages (fresh start)
     *
     * @return array Associative array with 'status', 'message', 'remained' keys
     */
    public function cleanContext(int $max_dialog_messages = 10, int $keep_tool_pairs = 2, bool $force_clean = false): array
    {
        // Apply safe limits unless force mode is enabled
        if (!$force_clean) {
            $keep_tool_pairs     = max(1, $keep_tool_pairs);
            $max_dialog_messages = max(2, $max_dialog_messages);
        } else {
            $keep_tool_pairs     = max(0, $keep_tool_pairs);
            $max_dialog_messages = max(0, $max_dialog_messages);
        }

        $history = $this->core->getSessionHistory();

        if (empty($history)) {
            return ['status' => 'error', 'message' => 'No session history to clean'];
        }

        $total = count($history);
        $roles = array_column($history, 'role');

        // 1. Locate all assistant indices
        $all_assistant_keys = array_keys($roles, 'assistant', true);

        // 2. Build tool pair ranges (assistant with tool_calls + following tool messages)
        $tool_pair_ranges = [];
        $last_end         = -1;

        foreach ($all_assistant_keys as $idx) {
            if ($idx <= $last_end) {
                continue;
            }

            if (!empty($history[$idx]['tool_calls'])) {
                $start = $idx;
                $end   = $idx;
                $j     = $idx + 1;

                while ($j < $total && 'tool' === ($roles[$j] ?? '')) {
                    $end = $j;
                    ++$j;
                }

                $tool_pair_ranges[] = ['start' => $start, 'end' => $end];
                $last_end           = $end;
            }
        }

        // 3. Collect normal messages: user messages + assistant without tool_calls
        $user_keys = array_keys($roles, 'user', true);

        $normal_assistant_keys = array_filter(
            $all_assistant_keys,
            function (int $idx) use ($history)
            {
                return empty($history[$idx]['tool_calls']);
            }
        );

        $normal_keys = array_merge($user_keys, $normal_assistant_keys);

        sort($normal_keys);

        // 4. Select recent normal messages up to $max_dialog_messages
        $total_normal    = count($normal_keys);
        $take_count      = min($total_normal, $max_dialog_messages);
        $selected_normal = array_slice($normal_keys, $total_normal - $take_count);

        // 5. Ensure at least one user message exists in selection
        $has_user = false;

        foreach ($selected_normal as $idx) {
            if ('user' === ($roles[$idx] ?? '')) {
                $has_user = true;
                break;
            }
        }

        if (!$has_user) {
            $start = $total_normal - $take_count - 1;

            for ($i = $start; $i >= 0; --$i) {
                $idx = $normal_keys[$i];

                if ('user' === ($roles[$idx] ?? '')) {
                    array_unshift($selected_normal, $idx);
                    break;
                }
            }
        }

        // 6. Select recent tool pairs up to $keep_tool_pairs
        $total_pairs    = count($tool_pair_ranges);
        $keep_from      = max(0, $total_pairs - $keep_tool_pairs);
        $selected_pairs = array_slice($tool_pair_ranges, $keep_from);

        // 7. Build indices to keep
        $keep_indices = [];

        foreach ($selected_normal as $idx) {
            $keep_indices[$idx] = true;
        }

        foreach ($selected_pairs as $pair) {
            for ($i = $pair['start']; $i <= $pair['end']; ++$i) {
                $keep_indices[$i] = true;
            }
        }

        // 8. Rebuild history preserving original order
        $new_history = [];

        for ($i = 0; $i < $total; ++$i) {
            if (isset($keep_indices[$i])) {
                $new_history[] = $history[$i];
            }
        }

        // 9. Prepend system message if present (original first system message)
        $system_idx = array_search('system', $roles, true);

        if (false !== $system_idx) {
            array_unshift($new_history, $history[$system_idx]);
        }

        // 10. Ensure first message after system is 'user'
        $new_count = count($new_history);

        if ($new_count > 1 && 'user' !== ($new_history[1]['role'] ?? '')) {
            for ($k = 2; $k < $new_count; ++$k) {
                if ('user' === ($new_history[$k]['role'] ?? '')) {
                    $user_msg = $new_history[$k];

                    unset($new_history[$k]);

                    $new_history = array_values($new_history);
                    array_splice($new_history, 1, 0, [$user_msg]);

                    break;
                }
            }
        }

        $this->core->session_history = $new_history;

        $new_count = count($new_history);
        $removed   = $total - $new_count;

        $result = [
            'status'  => 'success',
            'message' => 'Cleaned ' . $removed . ' messages including ' . ($total_pairs - min($total_pairs, $keep_tool_pairs)) . ' tool pairs. Total messages remained: ' . $new_count . '.'
        ];

        unset($max_dialog_messages, $keep_tool_pairs, $force_clean, $history, $total, $roles, $all_assistant_keys, $tool_pair_ranges, $last_end, $idx, $start, $end, $j, $user_keys, $normal_assistant_keys, $normal_keys, $total_normal, $take_count, $selected_normal, $has_user, $i, $total_pairs, $keep_from, $selected_pairs, $keep_indices, $pair, $new_history, $system_idx, $new_count, $user_msg, $removed);
        return $result;
    }

    /**
     * Read image file and return Data URL.
     *
     * @param string $file_path
     *
     * @return array
     */
    public function readImage(string $file_path): array
    {
        $full_path = $this->core->securePath($file_path);

        if (!is_file($full_path)) {
            return ['status' => 'error', 'error' => 'File not found: ' . $full_path];
        }

        if (!is_readable($full_path)) {
            return ['status' => 'error', 'error' => 'File not readable: ' . $full_path];
        }

        $info = getimagesize($full_path);

        if (false === $info || empty($info['mime'])) {
            return ['status' => 'error', 'error' => 'Cannot identify image type or unsupported format'];
        }

        $mime_type = $info['mime'];
        $allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];

        if (!in_array($mime_type, $allowed, true)) {
            return ['status' => 'error', 'error' => 'Unsupported image type: ' . $mime_type];
        }

        $data = file_get_contents($full_path);

        if (false === $data) {
            return ['status' => 'error', 'error' => 'Failed to read image data: ' . $full_path];
        }

        $base64    = base64_encode($data);
        $image_url = 'data:' . $mime_type . ';base64,' . $base64;

        $result = [
            'status'    => 'success',
            'content'   => $image_url,
            'filename'  => basename($full_path),
            'mime_type' => $mime_type,
            'file_path' => $full_path
        ];

        unset($file_path, $full_path, $info, $mime_type, $allowed, $data, $base64, $image_url);
        return $result;
    }

    /**
     * Read file content.
     *
     * @param string $file_path
     * @param int    $offset
     * @param int    $limit
     *
     * @return array
     */
    public function readFile(string $file_path, int $offset = 0, int $limit = 8192): array
    {
        $full_path = $this->core->securePath($file_path);

        if (!is_file($full_path)) {
            return ['status' => 'error', 'error' => 'File not found: ' . $full_path];
        }

        if (!is_readable($full_path)) {
            return ['status' => 'error', 'error' => 'File not readable: ' . $full_path];
        }

        $file_handle = fopen($full_path, 'rb');

        if (false === $file_handle) {
            return ['status' => 'error', 'error' => 'Failed to open file: ' . $full_path];
        }

        fseek($file_handle, $offset);
        $content = fread($file_handle, (0 === $limit) ? filesize($full_path) : $limit);
        $content = (string)mb_convert_encoding($content, 'UTF-8', 'auto');
        fclose($file_handle);

        $result = [
            'status'    => 'success',
            'content'   => $content,
            'file_path' => $full_path
        ];

        unset($file_path, $offset, $limit, $full_path, $file_handle, $content);
        return $result;
    }

    /**
     * Write content to file.
     *
     * @param string $file_path
     * @param string $content
     * @param bool   $append
     *
     * @return array
     */
    public function writeFile(string $file_path, string $content, bool $append = false): array
    {
        $full_path = $this->core->securePath($file_path);

        $dir_path = dirname($full_path);
        $dir_path = $this->mkPath($dir_path);

        $file_handle = fopen($full_path, $append ? 'ab' : 'wb');

        if (false === $file_handle) {
            return ['status' => 'error', 'error' => 'Failed to open file for writing: ' . $full_path];
        }

        $bytes = fwrite($file_handle, $content);
        fclose($file_handle);

        $result = [
            'status'        => 'success',
            'file_path'     => $full_path,
            'bytes_written' => $bytes ?: 0
        ];

        unset($file_path, $content, $append, $dir_path, $file_handle, $bytes);
        return $result;
    }

    /**
     * Copy a file.
     *
     * @param string $src_file_path
     * @param string $dst_file_path
     *
     * @return array
     */
    public function copyFile(string $src_file_path, string $dst_file_path): array
    {
        $src_full = $this->core->securePath($src_file_path);
        $dst_full = $this->core->securePath($dst_file_path);

        if (!is_file($src_full)) {
            return ['status' => 'error', 'error' => 'File not found: ' . $src_full];
        }

        $copy = copy($src_full, $dst_full);

        if (!$copy) {
            return ['status' => 'error', 'error' => 'Failed to copy file: ' . $src_full];
        }

        $result = [
            'status'        => 'success',
            'src_file_path' => $src_full,
            'dst_file_path' => $dst_full
        ];

        unset($src_file_path, $dst_file_path, $src_full, $dst_full, $copy);
        return $result;
    }

    /**
     * Delete a file.
     *
     * @param string $file_path
     *
     * @return array
     */
    public function deleteFile(string $file_path): array
    {
        $full_path = $this->core->securePath($file_path);

        if (!is_file($full_path)) {
            return [
                'status' => 'error',
                'error'  => 'File does not exist: ' . $full_path
            ];
        }

        $deleted = unlink($full_path);

        if ($deleted) {
            $result = [
                'status'    => 'success',
                'file_path' => $full_path
            ];
        } else {
            $result = [
                'status' => 'error',
                'error'  => 'Failed to delete file: ' . $full_path
            ];
        }

        unset($file_path, $full_path, $deleted);
        return $result;
    }

    /**
     * Get file size.
     *
     * @param string $file_path
     *
     * @return array
     */
    public function getFileSize(string $file_path): array
    {
        $full_path = $this->core->securePath($file_path);

        if (!is_file($full_path)) {
            return ['status' => 'error', 'error' => 'File not found: ' . $full_path];
        }

        $size = filesize($full_path);

        $result = [
            'status'    => 'success',
            'file_path' => $full_path,
            'filesize'  => $size
        ];

        unset($file_path, $full_path, $size);
        return $result;
    }

    /**
     * Search files by glob pattern.
     *
     * @param string $dir_path
     * @param string $pattern
     * @param bool   $recursive
     *
     * @return array
     */
    public function searchFiles(string $dir_path, string $pattern, bool $recursive = false): array
    {
        $full_path = $this->core->securePath($dir_path);

        if (!is_dir($full_path)) {
            return ['status' => 'error', 'error' => 'Directory not found: ' . $full_path];
        }

        $files = $this->libFileIO->findFiles($full_path, $pattern, $recursive);

        $result = [
            'status'   => 'success',
            'dir_path' => $full_path,
            'files'    => $files
        ];

        unset($dir_path, $pattern, $recursive, $full_path, $files);
        return $result;
    }

    /**
     * List directory contents.
     *
     * @param string $dir_path
     *
     * @return array
     */
    public function listDirectory(string $dir_path): array
    {
        $full_path = $this->core->securePath($dir_path);

        if (!is_dir($full_path)) {
            return ['status' => 'error', 'error' => 'Directory not found: ' . $full_path];
        }

        $contents = $this->libFileIO->getDirContents($full_path);

        $result = [
            'status'   => 'success',
            'dir_path' => $full_path,
            'contents' => $contents
        ];

        unset($dir_path, $full_path, $contents);
        return $result;
    }

    /**
     * Create directory.
     *
     * @param string $dir_path
     *
     * @return array
     */
    public function createDirectory(string $dir_path): array
    {
        $full_path = $this->core->securePath($dir_path);
        $created   = $this->mkPath($full_path);

        $result = [
            'status'   => 'success',
            'dir_path' => $created
        ];

        unset($dir_path, $full_path, $created);
        return $result;
    }

    /**
     * Copy directory recursively.
     *
     * @param string $src_dir_path
     * @param string $dst_dir_path
     * @param bool   $overwrite
     *
     * @return array
     */
    public function copyDirectory(string $src_dir_path, string $dst_dir_path, bool $overwrite = false): array
    {
        $src_full = $this->core->securePath($src_dir_path);
        $dst_full = $this->core->securePath($dst_dir_path);

        if (!is_dir($src_full)) {
            return ['status' => 'error', 'error' => 'Source directory not found: ' . $src_full];
        }

        if (!$overwrite && is_dir($dst_full)) {
            return ['status' => 'error', 'error' => 'Destination directory already exists: ' . $dst_full];
        }

        $copied = $this->libFileIO->copyDir($src_full, $dst_full);

        if (0 > $copied) {
            return ['status' => 'error', 'error' => 'Failed to copy directory: ' . $src_full];
        }

        $result = [
            'status'       => 'success',
            'src_dir_path' => $src_full,
            'dst_dir_path' => $dst_full,
            'copied_files' => $copied
        ];

        unset($src_dir_path, $dst_dir_path, $src_full, $dst_full, $copied);
        return $result;
    }

    /**
     * Delete directory recursively.
     *
     * @param string $dir_path
     *
     * @return array
     */
    public function deleteDirectory(string $dir_path): array
    {
        $full_path = $this->core->securePath($dir_path);

        if (!is_dir($full_path)) {
            return ['status' => 'error', 'error' => 'Directory does not exist: ' . $full_path];
        }

        $removed = $this->libFileIO->delDir($full_path);

        if (0 > $removed) {
            return ['status' => 'error', 'error' => 'Failed to delete directory: ' . $full_path];
        }

        $result = [
            'status'        => 'success',
            'dir_path'      => $full_path,
            'files_removed' => $removed
        ];

        unset($dir_path, $full_path, $removed);
        return $result;
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private function mkPath(string $path): string
    {
        if (!is_dir($path)) {
            try {
                mkdir($path, 0777, true);
            } catch (\Throwable) {
            }
        }

        return $path;
    }
}