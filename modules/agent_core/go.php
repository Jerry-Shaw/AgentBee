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
use modules\agent_openai\go as openai;
use modules\agent_skills\Memory\go as memory;
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\SocketMgr;

class go extends Factory
{
    public core    $core;
    public utils   $utils;
    public message $message;

    public memory $memory;
    public openai $openai;

    public bool $in_process    = false;
    public bool $clean_warning = false;

    /**
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function __construct()
    {
        $this->core  = core::new();
        $this->utils = utils::new();

        $this->init();

        $this->memory          = memory::new();
        $this->openai          = openai::new();
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

        $this->utils->debug('Model ID: ' . ($this->utils->agent_config['agent_llm']['model'] ?? 'NONE'), 'trace');
        $this->utils->debug('SandBox mode: ' . ($this->utils->agent_config['sandbox_mode'] ? 'ON' : 'OFF'), 'trace');

        $workspace_path = $this->utils->agent_config['workspace_path'] ?? '';
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
     * @param int      $proc_idx
     * @param string   $worker_name
     * @param callable $output_handler
     *
     * @return int
     * @throws \Exception
     */
    public function runProcWorker(int $proc_idx, string $worker_name, callable $output_handler): int
    {
        $worker_status = $this->core->utils->procMgr->getStatus($proc_idx);

        if (0 < $worker_status) {
            return $proc_idx;
        }

        $this->core->utils->procMgr->close($proc_idx);

        $proc_idx = $this->core->utils->procMgr->command(
            [
                $this->core->OSMgr->getPhpPath(),
                $this->core->app->script_path,
                '-c', 'agent_openai/' . $worker_name
            ]
        )->run($proc_idx);

        $worker_pid = $this->core->utils->procMgr->getPid($proc_idx);

        $this->utils->debug($worker_name . ' started with pid: ' . $worker_pid, 'trace');
        $this->utils->debug('Register Output Handler', 'debug');

        $this->core->socketMgr->addExternalProc(
            $this->core->utils->procMgr->getProc($proc_idx, 'stdout', 'process'),
            ['stdout' => $output_handler]
        );

        if (WORKER_MAIN === $worker_name) {
            $this->utils->debug('Create shared memory for ' . $worker_name, 'debug');
            $this->openai->setShmop($worker_pid);
        }

        unset($worker_name, $output_handler, $worker_status, $worker_pid);
        return $proc_idx;
    }

    /**
     * Start WebSocket server and worker process.
     *
     * @return void
     * @throws \Exception
     */
    public function start(): void
    {
        $memory_limit = $this->utils->agent_config['memory_limit'] ?? '4G';

        ini_set('memory_limit', $memory_limit);
        $this->utils->debug('Set memory limit to: ' . $memory_limit, 'trace');

        $this->runProcWorker($this->utils->getMainIDX(), WORKER_MAIN, [$this, 'streamWorkerHandler']);

        $this->utils->debug('Ready to start ' . AGENT_NAME . ' v' . AGENT_VERSION, 'trace');

        try {
            $server_host = 'tcp://' . $this->utils->agent_config['agent_server']['host'] . ':' . $this->utils->agent_config['agent_server']['port'];
            $this->utils->debug('Local IP address: ' . implode(', ', $this->core->OSMgr->getIPv4()), 'trace');
            $this->utils->debug('Ready to start server: ' . $server_host, 'trace');
            $this->core->socketMgr
                ->setDebugMode($this->utils->agent_config['socket_debug'])
                ->setAliveTimeout($this->utils->agent_config['agent_server']['ping_interval'])
                ->setEventListener('onHandshake', [$this, 'onHandshake'])
                ->setEventListener('onHeartbeat', [$this, 'onHeartbeat'])
                ->setEventListener('onMessage', [$this, 'onMessage'])
                ->setEventListener('onSendString', [$this, 'onSendString'])
                ->setEventListener('onClose', [$this, 'onClose'])
                ->listenTo($server_host, true);
        } catch (\Throwable $throwable) {
            $this->utils->debug('Failed to start server: ' . $throwable->getMessage(), 'trace');
        }
    }

