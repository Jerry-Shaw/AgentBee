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

    public bool $clean_notice = false;

    public array $socket_session = [];

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

        $this->core->agent_llm->setWorkType('procWorker');

        $this->core->procMgr->command([
            $this->core->OSMgr->getPhpPath(),
            $this->core->app->script_path,
            '-c=' . $this->core->agent_config['agent_llm']['provider'] . '/' . $this->core->agent_config['agent_llm']['work_name']
        ])->run(core::PROC_IDX_OPENAI);

        try {
            $this->core->socketMgr
                ->setDebugMode($this->core->agent_config['debug'])
                ->addExternalProc(
                    $this->core->procMgr->getProc(core::PROC_IDX_OPENAI),
                    [$this, 'streamWorkerHandler'],
                    [$this, 'streamWorkerHandler']
                )
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
        static $line_buffers = [];

        $stdout_stream = $context['stdout'];
        $data_chunk    = fread($stdout_stream, 8192);

        if (false === $data_chunk || '' === $data_chunk) {
            $this->core->socketMgr->cleanExternalProc($external_stream_id);
            unset($line_buffers[$external_stream_id]);
            return;
        }

        $buffer = &$line_buffers[$external_stream_id];
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

            $action    = $message['action'] ?? '';
            $socket_id = $message['socket_id'] ?? '';
            $payload   = $message['payload'] ?? [];

            switch ($action) {
                case 'sync':
                    if (isset($payload['data']) && !empty($payload['data'])) {
                        $this->core->session_history = $payload['data'];
                    }
                    break;

                case 'history':
                    if (isset($payload['type'], $payload['data'])) {
                        $this->core->addSessionHistory($payload['data']);
                    }
                    break;

                case 'message':
                    $this->core->sendMessage($socket_id, json_encode($payload));
                    break;

                case 'action':
                    $payload_type = $payload['type'] ?? '';

                    if ('complete' === $payload_type) {
                        $tool_calls = $payload['data']['tool_calls'] ?? false;

                        if ($tool_calls) {
                            $message_metadata = array_filter(
                                $payload,
                                function (string $key): bool
                                {
                                    return !in_array($key, ['type', 'data'], true);
                                },
                                ARRAY_FILTER_USE_KEY
                            );

                            $current_history = $this->core->getSessionHistory();
                            $this->core->agent_llm->chat($socket_id, $message_metadata, $current_history);
                        }
                    } elseif ('end' === $payload_type) {
                        $this->core->sendMessage($socket_id, json_encode(['type' => 'end']));
                        $this->core->socketMgr->cleanExternalProc($external_stream_id);

                        unset($line_buffers[$external_stream_id]);
                    }

                    $current_history = $this->core->getSessionHistory();
                    $current_count   = count($current_history);
                    $max_history     = $this->core->agent_config['agent_memory']['max_history'] ?? 20;
                    $double_history  = $max_history * 2;

                    if ($current_count < $max_history) {
                        $this->clean_notice = false;
                        break;
                    }

                    if (!$this->clean_notice && $current_count > $max_history) {
                        $this->clean_notice = true;

                        $system_prompt = '【系统提醒】当前对话历史较长（已有 ' . $current_count . ' 条，上限 ' . $max_history . ' 条）。请自动完成以下操作，并以自然语气告知用户：' . "\n\n" .
                            '1. 总结关键信息（用户需求、助手回复、重要工具结果等），保存到对应记忆（daily/important/system，临时内容可存 ram）。' . "\n" .
                            '2. 调用清理工具删除旧工具调用对，精简历史。' . "\n" .
                            '3. 完成后，向用户说明保存的内容概要、存储层级及剩余消息数，语气自然。' . "\n\n" .
                            '【特别提醒】对话历史超过 ' . $double_history . ' 条时，系统将强制清理上下文，重要信息可能丢失，请及时保存。';

                        $this->core->addSessionHistory(['role' => 'user', 'content' => $system_prompt]);

                        $current_history = $this->core->getSessionHistory();

                        $this->core->agent_llm->chat(
                            $socket_id,
                            [
                                'sessionId' => $this->socket_session[$socket_id] ?? 'sessionId undefined',
                                'messageId' => 'System Request'
                            ],
                            $current_history);
                    }

                    if ($current_count > $double_history) {
                        $this->core->cleanSessionHistory();
                    }

                    break;
            }
        }

        if (feof($stdout_stream)) {
            unset($line_buffers[$external_stream_id]);
            $this->core->socketMgr->cleanExternalProc($external_stream_id);
        }

        unset($external_stream_id, $context, $stdout_stream, $data_chunk, $buffer, $line_pos, $line, $message, $action, $socket_id, $payload, $payload_type, $tool_calls, $message_metadata, $current_history);
    }

    /**
     * WebSocket handshake callback.
     *
     * @param string $socket_id
     * @param string $websocket_protocol
     *
     * @return bool
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

        $task_content = '【定时任务】以下是待执行的定时任务列表（JSON 格式）：' . PHP_EOL .
            $task_json . PHP_EOL . PHP_EOL .
            '请按顺序处理每个任务：' . PHP_EOL .
            '1. 根据 task_prompt 执行相应操作（发送提醒、调用工具、回答问题等）。' . PHP_EOL .
            '2. 执行后将任务摘要和执行结果按需存入 daily 记忆层。重要事件可额外存入 important 层。' . PHP_EOL .
            '3. 全部处理完毕后，向用户说明任务概要、处理结果、存储层级，语气自然。';

        $this->core->addSessionHistory(['role' => 'user', 'content' => $task_content]);

        $current_history = $this->core->getSessionHistory();

        $this->core->agent_llm->chat(
            $socket_id,
            [
                'sessionId' => $this->socket_session[$socket_id] ?? 'sessionId undefined',
                'messageId' => 'Task Request'
            ],
            $current_history);

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

        $end_data = [];
        $llm_data = [];
        $messages = str_contains($message, "\n") ? explode("\n", $message) : [$message];

        foreach ($messages as $line) {
            $data = json_decode($line, true);
            if (!is_array($data) || !isset($data['type'])) {
                continue;
            }

            $this->socket_session[$socket_id] = $data['sessionId'] ?? 'sessionId undefined';

            $type_method = 'process_' . $data['type'];
            if (!method_exists($this->message, $type_method)) {
                continue;
            }

            $result = $this->message->$type_method($socket_id, $data['content'] ?? $data);
            if ($result['agent_llm']) {
                $llm_data = $result['content'];
            }

            unset($data['content']);
            $end_data[] = $data;
        }

        $message_metadata = array_pop($end_data);
        foreach ($end_data as $end_packet) {
            $this->core->sendMessage($socket_id, json_encode(['type' => 'close'] + $end_packet));
        }

        if (!empty($llm_data)) {
            $this->core->addSessionHistory(['role' => 'user', 'content' => $llm_data]);
            $current_history = $this->core->getSessionHistory();
            $this->core->agent_llm->chat($socket_id, $message_metadata, $current_history);
        }

        unset($socket_id, $message, $is_binary, $end_data, $llm_data, $messages, $line, $data, $type_method, $result, $message_metadata, $end_packet, $current_history);
    }

    /**
     * Callback for sending string messages.
     *
     * @param string $socket_id
     *
     * @return array
     */
    public function onSendString(string $socket_id): array
    {
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
        unset($socket_id);
    }
}