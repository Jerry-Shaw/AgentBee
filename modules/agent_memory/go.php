<?php

/**
 * Memory module for AgentBee - Four-layer memory management system
 *
 * Provides system/important/daily memory storage using JSONL format.
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

namespace modules\agent_memory;

use modules\agent_core\core;
use Nervsys\Core\Factory;

class go extends Factory
{
    public core $core;

    private string $memory_path;

    private string $daily_dir;
    private string $system_file;
    private string $important_file;

    public array $ram_memory = [];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core = core::new();

        $this->core->initCore();

        $this->memory_path = $this->core->app->root_path . DIRECTORY_SEPARATOR . 'memory' . DIRECTORY_SEPARATOR;

        $this->daily_dir      = $this->memory_path . 'daily' . DIRECTORY_SEPARATOR;
        $this->system_file    = $this->memory_path . 'system.txt';
        $this->important_file = $this->memory_path . 'important.txt';

        if (!is_dir($this->daily_dir)) {
            try {
                mkdir($this->daily_dir, 0777, true);
            } catch (\Throwable) {
            }
        }
    }

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
        if (!in_array($level, ['system', 'important', 'daily', 'ram'], true)) {
            return ['error' => "Invalid level: {$level}"];
        }

        if (!in_array($role, ['user', 'assistant', 'system', 'tool'], true)) {
            return ['error' => "Invalid role: {$role}"];
        }

        // RAM layer: store in memory array
        if ('ram' === $level) {
            $message            = ['role' => $role, 'content' => $content];
            $this->ram_memory[] = $message;
            return ['saved' => true, 'path' => 'ram://memory', 'role' => $role];
        }

        // Force role for system/important layers
        if ('system' === $level) {
            $role = 'system';
        } elseif ('important' === $level) {
            $role = 'user';
        }

        $line = json_encode(['role' => $role, 'content' => $content], JSON_FORMAT);

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
        if (!in_array($level, ['system', 'important', 'daily', 'ram'], true)) {
            return ['error' => "Invalid level: {$level}"];
        }

        // RAM layer: read from memory array
        if ('ram' === $level) {
            $all_messages = $this->ram_memory;
            $total        = count($all_messages);

            if (0 === $length) {
                $length = $total;
            }

            $selected = array_slice($all_messages, $offset, $length);
            $result   = ['messages' => $selected, 'total' => $total];

            unset($all_messages, $selected);
            return $result;
        }

        $target_file = $this->getTargetFile($level, $date);

        if (!is_file($target_file)) {
            return ['messages' => [], 'total' => 0];
        }

        $handle = fopen($target_file, 'rb');

        if (false === $handle) {
            return ['error' => "Cannot open file: {$target_file}"];
        }

        $all_messages = [];

        while (false !== ($line = fgets($handle))) {
            $line = rtrim($line, "\r\n");

            if ('' === $line) {
                continue;
            }

            $msg = json_decode($line, true);

            if (is_array($msg) && isset($msg['role'], $msg['content'])) {
                $all_messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }

        fclose($handle);

        $total = count($all_messages);

        if (0 === $length) {
            $length = $total;
        }

        $selected = array_slice($all_messages, $offset, $length);
        $result   = ['messages' => $selected, 'total' => $total];

        unset($all_messages, $selected);
        return $result;
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
        if (!in_array($level, ['system', 'important', 'daily', 'ram', 'all'], true)) {
            return ['error' => "Invalid level: {$level}"];
        }

        if (!in_array($mode, ['or', 'and'], true)) {
            return ['error' => "Invalid mode: {$mode}"];
        }

        if (empty($keywords)) {
            return ['error' => 'Keywords cannot be empty'];
        }

        $results          = [];
        $keywords_lower   = array_map('strtolower', $keywords);
        $levels_to_search = ('all' === $level) ? ['system', 'important', 'daily', 'ram'] : [$level];

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