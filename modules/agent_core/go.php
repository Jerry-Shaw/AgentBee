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
use modules\agent_toolsets\Memory\go as memory;
use Nervsys\Core\Factory;

class go extends Factory
{
    const STATUS_IDLE = 4;
    const STATUS_WAIT = 2;
    const STATUS_BUSY = 1;

    public core    $core;
    public utils   $utils;
    public message $message;

    public memory $memory;
    public openai $openai;

    public int $wait_until  = 0;
    public int $wait_status = self::STATUS_IDLE;

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

        $this->memory  = memory::new();
        $this->openai  = openai::new();
        $this->message = message::new();
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

        $this->utils->debug('API-URL: ' . ($this->utils->agent_config['agent_llm']['api_url'] ?? 'NONE'), 'trace');
        $this->utils->debug('Model-ID: ' . ($this->utils->agent_config['agent_llm']['model'] ?? 'NONE'), 'trace');
        $this->utils->debug('SandBox mode: ' . ($this->utils->agent_config['sandbox_mode'] ? 'ON' : 'OFF'), 'trace');

        $workspace_path = $this->utils->agent_config['workspace_path'] ?? '';
        $this->utils->debug('Workspace path: ' . $workspace_path, 'trace');

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
                '-c', '/modules/agent_openai/go/' . $worker_name
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
        $boot_now  = $this->utils->OSMgr->getBootInfo();
        $boot_file = $this->utils->config->config_dir . DIRECTORY_SEPARATOR . '.Boot';
        $kill_pid  = !is_file($boot_file) || file_get_contents($boot_file) === $boot_now;

        $this->utils->cleanPids($kill_pid);

        $occupied_pids = $this->core->OSMgr->findPidsByPortState($this->utils->agent_config['agent_server']['port'], 'LISTEN');

        if (!empty($occupied_pids)) {
            $this->utils->debug('AgentBee failed to start. Port ' . $this->utils->agent_config['agent_server']['port'] . ' is occupied.', 'trace');
            return;
        }

        $memory_limit = $this->utils->agent_config['memory_limit'] ?? '4G';

        ini_set('memory_limit', $memory_limit);

        file_put_contents($boot_file, $boot_now);

        $this->utils->debug('Set memory limit to: ' . $memory_limit, 'trace');
        $this->utils->debug('Ready to start ' . AGENT_NAME . ' v' . AGENT_VERSION, 'trace');

        $this->runProcWorker($this->utils->getMainIDX(), WORKER_MAIN, [$this, 'streamWorkerHandler']);

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

        if (!empty($system_memory['data'])) {
            $memory = ['## 角色与行为设定'];

            foreach ($system_memory['data'] as $content) {
                $memory[] = '- ' . $content['content'];
            }

            $system_default['content'] .= "\n" . implode("\n", $memory);
        }

