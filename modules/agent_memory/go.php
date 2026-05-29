<?php

/**
 * Memory module for AgentBee - SQLite-based four-layer memory management system
 *
 * Provides system/important/daily/ram memory storage using SQLite database.
 * All CRUD operations use libSQLite QueryBuilder with parameter binding.
 * Full-text search via FTS5 with LIKE fallback.
 *
 * Copyright 2026 AgentBee self developed
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
use Nervsys\Ext\libPDO;
use Nervsys\Ext\libSQLite;

class go extends Factory
{
    public core      $core;
    public libSQLite $db;
    public libPDO    $libPDO;

    public array $ram_memory = [];

    private string $db_path;
    private bool   $fts_enabled = false;

    private const LEVELS      = ['system', 'important', 'daily', 'ram'];
    private const ROLES       = ['user', 'assistant', 'system', 'tool'];
    private const SEARCH_MODE = ['or', 'and'];
    private const ALL_LEVELS  = ['system', 'important', 'daily', 'ram', 'all'];

    // DDL: main messages table
    private const DDL_MESSAGES = '
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            level TEXT NOT NULL DEFAULT \'daily\',
            role TEXT NOT NULL DEFAULT \'user\',
            content TEXT NOT NULL,
            date_key TEXT DEFAULT \'\',
            created_at INTEGER NOT NULL DEFAULT (strftime(\'%s\', \'now\'))
        )';

    // DDL: scheduled tasks table
    private const DDL_TASKS = '
        CREATE TABLE IF NOT EXISTS tasks (
            task_id TEXT PRIMARY KEY,
            task_prompt TEXT NOT NULL,
            run_at INTEGER NOT NULL,
            repeat INTEGER DEFAULT 0,
            repeat_interval INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT (strftime(\'%s\', \'now\'))
        )';

    // DDL: indexes
    private const DDL_INDEXES = [
        'CREATE INDEX IF NOT EXISTS idx_messages_level ON messages(level)',
        'CREATE INDEX IF NOT EXISTS idx_messages_date_key ON messages(date_key)',
        'CREATE INDEX IF NOT EXISTS idx_messages_level_date ON messages(level, date_key)',
        'CREATE INDEX IF NOT EXISTS idx_tasks_run_at ON tasks(run_at)',
    ];

    // DDL: FTS5 virtual table
    private const DDL_FTS = '
        CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5(
            content,
            level,
            role,
            content=\'messages\',
            content_rowid=\'id\'
        )';

    // DDL: FTS5 sync triggers
    private const DDL_FTS_TRIGGER_INSERT = '
        CREATE TRIGGER IF NOT EXISTS messages_ai AFTER INSERT ON messages BEGIN
            INSERT INTO messages_fts(rowid, content, level, role)
            VALUES (new.id, new.content, new.level, new.role);
        END';

    private const DDL_FTS_TRIGGER_DELETE = '
        CREATE TRIGGER IF NOT EXISTS messages_ad AFTER DELETE ON messages BEGIN
            INSERT INTO messages_fts(messages_fts, rowid, content, level, role)
            VALUES (\'delete\', old.id, old.content, old.level, old.role);
        END';

    private const DDL_FTS_TRIGGER_UPDATE = '
        CREATE TRIGGER IF NOT EXISTS messages_au AFTER UPDATE ON messages BEGIN
            INSERT INTO messages_fts(messages_fts, rowid, content, level, role)
            VALUES (\'delete\', old.id, old.content, old.level, old.role);
            INSERT INTO messages_fts(rowid, content, level, role)
            VALUES (new.id, new.content, new.level, new.role);
        END';

    // DDL: FTS5 backfill
    private const DDL_FTS_BACKFILL = '
        INSERT INTO messages_fts(rowid, content, level, role)
        SELECT id, content, level, role FROM messages
        WHERE id NOT IN (SELECT rowid FROM messages_fts)';

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core = core::new();
        $this->core->initCore();

        $this->db_path = $this->core->app->root_path . DIRECTORY_SEPARATOR . 'memory' . DIRECTORY_SEPARATOR;

        $this->initDatabase();
    }

    // =========================================================================
    //  Database Initialization (one-time)
    // =========================================================================

    private function initDatabase(): void
    {
        if (!is_dir($this->db_path)) {
            mkdir($this->db_path, 0777, true);
        }

        $db_file      = $this->db_path . 'agent_memory.db';
        $this->libPDO = libPDO::new('sqlite', $db_file);
        $this->libPDO->connect();

        $this->db = libSQLite::new();
        $this->db->bindLibPdo($this->libPDO);
        $this->db->autoCleanup();

        // Enable WAL mode and set busy timeout to reduce lock contention
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA busy_timeout = 3000');
        $this->db->exec('PRAGMA cache_size = -2000'); // 2MB cache

        $this->createSchema();
        $this->initFTS();
    }

    private function createSchema(): void
    {
        $this->db->exec(self::DDL_MESSAGES);
        $this->db->exec(self::DDL_TASKS);

        foreach (self::DDL_INDEXES as $index_sql) {
            $this->db->exec($index_sql);
        }
        unset($index_sql);
    }

    private function initFTS(): void
    {
        try {
            $this->db->exec(self::DDL_FTS);
            $this->db->exec(self::DDL_FTS_TRIGGER_INSERT);
            $this->db->exec(self::DDL_FTS_TRIGGER_DELETE);
            $this->db->exec(self::DDL_FTS_TRIGGER_UPDATE);
            $this->db->exec(self::DDL_FTS_BACKFILL);
            $this->fts_enabled = true;
        } catch (\Throwable) {
            $this->fts_enabled = false;
        }
    }

    // =========================================================================
    //  Task Management
    // =========================================================================

    public function addTask(string $task_id, string $task_prompt, int $run_at, bool $repeat = false, int $repeat_interval = 0): array
    {
        $now = time();
        if ($run_at < $now) {
            $run_at = $now;
        }
        unset($now);

        if ($repeat && 0 >= $repeat_interval) {
            unset($task_id, $task_prompt, $run_at, $repeat, $repeat_interval);
            return ['error' => 'repeat_interval must be a positive integer when repeat is enabled'];
        }

        try {
            $this->db->table('tasks')
                ->replace([
                    'task_id'         => $task_id,
                    'task_prompt'     => $task_prompt,
                    'run_at'          => $run_at,
                    'repeat'          => (int)$repeat,
                    'repeat_interval' => $repeat_interval,
                ])
                ->execute();
            $result = ['bytes_written' => 1];
        } catch (\Throwable $e) {
            $result = ['error' => $e->getMessage()];
        }

        unset($task_id, $task_prompt, $run_at, $repeat, $repeat_interval);
        return $result;
    }

    public function removeTask(string $task_id): array
    {
        try {
            $exists = $this->db->table('tasks')
                ->select('task_id')
                ->where(['task_id', '=', $task_id])
                ->fetch();

            if (empty($exists)) {
                unset($exists, $task_id);
                return ['success' => false, 'message' => 'Task not found.'];
            }

            $this->db->table('tasks')
                ->where(['task_id', '=', $task_id])
                ->delete()
                ->execute();
            $result = ['success' => true, 'message' => 'Task removed.'];
        } catch (\Throwable $e) {
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        unset($exists, $task_id);
        return $result;
    }

    public function listTasks(): array
    {
        $tasks = $this->db->table('tasks')
            ->select('task_id', 'task_prompt', 'run_at', 'repeat', 'repeat_interval', 'created_at')
            ->fetchAll();

        foreach ($tasks as &$task) {
            $task['repeat'] = (bool)$task['repeat'];
        }
        unset($task);
        return $tasks;
    }

    public function runTask(): array
    {
        $now = time();

        $due_tasks = $this->db->table('tasks')
            ->select('task_id', 'task_prompt', 'run_at', 'repeat', 'repeat_interval', 'created_at')
            ->where(['run_at', '<=', $now])
            ->fetchAll();

        if (empty($due_tasks)) {
            unset($now, $due_tasks);
            return [];
        }

        $task_runs = [];

        foreach ($due_tasks as $task) {
            $is_repeat = (bool)$task['repeat'];

            $task_runs[] = [
                'task_id'         => $task['task_id'],
                'task_prompt'     => $task['task_prompt'],
                'run_at'          => (int)$task['run_at'],
                'repeat'          => $is_repeat,
                'repeat_interval' => (int)$task['repeat_interval'],
            ];

            if ($is_repeat) {
                $interval = (int)$task['repeat_interval'];
                if ($interval <= 0) {
                    // Invalid interval, delete task
                    $this->db->table('tasks')
                        ->where(['task_id', '=', $task['task_id']])
                        ->delete()
                        ->execute();
                    continue;
                }
                $new_run_at = (int)$task['run_at'] + $interval;
                while ($now >= $new_run_at) {
                    $new_run_at += $interval;
                }
                $this->db->table('tasks')
                    ->where(['task_id', '=', $task['task_id']])
                    ->update(['run_at' => $new_run_at])
                    ->execute();
                unset($interval, $new_run_at);
            } else {
                $this->db->table('tasks')
                    ->where(['task_id', '=', $task['task_id']])
                    ->delete()
                    ->execute();
            }
            unset($is_repeat);
        }

        unset($now, $due_tasks, $task);
        return $task_runs;
    }

    // =========================================================================
    //  Memory CRUD
    // =========================================================================

    public function save(string $level, string $role, string $content): array
    {
        if (!in_array($level, self::LEVELS, true)) {
            unset($role, $content);
            return ['error' => "Invalid level: {$level}"];
        }

        if ('' === $content) {
            unset($level, $role);
            return ['error' => 'Content cannot be empty'];
        }

        if ('ram' === $level) {
            if (!in_array($role, self::ROLES, true)) {
                unset($level, $content);
                return ['error' => "Invalid role: {$role}"];
            }
            $this->ram_memory[] = ['role' => $role, 'content' => $content, 'created_at' => time()];
            $result             = ['saved' => true, 'path' => 'ram://memory', 'role' => $role];
            unset($level, $role, $content);
            return $result;
        }

        // Force role for system/important levels
        if ('system' === $level) {
            $role = 'system';
        } elseif ('important' === $level) {
            $role = 'user';
        } elseif (!in_array($role, self::ROLES, true)) {
            unset($level, $content);
            return ['error' => "Invalid role: {$role}"];
        }

        $date_key = ('daily' === $level) ? date('Ymd') : '';

        $this->db->table('messages')
            ->insert([
                'level'    => $level,
                'role'     => $role,
                'content'  => $content,
                'date_key' => $date_key,
            ])
            ->execute();

        $result = ['saved' => true, 'path' => "sqlite://mem_db/{$level}", 'role' => $role];

        unset($level, $role, $content, $date_key);
        return $result;
    }

    public function read(string $level, int $offset = 0, int $length = 100, string $date = ''): array
    {
        // 检查 level 是否合法（包括 'all'）
        if (!in_array($level, self::ALL_LEVELS, true)) {
            return ['error' => "Invalid level: {$level}"];
        }

        if ($offset < 0) {
            return ['error' => 'offset must be >= 0, got: ' . $offset];
        }
        if ($length < 0) {
            return ['error' => 'length must be >= 0, got: ' . $length];
        }

        // 处理 RAM 单独访问的情况（保持原逻辑）
        if ('ram' === $level) {
            $total    = count($this->ram_memory);
            $messages = array_slice($this->ram_memory, $offset, ($length === 0) ? $total : $length);
            // 返回时只保留 role 和 content
            $messages = array_map(fn($item) => ['role' => $item['role'], 'content' => $item['content']], $messages);
            return ['messages' => $messages, 'total' => $total];
        }

        // 确定要查询的层级列表
        $levels_to_fetch = ('all' === $level) ? self::LEVELS : [$level];
        $all_messages    = [];

        foreach ($levels_to_fetch as $lv) {
            if ('ram' === $lv) {
                // RAM 层直接收集（包含 created_at 用于排序）
                foreach ($this->ram_memory as $item) {
                    $all_messages[] = [
                        'role'       => $item['role'],
                        'content'    => $item['content'],
                        'created_at' => $item['created_at']
                    ];
                }
                continue;
            }

            // 数据库层查询
            $conditions = [['level', '=', $lv]];
            if ('daily' === $lv) {
                $date_val     = ($date !== '') ? $date : date('Ymd');
                $conditions[] = ['date_key', '=', $date_val];
            }

            $rows = $this->db->table('messages')
                ->select('role', 'content', 'created_at')
                ->where(...$conditions)
                ->order(['id' => 'ASC'])   // 保持原始插入顺序，后续会按 created_at 重排
                ->fetchAll();

            foreach ($rows as $row) {
                $all_messages[] = [
                    'role'       => $row['role'],
                    'content'    => $row['content'],
                    'created_at' => (int)$row['created_at']
                ];
            }
        }

        // 按 created_at 升序排序（最早记忆在前）
        usort($all_messages, fn($a, $b) => $a['created_at'] <=> $b['created_at']);

        $total = count($all_messages);
        if ($length === 0) {
            $length = $total;
        }
        $slice = array_slice($all_messages, $offset, $length);

        // 移除内部使用的 created_at 字段，保持返回格式与原版一致
        $messages = array_map(fn($item) => ['role' => $item['role'], 'content' => $item['content']], $slice);

        return ['messages' => $messages, 'total' => $total];
    }

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
            unset($keywords, $offset, $length, $mode, $start_date, $end_date);
            return ['error' => "Invalid level: {$level}"];
        }

        if (!in_array($mode, self::SEARCH_MODE, true)) {
            unset($level, $keywords, $offset, $length, $start_date, $end_date);
            return ['error' => "Invalid mode: {$mode}"];
        }

        if (empty($keywords)) {
            unset($level, $offset, $length, $mode, $start_date, $end_date);
            return ['error' => 'Keywords cannot be empty'];
        }

        if (0 > $offset) {
            unset($level, $keywords, $length, $mode, $start_date, $end_date);
            return ['error' => 'offset must be >= 0, got: ' . $offset];
        }
        if (0 > $length) {
            unset($level, $keywords, $offset, $mode, $start_date, $end_date);
            return ['error' => 'length must be >= 0, got: ' . $length];
        }

        // Auto-correct date range
        if ('' !== $start_date && '' !== $end_date && $start_date > $end_date) {
            [$start_date, $end_date] = [$end_date, $start_date];
        }

        $levels_to_search = ('all' === $level) ? self::LEVELS : [$level];
        $ram_results      = [];
        $db_levels        = [];

        foreach ($levels_to_search as $lv) {
            if ('ram' === $lv) {
                $ram_results = $this->searchRAM($keywords, $mode);
            } else {
                $db_levels[] = $lv;
            }
        }

        $db_results = [];
        if (!empty($db_levels)) {
            $db_results = $this->fts_enabled
                ? $this->searchFTS($db_levels, $keywords, $mode, $start_date, $end_date)
                : $this->searchLike($db_levels, $keywords, $mode, $start_date, $end_date);
        }

        $all_results = array_merge($ram_results, $db_results);
        $total       = count($all_results);
        $limit       = (0 === $length) ? $total : $length;
        $messages    = array_slice($all_results, $offset, $limit);

        unset($level, $keywords, $offset, $length, $mode, $start_date, $end_date);
        unset($levels_to_search, $lv, $ram_results, $db_levels, $db_results, $all_results, $limit);
        return ['messages' => $messages, 'total' => $total];
    }

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

        $kw_list = [];
        if ('' !== $keywords) {
            foreach (explode(',', $keywords) as $kw) {
                $kw = trim($kw);
                if ('' !== $kw) {
                    $kw_list[] = strtolower($kw);
                }
            }
        }

        // 检查是否至少提供了关键词、日期范围或时间范围之一
        $has_date_range = ($start_date !== '' || $end_date !== '');
        $has_time_range = ($start_time !== 0 || $end_time !== 0);
        if (empty($kw_list) && !$has_time_range && !$has_date_range) {
            return ['error' => 'At least one of keywords, date range (start_date/end_date), or time range (start_time/end_time) must be provided'];
        }

        $levels_to_process = ('all' === $level) ? self::LEVELS : [$level];
        $total_deleted     = 0;

        foreach ($levels_to_process as $lv) {
            if ('ram' === $lv) {
                $total_deleted += $this->deleteRAM($kw_list, $mode, $start_time, $end_time);
            } else {
                $total_deleted += $this->deleteFromDB($lv, $kw_list, $mode, $start_date, $end_date, $start_time, $end_time);
            }
        }

        return ['deleted' => $total_deleted];
    }

    // =========================================================================
    //  Search Internals
    // =========================================================================

    private function sanitizeFTSKeyword(string $keyword): string
    {
        $keyword = trim($keyword);
        if ('' === $keyword) {
            return '';
        }
        $keyword = str_replace('"', '', $keyword);
        return '"' . $keyword . '"';
    }

    private function searchRAM(array $keywords, string $mode): array
    {
        $results        = [];
        $keywords_lower = array_map('strtolower', $keywords);

        foreach ($this->ram_memory as $message) {
            $haystack = strtolower($message['role'] . ' ' . $message['content']);
            if ($this->matchKeywords($haystack, $keywords_lower, $mode)) {
                $results[] = ['role' => $message['role'], 'content' => $message['content']];
            }
        }

        unset($keywords, $mode, $keywords_lower, $message, $haystack);
        return $results;
    }

    private function buildDateFilter(array $levels, string $start_date, string $end_date, array &$bind, string $table_alias = ''): string
    {
        if (!in_array('daily', $levels, true)) {
            unset($levels, $start_date, $end_date, $table_alias);
            return '';
        }
        if ('' === $start_date && '' === $end_date) {
            unset($levels, $start_date, $end_date, $table_alias);
            return '';
        }

        $col = $table_alias . 'date_key';
        if ('' !== $start_date && '' !== $end_date) {
            $sql    = " AND ({$table_alias}level != 'daily' OR ({$col} >= ? AND {$col} <= ?))";
            $bind[] = $start_date;
            $bind[] = $end_date;
        } elseif ('' !== $start_date) {
            $sql    = " AND ({$table_alias}level != 'daily' OR {$col} >= ?)";
            $bind[] = $start_date;
        } else {
            $sql    = " AND ({$table_alias}level != 'daily' OR {$col} <= ?)";
            $bind[] = $end_date;
        }

        unset($levels, $start_date, $end_date, $table_alias, $col);
        return $sql;
    }

    private function searchFTS(array $levels, array $keywords, string $mode, string $start_date, string $end_date): array
    {
        $fts_terms = array_map([$this, 'sanitizeFTSKeyword'], $keywords);
        $fts_terms = array_filter($fts_terms, fn(string $term): bool => '' !== $term);

        if (empty($fts_terms)) {
            unset($fts_terms);
            return [];
        }

        $fts_query = ('or' === $mode)
            ? implode(' OR ', $fts_terms)
            : implode(' AND ', $fts_terms);

        $level_placeholders = implode(',', array_fill(0, count($levels), '?'));

        $sql = "SELECT m.role, m.content
                FROM messages_fts fts
                JOIN messages m ON m.id = fts.rowid
                WHERE messages_fts MATCH ?
                  AND m.level IN ({$level_placeholders})";

        $bind = array_merge([$fts_query], $levels);
        $sql  .= $this->buildDateFilter($levels, $start_date, $end_date, $bind, 'm.');
        $sql  .= ' ORDER BY m.created_at DESC';

        try {
            $stmt = $this->libPDO->pdo->prepare($sql);
            $stmt->execute($bind);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            unset($fts_terms, $fts_query, $level_placeholders, $sql, $bind, $stmt);
            if (!empty($result)) {
                return $result;
            }
        } catch (\Throwable) {
            unset($fts_terms, $fts_query, $level_placeholders, $sql, $bind);
        }

        return $this->searchLike($levels, $keywords, $mode, $start_date, $end_date);
    }

    private function searchLike(array $levels, array $keywords, string $mode, string $start_date, string $end_date): array
    {
        $like_clauses = [];
        $like_binds   = [];

        foreach ($keywords as $kw) {
            $like_clauses[] = 'LOWER(content) LIKE ?';
            $like_binds[]   = '%' . strtolower($kw) . '%';
        }

        $glue         = ('or' === $mode) ? ' OR ' : ' AND ';
        $content_expr = '(' . implode($glue, $like_clauses) . ')';

        $level_placeholders = implode(',', array_fill(0, count($levels), '?'));

        $sql = "SELECT role, content
                FROM messages
                WHERE {$content_expr}
                  AND level IN ({$level_placeholders})";

        $bind = array_merge($like_binds, $levels);
        $sql  .= $this->buildDateFilter($levels, $start_date, $end_date, $bind, '');
        $sql  .= ' ORDER BY created_at DESC';

        try {
            $stmt = $this->libPDO->pdo->prepare($sql);
            $stmt->execute($bind);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            unset($like_clauses, $like_binds, $kw, $glue, $content_expr, $level_placeholders, $sql, $bind, $stmt);
            return $result;
        } catch (\Throwable) {
            unset($like_clauses, $like_binds, $kw, $glue, $content_expr, $level_placeholders, $sql, $bind);
            return [];
        }
    }

    private function matchKeywords(string $haystack, array $keywords_lower, string $mode): bool
    {
        if ('or' === $mode) {
            foreach ($keywords_lower as $kw) {
                if (str_contains($haystack, $kw)) {
                    unset($haystack, $keywords_lower, $mode, $kw);
                    return true;
                }
            }
            unset($haystack, $keywords_lower, $mode);
            return false;
        }

        foreach ($keywords_lower as $kw) {
            if (!str_contains($haystack, $kw)) {
                unset($haystack, $keywords_lower, $mode, $kw);
                return false;
            }
        }
        unset($haystack, $keywords_lower, $mode);
        return true;
    }

    // =========================================================================
    //  Memory Delete
    // =========================================================================

    private function deleteRAM(array $kw_list, string $mode, int $start_time, int $end_time): int
    {
        $before_count = count($this->ram_memory);

        $this->ram_memory = array_values(array_filter($this->ram_memory, function (array $entry) use ($kw_list, $mode, $start_time, $end_time): bool
        {
            $entry_time = $entry['created_at'] ?? 0;
            if (0 !== $start_time && $entry_time < $start_time) {
                return true;
            }
            if (0 !== $end_time && $entry_time > $end_time) {
                return true;
            }
            if (empty($kw_list)) {
                return false;
            }
            $haystack = strtolower($entry['role'] . ' ' . $entry['content']);
            if ('or' === $mode) {
                foreach ($kw_list as $kw) {
                    if (str_contains($haystack, $kw)) {
                        return false;
                    }
                }
                return true;
            }
            foreach ($kw_list as $kw) {
                if (!str_contains($haystack, $kw)) {
                    return true;
                }
            }
            return false;
        }));

        return $before_count - count($this->ram_memory);
    }

    private function deleteFromDB(string $level, array $kw_list, string $mode, string $start_date, string $end_date, int $start_time, int $end_time): int
    {
        $conditions = [['level', '=', $level]];

        if ('daily' === $level) {
            if ('' !== $start_date && '' !== $end_date) {
                $conditions[] = ['date_key', '>=', $start_date];
                $conditions[] = ['date_key', '<=', $end_date];
            } elseif ('' !== $start_date) {
                $conditions[] = ['date_key', '>=', $start_date];
            } elseif ('' !== $end_date) {
                $conditions[] = ['date_key', '<=', $end_date];
            }
        }

        if (0 !== $start_time) {
            $conditions[] = ['created_at', '>=', $start_time];
        }
        if (0 !== $end_time) {
            $conditions[] = ['created_at', '<=', $end_time];
        }

        // If no keywords, delete using QueryBuilder
        if (empty($kw_list)) {
            $count = (int)($this->db->table('messages')
                ->select($this->db->useSql('COUNT(*) AS cnt'))
                ->where(...$conditions)
                ->fetch()['cnt'] ?? 0);
            if (0 === $count) {
                return 0;
            }
            $this->db->table('messages')
                ->where(...$conditions)
                ->delete()
                ->execute();
            return $count;
        }

        // With keywords: build manual SQL with LIKE conditions
        $like_clauses = [];
        $like_binds   = [];

        foreach ($kw_list as $kw) {
            $like_clauses[] = 'LOWER(content) LIKE ?';
            $like_binds[]   = '%' . $kw . '%';
        }

        $glue         = ('or' === $mode) ? ' OR ' : ' AND ';
        $content_expr = '(' . implode($glue, $like_clauses) . ')';

        $sql  = "DELETE FROM messages WHERE level = ? AND {$content_expr}";
        $bind = array_merge([$level], $like_binds);

        if ('daily' === $level) {
            if ('' !== $start_date && '' !== $end_date) {
                $sql    .= ' AND date_key >= ? AND date_key <= ?';
                $bind[] = $start_date;
                $bind[] = $end_date;
            } elseif ('' !== $start_date) {
                $sql    .= ' AND date_key >= ?';
                $bind[] = $start_date;
            } elseif ('' !== $end_date) {
                $sql    .= ' AND date_key <= ?';
                $bind[] = $end_date;
            }
        }

        if (0 !== $start_time) {
            $sql    .= ' AND created_at >= ?';
            $bind[] = $start_time;
        }
        if (0 !== $end_time) {
            $sql    .= ' AND created_at <= ?';
            $bind[] = $end_time;
        }

        try {
            $stmt = $this->libPDO->pdo->prepare($sql);
            $stmt->execute($bind);
            $deleted = (int)$stmt->rowCount();
            unset($like_clauses, $like_binds, $kw, $glue, $content_expr, $sql, $bind, $stmt);
            return $deleted;
        } catch (\Throwable) {
            unset($like_clauses, $like_binds, $kw, $glue, $content_expr, $sql, $bind);
            return 0;
        }
    }
}