    /**
     * Get system memory prompt.
     *
     * @return array
     * @throws \Exception
     */
    public function getSystemPrompt(): array
    {
        $system_default = $this->utils->getMainPrompt();
        $system_memory  = $this->memory->read('system', 0, 0);

        if (!empty($system_memory['messages'])) {
            $memory = ['', '=== 重要个性设定 ==='];

            foreach ($system_memory['messages'] as $message) {
                $memory[] = $message['content'];
            }

            $system_default['content'] .= implode("\n", $memory);
        }

        unset($system_memory, $memory, $message);
        return $system_default;
    }

    /**
     * Callback for external stream (stdout from worker).
     *
     * @param string $external_stream_id
     * @param array  $context
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function streamWorkerHandler(string $external_stream_id, array $context): void
    {
        $stdout_stream = $context['stdout'];
        $data_chunk    = fread($stdout_stream, 8192);

        if (false === $data_chunk || '' === $data_chunk) {
            unset($this->utils->stream_buffers[$external_stream_id]);
            return;
        }

        $buffer = &$this->utils->stream_buffers[$external_stream_id];
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

            $this->utils->debug($payload['workerName'] . ': ' . $message['type'] . '->' . $payload_type, 'debug');

            switch ($message['type']) {
                case 'stream':
                    if ('length' === $payload_type) {
                        $metadata = $this->utils->getMessageMarker(
                            $payload['sender'],
                            $payload['workerName'],
                            $payload['workerRole'],
                            $payload['workerName'],
                            $payload['isSubTalk'],
                            $payload['messageId']
                        );

                        $proc_idx = WORKER_MAIN === $payload['sender']
                            ? $this->utils->getMainIDX()
                            : ($this->utils->getChildWorker('WorkerBee', $payload['workerName'])['proc_idx'] ?? -1);

                        if (-1 !== $proc_idx) {
                            $this->openai->talk(
                                $proc_idx,
                                'talk',
                                $this->utils->getSessionHistory($payload['workerName']),
                                $metadata + ['socket_id' => $payload['socket_id']]
                            );
                        }

                        break;
                    }

                    $stream_msg = json_encode($payload, JSON_FORMAT);

                    if (isset($this->utils->socket_session[$message['socket_id']])) {
                        $this->core->sendMessage($message['socket_id'], $stream_msg);
                    } else {
                        $this->utils->message_buffers[] = $stream_msg;
                    }

                    if ('error' === $payload_type) {
                        unset($this->utils->stream_buffers[$external_stream_id]);
                    }
                    break;

                case 'history':
                    if (isset($payload['data'])) {
                        switch ($payload_type) {
                            case 'add':
                                $this->utils->addSessionHistory($payload['workerName'], $payload['data']);
                                break;

                            case 'sync':
                                $this->utils->setSessionHistory($payload['workerName'], $payload['data']);
                                break;
                        }
                    }
                    break;

                case 'context':
                    // Reset main worker status
                    $this->in_process = false;

                    switch ($payload_type) {
                        case 'readImage':
                            $this->utils->coming_messages = array_merge($this->utils->coming_messages, $payload['data']);
                            break;

                        case 'callHandler':
                            if (!isset($payload['data']['handler']) || !isset($payload['data']['action'])) {
                                $this->utils->debug('Handler: handler or action NOT found!', 'trace');
                                break;
                            }

                            try {
                                $payload['data']['ext_id'] = $context['ext_id'];

                                $handler = $payload['data']['handler']::new();
                                $handler->{$payload['data']['action']}($payload['data'], $this);
                            } catch (\Throwable $throwable) {
                                $this->utils->debug('Handler: ' . $payload['data']['handler'] . '->' . $payload['data']['action'] . ' (' . $throwable->getMessage() . ')', 'trace');
                                $this->core->error->exceptionHandler($throwable, false, false);
                                unset($throwable);
                            }
                            unset($handler);
                            break;

                        case 'cleanContext':
                            $this->utils->cleanSessionHistory(
                                $payload['data']['worker_name'],
                                $payload['data']['keep_normal'],
                                $payload['data']['keep_tool_pairs'],
                                $payload['data']['aggressive_mode'],
                                $payload['data']['tool_call_id']
                            );

                            $this->utils->addSessionHistory(
                                $payload['data']['worker_name'],
                                ['role' => 'user', 'content' => '[系统提醒] 上下文已清理，继续原有任务。']
                            );
                            break;
                    }
                    break;

                case 'end':
                    switch ($payload_type) {
                        case 'tools':
                            if (0 < count($this->utils->onsend_messages)) {
                                $this->utils->debug('System: Tool calls completed, fetching queued messages.', 'trace');

                                $llm_data = [];
                                while (!is_null($coming_message = array_shift($this->utils->onsend_messages))) {
                                    $llm_data[] = [
                                        'type' => 'text',
                                        'text' => $coming_message
                                    ];
                                }

                                $count_llm_data = count($llm_data);
                                if (0 < $count_llm_data) {
                                    $this->utils->debug('System: Adding ' . $count_llm_data . ' message(s) to history', 'trace');
                                    $this->utils->addSessionHistory(WORKER_MAIN, ['role' => 'user', 'content' => $llm_data]);
                                }

                                unset($llm_data, $coming_message, $count_llm_data);
                            }

                            $this->utils->debug($payload['workerName'] . ': LLM collecting tool results', 'trace');

                            if (WORKER_MAIN === $payload['sender']) {
                                $this->in_process = true;

                                $worker_idx = $this->utils->getMainIDX();
                            } else {
                                $worker_info = $this->utils->getChildWorker('WorkerBee', $payload['workerName']);

                                if (empty($worker_info)) {
                                    break;
                                }

                                $worker_idx = $worker_info['proc_idx'];
                                $this->utils->setChildWorker('WorkerBee', $worker_info['worker_name'], 'status', 'calling_tools');
                            }

                            $metadata = $this->utils->getMessageMarker(
                                $payload['sender'],
                                $payload['workerName'],
                                $payload['workerRole'],
                                $payload['workerName'],
                                $payload['isSubTalk'],
                                $payload['messageId']
                            );

                            $this->openai->talk(
                                $worker_idx,
                                'talk',
                                $this->utils->getSessionHistory($payload['workerName']),
                                $metadata + ['socket_id' => $payload['socket_id']]
                            );
                            break;

                        case 'end':
                            $this->utils->debug($payload['workerName'] . ': LLM Stream end', 'trace');

                            $max_ctx_len   = $this->utils->agent_config['max_ctx_len'];
                            $warning_count = $max_ctx_len * 2;
                            $limit_count   = $max_ctx_len * 3;

                            if (WORKER_MAIN === $payload['sender']) {
                                $this->in_process = false;

                                $current_count = $this->utils->countSessionHistory($payload['workerName']);

                                if ($current_count < $warning_count) {
                                    $this->clean_warning = false;
                                    break;
                                } elseif (!$this->clean_warning) {
                                    $this->clean_warning            = true;
                                    $this->utils->onsend_messages[] = '[系统提醒] 上下文 ' . $current_count . '/' . $max_ctx_len . '，超过 ' . $limit_count . ' 条将强制清理，建议主动清理：①保存关键信息到记忆；②清理过期上下文；③简单告知用户；④继续原任务。';

                                    $this->utils->debug($payload['sender'] . ': History too long (' . $current_count . '/' . $limit_count . ', config: ' . $max_ctx_len . ')', 'trace');
                                }
                            } else {
                                $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' finished reply', 'trace');

                                if ('' !== $payload['data']) {
                                    $this->utils->onsend_messages[] = '[WorkerBee] 来自 ["' . $payload['workerName'] . '" | ' . $payload['workerRole'] . ']:' . "\n" . $payload['data'];
                                }

                                $this->utils->setChildWorker('WorkerBee', $payload['workerName'], 'status', 'ready');
                                $this->utils->setChildWorker('WorkerBee', $payload['workerName'], 'last_talk', date('Y-m-d H:i:s'));
                                $this->utils->setChildWorker('WorkerBee', $payload['workerName'], 'talk_count', $payload['talk_count']);

                                if ($payload['talk_count'] > $limit_count) {
                                    $this->utils->debug('WorkerBee: History too long (' . $payload['talk_count'] . '/' . $warning_count . ', config: ' . $max_ctx_len . ')', 'trace');
                                    $this->utils->onsend_messages[] = '[WorkerBee] "' . $payload['workerName'] . '" | ' . $payload['workerRole'] . '，对话已达上限，请保存重要内容后关闭Worker，必要时可重启继续任务。';
                                }
                            }
                            break;
                    }
                    break;
            }
        }

        if (feof($stdout_stream)) {
            unset($this->utils->stream_buffers[$external_stream_id]);
        }

        unset($external_stream_id, $context, $stdout_stream, $data_chunk, $buffer, $line_pos, $line, $message, $payload, $payload_type);
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
     * @throws \Throwable
     */
    public function onHeartbeat(string $socket_id): string
    {
        if ($this->in_process) {
            return '';
        }

        $task_list = $this->memory->runTask();

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

        $task_content = '[定时任务] 任务：' . "\n" . $task_json . "\n" . '步骤：①执行（提醒/工具/问答）；②重要存 daily，特别重要存 important，琐事不存；③完成后简要汇报结果、层级，语气自然。';

        $llm_data = [];

        $this->in_process = true;

        if (!empty($this->utils->onsend_messages)) {
            while (!is_null($coming_message = array_shift($this->utils->onsend_messages))) {
                $llm_data[] = [
                    'type' => 'text',
                    'text' => $coming_message
                ];
            }
        }

        $llm_data[] = [
            'type' => 'text',
            'text' => $task_content
        ];

        $this->utils->addSessionHistory(WORKER_MAIN, ['role' => 'user', 'content' => $llm_data]);

        $metadata = $this->utils->getMessageMarker(
            WORKER_MAIN,
            WORKER_MAIN,
            'Assistant',
            AGENT_NAME,
            0
        );

        $this->openai->talk(
            $this->utils->getMainIDX(),
            'talk',
            $this->utils->getSessionHistory(WORKER_MAIN),
            $metadata + ['socket_id' => $socket_id]
        );

        unset($socket_id, $task_list, $task_jobs, $task_json, $task_content, $metadata);
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
     * @throws \Throwable
     */
    public function onMessage(string $socket_id, string $message, bool $is_binary): void
    {
        if ('' === $message) {
            return;
        }

        $this->utils->debug('User: receiving [' . (!$is_binary ? 'TEXT' : 'BINARY') . '] message', 'debug');

        if ($is_binary) {
            $message = $this->message->process_binary($socket_id, $message);
        }

        $this->utils->socket_session[$socket_id] = $this->utils->getMessageMarker(
            WORKER_MAIN,
            WORKER_MAIN,
            'Assistant',
            AGENT_NAME,
            0
        );

        $end_data = [];
        $llm_data = [];
        $messages = str_contains($message, "\n") ? explode("\n", $message) : [$message];

        foreach ($messages as $line) {
            $data = json_decode($line, true);

            if (!is_array($data) || !isset($data['type'])) {
                continue;
            }

            if ('stop' === $data['type']) {
                $this->utils->debug('User: Send abort to LLM', 'debug');
                $this->openai->abort($socket_id);
                continue;
            }

            $type_method = 'process_' . $data['type'];

            if (!method_exists($this->message, $type_method)) {
                $this->utils->debug('User: Incorrect message type: ' . $data['type'], 'debug');
                continue;
            }

            $result = $this->message->$type_method($socket_id, $data['content']);

            if (!$result['need_llm']) {
                // Other actions
                $this->utils->debug('User: ' . $data['type'] . '->' . ($result['data']['act'] ?? 'Unsupported'), 'debug');

                $response = ['type' => $data['type']] + $result['data'];
                $this->core->sendMessage($socket_id, json_encode($response, JSON_FORMAT));

                // Reload config
                if ('saveConfig' === $result['data']['act']) {
                    $this->init(true);
                    $this->openai->reload();
                    $this->utils->debug('User: ' . $data['type'] . '->reloaded', 'trace');
                }

                unset($socket_id, $message, $is_binary, $messages, $line, $data, $type_method, $result, $response);
                return;
            }

            if (!$this->in_process) {
                $llm_data = $result['content'];

                if (empty($this->utils->getSessionHistory(WORKER_MAIN))) {
                    $system_prompt = $this->getSystemPrompt();
                    $this->utils->addSessionHistory(WORKER_MAIN, $system_prompt);

                    array_unshift(
                        $llm_data, [
                            'type' => 'text',
                            'text' => '[提醒] 上下文不全，请加载今日记忆。如有需要，可继续加载昨日记忆和 important 记忆。'
                        ]
                    );
                }

                $count_coming_message = count($this->utils->onsend_messages);
                if (0 < $count_coming_message) {
                    $this->utils->debug('User: LLM ready, fetching ' . $count_coming_message . ' message(s) from queue.', 'trace');
                    while (!is_null($coming_message = array_shift($this->utils->coming_messages))) {
                        $llm_data[] = $coming_message;
                    }
                }
                unset($count_coming_message);
            } else {
                $this->utils->debug('User: LLM in progress, new message queued.', 'trace');
                $this->utils->coming_messages = array_merge($this->utils->coming_messages, $result['content']);
            }

            unset($data['content']);
            $end_data[] = $data;
        }

        foreach ($end_data as $end_packet) {
            $this->core->sendMessage($socket_id, json_encode(['type' => 'close'] + $end_packet));
        }

        $count_llm_data = count($llm_data);
        if (0 < $count_llm_data) {
            $this->utils->debug('User: Sending ' . $count_llm_data . ' message(s) to LLM', 'trace');

            $this->in_process = true;
            $this->utils->addSessionHistory(WORKER_MAIN, ['role' => 'user', 'content' => $llm_data]);
            $this->runProcWorker($this->utils->getMainIDX(), WORKER_MAIN, [$this, 'streamWorkerHandler']);

            $message_metadata = $this->utils->getMessageMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                AGENT_NAME,
                0
            );

            $this->openai->talk(
                $this->utils->getMainIDX(),
                'talk',
                $this->utils->getSessionHistory(WORKER_MAIN),
                $message_metadata + ['socket_id' => $socket_id]
            );
        }

        unset($socket_id, $message, $is_binary, $end_data, $llm_data, $messages, $line, $data, $type_method, $result, $message_metadata, $end_packet, $count_llm_data);
    }

