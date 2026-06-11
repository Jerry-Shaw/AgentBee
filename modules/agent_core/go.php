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

use modules\agent_core\lib\message;
use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\SocketMgr;

class go extends Factory
{
    public core    $core;
    public utils   $utils;
    public message $message;

    public int $child_idx = 100;

    public bool $in_process    = false;
    public bool $clean_warning = false;

    public array $child_workers  = [];
    public array $socket_session = [];
    public array $stream_buffers = [];

    public array $coming_messages = [];
    public array $onsend_messages = [];
    public array $message_buffers = [];

    /**
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function __construct()
    {
        $this->core  = core::new();
        $this->utils = utils::new();

        $this->init();

        $this->message         = message::new();
        $this->core->socketMgr = SocketMgr::new();
    }

    /**
     * @param bool $reload
     *
     * @return void
     * @throws \ReflectionException
     */
    public function init(bool $reload = false): void
    {
        $this->utils->debug($reload ? 'Reloading...' : 'Initializing...', 'trace');
        $this->core->initCore($reload);
        $this->utils->debug('Loading Tools...', 'debug');
        $this->core->initModule('tools');
        $this->utils->debug('Loading Skills...', 'debug');
        $this->core->initModule('skills');
        $this->utils->debug('Loading Providers...', 'debug');
        $this->core->initProvider();

        $this->utils->debug('Model ID: ' . ($this->core->agent_config['agent_llm']['model'] ?? 'NONE'), 'trace');
        $this->utils->debug('SandBox mode: ' . ($this->core->agent_config['sandbox_mode'] ? 'ON' : 'OFF'), 'trace');

        $workspace_path = $this->core->agent_config['workspace_path'] ?? '';
        $this->utils->debug('Checking workspace: ' . $workspace_path, 'trace');
        if ('' !== $workspace_path && !is_dir($workspace_path)) {
            try {
                $this->utils->debug('Creating workspace: ' . $workspace_path, 'trace');
                mkdir($workspace_path, 0777, true);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Start WebSocket server and worker process.
     *
     * @return void
     * @throws \Exception
     */
    public function start(): void
    {
        $memory_limit = $this->core->agent_config['memory_limit'] ?? '4G';

        ini_set('memory_limit', $memory_limit);
        $this->utils->debug('Set memory limit to: ' . $memory_limit, 'trace');

        $this->core->runProcWorker($this->core->openai_idx, $this->core->agent_config['agent_llm']['main_worker'], [$this, 'streamWorkerHandler']);

        $this->utils->debug('Ready to start ' . AGENT_NAME . ' v' . AGENT_VERSION, 'trace');

        try {
            $server_host = 'tcp://' . $this->core->agent_config['agent_server']['host'] . ':' . $this->core->agent_config['agent_server']['port'];
            $this->utils->debug('Local IP address: ' . implode(', ', $this->core->OSMgr->getIPv4()), 'trace');
            $this->utils->debug('Ready to start server: ' . $server_host, 'trace');
            $this->core->socketMgr
                ->setDebugMode($this->core->agent_config['socket_debug'])
                ->setAliveTimeout($this->core->agent_config['agent_server']['ping_interval'])
                ->setEventListener('onHandshake', [$this, 'onHandshake'])
                ->setEventListener('onHeartbeat', [$this, 'onHeartbeat'])
                ->setEventListener('onMessage', [$this, 'onMessage'])
                ->setEventListener('onSendString', [$this, 'onSendString'])
                ->setEventListener('onClose', [$this, 'onClose'])
                ->listenTo($server_host, true);
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
        $stdout_stream = $context['stdout'];
        $data_chunk    = fread($stdout_stream, 8192);

        $this->utils->debug('streamWorkerHandler: Data chunk received', 'debug');

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

            $this->utils->debug('streamWorkerHandler: ' . $message['type'] . '->' . $payload_type, 'debug');

            switch ($message['type']) {
                case 'stream':
                    $stream_msg = json_encode($payload, JSON_FORMAT);

                    if (isset($this->socket_session[$message['socket_id']])) {
                        $this->core->sendMessage($message['socket_id'], $stream_msg);
                    } else {
                        $this->message_buffers[] = $stream_msg;
                    }

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
                    switch ($payload_type) {
                        case 'readImage':
                            $this->coming_messages = array_merge($this->coming_messages, $payload['data']);
                            break;

                        case 'WorkerBee':
                            switch ($payload['data']['action']) {
                                case 'start':
                                    if (isset($this->child_workers[$payload['data']['worker_name']])) {
                                        // WorkerBee already exists, change name or talk
                                        $this->utils->debug('WorkerBee already exists: ' . $payload['data']['worker_name'] . ' | ' . $this->child_workers[$payload['data']['worker_name']]['worker_role'] . ' already exists!', 'trace');

                                        $this->onsend_messages[] = '[WorkerBee] "' . $payload['data']['worker_name'] . '" 已存在。请换名或直接使用 (角色: ' . $this->child_workers[$payload['data']['worker_name']]['worker_role'] . ')';
                                        break;
                                    }

                                    $proc_idx = $this->core->runProcWorker(
                                        $this->child_idx++,
                                        $this->core->agent_config['agent_llm']['child_worker'],
                                        [$this, 'streamWorkerHandler']
                                    );

                                    $this->utils->debug('WorkerBee started: ' . $payload['data']['worker_name'] . ' (WorkerID: ' . $proc_idx . ', ' . $payload['data']['worker_role'] . ')', 'trace');

                                    $this->onsend_messages[] = '[WorkerBee] "' . $payload['data']['worker_name'] . '" | ' . $payload['data']['worker_role'] . '，已就绪，等待指令';

                                    $start_datetime = date('Y-m-d H:i:s');

                                    $this->child_workers[$payload['data']['worker_name']] = [
                                        'proc_idx'    => $proc_idx,
                                        'socket_id'   => $payload['data']['socket_id'],
                                        'worker_name' => $payload['data']['worker_name'],
                                        'worker_role' => $payload['data']['worker_role'],
                                        'status'      => 'idle',
                                        'last_talk'   => $start_datetime,
                                        'talk_count'  => 0
                                    ];

                                    $this->core->procMgr->writeProc($proc_idx, json_encode([
                                        'cmd'           => 'start',
                                        'proc_idx'      => $proc_idx,
                                        'socket_id'     => $payload['data']['socket_id'],
                                        'worker_name'   => $payload['data']['worker_name'],
                                        'worker_role'   => $payload['data']['worker_role'],
                                        'system_prompt' => $payload['data']['system_prompt'],
                                    ], JSON_FORMAT));
                                    break;

                                case 'talk':
                                    $worker_info = $this->child_workers[$payload['data']['worker_name']] ?? [];

                                    if (empty($worker_info) || 0 === $this->core->procMgr->getStatus($worker_info['proc_idx'])) {
                                        // WorkerBee died, notice main worker
                                        $this->onsend_messages[] = '[WorkerBee] "' . $payload['data']['worker_name'] . '" 进程已终止，消息发送失败';
                                        break;
                                    }

                                    if ('processing' === $this->child_workers[$payload['data']['worker_name']]['status']) {
                                        $this->onsend_messages[] = '[WorkerBee] "' . $payload['data']['worker_name'] . '" 之前任务未完成，请等回复后再继续。';
                                        break;
                                    }

                                    $this->child_workers[$payload['data']['worker_name']]['status'] = 'processing';

                                    $worker_message = json_encode([
                                        'type'       => 'content',
                                        'sender'     => $this->core->agent_config['agent_llm']['main_worker'],
                                        'workerName' => AGENT_NAME,
                                        'workerRole' => 'Assistant',
                                        'sessionId'  => 'subSession-' . uniqid('', true),
                                        'messageId'  => 'subMessage-' . uniqid('', true),
                                        'isSubTalk'  => 1,
                                        'data'       => $payload['data']['message']
                                    ], JSON_FORMAT);

                                    if (isset($this->socket_session[$worker_info['socket_id']])) {
                                        $this->core->sendMessage($worker_info['socket_id'], $worker_message);
                                    } else {
                                        $this->utils->debug('Client offline, message queued', 'trace');
                                        $this->message_buffers[] = $worker_message;
                                    }

                                    $this->core->procMgr->writeProc(
                                        $worker_info['proc_idx'],
                                        json_encode([
                                            'cmd'     => 'talk',
                                            'message' => $payload['data']['message'],
                                        ], JSON_FORMAT)
                                    );
                                    break;

                                case 'close':
                                    $worker_info = $this->child_workers[$payload['data']['worker_name']] ?? [];

                                    if (!empty($worker_info) && 0 < $this->core->procMgr->getStatus($worker_info['proc_idx'])) {
                                        $this->utils->debug('WorkerBee closed: ' . $worker_info['worker_name'] . ' (WorkerID:' . $worker_info['proc_idx'] . ', ' . $worker_info['worker_role'] . ')', 'trace');

                                        $this->message_buffers[] = json_encode(['type' => 'close', 'isSubTalk' => 1, 'data' => $worker_info], JSON_FORMAT);

                                        $this->core->procMgr->writeProc($worker_info['proc_idx'], json_encode(['cmd' => 'close'], JSON_FORMAT));
                                        $this->core->procMgr->close($worker_info['proc_idx']);
                                        unset($this->child_workers[$payload['data']['worker_name']]);
                                    } else {
                                        $this->utils->debug('WorkerBee not found: ' . $worker_info['worker_name'], 'trace');
                                    }

                                    $this->onsend_messages[] = '[WorkerBee] "' . $payload['data']['worker_name'] . '" 进程已终止';
                                    break;

                                case 'list':
                                    $now   = time();
                                    $lines = ['[WorkerBee] 当前活跃Worker列表：'];

                                    foreach ($this->child_workers as $worker) {
                                        $elapsed = $now - strtotime($worker['last_talk']);

                                        $lines[] = '- "' . $worker['worker_name']
                                            . '" (ID:' . $worker['proc_idx'] . ')'
                                            . ' | 角色:' . $worker['worker_role']
                                            . ' | 状态:' . $worker['status']
                                            . ' | 对话:' . $worker['talk_count'] . '轮'
                                            . ' | 沉默:' . $elapsed . '秒';
                                    }

                                    $this->onsend_messages[] = implode("\n", $lines);
                                    break;
                            }
                            break;
                    }
                    break;

                case 'end':
                    $current_history = $this->core->getSessionHistory();

                    switch ($payload_type) {
                        case 'tools':
                            $this->in_process = true;
                            $this->utils->debug('streamWorkerHandler: LLM Tool calls', 'trace');

                            $this->core->agent_llm->chat(
                                $message['socket_id'],
                                array_intersect_key(
                                    $payload,
                                    [
                                        'sessionId' => '',
                                        'messageId' => '',
                                    ]
                                ),
                                $current_history
                            );
                            break;

                        case 'end':
                            $this->in_process = false;

                            $max_history   = $this->core->agent_config['agent_memory']['max_history'];
                            $warning_count = $max_history * 2;
                            $limit_count   = $max_history * 3;

                            if ($this->core->agent_config['agent_llm']['child_worker'] === $payload['sender'] && '' !== $payload['data']) {
                                $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' | ' . $payload['workerRole'] . ' replied', 'trace');
                                $this->onsend_messages[] = '[WorkerBee] 来自 ["' . $payload['workerName'] . '" | ' . $payload['workerRole'] . ']:' . "\n" . $payload['data'];

                                $this->child_workers[$payload['workerName']]['status']     = 'idle';
                                $this->child_workers[$payload['workerName']]['last_talk']  = date('Y-m-d H:i:s');
                                $this->child_workers[$payload['workerName']]['talk_count'] = $payload['talk_count'];

                                if ($payload['talk_count'] > $warning_count) {
                                    $this->utils->debug('WorkerBee: History too long (' . $payload['talk_count'] . '/' . $warning_count . ', config: ' . $max_history . ')', 'trace');
                                    $this->onsend_messages[] = '[WorkerBee] "' . $payload['workerName'] . '" | ' . $payload['workerRole'] . '，对话已达上限，请保存重要内容后关闭Worker';
                                }
                            }

                            $current_count = count($current_history);

                            if ($current_count < $warning_count) {
                                $this->clean_warning = false;
                                break;
                            } elseif (!$this->clean_warning) {
                                $this->clean_warning     = true;
                                $this->onsend_messages[] = '[历史 ' . $current_count . '/' . $max_history . '] 自动：①总结关键信息(需求/回复/工具结果)→存记忆(daily/important/system,临时ram)；②调用清理工具删旧工具对；③告知用户(概要/层级/剩余数)。| 超 ' . $limit_count . ' 条强制清理，及时保存。';

                                $this->utils->debug('streamWorkerHandler: History too long (' . $current_count . '/' . $limit_count . ', config: ' . $max_history . ')', 'trace');
                            }
                            break;
                    }

                    $this->utils->debug('streamWorkerHandler: LLM Stream end', 'trace');
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
        $this->utils->debug('Socket: New client: ' . $socket_id, 'trace');
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

        $this->utils->debug('ScheduledTask: Running task jobs (' . count($task_list) . ')', 'trace');

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

        $this->utils->debug('UserMessage: receiving [' . (!$is_binary ? 'TEXT' : 'BINARY') . '] message', 'debug');

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

            if ('stop' === $data['type']) {
                $this->core->agent_llm->abort($socket_id);
                $this->utils->debug('UserMessage: Send abort to LLM', 'debug');
                continue;
            }

            $type_method = 'process_' . $data['type'];

            if (!method_exists($this->message, $type_method)) {
                $this->utils->debug('UserMessage: Incorrect message type: ' . $data['type'], 'debug');
                continue;
            }

            $result = $this->message->$type_method($socket_id, $data['content']);

            if (!$result['need_llm']) {
                // Other actions
                $this->utils->debug('UserMessage: ' . $data['type'] . '->' . ($result['data']['act'] ?? 'Unsupported'), 'debug');

                $response = ['type' => $data['type']] + $result['data'];
                $this->core->sendMessage($socket_id, json_encode($response, JSON_FORMAT));

                // Reload config
                if ('saveConfig' === $result['data']['act']) {
                    $this->init(true);
                    $this->core->agent_llm->reload();
                    $this->utils->debug('UserMessage: ' . $data['type'] . '->reloaded', 'trace');
                }

                unset($socket_id, $message, $is_binary, $messages, $line, $data, $type_method, $result, $response);
                return;
            }

            // LLM action
            $this->socket_session[$socket_id] = [
                'sessionId'   => $data['sessionId'],
                'messageId'   => $data['messageId'],
                'sender_name' => AGENT_NAME,
                'sender_role' => 'Your Assistant',
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
            $this->in_process = true;
            $this->core->addSessionHistory(['role' => 'user', 'content' => $llm_data]);
            $this->utils->debug('UserMessage: Send message to LLM', 'debug');
            $this->core->runProcWorker($this->core->openai_idx, $this->core->agent_config['agent_llm']['main_worker'], [$this, 'streamWorkerHandler']);
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
        if ($this->in_process) {
            return [];
        }

        if (!empty($this->message_buffers)) {
            while (!is_null($buffer = array_shift($this->message_buffers))) {
                $this->core->sendMessage($socket_id, $buffer);
            }
        }

        if (!empty($this->onsend_messages)) {
            $llm_data = [];

            while (!is_null($message = array_shift($this->onsend_messages))) {
                $llm_data[] = [
                    'type' => 'text',
                    'text' => $message
                ];
            }

            $current_count = $this->core->addSessionHistory(['role' => 'user', 'content' => $llm_data]);

            if ($current_count > $this->core->agent_config['agent_memory']['max_history'] * 3) {
                $new_count = $this->core->cleanSessionHistory();
                $this->utils->debug('System: History truncated (' . $current_count . '->' . $new_count . ', config: ' . $this->core->agent_config['agent_memory']['max_history'] . ')', 'trace');
            }

            $this->in_process = true;

            $this->utils->debug('System: Send message to LLM', 'debug');

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
        $this->utils->debug('Socket: Client closed: ' . $socket_id, 'trace');
        unset($this->socket_session[$socket_id], $socket_id);
    }
}