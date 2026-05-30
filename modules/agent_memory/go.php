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

namespace modules\agent_memory;

use modules\agent_core\core;
use Nervsys\Core\Factory;
use Nervsys\Ext\libPDO;
use Nervsys\Ext\libSQLite;

class go extends Factory
{
    public core      $core;
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
            create_at INTEGER PRIMARY KEY,
            expire_at INTEGER DEFAULT 0,
            date_key  INTEGER NOT NULL,
            level     TEXT NOT NULL,
            role      TEXT NOT NULL,
            content   TEXT NOT NULL
        )';

    private const DDL_TASK = '
        CREATE TABLE IF NOT EXISTS agent_task (
            create_at INTEGER PRIMARY KEY,
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
            content_rowid=\'create_at\'
        )';

    private const DDL_FTS_TRIGGERS = [
        'CREATE TRIGGER IF NOT EXISTS mem_ai AFTER INSERT ON agent_memory BEGIN
            INSERT INTO agent_memory_fts(rowid, content, level, role) VALUES (new.create_at, new.content, new.level, new.role); END',
        'CREATE TRIGGER IF NOT EXISTS mem_ad AFTER DELETE ON agent_memory BEGIN
            INSERT INTO agent_memory_fts(agent_memory_fts, rowid, content, level, role) VALUES (\'delete\', old.create_at, old.content, old.level, old.role); END',
        'CREATE TRIGGER IF NOT EXISTS mem_au AFTER UPDATE ON agent_memory BEGIN
            INSERT INTO agent_memory_fts(agent_memory_fts, rowid, content, level, role) VALUES (\'delete\', old.create_at, old.content, old.level, old.role);
            INSERT INTO agent_memory_fts(rowid, content, level, role) VALUES (new.create_at, new.content, new.level, new.role); END'
    ];

    public function __construct()
    {
        $this->core = core::new();
        $this->core->initCore();
        $this->db_path = $this->core->app->root_path . DIRECTORY_SEPARATOR . 'memory' . DIRECTORY_SEPARATOR;

        if (!is_dir($this->db_path)) {
            mkdir($this->db_path, 0777, true);
        }

        $this->initDatabase();
    }

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

    private function setupSchema(): void
    {
        $this->libSQLite->exec('DROP TABLE IF EXISTS agent_memory');
        $this->libSQLite->exec(self::DDL_MEMORY);

        $this->libSQLite->exec('DROP TABLE IF EXISTS agent_task');
        $this->libSQLite->exec(self::DDL_TASK);

        foreach (self::DDL_INDEXES as $sql) {
            $this->libSQLite->exec($sql);
        }
        unset($sql);

        try {
            $this->libSQLite->exec('DROP TABLE IF EXISTS agent_memory_fts');
            $this->libSQLite->exec(self::DDL_FTS);
            foreach (self::DDL_FTS_TRIGGERS as $trigger) {
                $this->libSQLite->exec($trigger);
            }
            $this->fts_enabled = true;
            unset($trigger);
        } catch (\Throwable) {
            $this->fts_enabled = false;
        }
    }

    private function purgeExpired(): void
    {
        $this->libSQLite->table('agent_memory')
            ->where(['expire_at', '>', 0], ['expire_at', '<', time()])
            ->delete()
            ->execute();
    }

    private function generateMicroTimestamp(string $table): int
    {
        $ts = (int)(microtime(true) * 1000000);

        $exists = $this->libSQLite->table($table)
            ->select('create_at')
            ->where(['create_at', '=', $ts])
            ->fetch();

        while (!empty($exists)) {
            ++$ts;
            $exists = $this->libSQLite->table($table)
                ->select('create_at')
                ->where(['create_at', '=', $ts])
                ->fetch();
        }

        return $ts;
    }

    private function formatMicroTime(int $micro): string
    {
        return date('Y-m-d H:i:s', (int)($micro / 1000000));
    }

    // =========================================================================
    //  Memory CRUD
    // =========================================================================

    public function save(string $level, string $role, string $content): array
    {
        if (!in_array($level, self::LEVELS)) {
            return ['error' => 'Invalid level: ' . $level];
        }

        if ('system' === $level) {
            $role = 'system';
        } elseif ('important' === $level) {
            $role = 'user';
        }
        if (!in_array($role, self::ROLES)) {
            return ['error' => 'Invalid role: ' . $role];
        }

        $create_at = $this->generateMicroTimestamp('agent_memory');
        $now_sec   = time();
        $expire_at = ('misc' === $level) ? ($now_sec + self::MISC_TTL_SEC) : 0;
        $date_key  = (int)date('Ymd');

        $this->libSQLite->table('agent_memory')->insert([
            'create_at' => $create_at,
            'expire_at' => $expire_at,
            'date_key'  => $date_key,
            'level'     => $level,
            'role'      => $role,
            'content'   => $content
        ])->execute();

        $result = ['saved' => true, 'create_at' => $create_at, 'role' => $role];
        unset($create_at, $now_sec, $expire_at, $date_key);
        return $result;
    }

    public function read(string $level, int $offset = 0, int $length = 100, string $date = ''): array
    {
        if (!in_array($level, self::ALL_LEVELS)) {
            return ['error' => 'Invalid level: ' . $level];
        }

        $date_int = ('' === $date) ? (int)date('Ymd') : (int)$date;

        $this->purgeExpired();

        $query = $this->libSQLite->table('agent_memory')->select('role, content, create_at');
        if ('all' !== $level) {
            $query->where(['level', '=', $level]);
        }
        if ('daily' === $level || 'misc' === $level) {
            $query->where(['date_key', '=', $date_int]);
        }

        $query->order(['create_at' => 'ASC'])->limit($offset, $length);
        $data = $query->fetchAll();

        foreach ($data as &$item) {
            $item['create_time'] = $this->formatMicroTime($item['create_at']);
        }
        unset($item);

        $result = ['messages' => $data, 'total' => count($data)];
        unset($query, $date_int, $data);
        return $result;
    }

    public function search(string $level, array $keywords, string $mode = 'or', int $offset = 0, int $length = 100, string $start_date = '', string $end_date = ''): array
    {
        if (!in_array($level, self::ALL_LEVELS)) {
            return ['error' => 'Invalid level: ' . $level];
        }
        if (empty($keywords)) {
            return ['messages' => [], 'total' => 0];
        }

        $this->purgeExpired();

        if (true === $this->fts_enabled) {
            $result = $this->searchViaFts($level, $keywords, $mode, $offset, $length, $start_date, $end_date);
        } else {
            $result = $this->searchViaLike($level, $keywords, $mode, $offset, $length, $start_date, $end_date);
        }

        if (isset($result['messages'])) {
            foreach ($result['messages'] as &$msg) {
                if (isset($msg['create_at'])) {
                    $msg['create_time'] = $this->formatMicroTime($msg['create_at']);
                }
            }
            unset($msg);
        }

        unset($keywords, $mode, $offset, $length, $start_date, $end_date);
        return $result;
    }

    public function delete(string $level, string $keywords = '', string $mode = 'or', string $start_date = '', string $end_date = '', int $start_time = 0, int $end_time = 0): array
    {
        if (!in_array($level, self::ALL_LEVELS)) {
            return ['error' => 'Invalid level: ' . $level];
        }

        $query = $this->libSQLite->table('agent_memory');
        if ('all' !== $level) {
            $query->where(['level', '=', $level]);
        }

        $hasCondition   = false;
        $start_date_int = ('' !== $start_date) ? (int)$start_date : 0;
        $end_date_int   = ('' !== $end_date) ? (int)$end_date : 0;

        if ('' !== $keywords) {
            $this->applyKeywordFilter($query, $keywords, $mode);
            $hasCondition = true;
        }

        if (0 !== $start_date_int && 0 !== $end_date_int) {
            $query->where(['date_key', '>=', $start_date_int], ['date_key', '<=', $end_date_int]);
            $hasCondition = true;
        } elseif (0 !== $start_date_int) {
            $query->where(['date_key', '>=', $start_date_int]);
            $hasCondition = true;
        } elseif (0 !== $end_date_int) {
            $query->where(['date_key', '<=', $end_date_int]);
            $hasCondition = true;
        }

        if (0 < $start_time || 0 < $end_time) {
            if (0 < $start_time) {
                $query->where(['create_at', '>=', $start_time * 1000000]);
            }
            if (0 < $end_time) {
                $query->where(['create_at', '<=', $end_time * 1000000]);
            }
            $hasCondition = true;
        }

        if (!$hasCondition) {
            unset($query, $hasCondition, $keywords, $mode, $start_date, $end_date, $start_time, $end_time, $start_date_int, $end_date_int);
            return ['error' => 'No deletion criteria provided.'];
        }

        $query->delete()->execute();
        $affected = $this->libSQLite->getAffectedRows();
        $result   = ['deleted' => $affected];
        unset($query, $hasCondition, $affected, $keywords, $mode, $start_date, $end_date, $start_time, $end_time, $start_date_int, $end_date_int);
        return $result;
    }

    // =========================================================================
    //  Task Management
    // =========================================================================

    public function addTask(string $task_prompt, int $run_at, bool $repeat = false, int $repeat_interval = 0): array
    {
        $now = time();
        if ($run_at < $now) {
            $run_at = $now;
        }
        if ($repeat && 0 >= $repeat_interval) {
            unset($now);
            return ['error' => 'repeat_interval must be positive when repeat is enabled'];
        }

        $create_at = $this->generateMicroTimestamp('agent_task');
        $this->libSQLite->table('agent_task')->replace([
            'create_at' => $create_at,
            'run_at'    => $run_at,
            'repeat'    => (int)$repeat,
            'interval'  => $repeat_interval,
            'prompt'    => $task_prompt
        ])->execute();

        $result = ['bytes_written' => 1, 'create_at' => $create_at];
        unset($now, $create_at, $run_at, $repeat, $repeat_interval);
        return $result;
    }

    public function removeTask(int $create_at): array
    {
        $this->libSQLite->table('agent_task')->where(['create_at', '=', $create_at])->delete()->execute();
        $affected = $this->libSQLite->getAffectedRows();
        $result   = ['success' => (0 < $affected), 'message' => (0 < $affected) ? 'Task removed.' : 'Task not found.'];
        unset($affected);
        return $result;
    }

    public function listTasks(): array
    {
        $tasks = $this->libSQLite->table('agent_task')->select('*')->order(['run_at' => 'ASC'])->fetchAll();
        foreach ($tasks as &$task) {
            $task['run_time']    = date('Y-m-d H:i:s', $task['run_at']);
            $task['create_time'] = date('Y-m-d H:i:s', (int)($task['create_at'] / 1000000));
        }
        unset($task);
        return $tasks;
    }

    public function runTask(): array
    {
        $now   = time();
        $tasks = $this->libSQLite->table('agent_task')
            ->select('prompt')
            ->where(['run_at', '<=', $now])
            ->fetchAll(\PDO::FETCH_COLUMN);
        unset($now);
        return $tasks;
    }

    // =========================================================================
    //  Internal Helpers
    // =========================================================================

    private function applyKeywordFilter($query, string $keywords, string $mode): void
    {
        $kwArray = explode(',', $keywords);
        if (empty($kwArray)) {
            unset($kwArray);
            return;
        }

        if ('and' === $mode) {
            foreach ($kwArray as $kw) {
                $query->where(['content', 'LIKE', '%' . $kw . '%']);
            }
        } else {
            $conditions = [];
            foreach ($kwArray as $idx => $kw) {
                if (0 === $idx) {
                    $conditions[] = ['content', 'LIKE', '%' . $kw . '%'];
                } else {
                    $conditions[] = ['or', 'content', 'LIKE', '%' . $kw . '%'];
                }
            }
            $query->where(...$conditions);
            unset($conditions);
        }
        unset($kwArray, $kw, $idx);
    }

    private function searchViaFts(string $level, array $keywords, string $mode, int $offset, int $length, string $start_date, string $end_date): array
    {
        $escaped = array_map(
            function (string $k): string
            {
                return '"' . str_replace('"', '""', $k) . '"';
            },
            $keywords
        );

        $kwString = ('and' === $mode)
            ? implode(' AND ', $escaped)
            : implode(' OR ', $escaped);

        $start_date_int = ('' !== $start_date) ? (int)$start_date : 0;
        $end_date_int   = ('' !== $end_date) ? (int)$end_date : 0;

        $sql = 'SELECT m.role, m.content, m.create_at
                FROM agent_memory m
                JOIN agent_memory_fts f ON m.create_at = f.rowid
                WHERE agent_memory_fts MATCH ?';

        $params = [$kwString];

        if ('all' !== $level) {
            $sql      .= ' AND m.level = ?';
            $params[] = $level;
        }
        if (0 !== $start_date_int && 0 !== $end_date_int) {
            $sql      .= ' AND m.date_key BETWEEN ? AND ?';
            $params[] = $start_date_int;
            $params[] = $end_date_int;
        } elseif (0 !== $start_date_int) {
            $sql      .= ' AND m.date_key >= ?';
            $params[] = $start_date_int;
        } elseif (0 !== $end_date_int) {
            $sql      .= ' AND m.date_key <= ?';
            $params[] = $end_date_int;
        }

        $offset   = max(0, $offset);
        $length   = max(0, $length);
        $sql      .= ' ORDER BY m.create_at ASC LIMIT ? OFFSET ?';
        $params[] = $length;
        $params[] = $offset;

        $stmt = $this->libSQLite->pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = ['messages' => $results, 'total' => count($results)];
        unset($escaped, $kwString, $sql, $params, $stmt, $results, $offset, $length, $start_date_int, $end_date_int);
        return $result;
    }

    private function searchViaLike(string $level, array $keywords, string $mode, int $offset, int $length, string $start_date, string $end_date): array
    {
        $query = $this->libSQLite->table('agent_memory')->select('role, content, create_at');
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

        $query->order(['create_at' => 'ASC'])->limit($offset, $length);
        $data   = $query->fetchAll();
        $result = ['messages' => $data, 'total' => count($data)];
        unset($query, $data, $keywords, $mode, $offset, $length, $start_date_int, $end_date_int);
        return $result;
    }
}