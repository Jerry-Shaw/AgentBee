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

class go extends Factory
{
    public core    $core;
    public message $message;

    /**
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function __construct()
    {
        $this->core = core::new();

        $this->core->initCore();
        $this->core->initTools();
        $this->core->initModules();

        if (!is_dir($this->core->agent_config['agent_tools']['workspace_path'])) {
            try {
                mkdir($this->core->agent_config['agent_tools']['workspace_path'], 0777, true);
            } catch (\Throwable) {
            }
        }

        $this->message         = message::new();
        $this->core->socketMgr = SocketMgr::new();
    }

    /**
     * @return void
     */
    public function start(): void
    {
        ini_set('memory_limit', $this->core->agent_config['memory_limit'] ?? '4G');

        try {
            $this->core->socketMgr
                ->setDebugMode($this->core->agent_config['debug'])
                ->setAliveTimeout($this->core->agent_config['agent_server']['ping_interval'] * 2)
                ->setEventListener('onHandshake', [$this, 'onClientHandshake'])
                ->setEventListener('onMessage', [$this, 'onClientMessage'])
                ->setEventListener('onSendString', [$this, 'onMessageSend'])
                ->setEventListener('onClose', [$this, 'onClientClose'])
                ->listenTo('tcp://' . $this->core->agent_config['agent_server']['host'] . ':' . $this->core->agent_config['agent_server']['port'], $this->core->agent_config['agent_server']['websocket']);
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

            if ($message_data['agent_llm']) {
                $llm_data[] = $message_data['text'];
            }

            unset($data['message']);
            $end_data[] = $data;
        }

        $last_msg = array_pop($end_data);

        foreach ($end_data as $send_end) {
            $this->core->sendMessage($socket_id, json_encode(['type' => 'close'] + $send_end));
        }

        if (!empty($llm_data)) {
            $this->core->addSessionHistory(['role' => 'user', 'content' => implode("\n", $llm_data)]);
            $this->core->agent_llm->chat($socket_id, $last_msg, $this->core->getLLMParams());
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