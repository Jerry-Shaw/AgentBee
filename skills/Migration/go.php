<?php

/**
 * Memory Migration module for AgentBee - Industrial Refactored Version
 * exec: php modules/agent_bee.php Migration/memoryToChat
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

namespace skills\Migration;

use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Ext\libPDO;
use Nervsys\Ext\libSQLite;

class go extends Factory
{
    public libPDO    $libPDO;
    public libSQLite $libSQLite;

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $db_file      = utils::new()->app->root_path . DIRECTORY_SEPARATOR . 'memory' . DIRECTORY_SEPARATOR . 'agent_memory.db';
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
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function memoryToChat(): void
    {
        $query = $this->libSQLite->query('SELECT create_id,session_id,role,content FROM agent_memory');

        while ($row = $query->fetch()) {
            if ('' === $row['session_id']) {
                continue;
            }

            try {
                $saved = $this->libSQLite->table('agent_chat')
                    ->insert([
                        'create_id'   => $row['create_id'],
                        'session_id'  => $row['session_id'],
                        'worker_name' => WORKER_MAIN,
                        'type'        => 0,
                        'role'        => $row['role'],
                        'data'        => json_encode(['content' => $row['content']], JSON_FORMAT)
                    ])
                    ->execute();

                echo $row['create_id'] . ($saved ? ' saved' : 'not saved') . PHP_EOL;
            } catch (\Throwable) {
                echo $row['create_id'] . ' already saved' . PHP_EOL;
            }
        }
    }
}