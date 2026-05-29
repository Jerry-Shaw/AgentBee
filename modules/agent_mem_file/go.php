<?php

/**
 * Memory module for AgentBee - Four-layer memory management system
 *
 * Provides system/important/daily memory storage using JSONL format.
 *
 * Copyright 2026 秋水之冰 <27206617@qq.com>
 *  Copyright 2026 AgentBee self developed
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

namespace modules\agent_mem_file;

use modules\agent_core\core;
use Nervsys\Core\Factory;
use Nervsys\Ext\libFileIO;

class go extends Factory
{
    public core      $core;
    public libFileIO $libFileIO;

    private string $memory_path;

    private string $task_dir;
    private string $daily_dir;
    private string $system_file;
    private string $important_file;

    public array $ram_memory = [];

    private const LEVELS      = ['system', 'important', 'daily', 'ram'];
    private const ROLES       = ['user', 'assistant', 'system', 'tool'];
    private const SEARCH_MODE = ['or', 'and'];
    private const ALL_LEVELS  = ['system', 'important', 'daily', 'ram', 'all'];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core      = core::new();
        $this->libFileIO = libFileIO::new();

        $this->core->initCore();

        $this->memory_path = $this->core->app->root_path . DIRECTORY_SEPARATOR . 'memory' . DIRECTORY_SEPARATOR;

        $this->task_dir       = $this->memory_path . 'task' . DIRECTORY_SEPARATOR;
        $this->daily_dir      = $this->memory_path . 'daily' . DIRECTORY_SEPARATOR;
        $this->system_file    = $this->memory_path . 'system.txt';
        $this->important_file = $this->memory_path . 'important.txt';

        if (!is_dir($this->task_dir)) {
            try {
                mkdir($this->task_dir, 0777, true);
            } catch (\Throwable) {
            }
        }

        if (!is_dir($this->daily_dir)) {
            try {
                mkdir($this->daily_dir, 0777, true);
            } catch (\Throwable) {
            }
        }
    }

    // =========================================================================
    //  Task Management
    // =========================================================================

    /**
     * @param string $task_id
     * @param string $task_prompt
     * @param int    $run_at
     * @param bool   $repeat
     * @param int    $repeat_interval
     *
     * @return int[]
     */
    public function addTask(string $task_id, string $task_prompt, int $run_at, bool $repeat = false, int $repeat_interval = 0): array
    {
        $task_file = $this->task_dir . 'task_' . md5($task_id) . '.json';

        $task_data = [
            'task_id'         => $task_id,
            'task_prompt'     => $task_prompt,
            'run_at'          => $run_at,
            'repeat'          => $repeat,
            'repeat_interval' => $repeat_interval,
            'created_at'      => time()
        ];

        $bytes = file_put_contents($task_file, json_encode($task_data, JSON_FORMAT));

        unset($task_id, $task_prompt, $run_at, $repeat, $repeat_interval, $task_file);
        return ['bytes_written' => $bytes ?: 0];
    }

    /**
     * @param string $task_id
     *
     * @return array
     */
    public function removeTask(string $task_id): array
    {
        $task_file = $this->task_dir . 'task_' . md5($task_id) . '.json';

        if (is_file($task_file)) {
            $delete = unlink($task_file);
            $result = $delete
                ? ['success' => true, 'message' => 'Task removed.']
                : ['success' => false, 'message' => 'Task remove FAILED!'];
        } else {
            $result = ['success' => false, 'message' => 'Task not found.'];
        }

        unset($task_id, $task_file, $delete);
        return $result;
    }

    /**
     * @return array
     */
    public function listTasks(): array
    {
        $task_list  = [];
        $task_files = $this->libFileIO->getFiles($this->task_dir);

        foreach ($task_files as $task_file) {
            $content   = file_get_contents($task_file);
            $task_data = json_decode($content, true);

            if (is_null($task_data)) {
                unlink($task_file);
            }

            $task_list[] = $task_data;
        }

        unset($task_files, $task_file, $content, $task_data);
        return $task_list;
    }

    /**
     * @return array
     */
    public function runTask(): array
    {
        $task_run  = [];
        $now_time  = time();
        $task_list = $this->listTasks();

        foreach ($task_list as $task) {
            if ($task['run_at'] > $now_time) {
                continue;
            }

            $task_run[] = $task;

            if ($task['repeat']) {
                $task['run_at'] += $task['repeat_interval'];

                $this->addTask($task['task_id'], $task['task_prompt'], $task['run_at'], $task['repeat'], $task['repeat_interval']);
            } else {
                $this->removeTask($task['task_id']);
            }
        }

        unset($now_time, $task_list, $task);
        return $task_run;
    }

    // =========================================================================
    //  Memory CRUD
    // =========================================================================

    /**
     * Save a memory entry (append mode, JSONL format)
     *
     * @param string $level   'system', 'important', 'daily', or 'ram'
     * @param string $role    'user', 'assistant', 'system', or 'tool'
     * @param string $content Memory content
     *
     * @return array ['saved' => true, 'path' => ..., 'role' => ...] or ['error' => ...]
     */
    public function save(string $level, string $role, string $content): array
    {
        if (!in_array($level, self::LEVELS, true)) {
            return ['error' => "Invalid level: {$level}"];
        }

        if (!in_array($role, self::ROLES, true)) {
            return ['error' => "Invalid role: {$role}"];
        }

        // RAM layer: store in memory array
        if ('ram' === $level) {
            $this->ram_memory[] = ['role' => $role, 'content' => $content, 'created_at' => time()];
            return ['saved' => true, 'path' => 'ram://memory', 'role' => $role];
        }

        // Force role for system/important layers
        if ('system' === $level) {
            $role = 'system';
        } elseif ('important' === $level) {
            $role = 'user';
        }

        $line = json_encode(['role' => $role, 'content' => $content, 'created_at' => time()], JSON_FORMAT);

        if (false === $line) {
            return ['error' => 'JSON encode failed'];
        }

        $target_file = $this->getTargetFile($level);
        $target_dir  = dirname($target_file);

        if (!is_dir($target_dir)) {
            try {
                mkdir($target_dir, 0777, true);
            } catch (\Throwable) {
            }
        }

        $handle = fopen($target_file, 'ab');

        if (false === $handle) {
            return ['error' => "Cannot open file: {$target_file}"];
        }

        fwrite($handle, $line . "\n");
        fclose($handle);

        $result = ['saved' => true, 'path' => $target_file, 'role' => $role];
        unset($level, $role, $content, $line, $target_file, $target_dir, $handle);

        return $result;
    }

    /**
     * Read memory entries with pagination (supports date selection for daily layer)
     *
     * @param string $level  'system', 'important', 'daily', or 'ram'
     * @param int    $offset Line offset (zero-based)
     * @param int    $length Maximum number of lines to return (0 = all)
     * @param string $date   YYYYMMDD, only for daily layer, default empty (today)
     *
     * @return array ['messages' => [...], 'total' => int] or ['error' => ...]
     */
    public function read(string $level, int $offset = 0, int $length = 100, string $date = ''): array
    {
        if (!in_array($level, self::ALL_LEVELS, true)) {
            return ['error' => "Invalid level: {$level}"];
        }

        // RAM 单独访问的情况
        if ('ram' === $level) {
            $total    = count($this->ram_memory);
            $messages = array_slice($this->ram_memory, $offset, ($length === 0) ? $total : $length);
            $messages = array_map(fn($item) => ['role' => $item['role'], 'content' => $item['content']], $messages);
            return ['messages' => $messages, 'total' => $total];
        }

        $levels_to_fetch = ('all' === $level) ? self::LEVELS : [$level];
        $all_messages    = [];

        foreach ($levels_to_fetch as $lv) {
            if ('ram' === $lv) {
                foreach ($this->ram_memory as $item) {
                    $all_messages[] = [
                        'role'       => $item['role'],
                        'content'    => $item['content'],
                        'created_at' => $item['created_at']
                    ];
                }
                continue;
            }

            // 获取该层级对应的文件列表
            if ('daily' === $lv) {
                $target_date = ($date !== '') ? $this->validateDate($date) : date('Ymd');
                $file        = $this->daily_dir . $target_date . '.txt';
                $files       = is_file($file) ? [$file] : [];
            } else {
                $files = $this->getTargetFiles($lv);
            }

            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $handle = fopen($file, 'rb');
                if ($handle === false) {
                    continue;
                }
                while (($line = fgets($handle)) !== false) {
                    $line = rtrim($line, "\r\n");
                    if ($line === '') {
                        continue;
                    }
                    $msg = json_decode($line, true);
                    if (is_array($msg) && isset($msg['role'], $msg['content'])) {
                        $all_messages[] = [
                            'role'       => $msg['role'],
                            'content'    => $msg['content'],
                            'created_at' => $msg['created_at'] ?? 0
                        ];
                    }
                }
                fclose($handle);
            }
        }

        // 按 created_at 升序排序
        usort($all_messages, fn($a, $b) => $a['created_at'] <=> $b['created_at']);

        $total = count($all_messages);
        if ($length === 0) {
            $length = $total;
        }
        $slice    = array_slice($all_messages, $offset, $length);
        $messages = array_map(fn($item) => ['role' => $item['role'], 'content' => $item['content']], $slice);

        return ['messages' => $messages, 'total' => $total];
    }

    /**
     * Search memory with keyword matching (case-insensitive, AND/OR mode)
     * Supports date range for daily layer and cross-layer search.
     *
     * @param string $level      'system', 'important', 'daily', 'ram', or 'all'
     * @param array  $keywords   List of keywords (case-insensitive)
     * @param int    $offset     Result offset for pagination
     * @param int    $length     Maximum results (0 = all)
     * @param string $mode       'or' (any keyword) or 'and' (all keywords)
     * @param string $start_date YYYYMMDD, only for daily layer
     * @param string $end_date   YYYYMMDD, only for daily layer
     *
     * @return array ['messages' => [...], 'total' => int] or ['error' => ...]
     */
    public function search(
        string $level,
        array  $keywords,
        int    $offset = 0,
        int    $length = 100,
        string $mode = 'or',
        string $start_date = '',
        string $end_date = ''
    ): array
    {
        if (!in_array($level, self::ALL_LEVELS, true)) {
            return ['error' => "Invalid level: {$level}"];
        }

        if (!in_array($mode, self::SEARCH_MODE, true)) {
            return ['error' => "Invalid mode: {$mode}"];
        }

        if (empty($keywords)) {
            return ['error' => 'Keywords cannot be empty'];
        }

        $results          = [];
        $keywords_lower   = array_map('strtolower', $keywords);
        $levels_to_search = ('all' === $level) ? self::LEVELS : [$level];

        // Build list of daily files within date range
        $daily_files = [];
        if (in_array('daily', $levels_to_search, true)) {
            $start = ('' === $start_date) ? '00000000' : $this->validateDate($start_date);
            $end   = ('' === $end_date) ? '99999999' : $this->validateDate($end_date);

            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            $all_daily = glob($this->daily_dir . '*.txt');

            if (false !== $all_daily) {
                foreach ($all_daily as $file) {
                    $filename = basename($file, '.txt');

                    if (ctype_digit($filename) && 8 === strlen($filename) && $filename >= $start && $filename <= $end) {
                        $daily_files[] = $file;
                    }
                }

                sort($daily_files, SORT_NATURAL);
            }
        }

        foreach ($levels_to_search as $search_lv) {
            // RAM layer: search in memory array
            if ('ram' === $search_lv) {
                foreach ($this->ram_memory as $message) {
                    $line = json_encode($message, JSON_FORMAT);
                    if (false === $line) {
                        continue;
                    }

                    $lower_line = strtolower($line);
                    $matched    = false;

                    if ('or' === $mode) {
                        foreach ($keywords_lower as $kw) {
                            if (str_contains($lower_line, $kw)) {
                                $matched = true;
                                break;
                            }
                        }
                    } else {
                        $matched = true;
                        foreach ($keywords_lower as $kw) {
                            if (!str_contains($lower_line, $kw)) {
                                $matched = false;
                                break;
                            }
                        }
                    }

                    if ($matched) {
                        $results[] = ['role' => $message['role'], 'content' => $message['content']];
                    }
                }
                continue;
            }

            if ('daily' === $search_lv) {
                $files = $daily_files;
            } else {
                $files = $this->getTargetFiles($search_lv);
            }

            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }

                $handle = fopen($file, 'rb');

                if (false === $handle) {
                    continue;
                }

                while (false !== ($line = fgets($handle))) {
                    $line = rtrim($line, "\r\n");

                    if ('' === $line) {
                        continue;
                    }

                    $lower_line = strtolower($line);
                    $matched    = false;

                    if ('or' === $mode) {
                        foreach ($keywords_lower as $kw) {
                            if (str_contains($lower_line, $kw)) {
                                $matched = true;
                                break;
                            }
                        }
                    } else {
                        $matched = true;
                        foreach ($keywords_lower as $kw) {
                            if (!str_contains($lower_line, $kw)) {
                                $matched = false;
                                break;
                            }
                        }
                    }

                    if (!$matched) {
                        continue;
                    }

                    $msg = json_decode($line, true);

                    if (is_array($msg) && isset($msg['role'], $msg['content'])) {
                        $results[] = ['role' => $msg['role'], 'content' => $msg['content']];
                    }
                }

                fclose($handle);
                unset($handle);
            }

            unset($files);
        }

        $total = count($results);

        if (0 === $length) {
            $length = $total;
        }

        $selected = array_slice($results, $offset, $length);
        $result   = ['messages' => $selected, 'total' => $total];

        unset($results, $selected, $daily_files);
        return $result;
    }

    // =========================================================================
    //  Memory Delete
    // =========================================================================

    /**
     * Delete memory entries by keywords and/or time range
     *
     * Supports all four layers (system/important/daily/ram).
     * - keywords: content-based matching (case-insensitive, AND/OR mode)
     * - start_time/end_time: Unix timestamp range filter on created_at
     * - At least one of keywords or time range must be provided
     *
     * @param string $level      system|important|daily|ram|all
     * @param string $keywords   Comma-separated keywords (empty = no content filter)
     * @param string $mode       or|and (default: or)
     * @param string $start_date YYYYMMDD (only for daily layer file selection)
     * @param string $end_date   YYYYMMDD (only for daily layer file selection)
     * @param int    $start_time Unix timestamp lower bound (0 = no limit)
     * @param int    $end_time   Unix timestamp upper bound (0 = no limit)
     *
     * @return array ['deleted' => N] or ['error' => ...]
     */
    public function delete(
        string $level,
        string $keywords = '',
        string $mode = 'or',
        string $start_date = '',
        string $end_date = '',
        int    $start_time = 0,
        int    $end_time = 0
    ): array
    {
        if (!in_array($level, self::ALL_LEVELS, true)) {
            return ['error' => "Invalid level: {$level}"];
        }

        if (!in_array($mode, self::SEARCH_MODE, true)) {
            return ['error' => "Invalid mode: {$mode}"];
        }

        // Parse keywords
        $kw_list = [];
        if ('' !== $keywords) {
            foreach (explode(',', $keywords) as $kw) {
                $kw = trim($kw);
                if ('' !== $kw) {
                    $kw_list[] = strtolower($kw);
                }
            }
        }

        // Must have at least one filter condition
        if (empty($kw_list) && 0 === $start_time && 0 === $end_time) {
            return ['error' => 'At least one of keywords or time range (start_time/end_time) must be provided'];
        }

        $levels_to_process = ('all' === $level) ? self::LEVELS : [$level];
        $total_deleted     = 0;

        foreach ($levels_to_process as $lv) {
            if ('ram' === $lv) {
                $total_deleted += $this->deleteRAM($kw_list, $mode, $start_time, $end_time);
            } else {
                $total_deleted += $this->deleteFromFile($lv, $kw_list, $mode, $start_date, $end_date, $start_time, $end_time);
            }
        }

        return ['deleted' => $total_deleted];
    }

    /**
     * Delete matching entries from RAM memory array
     *
     * @param array  $kw_list    Lowercased keywords
     * @param string $mode       or|and
     * @param int    $start_time Unix timestamp lower bound (0 = no limit)
     * @param int    $end_time   Unix timestamp upper bound (0 = no limit)
     *
     * @return int Number of deleted entries
     */
    private function deleteRAM(array $kw_list, string $mode, int $start_time, int $end_time): int
    {
        $before_count = count($this->ram_memory);

        $this->ram_memory = array_values(array_filter($this->ram_memory, function (array $entry) use ($kw_list, $mode, $start_time, $end_time): bool
        {
            // Time filter
            $entry_time = $entry['created_at'] ?? 0;
            if (0 !== $start_time && $entry_time < $start_time) {
                return true;
            }
            if (0 !== $end_time && $entry_time > $end_time) {
                return true;
            }

            // If no keywords, time match alone means delete
            if (empty($kw_list)) {
                return false;
            }

            // Keyword filter
            $haystack = strtolower($entry['role'] . ' ' . $entry['content']);

            if ('or' === $mode) {
                foreach ($kw_list as $kw) {
                    if (str_contains($haystack, $kw)) {
                        return false;
                    }
                }
                return true;
            }

            // AND mode
            foreach ($kw_list as $kw) {
                if (!str_contains($haystack, $kw)) {
                    return true;
                }
            }
            return false;
        }));

        return $before_count - count($this->ram_memory);
    }

    /**
     * Delete matching entries from a file-based memory layer
     * Reads all lines, filters out matches, rewrites the file.
     *
     * @param string $level      system|important|daily
     * @param array  $kw_list    Lowercased keywords
     * @param string $mode       or|and
     * @param string $start_date YYYYMMDD (for daily file selection)
     * @param string $end_date   YYYYMMDD (for daily file selection)
     * @param int    $start_time Unix timestamp lower bound (0 = no limit)
     * @param int    $end_time   Unix timestamp upper bound (0 = no limit)
     *
     * @return int Number of deleted entries
     */
    private function deleteFromFile(string $level, array $kw_list, string $mode, string $start_date, string $end_date, int $start_time, int $end_time): int
    {
        $files = [];

        if ('daily' === $level) {
            // Build list of daily files within date range
            $start = ('' === $start_date) ? '00000000' : $this->validateDate($start_date);
            $end   = ('' === $end_date) ? '99999999' : $this->validateDate($end_date);

            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            $all_daily = glob($this->daily_dir . '*.txt');

            if (false !== $all_daily) {
                foreach ($all_daily as $file) {
                    $filename = basename($file, '.txt');
                    if (ctype_digit($filename) && 8 === strlen($filename) && $filename >= $start && $filename <= $end) {
                        $files[] = $file;
                    }
                }
            }
        } else {
            $target = $this->getTargetFile($level);
            if (is_file($target)) {
                $files[] = $target;
            }
        }

        $total_deleted = 0;

        foreach ($files as $file) {
            $total_deleted += $this->deleteFromFileLines($file, $kw_list, $mode, $start_time, $end_time);
        }

        return $total_deleted;
    }

    /**
     * Delete matching lines from a single JSONL file
     * Reads all lines, filters out matches, rewrites the file.
     *
     * @param string $file       File path
     * @param array  $kw_list    Lowercased keywords
     * @param string $mode       or|and
     * @param int    $start_time Unix timestamp lower bound (0 = no limit)
     * @param int    $end_time   Unix timestamp upper bound (0 = no limit)
     *
     * @return int Number of deleted entries
     */
    private function deleteFromFileLines(string $file, array $kw_list, string $mode, int $start_time, int $end_time): int
    {
        if (!is_file($file)) {
            return 0;
        }

        $handle = fopen($file, 'rb');

        if (false === $handle) {
            return 0;
        }

        $kept_lines = [];
        $deleted    = 0;

        while (false !== ($line = fgets($handle))) {
            $trimmed = rtrim($line, "\r\n");

            if ('' === $trimmed) {
                continue;
            }

            $msg = json_decode($trimmed, true);

            if (!is_array($msg) || !isset($msg['role'], $msg['content'])) {
                $kept_lines[] = $line;
                continue;
            }

            $should_delete = $this->shouldDeleteEntry($msg, $kw_list, $mode, $start_time, $end_time);

            if ($should_delete) {
                ++$deleted;
            } else {
                $kept_lines[] = $line;
            }
        }

        fclose($handle);

        // Rewrite file only if something was deleted
        if ($deleted > 0) {
            $handle = fopen($file, 'wb');

            if (false !== $handle) {
                foreach ($kept_lines as $kept) {
                    fwrite($handle, $kept);
                }
                fclose($handle);
            }
        }

        unset($kept_lines, $handle, $line, $trimmed, $msg, $should_delete);
        return $deleted;
    }

    /**
     * Check if a single memory entry should be deleted
     *
     * @param array  $msg        Decoded message with role, content, created_at
     * @param array  $kw_list    Lowercased keywords
     * @param string $mode       or|and
     * @param int    $start_time Unix timestamp lower bound (0 = no limit)
     * @param int    $end_time   Unix timestamp upper bound (0 = no limit)
     *
     * @return bool True if entry should be deleted
     */
    private function shouldDeleteEntry(array $msg, array $kw_list, string $mode, int $start_time, int $end_time): bool
    {
        // Time filter
        $entry_time = $msg['created_at'] ?? 0;

        if (0 !== $start_time && $entry_time < $start_time) {
            return false;
        }

        if (0 !== $end_time && $entry_time > $end_time) {
            return false;
        }

        // If no keywords, time match alone means delete
        if (empty($kw_list)) {
            return true;
        }

        // Keyword filter
        $haystack = strtolower($msg['role'] . ' ' . $msg['content']);

        if ('or' === $mode) {
            foreach ($kw_list as $kw) {
                if (str_contains($haystack, $kw)) {
                    return true;
                }
            }
            return false;
        }

        // AND mode
        foreach ($kw_list as $kw) {
            if (!str_contains($haystack, $kw)) {
                return false;
            }
        }
        return true;
    }

    // =========================================================================
    //  Internal Helpers
    // =========================================================================

    /**
     * @param string $level
     * @param string $date
     *
     * @return string
     */
    private function getTargetFile(string $level, string $date = ''): string
    {
        switch ($level) {
            case 'system':
                return $this->system_file;
            case 'important':
                return $this->important_file;
            case 'daily':
                $date = '' === $date ? date('Ymd') : $this->validateDate($date);
                return $this->daily_dir . $date . '.txt';
            default:
                return '';
        }
    }

    /**
     * @param string $level
     *
     * @return array|string[]
     */
    private function getTargetFiles(string $level): array
    {
        switch ($level) {
            case 'system':
                return [$this->system_file];
            case 'important':
                return [$this->important_file];
            case 'daily':
                $files = glob($this->daily_dir . '*.txt');
                return $files ?: [];
            default:
                return [];
        }
    }

    /**
     * @param string $date
     *
     * @return string
     */
    private function validateDate(string $date): string
    {
        if (!ctype_digit($date) || 8 !== strlen($date)) {
            return date('Ymd');
        }

        $year  = (int)substr($date, 0, 4);
        $month = (int)substr($date, 4, 2);
        $day   = (int)substr($date, 6, 2);

        if (!checkdate($month, $day, $year)) {
            return date('Ymd');
        }

        return $date;
    }
}