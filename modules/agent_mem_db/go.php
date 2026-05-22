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

namespace modules\agent_mem_db;

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
    private bool   $db_ready    = false;
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
    }

    // =========================================================================
    //  Database Initialization
    // =========================================================================

    /**
     * Initialize database connection, create tables and indexes
     *
     * @return void
     * @throws \ReflectionException
     */
    private function initDB(): void
    {
        if (true === $this->db_ready) {
            return;
        }

        if (!is_dir($this->db_path)) {
            mkdir($this->db_path, 0777, true);
        }

        $db_file      = $this->db_path . 'agent_memory.db';
        $this->libPDO = libPDO::new('sqlite', $db_file);
        $this->libPDO->connect();

        $this->db = libSQLite::new();
        $this->db->bindLibPdo($this->libPDO);
        $this->db->autoCleanup();

        $this->createSchema();
        $this->initFTS();

        $this->db_ready = true;
    }

    /**
     * Create main tables and indexes
     *
     * @return void
     */
    private function createSchema(): void
    {
        $this->db->exec(self::DDL_MESSAGES);
        $this->db->exec(self::DDL_TASKS);

        foreach (self::DDL_INDEXES as $index_sql) {
            $this->db->exec($index_sql);
        }

        unset($index_sql);
    }

    /**
     * Initialize FTS5 virtual table with triggers and backfill
     *
     * @return void
     */
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

    /**
     * Add or update a scheduled task
     *
     * @param string $task_id
     * @param string $task_prompt
     * @param int    $run_at
     * @param bool   $repeat
     * @param int    $repeat_interval
     *
     * @return array
     * @throws \ReflectionException
     */
    public function addTask(string $task_id, string $task_prompt, int $run_at, bool $repeat = false, int $repeat_interval = 0): array
    {
        // Auto-correct past run_at to current time
        $now = time();
        if ($run_at < $now) {
            $run_at = $now;
        }
        unset($now);

        // Validate repeat_interval when repeat is enabled
        if ($repeat && 0 >= $repeat_interval) {
            unset($task_id, $task_prompt, $run_at, $repeat, $repeat_interval);
            return ['error' => 'repeat_interval must be a positive integer when repeat is enabled'];
        }

        try {
            $this->initDB();

            $this->db->setTableOnce('tasks')
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

    /**
     * Remove a scheduled task
     *
     * @param string $task_id
     *
     * @return array
     * @throws \ReflectionException
     */
    public function removeTask(string $task_id): array
    {
        try {
            $this->initDB();

            $exists = $this->db->setTableOnce('tasks')
                ->select('task_id')
                ->where(['task_id', '=', $task_id])
                ->fetch();

            if (empty($exists)) {
                unset($exists, $task_id);
                return ['success' => false, 'message' => 'Task not found.'];
            }

            $this->db->setTableOnce('tasks')
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

    /**
     * List all scheduled tasks
     *
     * @return array
     * @throws \ReflectionException
     */
    public function listTasks(): array
    {
        $this->initDB();

        $tasks = $this->db->setTableOnce('tasks')
            ->select('task_id', 'task_prompt', 'run_at', 'repeat', 'repeat_interval', 'created_at')
            ->fetchAll();

        foreach ($tasks as &$task) {
            $task['repeat'] = (bool)$task['repeat'];
        }

        unset($task);
        return $tasks;
    }

    /**
     * Run due tasks with transaction protection
     * Uses SQL WHERE to filter directly instead of loading all tasks into PHP
     *
     * @return array
     * @throws \ReflectionException
     */
    public function runTask(): array
    {
        $this->initDB();

        $now       = time();
        $task_runs = [];

        // Fetch only due tasks directly via SQL
        $due_tasks = $this->db->setTableOnce('tasks')
            ->select('task_id', 'task_prompt', 'run_at', 'repeat', 'repeat_interval', 'created_at')
            ->where(['run_at', '<=', $now])
            ->fetchAll();

        if (empty($due_tasks)) {
            unset($now, $due_tasks);
            return [];
        }

        $this->db->begin();

        try {
            foreach ($due_tasks as $task) {
                $is_repeat = (bool)$task['repeat'];

                $task_runs[] = [
                    'task_id'         => $task['task_id'],
                    'task_prompt'     => $task['task_prompt'],
                    'run_at'          => (int)$task['run_at'],
                    'repeat'          => $is_repeat,
                    'repeat_interval' => (int)$task['repeat_interval'],
                ];

                if (true === $is_repeat) {
                    // Catch up: ensure next run is always in the future
                    $new_run_at = (int)$task['run_at'] + (int)$task['repeat_interval'];
                    while ($now >= $new_run_at) {
                        $new_run_at += (int)$task['repeat_interval'];
                    }

                    $this->db->setTableOnce('tasks')
                        ->where(['task_id', '=', $task['task_id']])
                        ->update(['run_at' => $new_run_at])
                        ->execute();

                    unset($new_run_at);
                } else {
                    $this->db->setTableOnce('tasks')
                        ->where(['task_id', '=', $task['task_id']])
                        ->delete()
                        ->execute();
                }

                unset($is_repeat);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        unset($now, $due_tasks, $task);
        return $task_runs;
    }

    // =========================================================================
    //  Memory CRUD
    // =========================================================================

    /**
     * Clean old tool call pairs after summarizing them into memory.
     * This tool should be called by LLM when context becomes too long.
     *
     * @param string $summary     Summary of the old tool calls (generated by LLM)
     * @param string $level       Memory level: 'daily' or 'important' (default 'daily')
     * @param int    $keep_recent Number of most recent tool call pairs to retain (default 5)
     *
     * @return array
     * @throws \ReflectionException
     */
    public function cleanContext(string $summary, string $level = 'daily', int $keep_recent = 5): array
    {
        $this->save($level, 'assistant', '工具调用摘要: ' . $summary);

        $keep_recent  = max(0, $keep_recent);
        $curr_history = $this->core->getSessionHistory();

        if (empty($curr_history)) {
            return ['status' => 'error', 'message' => 'No session history to clean'];
        }

        $tool_pairs = [];
        $total      = count($curr_history);
        $i          = 0;

        while ($i < $total) {
            $msg  = $curr_history[$i];
            $role = $msg['role'] ?? '';

            if ('assistant' === $role && !empty($msg['tool_calls'])) {
                $start     = $i;
                $pair_data = [$msg];
                ++$i;

                while ($i < $total && 'tool' === ($curr_history[$i]['role'] ?? '')) {
                    $pair_data[] = $curr_history[$i];
                    ++$i;
                }

                $tool_pairs[] = [
                    'start' => $start,
                    'end'   => $i - 1,
                    'data'  => $pair_data,
                ];
            } else {
                ++$i;
            }
        }

        $total_pairs = count($tool_pairs);
        $keep_from   = max(0, $total_pairs - $keep_recent);

        $final = [];
        $idx   = 0;
        $i     = 0;

        while ($i < $total) {
            if ($idx < $total_pairs && $i === $tool_pairs[$idx]['start']) {
                if ($idx >= $keep_from) {
                    array_push($final, ...$tool_pairs[$idx]['data']);
                }

                $i = $tool_pairs[$idx]['end'] + 1;
                ++$idx;
            } else {
                $final[] = $curr_history[$i];
                ++$i;
            }
        }

        $new_count = count($final);

        $this->core->session_history = $final;

        $result = [
            'status'     => 'success',
            'message'    => 'Cleaned ' . ($total - $new_count) . ' old tool call messages. Summary stored to ' . $level . '. Total remained messages:  ' . $new_count . '.',
            'tool_pairs' => min($total_pairs, $keep_recent)
        ];

        unset($summary, $level, $keep_recent, $curr_history, $total, $tool_pairs, $i, $msg, $role, $pair_data, $total_pairs, $keep_from, $final, $idx, $new_count);
        return $result;
    }

    /**
     * Save a memory entry
     *
     * @param string $level system|important|daily|ram
     * @param string $role  user|assistant|system|tool
     * @param string $content
     *
     * @return array
     * @throws \ReflectionException
     */
    public function save(string $level, string $role, string $content): array
    {
        if (!in_array($level, self::LEVELS, true)) {
            unset($role, $content);
            return ['error' => "Invalid level: {$level}"];
        }

        // Reject empty content to prevent storing meaningless entries
        if ('' === $content) {
            unset($level, $role);
            return ['error' => 'Content cannot be empty'];
        }

        // RAM: in-memory array (validate role before storing)
        if ('ram' === $level) {
            if (!in_array($role, self::ROLES, true)) {
                unset($level, $content);
                return ['error' => "Invalid role: {$role}"];
            }

            $this->ram_memory[] = ['role' => $role, 'content' => $content, 'created_at' => time()];

            $result = ['saved' => true, 'path' => 'ram://memory', 'role' => $role];
            unset($level, $role, $content);
            return $result;
        }

        // Force role for system/important levels (role is deterministic, no validation needed)
        if ('system' === $level) {
            $role = 'system';
        } elseif ('important' === $level) {
            $role = 'user';
        } elseif (!in_array($role, self::ROLES, true)) {
            // Only validate role for daily level
            unset($level, $content);
            return ['error' => "Invalid role: {$role}"];
        }

        $this->initDB();

        $date_key = ('daily' === $level) ? date('Ymd') : '';

        $this->db->setTableOnce('messages')
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

    /**
     * Read memory entries with pagination
     *
     * @param string $level  system|important|daily|ram
     * @param int    $offset Zero-based offset
     * @param int    $length Max entries (0 = all)
     * @param string $date   YYYYMMDD, only for daily layer
     *
     * @return array
     * @throws \ReflectionException
     */
    public function read(string $level, int $offset = 0, int $length = 100, string $date = ''): array
    {
        if (!in_array($level, self::LEVELS, true)) {
            unset($offset, $length, $date);
            return ['error' => "Invalid level: {$level}"];
        }

        // Reject negative offset/length
        if (0 > $offset) {
            unset($level, $length, $date);
            return ['error' => 'offset must be >= 0, got: ' . $offset];
        }
        if (0 > $length) {
            unset($level, $offset, $date);
            return ['error' => 'length must be >= 0, got: ' . $length];
        }

        // RAM: in-memory
        if ('ram' === $level) {
            $total    = count($this->ram_memory);
            $messages = array_slice($this->ram_memory, $offset, (0 === $length) ? $total : $length);

            unset($level, $offset, $length, $date);
            return ['messages' => $messages, 'total' => $total];
        }

        $this->initDB();

        // Build WHERE conditions (use index array format for libSQLite parseCond)
        $conditions = [['level', '=', $level]];

        if ('daily' === $level) {
            $date_val     = ('' !== $date) ? $date : date('Ymd');
            $conditions[] = ['date_key', '=', $date_val];
            unset($date_val);
        }

        // Get total count
        $total = (int)($this->db->setTableOnce('messages')
            ->select($this->db->useSql('COUNT(*) AS cnt'))
            ->where(...$conditions)
            ->fetch()['cnt'] ?? 0);

        // Fetch data
        $messages = [];

        if (0 < $total) {
            $limit    = (0 === $length) ? $total : $length;
            $messages = $this->db->setTableOnce('messages')
                ->select('role', 'content')
                ->where(...$conditions)
                ->order(['id' => 'ASC'])
                ->limit($offset, $limit)
                ->fetchAll();

            unset($limit);
        }

        unset($level, $offset, $length, $date, $conditions);
        return ['messages' => $messages, 'total' => $total];
    }

    /**
     * Search memory with keyword matching
     * Uses FTS5 when available, falls back to LIKE with parameter binding
     *
     * @param string $level      system|important|daily|ram|all
     * @param array  $keywords   Keywords to search
     * @param int    $offset     Pagination offset
     * @param int    $length     Max results (0 = all)
     * @param string $mode       or|and
     * @param string $start_date YYYYMMDD
     * @param string $end_date   YYYYMMDD
     *
     * @return array
     * @throws \ReflectionException
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

        // Reject negative offset/length
        if (0 > $offset) {
            unset($level, $keywords, $length, $mode, $start_date, $end_date);
            return ['error' => 'offset must be >= 0, got: ' . $offset];
        }
        if (0 > $length) {
            unset($level, $keywords, $offset, $mode, $start_date, $end_date);
            return ['error' => 'length must be >= 0, got: ' . $length];
        }

        // Auto-correct reversed date range (swap start and end)
        if ('' !== $start_date && '' !== $end_date && $start_date > $end_date) {
            [$start_date, $end_date] = [$end_date, $start_date];
        }

        $this->initDB();

        $levels_to_search = ('all' === $level) ? self::LEVELS : [$level];

        // Separate RAM from DB layers
        $ram_results = [];
        $db_levels   = [];

        foreach ($levels_to_search as $lv) {
            if ('ram' === $lv) {
                $ram_results = $this->searchRAM($keywords, $mode);
            } else {
                $db_levels[] = $lv;
            }
        }

        // Search DB layers
        $db_results = [];

        if (!empty($db_levels)) {
            $db_results = $this->fts_enabled
                ? $this->searchFTS($db_levels, $keywords, $mode, $start_date, $end_date)
                : $this->searchLike($db_levels, $keywords, $mode, $start_date, $end_date);
        }

        // Merge: RAM first, then DB (newest first from DB)
        $all_results = array_merge($ram_results, $db_results);
        $total       = count($all_results);
        $limit       = (0 === $length) ? $total : $length;
        $messages    = array_slice($all_results, $offset, $limit);

        unset($level, $keywords, $offset, $length, $mode, $start_date, $end_date);
        unset($levels_to_search, $lv, $ram_results, $db_levels, $db_results);
        unset($all_results, $limit);
        return ['messages' => $messages, 'total' => $total];
    }

    // =========================================================================
    //  Search Internals
    // =========================================================================

    /**
     * Sanitize keyword for FTS5 query safety
     *
     * Wraps keyword in double quotes to force exact phrase matching in FTS5.
     * This prevents FTS5 from interpreting the keyword as a query operator
     * (AND, OR, NOT, NEAR, *, etc.) without destroying the keyword content.
     * Only strips embedded double quotes to avoid breaking the FTS5 syntax.
     *
     * @param string $keyword
     *
     * @return string Quoted FTS5 term, or empty string if keyword is empty after trim
     */
    private function sanitizeFTSKeyword(string $keyword): string
    {
        $keyword = trim($keyword);

        if ('' === $keyword) {
            return '';
        }

        // Strip embedded double quotes to avoid breaking FTS5 phrase syntax
        $keyword = str_replace('"', '', $keyword);

        // Wrap in double quotes for exact phrase matching (prevents operator interpretation)
        $keyword = '"' . $keyword . '"';

        return $keyword;
    }

    /**
     * Search RAM memory (in-memory array)
     *
     * @param array  $keywords
     * @param string $mode
     *
     * @return array
     */
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

    /**
     * Build date range filter for mixed-level queries
     * Non-daily levels bypass date filter; daily level applies date_key range
     *
     * @param array  $levels      Levels being searched
     * @param string $start_date  YYYYMMDD
     * @param string $end_date    YYYYMMDD
     * @param array  $bind        Bind values array (passed by reference)
     * @param string $table_alias Table alias prefix (e.g. 'm.' for JOIN queries, '' for single-table)
     *
     * @return string SQL fragment (empty if no date filter needed)
     */
    private function buildDateFilter(array $levels, string $start_date, string $end_date, array &$bind, string $table_alias = ''): string
    {
        $sql = '';

        if (!in_array('daily', $levels, true)) {
            unset($levels, $start_date, $end_date, $table_alias);
            return $sql;
        }

        if ('' === $start_date && '' === $end_date) {
            unset($levels, $start_date, $end_date, $table_alias);
            return $sql;
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

    /**
     * Search via FTS5 (fast path)
     *
     * @param array  $levels
     * @param array  $keywords
     * @param string $mode
     * @param string $start_date
     * @param string $end_date
     *
     * @return array
     * @throws \ReflectionException
     */
    private function searchFTS(array $levels, array $keywords, string $mode, string $start_date, string $end_date): array
    {
        // Build FTS5 query: sanitized keywords joined by OR or AND
        $fts_terms = array_map([$this, 'sanitizeFTSKeyword'], $keywords);

        // Filter out empty terms after sanitization
        $fts_terms = array_filter($fts_terms, fn(string $term): bool => '' !== $term);

        if (empty($fts_terms)) {
            unset($fts_terms);
            return [];
        }

        $fts_query = ('or' === $mode)
            ? implode(' OR ', $fts_terms)
            : implode(' AND ', $fts_terms);

        // Build SQL with FTS5 match + level/date filters
        $level_placeholders = implode(',', array_fill(0, count($levels), '?'));

        $sql = "SELECT m.role, m.content
                 FROM messages_fts fts
                 JOIN messages m ON m.id = fts.rowid
                 WHERE messages_fts MATCH ?
                   AND m.level IN ({$level_placeholders})";

        $bind = array_merge([$fts_query], $levels);

        // Date range filter for daily layer
        $sql .= $this->buildDateFilter($levels, $start_date, $end_date, $bind, 'm.');
        $sql .= ' ORDER BY m.created_at DESC';

        try {
            $stmt = $this->libPDO->pdo->prepare($sql);
            $stmt->execute($bind);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            unset($fts_terms, $fts_query, $level_placeholders, $sql, $bind, $stmt);

            if (!empty($result)) {
                return $result;
            }

            // FTS5 returned empty (e.g. CJK tokenization issue), fallback to LIKE
        } catch (\Throwable) {
            // FTS query failed, fallback to LIKE
            unset($fts_terms, $fts_query, $level_placeholders, $sql, $bind);
        }

        // Fallback: use LIKE when FTS5 returns empty or throws
        return $this->searchLike($levels, $keywords, $mode, $start_date, $end_date);
    }

    /**
     * Search via LIKE with parameter binding (safe fallback)
     *
     * @param array  $levels
     * @param array  $keywords
     * @param string $mode
     * @param string $start_date
     * @param string $end_date
     *
     * @return array
     * @throws \ReflectionException
     */
    private function searchLike(array $levels, array $keywords, string $mode, string $start_date, string $end_date): array
    {
        // Build LIKE conditions with parameter binding
        $like_clauses = [];
        $like_binds   = [];

        foreach ($keywords as $kw) {
            $like_clauses[] = 'LOWER(content) LIKE ?';
            $like_binds[]   = '%' . strtolower($kw) . '%';
        }

        $glue         = ('or' === $mode) ? ' OR ' : ' AND ';
        $content_expr = '(' . implode($glue, $like_clauses) . ')';

        // Level filter
        $level_placeholders = implode(',', array_fill(0, count($levels), '?'));

        $sql = "SELECT role, content
                 FROM messages
                 WHERE {$content_expr}
                   AND level IN ({$level_placeholders})";

        $bind = array_merge($like_binds, $levels);

        // Date range filter for daily layer
        $sql .= $this->buildDateFilter($levels, $start_date, $end_date, $bind, '');
        $sql .= ' ORDER BY created_at DESC';

        try {
            $stmt = $this->libPDO->pdo->prepare($sql);
            $stmt->execute($bind);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            unset($like_clauses, $like_binds, $kw, $glue, $content_expr);
            unset($level_placeholders, $sql, $bind, $stmt);
            return $result;
        } catch (\Throwable) {
            unset($like_clauses, $like_binds, $kw, $glue, $content_expr);
            unset($level_placeholders, $sql, $bind);
            return [];
        }
    }

    /**
     * Check if haystack matches keywords under given mode
     *
     * @param string $haystack       Lowercased text
     * @param array  $keywords_lower Lowercased keywords
     * @param string $mode           or|and
     *
     * @return bool
     */
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

        // AND mode
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
     * @param string $start_date YYYYMMDD (only for daily layer date_key filter)
     * @param string $end_date   YYYYMMDD (only for daily layer date_key filter)
     * @param int    $start_time Unix timestamp lower bound (0 = no limit)
     * @param int    $end_time   Unix timestamp upper bound (0 = no limit)
     *
     * @return array ['deleted' => N] or ['error' => ...]
     * @throws \ReflectionException
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
                $total_deleted += $this->deleteFromDB($lv, $kw_list, $mode, $start_date, $end_date, $start_time, $end_time);
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
     * Delete matching entries from database (system/important/daily)
     * Uses SQL WHERE with parameter binding for safe deletion
     *
     * @param string $level      system|important|daily
     * @param array  $kw_list    Lowercased keywords
     * @param string $mode       or|and
     * @param string $start_date YYYYMMDD (for daily date_key filter)
     * @param string $end_date   YYYYMMDD (for daily date_key filter)
     * @param int    $start_time Unix timestamp lower bound (0 = no limit)
     * @param int    $end_time   Unix timestamp upper bound (0 = no limit)
     *
     * @return int Number of deleted entries
     * @throws \ReflectionException
     */
    private function deleteFromDB(string $level, array $kw_list, string $mode, string $start_date, string $end_date, int $start_time, int $end_time): int
    {
        $this->initDB();

        // Build WHERE conditions
        $conditions = [['level', '=', $level]];

        // Date range filter for daily layer
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

        // Time range filter
        if (0 !== $start_time) {
            $conditions[] = ['created_at', '>=', $start_time];
        }
        if (0 !== $end_time) {
            $conditions[] = ['created_at', '<=', $end_time];
        }

        // If no keywords, delete by conditions directly
        if (empty($kw_list)) {
            // First count, then delete
            $count = (int)($this->db->setTableOnce('messages')
                ->select($this->db->useSql('COUNT(*) AS cnt'))
                ->where(...$conditions)
                ->fetch()['cnt'] ?? 0);

            if (0 === $count) {
                return 0;
            }

            $this->db->setTableOnce('messages')
                ->where(...$conditions)
                ->delete()
                ->execute();

            return $count;
        }

        // With keywords: build LIKE conditions with parameter binding
        $like_clauses = [];
        $like_binds   = [];

        foreach ($kw_list as $kw) {
            $like_clauses[] = 'LOWER(content) LIKE ?';
            $like_binds[]   = '%' . $kw . '%';
        }

        $glue         = ('or' === $mode) ? ' OR ' : ' AND ';
        $content_expr = '(' . implode($glue, $like_clauses) . ')';

        // Build full SQL with manual PDO binding (libSQLite doesn't support raw SQL in WHERE for LIKE)
        $sql  = "DELETE FROM messages WHERE level = ? AND {$content_expr}";
        $bind = array_merge([$level], $like_binds);

        // Add date range filter for daily
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

        // Add time range filter
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
            $deleted = $stmt->rowCount();

            unset($like_clauses, $like_binds, $kw, $glue, $content_expr);
            unset($sql, $bind, $stmt);
            return (int)$deleted;
        } catch (\Throwable) {
            unset($like_clauses, $like_binds, $kw, $glue, $content_expr);
            unset($sql, $bind);
            return 0;
        }
    }
}