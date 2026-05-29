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

    public bool $in_process    = false;
    public bool $clean_warning = false;

    public array $socket_session = [];
    public array $stream_buffers = [];

    public array $coming_messages = [];
    public array $onsend_messages = [];

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

        $workspace_path = $this->core->agent_config['agent_tools']['workspace_path'] ?? '';
        if ('' !== $workspace_path && !is_dir($workspace_path)) {
            try {
                mkdir($workspace_path, 0777, true);
            } catch (\Throwable) {
            }
        }

        $this->message         = message::new();
        $this->core->socketMgr = SocketMgr::new();
    }

    /**
     * Start WebSocket server and worker process.
     *
     * @return void
     * @throws \Exception
     */
    public function start(): void
    {
        ini_set('memory_limit', $this->core->agent_config['memory_limit'] ?? '4G');

        $this->runProcWorker();

        try {
            $this->core->socketMgr
                ->setDebugMode($this->core->agent_config['debug'])
                ->setAliveTimeout($this->core->agent_config['agent_server']['ping_interval'])
                ->setEventListener('onHandshake', [$this, 'onHandshake'])
                ->setEventListener('onHeartbeat', [$this, 'onHeartbeat'])
                ->setEventListener('onMessage', [$this, 'onMessage'])
                ->setEventListener('onSendString', [$this, 'onSendString'])
                ->setEventListener('onClose', [$this, 'onClose'])
                ->listenTo('tcp://' . $this->core->agent_config['agent_server']['host'] . ':' . $this->core->agent_config['agent_server']['port'], true);
        } catch (\Throwable) {
        }
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function runProcWorker(): void
    {
        $worker_status = $this->core->procMgr->getStatus(core::PROC_IDX_OPENAI);

        if (0 < $worker_status) {
            return;
        }

        $this->core->procMgr->close(core::PROC_IDX_OPENAI);

        $this->core->procMgr->command([
            $this->core->OSMgr->getPhpPath(),
            $this->core->app->script_path,
            '-c=' . $this->core->agent_config['agent_llm']['provider'] . '/' . $this->core->agent_config['agent_llm']['work_name']
        ])->run(core::PROC_IDX_OPENAI);

        $worker_pid = $this->core->procMgr->getPid(core::PROC_IDX_OPENAI);

        $this->core->socketMgr->addExternalProc(
            $this->core->procMgr->getProc(core::PROC_IDX_OPENAI),
            [$this, 'streamWorkerHandler'],
            [$this, 'streamWorkerHandler']
        );

        $this->core->agent_llm->buildShmop($worker_pid);
    }

    /**
     * Callback for external stream (stdout from worker).
     *
     * @param string $external_stream_id
     * @param array  $context
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function streamWorkerHandler(string $external_stream_id, array $context): void
    {
        $stdout_stream = $context['stdout'];
        $data_chunk    = fread($stdout_stream, 8192);

        if (false === $data_chunk || '' === $data_chunk) {
            unset($this->stream_buffers[$external_stream_id]);
            return;
        }

        $buffer = &$this->stream_buffers[$external_stream_id];
        $buffer .= $data_chunk;

        while (false !== ($line_pos = strpos($buffer, "\n"))) {
            $line   = substr($buffer, 0, $line_pos);
            $buffer = substr($buffer, $line_pos + 1);
            $line   = trim($line);

            if ('' === $line) {
                continue;
            }

            $message = json_decode($line, true);

            if (!is_array($message)) {
                continue;
            }

            $payload      = $message['payload'];
            $payload_type = $payload['type'];

            switch ($message['type']) {
                case 'stream':
                    $this->core->sendMessage($message['socket_id'], json_encode($payload, JSON_FORMAT));

                    if ('error' === $payload_type) {
                        unset($this->stream_buffers[$external_stream_id]);
                    }
                    break;

                case 'history':
                    if (isset($payload['data'])) {
                        switch ($payload_type) {
                            case 'add':
                                $this->core->addSessionHistory($payload['data']);
                                break;

                            case 'sync':
                                $this->core->session_history = $payload['data'];
                                break;
                        }
                    }
                    break;

                case 'context':
                    $this->coming_messages = array_merge($this->coming_messages, $payload['data']);
                    break;

                case 'end':
                    $current_history = $this->core->getSessionHistory();

                    switch ($payload_type) {
                        case 'tools':
                            $this->in_process = true;

                            $this->core->agent_llm->chat(
                                $message['socket_id'],
                                array_intersect_key($payload, $this->socket_session[$message['socket_id']]),
                                $current_history
                            );
                            break;

                        case 'end':
                            $this->in_process = false;

                            $current_count = count($current_history);
                            $max_history   = $this->core->agent_config['agent_memory']['max_history'];
                            $warning_count = $max_history * 2;
                            $limit_count   = $max_history * 3;

                            if ($current_count < $warning_count) {
                                $this->clean_warning = false;
                                break;
                            } elseif (!$this->clean_warning) {
                                $this->clean_warning     = true;
                                $this->onsend_messages[] = '[历史 ' . $current_count . '/' . $max_history . '] 自动：①总结关键信息(需求/回复/工具结果)→存记忆(daily/important/system,临时ram)；②调用清理工具删旧工具对；③告知用户(概要/层级/剩余数)。| 超 ' . $limit_count . ' 条强制清理，及时保存。';
                            }
                            break;
                    }
                    break;
            }
        }

        if (feof($stdout_stream)) {
            unset($this->stream_buffers[$external_stream_id]);
        }

        unset($external_stream_id, $context, $stdout_stream, $data_chunk, $buffer, $line_pos, $line, $message, $payload, $payload_type, $current_history);
    }

    /**
     * WebSocket handshake callback.
     *
     * @param string $socket_id
     * @param string $websocket_protocol
     *
     * @return bool
     * @throws \Exception
     */
    public function onHandshake(string $socket_id, string $websocket_protocol): bool
    {
        unset($socket_id, $websocket_protocol);
        return true;
    }

    /**
     * Heartbeat callback.
     *
     * @param string $socket_id
     *
     * @return string
     * @throws \Exception
     */
    public function onHeartbeat(string $socket_id): string
    {
        $task_list = $this->core->agent_task->runTask();

        if (empty($task_list)) {
            unset($socket_id, $task_list);
            return '';
        }

        $task_jobs = [
            'time' => date('Y-m-d H:i:s'),
            'jobs' => $task_list
        ];

        $task_json = json_encode($task_jobs, JSON_FORMAT);

        $task_content = '[定时任务] JSON:' . PHP_EOL . $task_json . PHP_EOL . '按序:①按任务要求执行(提醒/工具/问答) ②仅重要结果存daily(重要可+important),琐碎不存 ③完成后简述概要/结果/存储层级,语气自然。';

        $this->core->addSessionHistory(['role' => 'user', 'content' => $task_content]);

        $current_history = $this->core->getSessionHistory();

        $this->in_process = true;

        $this->core->agent_llm->chat(
            $socket_id,
            [
                'sessionId' => $this->socket_session[$socket_id]['sessionId'] ?? 'default',
                'messageId' => 'task-' . microtime(true),
            ],
            $current_history
        );

        unset($socket_id, $task_list, $task_jobs, $task_json, $task_content, $current_history);
        return '';
    }

    /**
     * WebSocket message callback.
     *
     * @param string $socket_id
     * @param string $message
     * @param bool   $is_binary
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function onMessage(string $socket_id, string $message, bool $is_binary): void
    {
        if ('' === $message) {
            return;
        }

        if ($is_binary) {
            $message = $this->message->process_binary($socket_id, $message);
        }

        $messages = str_contains($message, "\n") ? explode("\n", $message) : [$message];

        foreach ($messages as $line) {
            $data = json_decode($line, true);

            if (!is_array($data) || !isset($data['type'])) {
                continue;
            }

            if ('stop' === $data['type']) {
                $this->core->agent_llm->abort($socket_id);
                continue;
            }

            $type_method = 'process_' . $data['type'];

            if (!method_exists($this->message, $type_method)) {
                continue;
            }

            $result = $this->message->$type_method($socket_id, $data['content']);

            if (!$result['need_llm']) {
                // Other actions
                $response = ['type' => $data['type']] + $result['data'];
                $this->core->sendMessage($socket_id, json_encode($response, JSON_FORMAT));

                unset($socket_id, $message, $is_binary, $messages, $line, $data, $type_method, $result, $response);
                return;
            }

            // LLM action
            $end_data = [];
            $llm_data = [];

            $this->socket_session[$socket_id] = [
                'sessionId' => $data['sessionId'],
                'messageId' => $data['messageId']
            ];

            if (!$this->in_process) {
                $llm_data = $result['content'];

                if (empty($this->core->session_history)) {
                    array_unshift(
                        $llm_data, [
                            'type' => 'text',
                            'text' => '[提醒] 上下文不全，请加载今日记忆。如有需要，可继续加载昨日记忆和 important 记忆。'
                        ]
                    );
                }

                if (!empty($this->coming_messages)) {
                    while (!is_null($coming_message = array_shift($this->coming_messages))) {
                        $llm_data[] = $coming_message;
                    }
                }
            } else {
                $this->coming_messages = array_merge($this->coming_messages, $result['content']);
            }

            unset($data['content']);
            $end_data[] = $data;
        }

        $message_metadata = array_pop($end_data);

        foreach ($end_data as $end_packet) {
            $this->core->sendMessage($socket_id, json_encode(['type' => 'close'] + $end_packet));
        }

        if (!empty($llm_data)) {
            $this->runProcWorker();
            $this->in_process = true;
            $this->core->addSessionHistory(['role' => 'user', 'content' => $llm_data]);
            $this->core->agent_llm->chat($socket_id, $message_metadata, $this->core->getSessionHistory());
        }

        unset($socket_id, $message, $is_binary, $end_data, $llm_data, $messages, $line, $data, $type_method, $result, $message_metadata, $end_packet);
    }

    /**
     * Callback for sending string messages.
     *
     * @param string $socket_id
     *
     * @return array
     * @throws \Exception
     */
    public function onSendString(string $socket_id): array
    {
        if (!empty($this->onsend_messages[$socket_id])) {
            $llm_data = [];

            while (is_null($message = array_shift($this->onsend_messages[$socket_id]))) {
                $llm_data[] = [
                    'type' => 'text',
                    'text' => $message
                ];
            }

            $current_count = $this->core->addSessionHistory(['role' => 'user', 'content' => $llm_data]);

            if ($current_count > $this->core->agent_config['agent_memory']['max_history'] * 3) {
                $this->core->cleanSessionHistory();
            }

            $this->in_process = true;

            $this->core->agent_llm->chat(
                $socket_id,
                [
                    'sessionId' => $this->socket_session[$socket_id]['sessionId'] ?? 'default',
                    'messageId' => 'system-' . microtime(true),
                ],
                $this->core->getSessionHistory()
            );

            unset($llm_data, $message, $current_count);
        }

        unset($socket_id);
        return [];
    }

    /**
     * WebSocket close callback.
     *
     * @param string $socket_id
     *
     * @return void
     */
    public function onClose(string $socket_id): void
    {
        unset($this->socket_session[$socket_id], $socket_id);
    }
}