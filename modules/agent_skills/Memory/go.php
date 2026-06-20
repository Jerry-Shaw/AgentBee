<?php

/**
 * Memory module for AgentBee - Industrial Refactored Version
 *
 * This module provides high-efficiency web data acquisition tools for Agents,
 * focusing on noise reduction and structural extraction to optimize LLM token usage.
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

namespace modules\agent_skills\Memory;

use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Ext\libPDO;
use Nervsys\Ext\libSQLite;

class go extends Factory
{
    public utils     $utils;
    public libPDO    $libPDO;
    public libSQLite $libSQLite;

    private string $db_path;
    private bool   $fts_enabled = true;

    private const LEVELS       = ['system', 'important', 'daily', 'misc'];
    private const ROLES        = ['user', 'assistant', 'system', 'tool'];
    private const ALL_LEVELS   = ['system', 'important', 'daily', 'misc', 'all'];
    private const MISC_TTL_SEC = 259200; // 3 days

    private const DDL_MEMORY = '
        CREATE TABLE IF NOT EXISTS agent_memory (
            create_id INTEGER PRIMARY KEY,
            expire_at INTEGER DEFAULT 0,
            date_key  INTEGER NOT NULL,
            level     TEXT NOT NULL,
            role      TEXT NOT NULL,
            content   TEXT NOT NULL
        )';

    private const DDL_TASK = '
        CREATE TABLE IF NOT EXISTS agent_task (
            create_id INTEGER PRIMARY KEY,
            run_at    INTEGER NOT NULL,
            repeat    INTEGER DEFAULT 0,
            interval  INTEGER DEFAULT 0,
            prompt    TEXT NOT NULL
        )';

    private const DDL_INDEXES = [
        'CREATE INDEX IF NOT EXISTS idx_mem_level ON agent_memory(level)',
        'CREATE INDEX IF NOT EXISTS idx_mem_date ON agent_memory(date_key)',
        'CREATE INDEX IF NOT EXISTS idx_mem_lvl_date ON agent_memory(level, date_key)',
        'CREATE INDEX IF NOT EXISTS idx_mem_expire ON agent_memory(expire_at)',
        'CREATE INDEX IF NOT EXISTS idx_task_runat ON agent_task(run_at)',
    ];

    private const DDL_FTS = '
    CREATE VIRTUAL TABLE IF NOT EXISTS agent_memory_fts USING fts5(
        content,
        level,
        role,
        content=\'agent_memory\',
        content_rowid=\'create_id\',
        tokenize=\'unicode61\'
    )';

    private const DDL_FTS_TRIGGERS = [
        'CREATE TRIGGER IF NOT EXISTS memory_insert AFTER INSERT ON agent_memory BEGIN
            INSERT INTO agent_memory_fts(rowid, content, level, role) VALUES (new.create_id, new.content, new.level, new.role); END',
        'CREATE TRIGGER IF NOT EXISTS memory_delete AFTER DELETE ON agent_memory BEGIN
            INSERT INTO agent_memory_fts(agent_memory_fts, rowid, content, level, role) VALUES (\'delete\', old.create_id, old.content, old.level, old.role); END',
        'CREATE TRIGGER IF NOT EXISTS memory_update AFTER UPDATE ON agent_memory BEGIN
            INSERT INTO agent_memory_fts(agent_memory_fts, rowid, content, level, role) VALUES (\'delete\', old.create_id, old.content, old.level, old.role);
            INSERT INTO agent_memory_fts(rowid, content, level, role) VALUES (new.create_id, new.content, new.level, new.role); END'
    ];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->utils   = utils::new();
        $this->db_path = $this->utils->app->root_path . DIRECTORY_SEPARATOR . 'memory' . DIRECTORY_SEPARATOR;

        if (!is_dir($this->db_path)) {
            mkdir($this->db_path, 0777, true);
        }

        $this->initDatabase();
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    private function initDatabase(): void
    {
        $db_file      = $this->db_path . 'agent_memory.db';
        $this->libPDO = libPDO::new('sqlite', $db_file);
        $this->libPDO->connect();

        $this->libSQLite = libSQLite::new();
        $this->libSQLite->bindLibPdo($this->libPDO);
        $this->libSQLite->autoCleanup();

        $this->libSQLite->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
        $this->libSQLite->exec('PRAGMA strict=OFF');
        $this->libSQLite->exec('PRAGMA journal_mode=WAL');
        $this->libSQLite->exec('PRAGMA busy_timeout = 5000');
        $this->libSQLite->exec('PRAGMA cache_size = -4000');

        $this->setupSchema();
        unset($db_file);
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    private function setupSchema(): void
    {
        // Create memory table if not exists
        $this->libSQLite->exec(self::DDL_MEMORY);

        // Create task table if not exists
        $this->libSQLite->exec(self::DDL_TASK);

        // Create indexes if not exists
        foreach (self::DDL_INDEXES as $sql) {
            $this->libSQLite->exec($sql);
        }

        // Create FTS5 virtual table and triggers if not exists (if FTS is available)
        try {
            $this->libSQLite->exec(self::DDL_FTS);

            foreach (self::DDL_FTS_TRIGGERS as $trigger) {
                $this->libSQLite->exec($trigger);
            }

            $this->fts_enabled = true;
        } catch (\Throwable) {
            $this->fts_enabled = false;
        }
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    private function purgeExpired(): void
    {
        $this->libSQLite->table('agent_memory')
            ->where(['expire_at', '>', 0], ['expire_at', '<', time()])
            ->delete()
            ->execute();
    }

    /**
     * @param string $table
     *
     * @return int
     * @throws \ReflectionException
     */
    private function generateMicroTimestamp(string $table): int
    {
        $ts = (int)(microtime(true) * 1000000);

        $exists = $this->libSQLite->table($table)
            ->select('create_id')
            ->where(['create_id', '=', $ts])
            ->fetch();

        while (!empty($exists)) {
            ++$ts;
            $exists = $this->libSQLite->table($table)
                ->select('create_id')
                ->where(['create_id', '=', $ts])
                ->fetch();
        }

        return $ts;
    }

    /**
     * @param int $micro
     *
     * @return string
     */
    private function formatMicroTime(int $micro): string
    {
        return date('Y-m-d H:i:s', (int)($micro / 1000000));
    }

    // =========================================================================
    //  Memory CRUD
    // =========================================================================

    /**
     * @param string $level
     * @param string $role
     * @param string $content
     *
     * @return array
     * @throws \ReflectionException
     */
    public function save(string $level, string $role, string $content): array
    {
        if ('' === trim($content)) {
            return ['status' => 'error', 'error' => 'Empty content'];
        }

        if (!in_array($level, self::LEVELS)) {
            return ['status' => 'error', 'error' => 'Invalid level: ' . $level];
        }

        if ('system' === $level) {
            $role = 'system';
        }

        if (!in_array($role, self::ROLES)) {
            return ['status' => 'error', 'error' => 'Invalid role: ' . $role];
        }

        $create_id = $this->generateMicroTimestamp('agent_memory');
        $now_sec   = time();
        $expire_at = ('misc' === $level) ? ($now_sec + self::MISC_TTL_SEC) : 0;
        $date_key  = (int)date('Ymd');

        $this->libSQLite->table('agent_memory')->insert([
            'create_id' => $create_id,
            'expire_at' => $expire_at,
            'date_key'  => $date_key,
            'level'     => $level,
            'role'      => $role,
            'content'   => $content
        ])->execute();

        $result = ['status' => 'success', 'create_id' => $create_id];

        unset($level, $role, $content, $create_id, $now_sec, $expire_at, $date_key);
        return $result;
    }

    /**
     * @param int    $create_id
     * @param string $role
     * @param string $content
     *
     * @return array
     * @throws \ReflectionException
     */
    public function update(int $create_id, string $role, string $content): array
    {
        if ('' === trim($content)) {
            return ['status' => 'error', 'error' => 'Empty content'];
        }

        if (!in_array($role, self::ROLES)) {
            return ['status' => 'error', 'error' => 'Invalid role: ' . $role];
        }

        $record = $this->libSQLite->table('agent_memory')
            ->select('level')
            ->where(['create_id', '=', $create_id])
            ->fetch();

        if (empty($record)) {
            return ['status' => 'error', 'error' => 'Record not found: ' . $create_id];
        }

        $level = $record['level'];

        if ('system' === $level) {
            $role = 'system';
        }

        $this->libSQLite->table('agent_memory')
            ->where(['create_id', '=', $create_id])
            ->update(['role' => $role, 'content' => $content])
            ->execute();

        unset($create_id, $role, $content, $record, $level);
        return ['status' => 'success', 'affected_rows' => $this->libSQLite->getAffectedRows()];
    }

    /**
     * @param string $level
     * @param int    $offset
     * @param int    $length
     * @param string $date
     *
     * @return array
     * @throws \ReflectionException
     */
    public function read(string $level, int $offset = 0, int $length = 100, string $date = ''): array
    {
        if (!in_array($level, self::ALL_LEVELS)) {
            return ['status' => 'error', 'error' => 'Invalid level: ' . $level];
        }

        $date_int = ('' === $date) ? (int)date('Ymd') : (int)$date;

        $this->purgeExpired();

        $query = $this->libSQLite
            ->table('agent_memory')
            ->select('role', 'content', 'create_id');

        if ('all' !== $level) {
            $query->where(['level', '=', $level]);
        }

        if (in_array($level, ['important', 'daily', 'misc'], true)) {
            $query->where(['date_key', '=', $date_int]);
        }

        $query->order(['create_id' => 'ASC']);

        if (0 < $length) {
            $query->limit($offset, $length);
        }

        $data = $query->fetchAll();

        foreach ($data as &$item) {
            $item['create_time'] = $this->formatMicroTime($item['create_id']);
        }

        $result = ['status' => 'success', 'data' => $data, 'total' => $query->getLastFoundRows()];

        unset($query, $date_int, $data, $item);
        return $result;
    }

    /**
     * @param string $level
     * @param array  $keywords
     * @param string $mode
     * @param int    $offset
     * @param int    $length
     * @param string $start_date
     * @param string $end_date
     *
     * @return array
     * @throws \ReflectionException
     */
    public function search(string $level, array $keywords, string $mode = 'or', int $offset = 0, int $length = 100, string $start_date = '', string $end_date = ''): array
    {
        if (!in_array($level, self::ALL_LEVELS)) {
            return ['status' => 'error', 'error' => 'Invalid level: ' . $level];
        }

        $keywords = array_filter(
            $keywords,
            function (string $word): bool
            {
                return '' !== trim($word);
            }
        );

        if (empty($keywords)) {
            return ['status' => 'success', 'data' => [], 'total' => 0];
        }

        $this->purgeExpired();

        $use_fts = true;

        foreach ($keywords as $word) {
            if (1 === mb_strlen($word, 'UTF-8') && 1 < strlen($word)) {
                $use_fts = false;
                break;
            }

            foreach (['@', '(', ')', '{', '}', '[', ']', '*', '^', '?', '+', '-', '~', '&', '|', '!', '<', '>', '=', '%', '_', '#', '$', '.', '/', ';', ':', '"', "'", "`", '\\'] as $char) {
                if (str_contains($word, $char)) {
                    $use_fts = false;
                    break 2;
                }
            }
        }

        $result = $this->fts_enabled && $use_fts
            ? $this->searchViaFts($level, $keywords, $mode, $offset, $length, $start_date, $end_date)
            : $this->searchViaLike($level, $keywords, $mode, $offset, $length, $start_date, $end_date);

        if (isset($result['data'])) {
            foreach ($result['data'] as &$msg) {
                if (isset($msg['create_id'])) {
                    $msg['create_time'] = $this->formatMicroTime($msg['create_id']);
                }
            }

            unset($msg);
        }

        $result['status'] = 'success';

        unset($level, $keywords, $mode, $offset, $length, $start_date, $end_date, $use_fts, $word, $msg);
        return $result;
    }

    /**
     * @param string $level
     * @param array  $create_ids
     * @param int    $start_time
     * @param int    $end_time
     * @param array  $keywords
     * @param string $mode
     *
     * @return array|string[]
     * @throws \ReflectionException
     */
    public function delete(string $level, array $create_ids = [], int $start_time = 0, int $end_time = 0, array $keywords = [], string $mode = 'or'): array
    {
        if (!in_array($level, self::ALL_LEVELS)) {
            $result = ['status' => 'error', 'error' => 'Invalid level: ' . $level];

            unset($level, $create_ids, $start_time, $end_time, $keywords, $mode);
            return $result;
        }

        if (!empty($create_ids)) {
            $valid_ids = array_filter(
                $create_ids,
                function (int $id): bool
                {
                    return false !== filter_var($id, FILTER_VALIDATE_INT) && 0 < $id;
                }
            );

            $valid_ids = array_map('intval', $valid_ids);

            if (empty($valid_ids)) {
                $result = ['status' => 'error', 'error' => 'Invalid create_ids (must be positive integers)'];
            } else {
                $query = $this->libSQLite->table('agent_memory');

                if ('all' !== $level) {
                    $query->where(['level', '=', $level]);
                }

                $query->where(['create_id', 'IN', $valid_ids])
                    ->delete()
                    ->execute();

                $result = ['status' => 'success', 'deleted' => $this->libSQLite->getAffectedRows()];
            }

            unset($valid_ids);
        } elseif (0 < $start_time || 0 < $end_time || !empty($keywords)) {
            if (0 < $start_time && 0 < $end_time && $start_time > $end_time) {
                $result = ['status' => 'error', 'error' => 'start_time cannot be greater than end_time'];
            } else {
                $query = $this->libSQLite->table('agent_memory');

                if ('all' !== $level) {
                    $query->where(['level', '=', $level]);
                }

                if (0 < $start_time) {
                    $query->where(['create_id', '>=', $start_time * 1000000]);
                }

                if (0 < $end_time) {
                    $query->where(['create_id', '<=', $end_time * 1000000]);
                }

                if (!empty($keywords)) {
                    if ('and' === $mode) {
                        foreach ($keywords as $word) {
                            $query->where(['content', 'LIKE', '%' . $word . '%']);
                        }
                    } else {
                        $conditions = [];

                        foreach ($keywords as $idx => $word) {
                            if (0 === $idx) {
                                $conditions[] = ['content', 'LIKE', '%' . $word . '%'];
                            } else {
                                $conditions[] = ['or', 'content', 'LIKE', '%' . $word . '%'];
                            }
                        }

                        $query->where(...$conditions);

                        unset($conditions);
                    }

                    unset($idx, $word);
                }

                $query->delete()->execute();
                $result = ['status' => 'success', 'deleted' => $this->libSQLite->getAffectedRows()];
            }
        } else {
            $result = ['status' => 'error', 'error' => 'No deletion criteria provided.'];
        }

        unset($level, $create_ids, $start_time, $end_time, $keywords, $mode, $query);
        return $result;
    }

    // =========================================================================
    //  Task Management
    // =========================================================================

    /**
     * @param string $task_prompt
     * @param int    $run_at
     * @param bool   $repeat
     * @param int    $repeat_interval
     *
     * @return array
     * @throws \ReflectionException
     */
    public function addTask(string $task_prompt, int $run_at, bool $repeat = false, int $repeat_interval = 0): array
    {
        $now = time();
        if ($run_at < $now) {
            $run_at = $now;
        }
        if ($repeat && 0 >= $repeat_interval) {
            unset($now);
            return ['status' => 'error', 'error' => 'repeat_interval must be positive when repeat is enabled'];
        }

        $create_id = $this->generateMicroTimestamp('agent_task');
        $this->libSQLite->table('agent_task')->replace([
            'create_id' => $create_id,
            'run_at'    => $run_at,
            'repeat'    => (int)$repeat,
            'interval'  => $repeat_interval,
            'prompt'    => $task_prompt
        ])->execute();

        $result = ['status' => 'success', 'create_id' => $create_id];

        unset($now, $create_id, $run_at, $repeat, $repeat_interval);
        return $result;
    }

    /**
     * @param int $create_id
     *
     * @return array
     * @throws \ReflectionException
     */
    public function removeTask(int $create_id): array
    {
        $this->libSQLite->table('agent_task')->where(['create_id', '=', $create_id])->delete()->execute();
        $affected = $this->libSQLite->getAffectedRows();

        $result = 0 < $affected
            ? ['status' => 'success', 'message' => $affected . ' task(s) were removed.']
            : ['status' => 'error', 'error' => 'Task not found.'];

        unset($create_id, $affected);
        return $result;
    }

    /**
     * @return array
     * @throws \ReflectionException
     */
    public function listTasks(): array
    {
        $tasks = $this->libSQLite->table('agent_task')->select('*')->order(['run_at' => 'ASC'])->fetchAll();

        foreach ($tasks as &$task) {
            $task['run_time']    = date('Y-m-d H:i:s', $task['run_at']);
            $task['create_time'] = date('Y-m-d H:i:s', (int)($task['create_id'] / 1000000));
        }

        unset($task);
        return ['status' => 'success', 'tasks' => $tasks];
    }

    /**
     * @return array
     * @throws \ReflectionException
     */
    public function runTask(): array
    {
        $now = time();

        // Fetch due tasks
        $tasks = $this->libSQLite->table('agent_task')
            ->select('create_id', 'prompt', 'run_at', 'repeat', 'interval')
            ->where(['run_at', '<=', $now])
            ->fetchAll();

        $result = [];

        foreach ($tasks as $task) {
            $result[] = $task['prompt'];

            if ($task['repeat']) {
                // Recurring task: calculate next run time
                $next_run = $task['run_at'] + $task['interval'];

                // Avoid infinite backlog: if next_run still <= now, jump to now + interval
                if ($next_run <= $now) {
                    $next_run = $now + $task['interval'];
                }

                $this->libSQLite->table('agent_task')
                    ->where(['create_id', '=', $task['create_id']])
                    ->update(['run_at' => $next_run])
                    ->execute();
            } else {
                // One-time task: delete it
                $this->libSQLite->table('agent_task')
                    ->where(['create_id', '=', $task['create_id']])
                    ->delete()
                    ->execute();
            }
        }

        unset($now, $tasks, $task, $next_run);
        return $result;
    }

    // =========================================================================
    //  Internal Helpers
    // =========================================================================

    /**
     * @param string $level
     * @param array  $keywords
     * @param string $mode
     * @param int    $offset
     * @param int    $length
     * @param string $start_date
     * @param string $end_date
     *
     * @return array
     * @throws \ReflectionException
     */
    private function searchViaFts(string $level, array $keywords, string $mode, int $offset, int $length, string $start_date, string $end_date): array
    {
        $keywords = array_map(
            function (string $word): string
            {
                return in_array(strtoupper($word), ['AND', 'OR', 'NOT'], true) ? '"' . str_replace('"', '""', $word) . '"' : $word;
            },
            $keywords
        );

        $kw_string = ('and' === strtolower($mode))
            ? implode(' AND ', $keywords)
            : implode(' OR ', $keywords);

        $start_date_int = ('' !== $start_date) ? (int)$start_date : 0;
        $end_date_int   = ('' !== $end_date) ? (int)$end_date : 0;

        $query = $this->libSQLite
            ->table('agent_memory')
            ->join('agent_memory_fts', 'INNER')
            ->on(['agent_memory.create_id', '=', 'agent_memory_fts.rowid'])
            ->select('agent_memory.role', 'agent_memory.content', 'agent_memory.create_id');

        if ('all' !== $level) {
            $query->where(['agent_memory.level', '=', $level]);
        }

        if (0 !== $start_date_int && 0 !== $end_date_int) {
            $query->where(['agent_memory.date_key', 'BETWEEN', [$start_date_int, $end_date_int]]);
        } elseif (0 !== $start_date_int) {
            $query->where(['agent_memory.date_key', '>=', $start_date_int]);
        } elseif (0 !== $end_date_int) {
            $query->where(['agent_memory.date_key', '<=', $end_date_int]);
        }

        $query->match(['agent_memory_fts', $kw_string])->order(['agent_memory.create_id' => 'ASC']);

        if (0 < $length) {
            $query->limit($offset, $length);
        }

        $data  = $query->fetchAll();
        $total = $query->getLastFoundRows();

        unset($level, $keywords, $mode, $offset, $length, $start_date, $end_date, $kw_string, $start_date_int, $end_date_int, $query);
        return ['data' => $data, 'total' => $total];
    }

    /**
     * @param string $level
     * @param array  $keywords
     * @param string $mode
     * @param int    $offset
     * @param int    $length
     * @param string $start_date
     * @param string $end_date
     *
     * @return array
     * @throws \ReflectionException
     */
    private function searchViaLike(string $level, array $keywords, string $mode, int $offset, int $length, string $start_date, string $end_date): array
    {
        $query = $this->libSQLite
            ->table('agent_memory')
            ->select('role', 'content', 'create_id');

        if ('all' !== $level) {
            $query->where(['level', '=', $level]);
        }

        $start_date_int = ('' !== $start_date) ? (int)$start_date : 0;
        $end_date_int   = ('' !== $end_date) ? (int)$end_date : 0;

        if (0 !== $start_date_int && 0 !== $end_date_int) {
            $query->where(['date_key', 'BETWEEN', [$start_date_int, $end_date_int]]);
        } elseif (0 !== $start_date_int) {
            $query->where(['date_key', '>=', $start_date_int]);
        } elseif (0 !== $end_date_int) {
            $query->where(['date_key', '<=', $end_date_int]);
        }

        if (!empty($keywords)) {
            if ('and' === $mode) {
                foreach ($keywords as $kw) {
                    $query->where(['content', 'LIKE', '%' . $kw . '%']);
                }
            } else {
                $conditions = [];
                foreach ($keywords as $idx => $kw) {
                    if (0 === $idx) {
                        $conditions[] = ['content', 'LIKE', '%' . $kw . '%'];
                    } else {
                        $conditions[] = ['or', 'content', 'LIKE', '%' . $kw . '%'];
                    }
                }
                $query->where(...$conditions);
                unset($conditions);
            }
        }

        $query->order(['create_id' => 'ASC'])->limit($offset, $length);

        $data   = $query->fetchAll();
        $result = ['data' => $data, 'total' => $query->getLastFoundRows()];

        unset($query, $data, $keywords, $mode, $offset, $length, $start_date_int, $end_date_int);
        return $result;
    }
}