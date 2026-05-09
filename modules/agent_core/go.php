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
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Core\System;
use Nervsys\Ext\libOpenAI;

class go
{
    use System;

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

        $this->procMgr   = ProcMgr::new();
        $this->socketMgr = SocketMgr::new();
        $this->libOpenAI = libOpenAI::new($this->config['llm']['api_url'], $this->config['llm']['api_key'], $this->config['llm']['org_id']);

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
                '-c', '/' . ProcMgr::class . '/worker'
            ])
            ->setWorkDir($this->temp_dir)
            ->runMP($this->config['worker']['count'] ?? 4, $this->config['worker']['max_executions'] ?? 10000);
    }

    public function start(): void
    {
        ini_set('memory_limit', $this->config['memory_limit'] ?? '4G');

        try {
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

    public function onClientHandshake(int $socket_id, string $ws_proto): bool
    {
        return true;
    }

    public function onClientMessage(int $socket_id, string $message): void
    {
        if ($this->in_process) {
            $this->queue[] = $message;
        } elseif (!empty($this->queue)) {
            $this->queue[] = $message;
            $message       = implode('\n\n', $this->queue);
        }

        $this->in_process = true;

        // 初始化会话历史
        if (!isset($this->sessions[$socket_id])) {
            $this->sessions[$socket_id] = [
                ['role' => 'system', 'content' => '你是我的助手，请帮我解决这个问题']
            ];
        }

        // 添加用户消息到历史
        $this->sessions[$socket_id][] = ['role' => 'user', 'content' => $message];
        $this->trimSessionHistory($socket_id);

        $messages = $this->sessions[$socket_id];

        var_dump($messages);

        $callbackKey = 'stream_' . uniqid('', true);
        $fullReply   = '';

        $this->libOpenAI->addStreamCallback($callbackKey, function ($key, $data, $finished) use ($socket_id, $callbackKey, &$fullReply)
        {
            if ($finished) {
                $this->in_process = false;
                // 将 AI 回复追加到历史
                if ($fullReply !== '') {
                    $this->sessions[$socket_id][] = ['role' => 'assistant', 'content' => $fullReply];
                }
                $this->libOpenAI->removeStreamCallback($callbackKey);
                $this->socketMgr->sendMessage($socket_id, $this->socketMgr->wsEncode(json_encode(['type' => 'end'], JSON_FORMAT)));
                return;
            }

            // 区分 think 和 content
            if (isset($data['choices'][0]['delta']['content'])) {
                $text = $data['choices'][0]['delta']['content'];
                $type = 'content';
            } elseif (isset($data['choices'][0]['delta']['reasoning_content'])) {
                $text = $data['choices'][0]['delta']['reasoning_content'];
                $type = 'think';
            } else {
                return;
            }

            if ($text !== '') {
                if ($type === 'content') {
                    $fullReply .= $text;
                }
                $this->socketMgr->sendMessage($socket_id, $this->socketMgr->wsEncode(json_encode(['type' => $type, 'data' => $text], JSON_FORMAT)));
            }
        });

        // 调用 AI（流式模式）
        $this->libOpenAI->chat($messages, $this->config['llm']['model'], $this->config['model'], true);
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