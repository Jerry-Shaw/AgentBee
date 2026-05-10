<?php

/**
 * Agent Core module for AgentBee (Simplified version)
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
use modules\agent_core\app\openAi;
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Core\System;
use Nervsys\Ext\libOpenAI;

class go
{
    use System;

    public openAi    $openAi;
    public ProcMgr   $procMgr;
    public SocketMgr $socketMgr;
    public libOpenAI $libOpenAI;

    public array $config = [];

    public string $temp_dir = '';

    // 会话历史存储：key = socket_id, value = 消息数组
    public array $sessions = [];

    public array $queue      = [];
    public bool  $in_process = false;

    /**
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function __construct()
    {
        $this->init();
        $this->config   = config::new()->get();
        $this->temp_dir = $this->app->root_path . DIRECTORY_SEPARATOR . 'temp';

        if (!is_dir($this->temp_dir)) {
            try {
                mkdir($this->temp_dir, 0777, true);
            } catch (\Throwable) {
            }
        }

        $this->openAi    = openAi::new();
        $this->socketMgr = SocketMgr::new();
        $this->procMgr   = ProcMgr::new('socket');
    }

    /**
     * @return void
     */
    public function start(): void
    {
        ini_set('memory_limit', $this->config['memory_limit'] ?? '4G');

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
                ->runMP($this->config['worker']['count'] ?? 4, $this->config['worker']['max_executions'] ?? 10000);

            $this->socketMgr
                ->setDebugMode($this->config['debug'])
                ->setAliveTimeout($this->config['server']['ping_interval'] * 2)
                ->setEventListener('onHandshake', [$this, 'onClientHandshake'])
                ->setEventListener('onMessage', [$this, 'onClientMessage'])
                ->setEventListener('onSendString', [$this, 'onMessageSend'])
                ->setEventListener('onClose', [$this, 'onClientClose'])
                ->listenTo('tcp://' . $this->config['server']['host'] . ':' . $this->config['server']['port'], $this->config['server']['websocket']);
        } catch (\Throwable) {
        }
    }

    public function procChat(int $socket_id, array $messages): void
    {
        $this->openAi->chat($socket_id, $messages);
    }

    public function onClientHandshake(int $socket_id, string $ws_proto): bool
    {
        return true;
    }

    public function onClientMessage(int $socket_id, string $message): void
    {
        // 初始化会话历史
        if (!isset($this->sessions[$socket_id])) {
            $this->sessions[$socket_id] = [
                ['role' => 'system', 'content' => '你是我的助手，请帮我解决这个问题']
            ];
        }

        // 添加用户消息到历史
        $this->sessions[$socket_id][] = ['role' => 'user', 'content' => $message];
        $this->trimSessionHistory($socket_id);

        $task = [
            'c'         => 'agent_core/procChat',
            'socket_id' => $socket_id,
            'messages'  => $this->sessions[$socket_id]
        ];

        $this->procMgr->putJob(
            json_encode($task, JSON_FORMAT),
            function (string $output) use ($socket_id): void
            {
                $this->openAi->onWorkerOutput($socket_id, $output, $this);
            },
            function (string $output) use ($socket_id): void
            {
                $this->openAi->onWorkerError($socket_id, $output, $this);
            },
            '[DONE]'
        );

        $this->procMgr->awaitJobs();
    }

    private function trimSessionHistory(int $socket_id, int $max_messages = 20): void
    {
        $history = &$this->sessions[$socket_id];
        if (count($history) <= $max_messages) {
            return;
        }

        $system = [];
        if (isset($history[0]['role']) && $history[0]['role'] === 'system') {
            $system = [$history[0]];
            array_shift($history);
        }

        $history = array_slice($history, -($max_messages - count($system)));

        if (!empty($system)) {
            array_unshift($history, $system[0]);
        }
    }

    public function onMessageSend(int $socket_id): array
    {
        return [];
    }

    public function onClientClose(int $socket_id): void
    {
        unset($this->sessions[$socket_id]);
    }
}