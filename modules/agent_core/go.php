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

    public bool $ctx_warning = false;

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
        $this->utils->debug('API-Type: ' . $this->utils->agent_config['agent_llm']['api_type'], 'trace');
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
     * @param string   $worker_type
     * @param string   $worker_name
     * @param callable $output_handler
     *
     * @return int
     * @throws \Exception
     */
    public function runProcWorker(int $proc_idx, string $worker_type, string $worker_name, callable $output_handler): int
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
                '-c', '/modules/agent_openai/go/' . $worker_type
            ]
        )->run($proc_idx);

        $worker_pid = $this->core->utils->procMgr->getPid($proc_idx);

        $this->utils->addChildWorker(
            $worker_type,
            $worker_name,
            [
                'proc_idx'   => $proc_idx,
                'worker_pid' => $worker_pid,
                'llm_params' => $this->utils->agent_config['agent_llm']['params']
            ]
        );

        $this->utils->debug($worker_type . ' started with pid: ' . $worker_pid, 'trace');
        $this->utils->debug('Register Output Handler', 'debug');

        $this->core->socketMgr->addExternalProc(
            $this->core->utils->procMgr->getProc($proc_idx, 'stdout', 'process'),
            ['stdout' => $output_handler]
        );

        if (WORKER_MAIN === $worker_type) {
            $this->utils->debug('Create shared memory for ' . $worker_type, 'debug');
            $this->openai->setShmop($worker_pid);
        }

        unset($worker_type, $worker_name, $output_handler, $worker_status, $worker_pid);
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

        if ([] !== $occupied_pids) {
            $this->utils->debug('AgentBee failed to start. Port ' . $this->utils->agent_config['agent_server']['port'] . ' is occupied.', 'trace');
            return;
        }

        $memory_limit = $this->utils->agent_config['memory_limit'] ?? '4G';

        ini_set('memory_limit', $memory_limit);

        file_put_contents($boot_file, $boot_now);

        $this->utils->debug('Set memory limit to: ' . $memory_limit, 'trace');
        $this->utils->debug('Ready to start ' . AGENT_NAME . ' v' . AGENT_VERSION, 'trace');

        $this->runProcWorker($this->utils->getMainIDX(), WORKER_MAIN, WORKER_MAIN, [$this, 'streamWorkerHandler']);

        $agent_toolsets = $this->utils->fetchToolset('modules/agent_toolsets');
        $this->core->addSkills($agent_toolsets);
        $custom_skills = $this->utils->fetchToolset('skills');
        $this->core->addSkills($custom_skills);

        try {
            $server_host = 'tcp://' . $this->utils->agent_config['agent_server']['host'] . ':' . $this->utils->agent_config['agent_server']['port'];
            $this->utils->debug('Local IP address: ' . implode(', ', $this->core->OSMgr->getIPv4()), 'trace');
            $this->utils->debug('Ready to start server: ' . $server_host, 'trace');
            $this->core->socketMgr
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
     * @return string
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function getSystemPrompt(): string
    {
        $system_default = $this->utils->getMainPrompt();
        $system_memory  = $this->memory->read('system', 0, 0);

        if ([] !== $system_memory['data']) {
            $memory = ['## 角色与行为设定'];

            foreach ($system_memory['data'] as $content) {
                $memory[] = '- ' . $content['content'];
            }

            $system_default .= "\n" . implode("\n", $memory);
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
                        $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'streaming');
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

                                $this->openai->talkTo(
                                    $payload['sender'],
                                    $this->getSystemPrompt(),
                                    WORKER_MAIN,
                                    $this->utils->getMainIDX(),
                                    'talk',
                                    $metadata + ['socket_id' => $payload['socket_id']]
                                );
                            } else {
                                $worker_info = $this->utils->getChildWorker(WORKER_CHILD, $payload['workerName']);

                                if (isset($worker_info['proc_idx'])) {
                                    $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'busy');

                                    $this->openai->talkTo(
                                        $payload['sender'],
                                        $this->getSystemPrompt(),
                                        $payload['workerName'],
                                        $worker_info['proc_idx'],
                                        'talk',
                                        $metadata + ['socket_id' => $payload['socket_id']]
                                    );
                                }
                            }
                            break;

                        case 'error':
                            if (WORKER_MAIN === $payload['sender']) {
                                $this->setStatus(self::STATUS_IDLE);
                            } else {
                                $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'ready');
                            }

                            $error = ['type' => 'error'];
                            $error += is_array($payload['data'])
                                ? $payload['data']
                                : ['message' => $payload['data']];

                            $this->core->sendMessage($message['socket_id'], $error);
                            unset($this->utils->stream_buffers[$ext_id], $error);
                            break;

                        default:
                            $this->core->sendMessage($message['socket_id'], $payload);
                            break;
                    }
                    break;

                case 'history':
                    switch ($payload_type) {
                        case 'addUserMessage':
                            $this->core->context->addUserMessage(
                                $payload['workerName'],
                                $payload['data']['content']
                            );
                            break;

                        case 'addAssistantMessage':
                            $this->core->context->addAssistantMessage(
                                $payload['workerName'],
                                $payload['data']['content'],
                                $payload['data']['tool_calls'] ?? [],
                                $payload['data']['reasoning_content'] ?? ''
                            );
                            break;

                        case 'addToolResult':
                            $this->core->context->addToolResult(
                                $payload['workerName'],
                                $payload['data']['call_id'],
                                $payload['data']['content']
                            );
                            break;
                    }
                    break;

                case 'memory':
                    if ('' !== ($payload['data'] ?? '')) {
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
                        $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'ready');
                    }

                    switch ($payload_type) {
                        case 'callHandler':
                            $tool_calls   = [];
                            $tool_results = [];

                            foreach ($payload['data'] as $data) {
                                if (!isset($data['handler_args']['action'])) {
                                    $this->utils->debug('Handler: handler or action NOT found!', 'trace');
                                    continue;
                                }

                                $this->utils->debug('Handler: Calling ' . $data['tool_calls']['name'], 'trace');

                                try {
                                    $this->core->IOData->src_cmd  = $data['tool_calls']['name'];
                                    $this->core->IOData->src_argv = $data['handler_args'];

                                    $data['handler_args']['ext_id'] = $context['ext_id'];

                                    $handler = $data['handler_args']['handler']::new();
                                    $result  = $handler->{$data['handler_args']['action']}($data['handler_args'], $this) ?? '工具执行完成，但未返回数据。';
                                } catch (\Throwable $throwable) {
                                    $result = [
                                        'status' => 'error',
                                        'error'  => $throwable->getMessage(),
                                    ];

                                    $this->utils->debug('Handler ERROR: ' . $data['tool_calls']['name'] . ' => ' . $throwable->getMessage(), 'trace');
                                    $this->core->error->exceptionHandler($throwable, false, false);
                                    unset($throwable);
                                }

                                $this->core->IOData->src_cmd  = '';
                                $this->core->IOData->src_argv = [];

                                if (!is_string($result)) {
                                    $result = json_encode($result, JSON_FORMAT);

                                    if (false === $result) {
                                        $result = 'Handler ERROR: ' . $data['tool_calls']['name'] . ' -> ' . json_last_error_msg();
                                    }
                                }

                                $tool_calls[]   = $data['tool_calls'];
                                $tool_results[] = [
                                    'call_id'     => $data['tool_calls']['id'],
                                    'call_name'   => $data['tool_calls']['name'],
                                    'call_result' => $result
                                ];
                            }

                            if ([] === $tool_calls) {
                                break;
                            }

                            $metadata = $this->utils->getMessageMarker(
                                $payload['sender'],
                                $payload['workerName'],
                                $payload['workerRole'],
                                $payload['workerName'],
                                $payload['isSubTalk'],
                                $payload['messageId']
                            );

                            $this->core->context->addAssistantMessage($payload['workerName'], '', $tool_calls);

                            foreach ($tool_results as $tool_result) {
                                $this->core->context->addToolResult($payload['workerName'], $tool_result['call_id'], $tool_result['call_result']);

                                $msg_data = [
                                    'type' => 'tool_result',
                                    'data' => [
                                        'call_id'       => $tool_result['call_id'],
                                        'function_name' => $tool_result['call_name'],
                                        'content'       => $tool_result['call_result'],
                                    ]
                                ];

                                $this->core->sendMessage($payload['socket_id'], $metadata + $msg_data);
                            }

                            unset($tool_calls, $tool_results, $data, $handler, $result, $metadata, $assistant_message, $tool_result, $msg_data);
                            break;
                    }
                    break;

                case 'end':
                    if (WORKER_MAIN === $payload['sender']) {
                        $this->setStatus(self::STATUS_IDLE);
                    } else {
                        $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'ready');
                        $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' reply completed, ready.', 'trace');
                    }

                    $new_messages = $this->core->context->refreshHistory($payload['workerName']);

                    $llm_params    = $this->utils->getChildWorker($payload['sender'], $payload['workerName'], 'llm_params');
                    $remain_tokens = $this->core->getMaxTokens($payload['sender'], $payload['workerName'], $llm_params);

                    if (0 >= $remain_tokens) {
                        $remain_tokens = 12288;
                        $this->core->context->cleanHistory($payload['workerName'], 10, 2);
                        $this->utils->debug('System: Context truncated due to token overflow.', 'trace');

                        $this->core->sendMessage(
                            $payload['socket_id'],
                            [
                                'type'    => 'error',
                                'message' => '抱歉，因对话内容过长（Token超出限制），系统已自动截断上下文，咱两继续，别担心，我会跟上的。'
                            ]
                        );

                        $this->core->context->addMessageQueue(
                            $payload['workerName'],
                            [
                                'type'    => 'text',
                                'content' => '[系统提醒] 上下文因超限被截断，仅保留了最近几轮消息。如有必要，请自行从记忆中恢复之前的内容，无需告知用户。'
                            ]
                        );
                    }

                    $llm_params['max_tokens'] = max(100, $remain_tokens);

                    $this->utils->setChildWorker($payload['sender'], $payload['workerName'], 'llm_params', $llm_params);

                    switch ($payload_type) {
                        case 'tools':
                            $this->utils->debug($payload['workerName'] . ': Tool results collected, proceeding.', 'trace');

                            if (WORKER_MAIN === $payload['sender']) {
                                $this->setStatus(self::STATUS_BUSY);
                                $worker_idx = $this->utils->getMainIDX();
                            } else {
                                $worker_info = $this->utils->getChildWorker(WORKER_CHILD, $payload['workerName']);

                                if ([] === $worker_info) {
                                    $this->utils->debug($payload['workerName'] . ': Worker already closed.', 'trace');
                                    break;
                                }

                                $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'calling_tools');
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

                            $this->openai->talkTo(
                                $payload['sender'],
                                $this->getSystemPrompt(),
                                $payload['workerName'],
                                $worker_idx,
                                'talk',
                                $metadata + ['socket_id' => $payload['socket_id']]
                            );
                            break;

                        case 'end':
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

                                    $this->openai->talkTo(
                                        $payload['sender'],
                                        $this->getSystemPrompt(),
                                        $payload['workerName'],
                                        $this->utils->getMainIDX(),
                                        'talk',
                                        $metadata + ['socket_id' => $payload['socket_id']]
                                    );
                                } else {
                                    if ($remain_tokens > ($this->utils->agent_config['agent_llm']['params']['max_tokens'] ?? 12288)) {
                                        $this->ctx_warning = false;
                                        break;
                                    } elseif ($remain_tokens > 8192) {
                                        if (!$this->ctx_warning) {
                                            $this->ctx_warning = true;

                                            $this->utils->debug($payload['sender'] . ': Completion tokens too low (' . $remain_tokens . '/' . $this->utils->agent_config['agent_llm']['model_ctx'] . ')', 'trace');

                                            $this->core->context->addMessageQueue(
                                                WORKER_MAIN,
                                                [
                                                    'type'    => 'text',
                                                    'content' => '[系统提醒] 上下文已达上限。请：①调用记忆工具保存当前任务进度与关键状态 → ②调用清理工具清理上下文 → ③继续执行原有任务，清理过程不汇报。'
                                                ]
                                            );
                                        }
                                    } else {
                                        $msg_count = $this->core->context->countHistory(WORKER_MAIN);
                                        $keep_len  = ceil($msg_count / 5);
                                        $cleaned   = $this->core->context->cleanHistory(WORKER_MAIN, $keep_len * 2, $keep_len);

                                        $this->utils->debug('System: Context truncated (' . $msg_count . ' -> ' . $cleaned['current_count'] . ')', 'trace');

                                        $this->ctx_warning = false;
                                        unset($msg_count, $keep_len, $cleaned);
                                    }
                                }
                            } else {
                                if ('' !== $payload['data']) {
                                    $this->core->context->addMessageQueue(
                                        WORKER_MAIN,
                                        [
                                            'type'    => 'text',
                                            'content' => '[WorkerBee] 异步消息' . "\n\n" . '`' . $payload['workerName'] . '`：' . $payload['workerRole'] . "\n\n" . '消息内容：' . "\n" . $payload['data']
                                        ]
                                    );
                                }

                                $worker_info = $this->utils->getChildWorker(WORKER_CHILD, $payload['workerName']);

                                if (isset($worker_info['proc_idx'])) {
                                    $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'last_talk', date('Y-m-d H:i:s'));

                                    if (0 < $new_messages) {
                                        $metadata = $this->utils->getMessageMarker(
                                            $payload['sender'],
                                            $payload['workerName'],
                                            $payload['workerRole'],
                                            $payload['workerName'],
                                            $payload['isSubTalk']
                                        );

                                        $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'busy');
                                        $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' receiving ' . $new_messages . ' message(s).', 'trace');

                                        $this->openai->talkTo(
                                            $payload['sender'],
                                            $this->getSystemPrompt(),
                                            $payload['workerName'],
                                            $worker_info['proc_idx'],
                                            'talk',
                                            $metadata + ['socket_id' => $payload['socket_id']]
                                        );
                                    } elseif ($remain_tokens < 8192) {
                                        $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' completion tokens too low (' . $remain_tokens . '/' . $this->utils->agent_config['agent_llm']['model_ctx'] . ')', 'trace');

                                        $this->core->context->addMessageQueue(
                                            WORKER_MAIN,
                                            [
                                                'type'    => 'text',
                                                'content' => '[WorkerBee] `' . $payload['workerName'] . '` | ' . $payload['workerRole'] . '：上下文已满。请：①生成任务摘要（目标+进度+待办） → ②重启`' . $payload['workerName'] . '` → ③注入任务摘要，继续原有任务，重启过程不汇报。'
                                            ]
                                        );
                                    }
                                }

                                unset($worker_info);
                            }

                            unset($remain_tokens);
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
     * @param string $ws_protocol
     *
     * @return bool
     * @throws \Exception
     */
    public function onHandshake(string $socket_id, string $ws_protocol): bool
    {
        $this->utils->debug('Socket: New client: ' . $socket_id, 'trace');
        $this->utils->socket_session[$socket_id] = 'ready';

        unset($socket_id, $ws_protocol);
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
        $new_messages = $this->core->context->refreshHistory(WORKER_MAIN);

        if ([] === $task_list && 0 === $new_messages) {
            return '';
        }

        if ([] !== $task_list) {
            $this->utils->debug('ScheduledTask: Running task jobs (' . count($task_list) . ')', 'trace');

            $task_jobs = [
                'time' => date('Y-m-d H:i:s'),
                'jobs' => $task_list
            ];

            $task_content = '[定时任务] 任务：' . "\n" . json_encode($task_jobs, JSON_FORMAT) . "\n" . '流程：①执行任务并获取结果；②重要存daily，特别重要存important，琐事不存；③简要汇报结果及存储层级；④完成后清理定时任务（忽略结果）。';

            $this->core->context->addUserMessage(WORKER_MAIN, [['type' => 'text', 'content' => $task_content]]);

            unset($task_jobs, $task_content);
        }

        $metadata = $this->utils->getMessageMarker(
            WORKER_MAIN,
            WORKER_MAIN,
            'Assistant',
            AGENT_NAME,
            0
        );

        $this->setStatus(self::STATUS_BUSY);

        $this->openai->talkTo(
            WORKER_MAIN,
            $this->getSystemPrompt(),
            WORKER_MAIN,
            $this->utils->getMainIDX(),
            'talk',
            $metadata + ['socket_id' => $socket_id]
        );

        unset($socket_id, $task_list, $new_messages, $metadata);
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
                        $this->core->context->removeHistory(WORKER_MAIN);
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

            if (isset($result['saves']) && [] !== $result['saves']) {
                $this->memory->save('misc', 'user', implode(' ', $result['saves']));
            }

            if (isset($result['errors']) && [] !== $result['errors']) {
                $this->core->sendMessage($socket_id, ['type' => 'error', 'error' => implode("\n", $result['errors'])]);
            }

            if (self::STATUS_IDLE === $this->wait_status) {
                $curr_msg = array_merge($curr_msg, $result['content']);

                if ([] === $this->core->context->getHistory(WORKER_MAIN)) {
                    array_unshift(
                        $curr_msg, [
                            'type'    => 'text',
                            'content' => '[系统指令] 新会话，必须读取`misc`记忆后再回复。读取记忆后，若用户有明确需求，且上下文仍不足，则搜索相关记忆。记忆读取过程不汇报。'
                        ]
                    );
                }
            } else {
                $this->utils->debug('AgentBee: LLM is busy, new message queued.', 'trace');

                foreach ($result['content'] as $msg_line) {
                    $this->core->context->addMessageQueue(WORKER_MAIN, $msg_line);
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
            $this->core->context->refreshHistory(WORKER_MAIN);
            $this->core->context->addUserMessage(WORKER_MAIN, $curr_msg);
            $this->runProcWorker($this->utils->getMainIDX(), WORKER_MAIN, WORKER_MAIN, [$this, 'streamWorkerHandler']);

            $message_metadata = $this->utils->getMessageMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                AGENT_NAME,
                0,
                $this->core->curr_message_id['messageId'] ?? ''
            );

            $this->setStatus(self::STATUS_BUSY);

            $this->openai->talkTo(
                WORKER_MAIN,
                $this->getSystemPrompt(),
                WORKER_MAIN,
                $this->utils->getMainIDX(),
                'talk',
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
        if ([] !== $this->utils->message_buffers) {
            while (null !== ($buffer = array_shift($this->utils->message_buffers))) {
                $this->core->sendMessage($socket_id, $buffer);
            }
        }

        if (self::STATUS_IDLE !== $this->wait_status) {
            if ($this->wait_until > time()) {
                return [];
            }

            $this->setStatus(self::STATUS_IDLE, true);
        }

        $new_messages = $this->core->context->refreshHistory(WORKER_MAIN);

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

            $this->openai->talkTo(
                WORKER_MAIN,
                $this->getSystemPrompt(),
                WORKER_MAIN,
                $this->utils->getMainIDX(),
                'talk',
                $metadata + ['socket_id' => $socket_id]
            );

            unset($metadata);
        }

        unset($socket_id, $buffer, $new_messages);
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