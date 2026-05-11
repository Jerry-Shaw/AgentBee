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
use Nervsys\Core\Mgr\ProcMgr;
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

        if (!is_dir($this->agent_config['tools']['root_path'])) {
            try {
                mkdir($this->agent_config['tools']['root_path'], 0777, true);
            } catch (\Throwable) {
            }
        }

        $this->message   = message::new();
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
                ->setWorkDir($this->agent_config['tools']['root_path'])
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
            $llm_text = implode("\n", $llm_data);

            $this->addMemory('user', $llm_text);

            $default_memory = $this->getDefaultMemory();
            $today_memory   = $this->getMemory(strtotime(date('Y-m-d')));

            $default_memory[] = [
                'role'    => 'system',
                'content' => implode("\n", [
                    '当前用户的操作系统是 ' . $this->uname,
                    '当前 Agent 架构为 ' . $this->name . ' based on ' . NS_NAMESPACE . ' / ' . NS_VER,
                    '运行环境为 PHP/' . PHP_VERSION,
                    '',
                    '当前工作目录: ' . getcwd(),
                    '允许的文件操作根目录: ' . ($this->agent_config['tools']['root_path'] ?? getcwd()),
                    '',
                    '你可以使用以下工具来帮助用户：',
                    json_encode($this->llm_params['tools'], JSON_FORMAT),
                    '',
                    '注意事项：',
                    '- 文件操作只能在允许的根目录内进行',
                    '- 危险操作（如删除文件）需要用户确认',
                    '- 不要执行可能危害系统的命令',
                    '- 回答时请使用中文',
                    '',
                    '当前时间: ' . date('Y-m-d H:i:s'),
                ])
            ];

            $history = array_merge($default_memory, $today_memory);

            $this->llm->chat($socket_id, $history, $last_msg, $this->getLLMParams());
        }
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