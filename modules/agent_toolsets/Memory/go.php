<?php

/**
 * Memory module for AgentBee - Industrial Refactored Version
 *
 * This module provides high-efficiency web data acquisition tools for Agents,
 * focusing on noise reduction and structural extraction to optimize LLM token usage.
 *
 * Copyright 2026 AgentBee self developed
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

namespace modules\agent_toolsets\Memory;

use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Ext\Algo\NGram;
use Nervsys\Ext\libPDO;
use Nervsys\Ext\libSQLite;

class go extends Factory
{
    public utils     $utils;
    public NGram     $NGram;
    public libPDO    $libPDO;
    public libSQLite $libSQLite;

    private string $db_path;
    private bool   $fts_enabled = true;

    private const LEVELS     = ['system', 'important', 'daily', 'misc'];
    private const ROLES      = ['user', 'assistant', 'system', 'tool'];
    private const ALL_LEVELS = ['system', 'important', 'daily', 'misc', 'all'];

    private const DDL_MEMORY = '
        CREATE TABLE IF NOT EXISTS agent_memory (
            create_id INTEGER PRIMARY KEY,
            expire_at INTEGER DEFAULT 0,
            date_key  INTEGER NOT NULL,
            level     TEXT NOT NULL,
            role      TEXT NOT NULL,
            content   TEXT NOT NULL,
            tokens    TEXT NOT NULL
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
        'CREATE INDEX IF NOT EXISTS idx_mem_date ON agent_memory(date_key)',
        'CREATE INDEX IF NOT EXISTS idx_mem_expire ON agent_memory(expire_at)',
        'CREATE INDEX IF NOT EXISTS idx_mem_level_create ON agent_memory(level, create_id DESC)',
        'CREATE INDEX IF NOT EXISTS idx_mem_lvl_date_create ON agent_memory(level, date_key, create_id DESC)',
        'CREATE INDEX IF NOT EXISTS idx_task_runat ON agent_task(run_at)',
    ];

    private const DDL_FTS = '
        CREATE VIRTUAL TABLE IF NOT EXISTS agent_memory_fts USING fts5(
            tokens,
            level,
            role,
            content=\'agent_memory\',
            content_rowid=\'create_id\',
            tokenize="unicode61 tokenchars \'_\'"
        )';

    private const DDL_FTS_TRIGGERS = [
        'CREATE TRIGGER IF NOT EXISTS memory_insert AFTER INSERT ON agent_memory BEGIN
            INSERT INTO agent_memory_fts(rowid, tokens, level, role) VALUES (new.create_id, new.tokens, new.level, new.role); END',
        'CREATE TRIGGER IF NOT EXISTS memory_delete AFTER DELETE ON agent_memory BEGIN
            INSERT INTO agent_memory_fts(agent_memory_fts, rowid, tokens, level, role) VALUES (\'delete\', old.create_id, old.tokens, old.level, old.role); END',
        'CREATE TRIGGER IF NOT EXISTS memory_update AFTER UPDATE ON agent_memory BEGIN
            INSERT INTO agent_memory_fts(agent_memory_fts, rowid, tokens, level, role) VALUES (\'delete\', old.create_id, old.tokens, old.level, old.role);
            INSERT INTO agent_memory_fts(rowid, tokens, level, role) VALUES (new.create_id, new.tokens, new.level, new.role); END'
    ];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->utils   = utils::new();
        $this->NGram   = NGram::new();
        $this->db_path = $this->utils->app->root_path . DIRECTORY_SEPARATOR . 'memory' . DIRECTORY_SEPARATOR;

        if (!is_dir($this->db_path)) {
            mkdir($this->db_path, 0777, true);
        }

        $this->initDatabase();
        $this->purgeExpired();
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
            return ['status' => 'error', 'error' => '内容为空'];
        }

        if (!in_array($level, self::LEVELS)) {
            return ['status' => 'error', 'error' => '无效层级：' . $level . '，可用：system/important/daily'];
        }

        if (!in_array($role, self::ROLES)) {
            return ['status' => 'error', 'error' => '无效角色：' . $role . '，可用：user/assistant/system/tool'];
        }

        $date_key  = (int)date('Ymd');
        $create_id = $this->generateMicroTimestamp('agent_memory');

        $expire_at = 'misc' === $level
        && isset($this->utils->agent_config['misc_keep_days'])
        && 0 < $this->utils->agent_config['misc_keep_days']
            ? (time() + $this->utils->agent_config['misc_keep_days'] * 86400)
            : 0;

        $this->libSQLite->table('agent_memory')->insert([
            'create_id' => $create_id,
            'expire_at' => $expire_at,
            'date_key'  => $date_key,
            'level'     => $level,
            'role'      => $role,
            'content'   => $content,
            'tokens'    => $this->buildTokens($content)
        ])->execute();

        $result = ['status' => 'success', 'create_id' => $create_id];

        unset($level, $role, $content, $create_id, $expire_at, $date_key);
        return $result;
    }

    /**
     * @param int    $create_id
     * @param string $level
     * @param string $role
     * @param string $content
     * @param string $expire_at
     *
     * @return array|string[]
     * @throws \ReflectionException
     */
    public function update(int $create_id, string $level, string $role, string $content, string $expire_at = ''): array
    {
        if ('' === trim($content)) {
            return ['status' => 'error', 'error' => '内容为空'];
        }

        if (!in_array($level, self::LEVELS)) {
            return ['status' => 'error', 'error' => '无效层级：' . $level . '，可用：system/important/daily'];
        }

        if (!in_array($role, self::ROLES)) {
            return ['status' => 'error', 'error' => '无效角色：' . $role . '，可用：user/assistant/system/tool'];
        }

        if ('' === $expire_at) {
            $expire_at = 0;
        } else {
            $expire_at = strtotime($expire_at);

            if (false === $expire_at) {
                return ['status' => 'error', 'error' => '过期时间格式无效，请使用YYYY-mm-dd HH:ii:ss'];
            }
        }

        $record = $this->libSQLite->table('agent_memory')
            ->select('create_id')
            ->where(['create_id', '=', $create_id])
            ->fetch();

        if ([] === $record) {
            return ['status' => 'error', 'error' => '记录不存在：' . $create_id];
        }

        $this->libSQLite->table('agent_memory')
            ->where(['create_id', '=', $create_id])
            ->update([
                'expire_at' => $expire_at,
                'level'     => $level,
                'role'      => $role,
                'content'   => $content,
                'tokens'    => $this->buildTokens($content)
            ])
            ->execute();

        unset($create_id, $level, $role, $content, $expire_at, $record);
        return ['status' => 'success', 'affected_rows' => $this->libSQLite->getAffectedRows()];
    }

    /**
     * @param string $level
     * @param int    $offset
     * @param int    $length
     * @param int    $date
     * @param int    $create_id
     *
     * @return array
     * @throws \ReflectionException
     */
    public function read(string $level, int $offset = 0, int $length = 10, int $date = 0, int $create_id = 0): array
    {
        if (!in_array($level, self::ALL_LEVELS)) {
            return ['status' => 'error', 'error' => '无效层级：' . $level . '，可用：system/important/daily/misc/all'];
        }

        $query = $this->libSQLite
            ->table('agent_memory')
            ->select('level', 'role', 'content', 'create_id');

        if ('all' !== $level) {
            $query->where(['level', '=', $level]);
        }

        if (0 < $create_id) {
            $query->where(['create_id', '<', $create_id]);
        }

        if (0 < $date) {
            $query->where(['date_key', '=', $date]);
        }

        $query->order(['create_id' => 'DESC']);

        if (0 < $length) {
            $query->limit($offset, $length);
        }

        $data = $query->fetchAll();
        $data = array_reverse($data);

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
    public function search(string $level, array $keywords, string $mode = 'and', int $offset = 0, int $length = 10, string $start_date = '', string $end_date = ''): array
    {
        if (!in_array($level, self::ALL_LEVELS)) {
            return ['status' => 'error', 'error' => '无效层级：' . $level . '，可用：system/important/daily/misc/all'];
        }

        $keywords = array_filter(
            $keywords,
            function (string $word): bool
            {
                return '' !== trim($word);
            }
        );

        if ([] === $keywords) {
            return ['status' => 'success', 'data' => [], 'total' => 0];
        }

        $use_fts = true;

        foreach ($keywords as $word) {
            if (1 === mb_strlen($word, 'UTF-8') && 1 < strlen($word)) {
                $use_fts = false;
                break;
            }

            foreach (['@', '(', ')', '{', '}', '[', ']', '*', '^', '?', '+', '-', '~', '&', '|', '!', '<', '>', '=', '%', '_', '#', '$', ',', '.', '/', ';', ':', '"', "'", "`", '\\'] as $char) {
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
     * @param string $start_time
     * @param string $end_time
     * @param array  $keywords
     * @param string $mode
     *
     * @return array|string[]
     * @throws \ReflectionException
     */
    public function delete(string $level, array $create_ids = [], string $start_time = '', string $end_time = '', array $keywords = [], string $mode = 'and'): array
    {
        if (!in_array($level, self::ALL_LEVELS)) {
            $result = ['status' => 'error', 'error' => '无效层级：' . $level . '，可用：system/important/daily/misc/all'];

            unset($level, $create_ids, $start_time, $end_time, $keywords, $mode);
            return $result;
        }

        if ('' === $start_time) {
            $start_time = 0;
        } else {
            $start_time = strtotime($start_time);

            if (false === $start_time) {
                return ['status' => 'error', 'error' => '开始时间格式无效，请使用YYYY-mm-dd HH:ii:ss'];
            }
        }

        if ('' === $end_time) {
            $end_time = 0;
        } else {
            $end_time = strtotime($end_time);

            if (false === $end_time) {
                return ['status' => 'error', 'error' => '结束时间格式无效，请使用YYYY-mm-dd HH:ii:ss'];
            }
        }

        if ([] !== $create_ids) {
            $valid_ids = array_filter(
                $create_ids,
                function (int $id): bool
                {
                    return false !== filter_var($id, FILTER_VALIDATE_INT) && 0 < $id;
                }
            );

            $valid_ids = array_map('intval', $valid_ids);

            if ([] === $valid_ids) {
                $result = ['status' => 'error', 'error' => 'create_ids有误，须为正整数'];
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
        } elseif (0 < $start_time || 0 < $end_time || [] !== $keywords) {
            if (0 < $start_time && 0 < $end_time && $start_time > $end_time) {
                $result = ['status' => 'error', 'error' => '起始时间不能大于结束时间'];
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

                if ([] !== $keywords) {
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
            $result = ['status' => 'error', 'error' => '缺少删除条件，请补充必要条件后重试'];
        }

        unset($level, $create_ids, $start_time, $end_time, $keywords, $mode, $query);
        return $result;
    }

    // =========================================================================
    //  Task Management
    // =========================================================================

    /**
     * @param string $task_prompt
     * @param string $run_at
     * @param bool   $repeat
     * @param int    $repeat_interval
     *
     * @return array
     * @throws \ReflectionException
     */
    public function addTask(string $task_prompt, string $run_at, bool $repeat = false, int $repeat_interval = 0): array
    {
        $now    = time();
        $run_at = strtotime($run_at);

        if (false === $run_at) {
            return ['status' => 'error', 'error' => '执行时间格式无效，请使用YYYY-mm-dd HH:ii:ss'];
        }

        if ($run_at < $now) {
            return ['status' => 'error', 'error' => '执行时间不能早于当前时间，请重新设置'];
        }

        if ($repeat && 0 >= $repeat_interval) {
            return ['status' => 'error', 'error' => '重复间隔必须大于0'];
        }

        $create_id = $this->generateMicroTimestamp('agent_task');
        $this->libSQLite->table('agent_task')->replace([
            'create_id' => $create_id,
            'run_at'    => $run_at,
            'repeat'    => $repeat ? 1 : 0,
            'interval'  => $repeat_interval,
            'prompt'    => $task_prompt
        ])->execute();

        $result = ['status' => 'success', 'create_id' => $create_id, 'run_time' => date('Y-m-d H:i:s', $run_at)];

        unset($task_prompt, $run_at, $repeat, $repeat_interval, $now, $create_id);
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
            ? ['status' => 'success', 'message' => '已删除' . $affected . '个任务']
            : ['status' => 'error', 'error' => '任务不存在'];

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
        $keep_ids = $this->libSQLite->table('agent_memory')
            ->select('create_id')
            ->where(['level', 'misc'])
            ->limit($this->utils->agent_config['misc_save_len'] ?? 200)
            ->order(['create_id' => 'DESC'])
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->libSQLite->table('agent_memory');
        $this->libSQLite->where(['level', 'misc']);

        if ([] !== $keep_ids) {
            $this->libSQLite->and(['create_id', '<', min($keep_ids)], ['or', 'expire_at', '<', time()]);
        } else {
            $this->libSQLite->and(['expire_at', '<', time()]);
        }

        unset($keep_ids);
        $this->libSQLite->delete()->execute();
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

        while ([] !== $exists) {
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

    /**
     * @param string $text
     *
     * @return string
     */
    private function buildTokens(string $text): string
    {
        $text = preg_replace('/[^\p{L}\p{N}_]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        $asian_tokens = [];

        $tokens = explode(' ', $text);
        $tokens = array_filter($tokens, 'strlen');
        $tokens = array_values($tokens);

        foreach ($tokens as $key => $value) {
            if (2 >= mb_strlen($value, 'UTF-8')) {
                $asian_tokens[] = $value;
                unset($tokens[$key]);
            }
        }

        $text     = implode(' ', $tokens);
        $segments = $this->NGram->splitText($text);

        $latin_tokens = explode(' ', $segments['latin']);
        $latin_tokens = array_filter($latin_tokens, 'strlen');
        $latin_tokens = array_map('strtolower', $latin_tokens);

        if ('' !== $segments['asian']) {
            $asian_texts = explode(' ', $segments['asian']);
            $asian_texts = array_filter($asian_texts, 'strlen');

            foreach ($asian_texts as $asian_text) {
                if (2 >= mb_strlen($asian_text, 'UTF-8')) {
                    $asian_tokens[] = $asian_text;
                } else {
                    $asian_grams  = $this->NGram->getGrams($asian_text, 2);
                    $asian_tokens = array_merge($asian_tokens, $asian_grams);
                }
            }

            unset($asian_texts, $asian_text, $asian_grams);
        }

        $content_tokens = array_merge($asian_tokens, $latin_tokens);
        $content_tokens = array_unique($content_tokens);
        $indexed_tokens = implode(' ', $content_tokens);

        unset($text, $tokens, $key, $value, $segments, $asian_tokens, $latin_tokens, $content_tokens);
        return $indexed_tokens;
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
    private function searchViaFts(string $level, array $keywords, string $mode, int $offset, int $length, string $start_date, string $end_date): array
    {
        $keywords = array_map([$this, 'buildTokens'], $keywords);
        $keywords = array_filter($keywords, 'strlen');

        if ([] === $keywords) {
            return ['data' => [], 'total' => 0];
        }

        $keywords = array_map(function ($text)
        {
            $tokens = explode(' ', $text);
            $tokens = array_filter($tokens, 'strlen');

            $escaped = array_map(function ($token)
            {
                return in_array(strtoupper($token), ['AND', 'OR', 'NOT'], true)
                    ? '"' . str_replace('"', '""', $token) . '"'
                    : $token;
            }, $tokens);

            unset($text, $tokens);
            return implode(' AND ', $escaped);
        }, $keywords);

        $kw_string = ('and' === strtolower($mode))
            ? implode(' AND ', $keywords)
            : implode(' OR ', $keywords);

        $start_date_int = ('' !== $start_date) ? (int)$start_date : 0;
        $end_date_int   = ('' !== $end_date) ? (int)$end_date : 0;

        $query = $this->libSQLite
            ->table('agent_memory')
            ->join('agent_memory_fts', 'INNER')
            ->on(['agent_memory.create_id', '=', 'agent_memory_fts.rowid'])
            ->select('agent_memory.level', 'agent_memory.role', 'agent_memory.content', 'agent_memory.create_id');

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
            ->select('level', 'role', 'content', 'create_id');

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

        if ([] !== $keywords) {
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