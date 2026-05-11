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

namespace modules\agent_core;

use modules\agent_core\app\config;
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Core\System;

class go extends Factory
{
    use core;
    use System;

    public string $temp_dir = '';

    /**
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function __construct()
    {
        $this->init();
        $this->initCore();
        $this->initModules();

        $this->temp_dir = $this->app->root_path . DIRECTORY_SEPARATOR . 'temp';

        if (!is_dir($this->temp_dir)) {
            try {
                mkdir($this->temp_dir, 0777, true);
            } catch (\Throwable) {
            }
        }

        $this->socketMgr = SocketMgr::new();
        $this->procMgr   = ProcMgr::new('socket');
    }

    /**
     * @return void
     */
    public function start(): void
    {
        ini_set('memory_limit', $this->agent_config['memory_limit'] ?? '4G');

        try {
            register_shutdown_function(
                function (): void
                {
                    $this->procMgr->exit();
                }
            );

            $this->procMgr
                ->command([
                    $this->OSMgr->getPhpPath(),
                    $this->app->script_path,
                    '-c', procMgr::WORKER_STREAM
                ])
                ->setWorkDir($this->temp_dir)
                ->runMP($this->agent_config['worker']['count'] ?? 4, $this->agent_config['worker']['max_executions'] ?? 10000);

            $this->socketMgr
                ->setDebugMode($this->agent_config['debug'])
                ->setAliveTimeout($this->agent_config['server']['ping_interval'] * 2)
                ->setEventListener('onHandshake', [$this, 'onClientHandshake'])
                ->setEventListener('onMessage', [$this, 'onClientMessage'])
                ->setEventListener('onSendString', [$this, 'onMessageSend'])
                ->setEventListener('onClose', [$this, 'onClientClose'])
                ->listenTo('tcp://' . $this->agent_config['server']['host'] . ':' . $this->agent_config['server']['port'], $this->agent_config['server']['websocket']);
        } catch (\Throwable) {
        }
    }

    /**
     * @param int    $socket_id
     * @param string $ws_proto
     *
     * @return bool
     */
    public function onClientHandshake(int $socket_id, string $ws_proto): bool
    {
        return true;
    }

    /**
     * @throws \ReflectionException
     */
    public function onClientMessage(int $socket_id, string $message): void
    {
        if ('' === $message) {
            return;
        }

        $this->addMemory('user', $message);

        $default_memory = $this->getDefaultMemory();
        $today_memory   = $this->getMemory(strtotime(date('Y-m-d')));

        $history = array_merge($default_memory, $today_memory);

        $this->llm->chat($socket_id, $history);
    }

    /**
     * @param int $socket_id
     *
     * @return array
     */
    public function onMessageSend(int $socket_id): array
    {
        return [];
    }

    /**
     * @param int $socket_id
     *
     * @return void
     */
    public function onClientClose(int $socket_id): void
    {
    }
}