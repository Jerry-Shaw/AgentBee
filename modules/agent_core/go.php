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

use modules\agent_core\app\message;
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Core\System;

class go extends Factory
{
    use core;
    use System;

    public message $message;

    /**
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function __construct()
    {
        $this->init();
        $this->initCore();
        $this->initTools();
        $this->initModules();

        if (!is_dir($this->agent_config['tools']['workspace_path'])) {
            try {
                mkdir($this->agent_config['tools']['workspace_path'], 0777, true);
            } catch (\Throwable) {
            }
        }

        $this->message   = message::new();
        $this->socketMgr = SocketMgr::new();
    }

    /**
     * @return void
     */
    public function start(): void
    {
        ini_set('memory_limit', $this->agent_config['memory_limit'] ?? '4G');

        try {
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
     * @throws \Exception
     */
    public function onClientMessage(int $socket_id, string $message): void
    {
        if ('' === $message) {
            return;
        }

        $end_data = [];
        $llm_data = [];
        $messages = str_contains($message, "\n") ? explode("\n", $message) : [$message];

        foreach ($messages as $message) {
            $data = json_decode($message, true);

            if (!is_array($data) || !isset($data['type'])) {
                continue;
            }

            $message_type = 'process_' . $data['type'];

            if (!method_exists($this->message, $message_type)) {
                continue;
            }

            $message_data = $this->message->$message_type($socket_id, $data);

            if ($message_data['llm']) {
                $llm_data[] = $message_data['text'];
            }

            unset($data['message']);
            $end_data[] = $data;
        }

        $last_msg = array_pop($end_data);

        foreach ($end_data as $send_end) {
            $this->sendMessage($socket_id, json_encode(['type' => 'close'] + $send_end));
        }

        if (!empty($llm_data)) {
            $history = [];

            $system_settings = $this->getSystemPrompt($this->agent_config['tools']['in_sandbox'] ?? true);
            $system_memory   = $this->memory->read('system', 0, 0);

            if (!empty($system_memory['messages'])) {
                $memory = ["\n", '=== 重要个性设定 ==='];

                foreach ($system_memory['messages'] as $message) {
                    $memory[] = $message['content'];
                }

                $system_settings['content'] .= implode("\n", $memory);
            }

            $history[] = $system_settings;

            $sessions = $this->memory->addSessionHistory(['role' => 'user', 'content' => implode("\n", $llm_data)]);
            $history  = array_merge($history, $sessions);

            $this->llm->chat($socket_id, $history, $last_msg, $this->getLLMParams());

            unset($history, $system_settings, $system_memory, $memory, $sessions);
        }

        unset($socket_id, $message, $end_data, $llm_data, $messages, $data, $message_type, $message_data, $last_msg, $send_end);
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