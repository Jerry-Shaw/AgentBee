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
                ->setEventListener('onHandshake', [$this, 'onHandshake'])
                ->setEventListener('onHeartbeat', [$this, 'onHeartbeat'])
                ->setEventListener('onMessage', [$this, 'onMessage'])
                ->setEventListener('onSendString', [$this, 'onSendString'])
                ->setEventListener('onClose', [$this, 'onClose'])
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
    public function onHandshake(int $socket_id, string $ws_proto): bool
    {
        return true;
    }

    /**
     * @param int $socket_id
     *
     * @return string
     * @throws \Exception
     */
    public function onHeartbeat(int $socket_id): string
    {
        $task_list = $this->core->agent_task->runTask();

        if (empty($task_list)) {
            return '';
        }

        $task_jobs = [
            'time' => date('Y-m-d H:i:s'),
            'jobs' => $task_list
        ];

        $task_json = json_encode($task_jobs, JSON_FORMAT);

        $task_content = '【定时任务】以下是待执行的定时任务列表（JSON 格式）：' . PHP_EOL .
            $task_json . PHP_EOL . PHP_EOL .
            '请按顺序处理每个任务：' . PHP_EOL .
            '1. 根据 task_prompt 执行相应操作（发送提醒、调用工具、回答问题等）。' . PHP_EOL .
            '2. 执行后将任务摘要和执行结果存入 daily 记忆层。' . PHP_EOL .
            '3. 如需回复用户，直接输出内容。' . PHP_EOL .
            '4. 全部处理完毕后，若无用户交互，回复“定时任务已处理”。' . PHP_EOL .
            '5. 重要事件可额外存入 important 层。';

        $this->core->addSessionHistory(['role' => 'user', 'content' => $task_content]);
        $this->core->agent_llm->chat($socket_id, [], $this->core->getLLMParams());

        unset($socket_id, $task_list, $task_jobs, $task_json, $task_content);
        return '';
    }

    /**
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function onMessage(int $socket_id, string $message): void
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

            $message_data = $this->message->$message_type($socket_id, $data['content']);

            if ($message_data['agent_llm']) {
                $llm_data = $message_data['content'];
            }

            unset($data['content']);
            $end_data[] = $data;
        }

        $msg_meta = array_pop($end_data);

        foreach ($end_data as $send_end) {
            $this->core->sendMessage($socket_id, json_encode(['type' => 'close'] + $send_end));
        }

        if (!empty($llm_data)) {
            $this->core->addSessionHistory(['role' => 'user', 'content' => $llm_data]);
            $this->core->agent_llm->chat($socket_id, $msg_meta, $this->core->getLLMParams());
        }

        unset($socket_id, $message, $end_data, $llm_data, $messages, $data, $message_type, $message_data, $msg_meta, $send_end);
    }

    /**
     * @param int $socket_id
     *
     * @return array
     */
    public function onSendString(int $socket_id): array
    {
        return [];
    }

    /**
     * @param int $socket_id
     *
     * @return void
     */
    public function onClose(int $socket_id): void
    {
    }
}