        unset($system_memory, $memory, $content);
        return $system_default;
    }

    /**
     * Callback for external stream (stdout from worker).
     *
     * @param string $ext_id
     * @param array  $context
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function streamWorkerHandler(string $ext_id, array $context): void
    {
        $stdout_stream = $context['stdout'];
        $data_chunk    = fread($stdout_stream, 8192);

        if (false === $data_chunk || '' === $data_chunk) {
            unset($this->utils->stream_buffers[$ext_id]);
            return;
        }

        $buffer = &$this->utils->stream_buffers[$ext_id];
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
                    if (WORKER_MAIN === $payload['sender']) {
                        $this->setStatus(self::STATUS_WAIT);
                    } else {
                        $this->utils->setChildWorker('WorkerBee', $payload['workerName'], 'status', 'streaming');
                        $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' working on streaming', 'debug');
                    }

                    switch ($payload_type) {
                        case 'length':
                            $metadata = $this->utils->getMessageMarker(
                                $payload['sender'],
                                $payload['workerName'],
                                $payload['workerRole'],
                                $payload['workerName'],
                                $payload['isSubTalk'],
                                $payload['messageId']
                            );

                            if (WORKER_MAIN === $payload['sender']) {
                                $this->setStatus(self::STATUS_BUSY);

                                $this->openai->talk(
                                    $this->utils->getMainIDX(),
                                    'talk',
                                    $this->utils->getSessionHistory(WORKER_MAIN),
                                    $metadata + ['socket_id' => $payload['socket_id']]
                                );
                            } else {
                                $worker_info = $this->utils->getChildWorker('WorkerBee', $payload['workerName']);

                                if (isset($worker_info['proc_idx'])) {
                                    $this->utils->setChildWorker('WorkerBee', $payload['workerName'], 'status', 'busy');

                                    $this->openai->talk(
                                        $worker_info['proc_idx'],
                                        'talk',
                                        $this->utils->getSessionHistory($payload['workerName']),
                                        $metadata + ['socket_id' => $payload['socket_id']]
                                    );
                                }
                            }
                            break;

                        case 'error':
                            if (WORKER_MAIN === $payload['sender']) {
                                $this->setStatus(self::STATUS_IDLE);
                            } else {
                                $this->utils->setChildWorker('WorkerBee', $payload['workerName'], 'status', 'ready');
                            }

                            unset($this->utils->stream_buffers[$ext_id]);
                            $this->core->sendMessage($message['socket_id'], ['type' => 'error'] + $payload['data']);
                            break;

                        default:
                            $this->core->sendMessage($message['socket_id'], $payload);
                            break;
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

                case 'memory':
                    if (isset($payload['data'])) {
                        switch ($payload_type) {
                            case 'add':
                                $this->utils->memory_buffer .= $payload['data'];
                                break;

                            case 'save':
                                $this->utils->memory_buffer .= $payload['data'];
                                $this->memory->save('misc', 'assistant', $this->utils->memory_buffer);

                                $this->utils->memory_buffer = '';
                                break;
                        }
                    }
                    break;

                case 'context':
                    // Reset main worker status
                    if (WORKER_MAIN === $payload['sender']) {
                        $this->setStatus(self::STATUS_IDLE);
                    } else {
                        $this->utils->setChildWorker('WorkerBee', $payload['workerName'], 'status', 'ready');
                    }

                    switch ($payload_type) {
                        case 'callHandler':
                            if (!isset($payload['data']['handler']) || !isset($payload['data']['action'])) {
                                $this->utils->debug('Handler: handler or action NOT found!', 'trace');
                                break;
                            }

                            $this->utils->debug('Handler: Calling ' . $payload['data']['function_name'], 'trace');

                            try {
                                $payload['data']['ext_id'] = $context['ext_id'];

                                $handler = $payload['data']['handler']::new();
                                $result  = $handler->{$payload['data']['action']}($payload['data'], $this) ?? $payload['data']['function_name'] . ': 工具执行完成，但未返回数据';

                                unset($handler);
                            } catch (\Throwable $throwable) {
                                $result = [
                                    'status' => 'error',
                                    'error'  => $throwable->getMessage(),
                                ];

                                $this->utils->debug('Handler ERROR: ' . $payload['data']['function_name'] . ' (' . $throwable->getMessage() . ')', 'trace');
                                $this->core->error->exceptionHandler($throwable, false, false);
                                unset($throwable);
                            }

                            $result_text = is_string($result) ? $result : json_encode($result, JSON_FORMAT);

                            $this->utils->addSessionHistory(
                                $payload['workerName'],
                                [
                                    'role'         => 'tool',
                                    'tool_call_id' => $payload['data']['tool_call_id'],
                                    'content'      => $result_text
                                ]
                            );

                            $metadata = $this->utils->getMessageMarker(
                                $payload['sender'],
                                $payload['workerName'],
                                $payload['workerRole'],
                                $payload['workerName'],
                                $payload['isSubTalk'],
                                $payload['messageId']
                            );

                            $msg_data = [
                                'type' => 'tool_result',
                                'data' => [
                                    'tool_call_id'  => $payload['data']['tool_call_id'],
                                    'function_name' => $payload['data']['function_name'],
                                    'content'       => $result
                                ]
                            ];

                            $this->core->sendMessage($payload['socket_id'], $metadata + $msg_data);

                            unset($result, $result_text, $metadata, $msg_data);
                            break;

                        case 'ToolErrors':
                            $this->utils->addSessionHistory(
                                $payload['workerName'],
                                [
                                    'role'    => 'user',
                                    'content' => [[
                                        'type' => 'text',
                                        'text' => '停止重复调用 `' . $payload['data']['function_name'] . '`，立即审视历史中的错误与上下文，调整策略；无法解决则上报用户。'
                                    ]]
                                ]
                            );
                            break;
                    }
                    break;

                case 'end':
                    if (WORKER_MAIN === $payload['sender']) {
                        $this->setStatus(self::STATUS_IDLE);
                    } else {
                        $this->utils->setChildWorker('WorkerBee', $payload['workerName'], 'status', 'ready');
                        $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' reply completed, ready.', 'trace');
                    }

                    $new_messages = $this->utils->refreshSessionHistory($payload['workerName']);

                    switch ($payload_type) {
                        case 'tools':
                            $this->utils->debug($payload['workerName'] . ': Tool results collected, proceeding.', 'trace');

                            if (WORKER_MAIN === $payload['sender']) {
                                $this->setStatus(self::STATUS_BUSY);
                                $worker_idx = $this->utils->getMainIDX();
                            } else {
                                $worker_info = $this->utils->getChildWorker('WorkerBee', $payload['workerName']);

                                if (empty($worker_info)) {
                                    $this->utils->debug($payload['workerName'] . ': Worker already closed.', 'trace');
                                    break;
                                }

                                $this->utils->setChildWorker('WorkerBee', $worker_info['worker_name'], 'status', 'calling_tools');
                                $worker_idx = $worker_info['proc_idx'];

                                unset($worker_info);
                            }

                            $metadata = $this->utils->getMessageMarker(
                                $payload['sender'],
                                $payload['workerName'],
                                $payload['workerRole'],
                                $payload['workerName'],
                                $payload['isSubTalk'],
                                0 === $new_messages ? $payload['messageId'] : ''
                            );

                            $this->openai->talk(
                                $worker_idx,
                                'talk',
                                $this->utils->getSessionHistory($payload['workerName']),
                                $metadata + ['socket_id' => $payload['socket_id']]
                            );
                            break;

                        case 'end':
                            $max_ctx_len   = $this->utils->agent_config['max_ctx_len'];
                            $warning_count = $max_ctx_len * 2;
                            $limit_count   = $max_ctx_len * 3;

                            if (WORKER_MAIN === $payload['sender']) {
                                if (0 < $new_messages) {
                                    $metadata = $this->utils->getMessageMarker(
                                        $payload['sender'],
                                        $payload['workerName'],
                                        $payload['workerRole'],
                                        $payload['workerName'],
                                        $payload['isSubTalk']
                                    );

                                    $this->setStatus(self::STATUS_BUSY);

                                    $this->openai->talk(
                                        $this->utils->getMainIDX(),
                                        'talk',
                                        $this->utils->getSessionHistory($payload['workerName']),
                                        $metadata + ['socket_id' => $payload['socket_id']]
                                    );
                                } else {
                                    $current_count = $this->utils->countSessionHistory($payload['workerName']);
                                    if ($current_count < $warning_count) {
                                        $this->clean_warning = false;
                                        break;
                                    } elseif (!$this->clean_warning) {
                                        $this->clean_warning = true;

                                        $this->utils->debug($payload['sender'] . ': History too long (' . $current_count . '/' . $limit_count . ', config: ' . $max_ctx_len . ')', 'trace');
                                        $this->utils->addMessageQueue(
                                            WORKER_MAIN,
                                            [
                                                'type' => 'text',
                                                'text' => '[系统提醒] 上下文已达临界值，即将强制清理。必须闭环：①保存进度快照 → ②执行上下文清理 → ③同步任务目标与最新状态 → ④衔接原任务指令 → ⑤通知用户。禁止中断，自动继续原任务。'
                                            ]
                                        );
                                    }
                                    unset($current_count, $limit_count);
                                }
                            } else {
                                if ('' !== $payload['data']) {
                                    $this->utils->addMessageQueue(
                                        WORKER_MAIN,
                                        [
                                            'type' => 'text',
                                            'text' => '[WorkerBee] 异步消息' . "\n\n" . '`' . $payload['workerName'] . '`：' . $payload['workerRole'] . "\n\n" . '消息内容：' . "\n" . $payload['data']
                                        ]
                                    );
                                }

                                $worker_info = $this->utils->getChildWorker('WorkerBee', $payload['workerName']);

                                if (isset($worker_info['proc_idx'])) {
                                    $this->utils->setChildWorker('WorkerBee', $payload['workerName'], 'last_talk', date('Y-m-d H:i:s'));
                                    $this->utils->setChildWorker('WorkerBee', $payload['workerName'], 'talk_count', $payload['talk_count']);

                                    if (0 < $new_messages) {
                                        $metadata = $this->utils->getMessageMarker(
                                            $payload['sender'],
                                            $payload['workerName'],
                                            $payload['workerRole'],
                                            $payload['workerName'],
                                            $payload['isSubTalk']
                                        );

                                        $this->utils->setChildWorker('WorkerBee', $payload['workerName'], 'status', 'busy');
                                        $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' receiving ' . $new_messages . ' message(s).', 'trace');

                                        $this->openai->talk(
                                            $worker_info['proc_idx'],
                                            'talk',
                                            $this->utils->getSessionHistory($payload['workerName']),
                                            $metadata + ['socket_id' => $payload['socket_id']]
                                        );
                                    } elseif ($payload['talk_count'] > $limit_count) {
                                        $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' history too long (' . $payload['talk_count'] . '/' . $warning_count . ', config: ' . $max_ctx_len . ')', 'trace');

                                        $this->utils->addMessageQueue(
                                            WORKER_MAIN,
                                            [
                                                'type' => 'text',
                                                'text' => '[WorkerBee] `' . $payload['workerName'] . '` | ' . $payload['workerRole'] . '，对话已达上限。必须闭环：①保存状态 → ②关闭进程 → ③重启加载 → ④注入关键历史（从记忆提取摘要）→ ⑤继续原任务指令 → ⑥通知用户。禁止中断，自动接续。'
                                            ]
                                        );
                                    }
                                }

                                unset($worker_info);
                            }

                            unset($max_ctx_len, $warning_count, $limit_count);
                            break;
                    }

                    unset($new_messages);
                    break;
            }
        }

        if (is_resource($stdout_stream) && feof($stdout_stream)) {
            unset($this->utils->stream_buffers[$ext_id]);
        }

        unset($ext_id, $context, $stdout_stream, $data_chunk, $buffer, $line_pos, $line, $message, $payload, $payload_type, $metadata);
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
        $this->utils->socket_session[$socket_id] = 'ready';
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
        if (self::STATUS_IDLE !== $this->wait_status) {
            if ($this->wait_until > time()) {
                return '';
            }

            $this->setStatus(self::STATUS_IDLE, true);
        }

        $task_list    = $this->memory->runTask();
        $new_messages = $this->utils->refreshSessionHistory(WORKER_MAIN);

        if (empty($task_list) && 0 === $new_messages) {
            return '';
        }

        if (!empty($task_list)) {
            $this->utils->debug('ScheduledTask: Running task jobs (' . count($task_list) . ')', 'trace');
        }

        $task_jobs = [
            'time' => date('Y-m-d H:i:s'),
            'jobs' => $task_list
        ];

        $task_content = '[定时任务] 任务：' . "\n" . json_encode($task_jobs, JSON_FORMAT) . "\n" . '流程：①执行任务并获取结果；②重要存daily，特别重要存important，琐事不存；③简要汇报结果及存储层级；④完成后清理定时任务（忽略结果）。';

        $task_data = [[
            'type' => 'text',
            'text' => $task_content
        ]];

        $this->utils->addSessionHistory(WORKER_MAIN, ['role' => 'user', 'content' => $task_data]);

        $metadata = $this->utils->getMessageMarker(
            WORKER_MAIN,
            WORKER_MAIN,
            'Assistant',
            AGENT_NAME,
            0
        );

        $this->setStatus(self::STATUS_BUSY);

        $this->openai->talk(
            $this->utils->getMainIDX(),
            'talk',
            $this->utils->getSessionHistory(WORKER_MAIN),
            $metadata + ['socket_id' => $socket_id]
        );

        unset($socket_id, $task_list, $new_messages, $task_jobs, $task_content, $task_data, $metadata);
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
            $this->utils->debug('System: Binary message is NOT supported yet', 'trace');
            return;
        }

        $curr_msg = [];
        $user_msg = str_contains($message, "\n") ? explode("\n", $message) : [$message];
        $user_msg = array_filter($user_msg, 'strlen');
        $last_key = array_key_last($user_msg);

        foreach ($user_msg as $key => $line) {
            $data = json_decode($line, true);

            if (!is_array($data) || !isset($data['type'])) {
                continue;
            }

            if ('stop' === $data['type']) {
                $this->setStatus(self::STATUS_IDLE);
                $this->openai->abort($socket_id);
                $this->utils->debug('User: Abort signal sent. Cancelling task.', 'trace');
                continue;
            }

            $type_method = 'process_' . $data['type'];

            if (!method_exists($this->message, $type_method)) {
                $this->utils->debug('User: Incorrect message type: ' . $data['type'], 'debug');
                continue;
            }

            if ('memory' === $data['type']) {
                $data['content']['memory'] = $this->memory;
            }

            $result = $this->message->$type_method($socket_id, $data['content']);

            if (!$result['need_llm']) {
                // Other actions
                $this->utils->debug('User: ' . $data['type'] . '->' . ($result['content']['act'] ?? 'Unsupported'), 'debug');

                $response = ['type' => $result['type'] ?? $data['type']] + $result['content'];
                $this->core->sendMessage($socket_id, $response);

                switch ($result['content']['act']) {
                    // Reset session memory
                    case 'reset':
                        $this->setStatus(self::STATUS_IDLE);
                        $this->utils->removeSessionHistory(WORKER_MAIN);
                        break;

                    // Reload config
                    case 'saveConfig':
                        $this->init(true);
                        $this->openai->reload();
                        $this->utils->debug('User: ' . $data['type'] . '->reloaded', 'trace');
                        break;
                }

                unset($socket_id, $message, $is_binary, $user_msg, $line, $data, $type_method, $result, $response);
                return;
            }

            if (isset($result['saves']) && !empty($result['saves'])) {
                $this->memory->save('misc', 'user', implode(' ', $result['saves']));
            }

            if (isset($result['errors']) && !empty($result['errors'])) {
                $this->core->sendMessage($socket_id, ['type' => 'error', 'error' => implode("\n", $result['errors'])]);
            }

            if (self::STATUS_IDLE === $this->wait_status) {
                $curr_msg = array_merge($curr_msg, $result['content']);

                if (empty($this->utils->getSessionHistory(WORKER_MAIN))) {
                    $this->utils->addSessionHistory(WORKER_MAIN, $this->getSystemPrompt());

                    array_unshift(
                        $curr_msg, [
                            'type' => 'text',
                            'text' => '新会话：先加载`misc`记忆再回复，加载过程不汇报。'
                        ]
                    );
                }
            } else {
                $this->utils->debug('AgentBee: LLM is busy, new message queued.', 'trace');

                foreach ($result['content'] as $msg_line) {
                    $this->utils->addMessageQueue(WORKER_MAIN, $msg_line);
                }

                unset($msg_line);
            }

            if (isset($this->core->curr_message_id['messageId'])) {
                $this->core->sendMessage(
                    $socket_id,
                    [
                        'type'      => 'close',
                        'sessionId' => $this->core->curr_message_id['sessionId'],
                        'messageId' => $this->core->curr_message_id['messageId']
                    ]
                );
            }

            $this->core->curr_message_id = ['sessionId' => $data['sessionId'], 'messageId' => $data['messageId']];
        }

        $count_msg = count($curr_msg);
        if (0 < $count_msg) {
            $this->utils->debug('User: Sending ' . $count_msg . ' message(s) to ' . WORKER_MAIN, 'trace');
            $this->utils->refreshSessionHistory(WORKER_MAIN);
            $this->utils->addSessionHistory(WORKER_MAIN, ['role' => 'user', 'content' => $curr_msg]);
            $this->runProcWorker($this->utils->getMainIDX(), WORKER_MAIN, [$this, 'streamWorkerHandler']);

            $message_metadata = $this->utils->getMessageMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                AGENT_NAME,
                0,
                $this->core->curr_message_id['messageId'] ?? ''
            );

            $this->setStatus(self::STATUS_BUSY);

            $this->openai->talk(
                $this->utils->getMainIDX(),
                'talk',
                $this->utils->getSessionHistory(WORKER_MAIN),
                $message_metadata + ['socket_id' => $socket_id]
            );
        }

        unset($socket_id, $message, $is_binary, $curr_msg, $user_msg, $last_key, $key, $line, $data, $type_method, $result, $message_metadata, $count_msg);
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
        if (!empty($this->utils->message_buffers)) {
            while (!is_null($buffer = array_shift($this->utils->message_buffers))) {
                $this->core->sendMessage($socket_id, $buffer);
            }
        }

        if (self::STATUS_IDLE !== $this->wait_status) {
            if ($this->wait_until > time()) {
                return [];
            }

            $this->setStatus(self::STATUS_IDLE, true);
        }

        $current_count = $this->utils->countSessionHistory(WORKER_MAIN);

        if ($current_count > $this->utils->agent_config['max_ctx_len'] * 3) {
            $keep_len = ceil($this->utils->agent_config['max_ctx_len'] / 10);
            $cleaned  = $this->utils->cleanSessionHistory(WORKER_MAIN, $keep_len * 4, $keep_len);
            $this->utils->debug('System: History truncated (' . $current_count . ' -> ' . $cleaned['current_count'] . ', config: ' . $this->utils->agent_config['max_ctx_len'] . ')', 'trace');
            unset($keep_len, $cleaned);
        }

        $new_messages = $this->utils->refreshSessionHistory(WORKER_MAIN);

        if (0 < $new_messages) {
            $this->utils->debug('System: Sending ' . $new_messages . ' message(s) to ' . WORKER_MAIN, 'trace');

            $metadata = $this->utils->getMessageMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                AGENT_NAME,
                0
            );

            $this->setStatus(self::STATUS_BUSY);

            $this->openai->talk(
                $this->utils->getMainIDX(),
                'talk',
                $this->utils->getSessionHistory(WORKER_MAIN),
                $metadata + ['socket_id' => $socket_id]
            );

            unset($metadata);
        }

        unset($socket_id, $buffer, $current_count, $new_messages);
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

    /**
     * @param int  $status
     * @param bool $timeout
     *
     * @return void
     */
    private function setStatus(int $status, bool $timeout = false): void
    {
        if (self::STATUS_IDLE === $status) {
            $this->utils->debug('AgentBee: IDLE (' . (!$timeout ? 'stream ended' : 'response timeout') . ')', 'trace');
            $this->wait_status = $status;

            unset($status, $timeout);
            return;
        }

        $this->wait_until = time() + ($this->utils->agent_config['agent_llm']['timeout']);

        if (($this->wait_status & $status) !== $status) {
            switch ($status) {
                case self::STATUS_BUSY:
                    $this->utils->debug('AgentBee: BUSY (waiting for response)', 'trace');
                    break;
                case self::STATUS_WAIT:
                    $this->utils->debug('AgentBee: WAIT (receiving stream data)', 'trace');
                    break;
            }

            $this->wait_status |= $status;
        }

        unset($status, $timeout);
    }
}