    /**
     * Callback for sending string messages.
     *
     * @param string $socket_id
     *
     * @return array
     * @throws \Throwable
     */
    public function onSendString(string $socket_id): array
    {
        if ($this->in_process) {
            return [];
        }

        if (!empty($this->utils->message_buffers)) {
            while (!is_null($buffer = array_shift($this->utils->message_buffers))) {
                $this->core->sendMessage($socket_id, $buffer);
            }
        }

        $current_count = $this->utils->countSessionHistory(WORKER_MAIN);

        if ($current_count > $this->utils->agent_config['max_ctx_len'] * 3) {
            $cleaned = $this->utils->cleanSessionHistory(WORKER_MAIN);
            $this->utils->debug('System: History truncated (' . $current_count . ' -> ' . $cleaned['current_count'] . ', config: ' . $this->utils->agent_config['max_ctx_len'] . ')', 'trace');
        }

        $count_onsend_message = count($this->utils->onsend_messages);
        if (0 < $count_onsend_message) {
            $this->utils->debug('System: LLM ready, fetching ' . $count_onsend_message . ' message(s) from queue.', 'trace');

            $llm_data = [];
            while (!is_null($coming_message = array_shift($this->utils->onsend_messages))) {
                $llm_data[] = [
                    'type' => 'text',
                    'text' => $coming_message
                ];
            }

            $count_llm_data = count($llm_data);
            if (0 < $count_llm_data) {
                $this->utils->debug('System: Sending ' . $count_llm_data . ' message(s) to LLM', 'trace');

                $this->in_process = true;
                $this->utils->addSessionHistory(WORKER_MAIN, ['role' => 'user', 'content' => $llm_data]);

                $metadata = $this->utils->getMessageMarker(
                    WORKER_MAIN,
                    WORKER_MAIN,
                    'Assistant',
                    AGENT_NAME,
                    0
                );

                $this->openai->talk(
                    $this->utils->getMainIDX(),
                    'talk',
                    $this->utils->getSessionHistory(WORKER_MAIN),
                    $metadata + ['socket_id' => $socket_id]
                );

                unset($metadata);
            }

            unset($llm_data, $coming_message, $count_llm_data);
        }

        unset($socket_id, $buffer, $current_count, $count_onsend_message);
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
        unset($this->utils->socket_session[$socket_id], $socket_id);
    }
}