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
    public core    $core;
    public utils   $utils;
    public message $message;

    public memory $memory;
    public openai $openai;

    public int $keep_pairs = 2;

    public bool $ctx_warning = false;

    public array $last_response = [];

    /**
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function __construct()
    {
        $this->core    = core::new();
        $this->utils   = utils::new();
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

        $this->utils->debug('WS-Token: ' . ($this->utils->agent_config['agent_server']['ws_token'] ?? 'NONE'), 'trace');
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
        $boot_file = $this->utils->config->config_dir . DIRECTORY_SEPARATOR . '.Boot';
        $flock_fp  = fopen($boot_file, 'c+');

        if (!flock($flock_fp, LOCK_EX | LOCK_NB)) {
            $this->utils->debug('AgentBee already running, exit!', 'debug');
            return;
        }

        register_shutdown_function(
            function () use ($flock_fp): void
            {
                flock($flock_fp, LOCK_UN);
            }
        );

        $boot_time = $this->utils->OSMgr->getBootInfo();

        $this->utils->cleanPids(fgets($flock_fp) === $boot_time);

        $occupied_pids = $this->core->OSMgr->findPidsByPortState($this->utils->agent_config['agent_server']['port'], 'LISTEN');

        if ([] !== $occupied_pids) {
            $this->utils->debug('AgentBee failed to start. Port ' . $this->utils->agent_config['agent_server']['port'] . ' is occupied.', 'trace');
            return;
        }

        rewind($flock_fp);
        ftruncate($flock_fp, 0);
        fwrite($flock_fp, $boot_time);

        $memory_limit = $this->utils->agent_config['memory_limit'] ?? '4G';

        ini_set('memory_limit', $memory_limit);

        $this->utils->debug('Set memory limit to: ' . $memory_limit, 'trace');
        $this->utils->debug('Ready to start ' . AGENT_NAME . ' v' . AGENT_VERSION, 'trace');

        $this->init();
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
     * @param string $session_id
     *
     * @return string
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function getSystemPrompt(string $session_id): string
    {
        $system_default = $this->utils->getMainPrompt();
        $system_memory  = $this->memory->read('system');

        if ([] !== $system_memory['data']) {
            $memory = ['## 角色与行为设定'];

            foreach ($system_memory['data'] as $content) {
                $memory[] = '- ' . $content['content'];
            }

            $system_default .= "\n" . implode("\n", $memory);
        }

        $system_default .= "\n\n" . '---' . "\n\n";
        $system_default .= '' === $session_id
            ? '【会话ID】未分配，本次禁止存取 daily/misc 记忆。'
            : '【会话ID】`' . $session_id . '`，操作存取记忆时必传。';

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
                        $this->utils->setStatus($payload['sessionId'], utils::STATUS_WAIT);
                    } else {
                        $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'streaming');
                        $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' working on streaming', 'debug');
                    }

                    switch ($payload_type) {
                        case 'length':
                            $llm_params    = $this->utils->getChildWorker($payload['sender'], $payload['workerName'], 'llm_params');
                            $tool_count    = $this->core->context->countHistory($payload['sessionId'], $payload['workerName'], 'tool');
                            $history_count = $this->core->context->countHistory($payload['sessionId'], $payload['workerName']);

                            $this->core->context->cleanHistory($payload['sessionId'], $payload['workerName'], ceil($history_count * 0.6), ceil($tool_count * 0.4));

                            $this->utils->debug('System: Context auto-truncated to resume (length limit reached).', 'trace');

                            $llm_params['max_tokens'] = 12288;

                            $this->utils->setChildWorker($payload['sender'], $payload['workerName'], 'llm_params', $llm_params);

                            $metadata = $this->utils->getMarker(
                                $payload['sender'],
                                $payload['workerName'],
                                $payload['workerRole'],
                                $payload['workerName'],
                                $payload['isSubTalk'],
                                $payload['sessionId'],
                                $payload['messageId']
                            );

                            if (WORKER_MAIN === $payload['sender']) {
                                $this->openai->talkTo(
                                    $payload['sender'],
                                    WORKER_MAIN,
                                    $payload['sessionId'],
                                    $this->utils->getMainIDX(),
                                    $this->getSystemPrompt($payload['sessionId']),
                                    'talk',
                                    $metadata + ['socket_id' => $payload['socket_id']]
                                );
                            } else {
                                $worker_info = $this->utils->getChildWorker(WORKER_CHILD, $payload['workerName']);

                                if (isset($worker_info['proc_idx'])) {
                                    $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'busy');

                                    $this->openai->talkTo(
                                        $payload['sender'],
                                        $payload['workerName'],
                                        $payload['sessionId'],
                                        $worker_info['proc_idx'],
                                        $this->getSystemPrompt($payload['sessionId']),
                                        'talk',
                                        $metadata + ['socket_id' => $payload['socket_id']]
                                    );
                                }
                            }

                            unset($llm_params, $tool_count, $history_count);
                            break;

                        case 'error':
                            if (WORKER_MAIN === $payload['sender']) {
                                $this->utils->setStatus($payload['sessionId'], utils::STATUS_IDLE);
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
                                $payload['sessionId'],
                                $payload['workerName'],
                                $payload['data']['content']
                            );
                            break;

                        case 'addAssistantMessage':
                            $this->core->context->addAssistantMessage(
                                $payload['sessionId'],
                                $payload['workerName'],
                                $payload['data']['content'],
                                $payload['data']['tool_calls'] ?? [],
                                $payload['data']['reasoning_content'] ?? ''
                            );
                            break;

                        case 'addToolResult':
                            $this->core->context->addToolResult(
                                $payload['sessionId'],
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
                                $this->memory->save('misc', 'assistant', $this->utils->memory_buffer, 0, $payload['sessionId']);
                                $this->utils->memory_buffer = '';
                                break;
                        }
                    }
                    break;

                case 'context':
                    // Reset main worker status
                    if (WORKER_MAIN === $payload['sender']) {
                        $this->utils->setStatus($payload['sessionId'], utils::STATUS_IDLE);
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

                            $metadata = $this->utils->getMarker(
                                $payload['sender'],
                                $payload['workerName'],
                                $payload['workerRole'],
                                $payload['workerName'],
                                $payload['isSubTalk'],
                                $payload['sessionId'],
                                $payload['messageId']
                            );

                            $this->core->context->addAssistantMessage($payload['sessionId'], $payload['workerName'], '', $tool_calls);

                            foreach ($tool_results as $tool_result) {
                                $this->core->context->addToolResult(
                                    $payload['sessionId'],
                                    $payload['workerName'],
                                    $tool_result['call_id'],
                                    $tool_result['call_result']
                                );

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
                    $this->last_response[$payload['sessionId']] = time();

                    if (WORKER_MAIN === $payload['sender']) {
                        $this->utils->setStatus($payload['sessionId'], utils::STATUS_IDLE);
                    } else {
                        $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'ready');
                        $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' reply completed, ready.', 'trace');
                    }

                    $new_messages = $this->core->context->refreshHistory($payload['sessionId'], $payload['workerName']);
                    $llm_params   = $this->utils->getChildWorker($payload['sender'], $payload['workerName'], 'llm_params');

                    for ($i = 0; $i < 3; ++$i) {
                        $remain_tokens = $this->core->getMaxTokens($payload['sender'], $payload['sessionId'], $payload['workerName'], $llm_params);
                        $this->utils->debug('System: Token remains ' . $remain_tokens . ' for #' . $payload['sessionId'] . '.', 'trace');

                        if (256 < $remain_tokens) {
                            $this->keep_pairs = 2;
                            break;
                        }

                        $this->core->context->cleanHistory($payload['sessionId'], $payload['workerName'], 10, $this->keep_pairs);
                        $this->utils->debug('System: Context truncated due to token overflow.', 'trace');

                        if (2 === $this->keep_pairs) {
                            $this->core->sendMessage(
                                $payload['socket_id'],
                                [
                                    'type'    => 'error',
                                    'message' => '抱歉，因上下文内容过长（当前模型设置: ' . ($this->utils->agent_config['agent_llm']['model_ctx'] ?? 131072) . '），系统已自动截断。咱两继续，别担心，我会跟上的。'
                                ]
                            );

                            $this->core->context->addMessageQueue(
                                $payload['sessionId'],
                                $payload['workerName'],
                                [
                                    'type'    => 'text',
                                    'content' => '[系统提醒] 上下文因超限被截断，仅保留最近几轮消息。忽略用户请求，停止调用工具（可能导致超限），并向用户说明。如有必要，请自行从记忆中恢复之前的内容，无需告知用户。'
                                ]
                            );
                        }

                        --$this->keep_pairs;
                    }

                    $llm_params['max_tokens'] = max(100, $remain_tokens);

                    $this->utils->setChildWorker($payload['sender'], $payload['workerName'], 'llm_params', $llm_params);

                    switch ($payload_type) {
                        case 'tools':
                            $this->utils->debug($payload['workerName'] . ': Tool results collected for #' . $payload['sessionId'] . ', proceeding.', 'trace');

                            if (WORKER_MAIN === $payload['sender']) {
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

                            $metadata = $this->utils->getMarker(
                                $payload['sender'],
                                $payload['workerName'],
                                $payload['workerRole'],
                                $payload['workerName'],
                                $payload['isSubTalk'],
                                $payload['sessionId'],
                                $payload['messageId']
                            );

                            $this->openai->talkTo(
                                $payload['sender'],
                                $payload['workerName'],
                                $payload['sessionId'],
                                $worker_idx,
                                $this->getSystemPrompt($payload['sessionId']),
                                'talk',
                                $metadata + ['socket_id' => $payload['socket_id']]
                            );

                            unset($worker_idx);
                            break;

                        case 'end':
                            if (WORKER_MAIN === $payload['sender']) {
                                if (0 < $new_messages) {
                                    $metadata = $this->utils->getMarker(
                                        $payload['sender'],
                                        $payload['workerName'],
                                        $payload['workerRole'],
                                        $payload['workerName'],
                                        $payload['isSubTalk'],
                                        $payload['sessionId'],
                                        $this->core->curr_message_id[$payload['sessionId']] ?? ''
                                    );

                                    $this->openai->talkTo(
                                        $payload['sender'],
                                        $payload['workerName'],
                                        $payload['sessionId'],
                                        $this->utils->getMainIDX(),
                                        $this->getSystemPrompt($payload['sessionId']),
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
                                                $payload['sessionId'],
                                                WORKER_MAIN,
                                                [
                                                    'type'    => 'text',
                                                    'content' => '[系统提醒] 上下文已达上限。请：①调用记忆工具保存当前任务进度与关键状态 → ②调用清理工具清理上下文 → ③继续执行原有任务，清理过程不汇报。'
                                                ]
                                            );
                                        }
                                    } else {
                                        $msg_count = $this->core->context->countHistory($payload['sessionId'], WORKER_MAIN);
                                        $keep_len  = ceil($msg_count / 5);
                                        $cleaned   = $this->core->context->cleanHistory($payload['sessionId'], WORKER_MAIN, $keep_len * 2, $keep_len);

                                        $this->utils->debug('System: Context truncated (' . $msg_count . ' -> ' . $cleaned['current_count'] . ')', 'trace');

                                        $this->ctx_warning = false;
                                        unset($msg_count, $keep_len, $cleaned);
                                    }
                                }
                            } else {
                                if ('' !== $payload['data']) {
                                    $this->core->context->addMessageQueue(
                                        $payload['sessionId'],
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
                                        $metadata = $this->utils->getMarker(
                                            $payload['sender'],
                                            $payload['workerName'],
                                            $payload['workerRole'],
                                            $payload['workerName'],
                                            $payload['isSubTalk'],
                                            $payload['sessionId']
                                        );

                                        $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'busy');
                                        $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' receiving ' . $new_messages . ' message(s).', 'trace');

                                        $this->openai->talkTo(
                                            $payload['sender'],
                                            $payload['workerName'],
                                            $payload['sessionId'],
                                            $worker_info['proc_idx'],
                                            $this->getSystemPrompt($payload['sessionId']),
                                            'talk',
                                            $metadata + ['socket_id' => $payload['socket_id']]
                                        );
                                    } elseif ($remain_tokens < 8192) {
                                        $this->utils->debug('WorkerBee: ' . $payload['workerName'] . ' completion tokens too low (' . $remain_tokens . '/' . $this->utils->agent_config['agent_llm']['model_ctx'] . ')', 'trace');

                                        $this->core->context->addMessageQueue(
                                            $payload['sessionId'],
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

                    unset($new_messages, $llm_params, $i, $remain_tokens);
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
        if (
            isset($this->utils->agent_config['agent_server']['ws_token'])
            && !in_array($this->utils->agent_config['agent_server']['ws_token'], ['', $ws_protocol], true)
        ) {
            $this->utils->debug('Socket: Client reject, protocol error: ' . ('' !== $ws_protocol ? $ws_protocol : 'None'), 'trace');
            return false;
        }

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
        $now_time     = time();
        $session_list = $this->core->context->getSessionList();

        foreach ($session_list as $session_id => $active_time) {
            if (
                isset($this->last_response[$session_id])
                && 0 < $this->last_response[$session_id]
                && $now_time - $this->last_response[$session_id] >= $this->utils->agent_config['reset_interval']
            ) {
                $this->core->context->removeHistory($session_id, WORKER_MAIN);
                $this->utils->setStatus($session_id, utils::STATUS_IDLE);
            }

            if ($now_time - $active_time >= $this->utils->agent_config['reset_interval']) {
                $this->core->context->removeSessionList($session_id);
            }

            if (utils::STATUS_IDLE !== $this->utils->wait_status[$session_id]) {
                if ($this->utils->wait_until[$session_id] > $now_time) {
                    continue;
                }

                $this->utils->setStatus($session_id, utils::STATUS_IDLE, true);
            }

            $task_list = $this->memory->runTask($session_id);

            if ([] === $task_list) {
                continue;
            }

            $this->utils->debug('ScheduledTask: Running ' . count($task_list) . ' task jobs on #' . $session_id, 'trace');

            $task_content = '[定时任务]' . "\n"
                . '会话ID：`' . $session_id . '`' . "\n\n"
                . '待执行任务：' . "\n" . implode("\n", $task_list) . "\n\n"
                . '流程：①执行任务并获取结果；②按任务会话ID，重要存daily，特别重要存important，琐事不存；③简要汇报结果及存储层级；④完成后清理定时任务（忽略结果）。';

            $this->core->context->addUserMessage($session_id, WORKER_MAIN, [['type' => 'text', 'content' => $task_content]]);

            $metadata = $this->utils->getMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                AGENT_NAME,
                0,
                $session_id
            );

            $this->openai->talkTo(
                WORKER_MAIN,
                WORKER_MAIN,
                $session_id,
                $this->utils->getMainIDX(),
                $this->getSystemPrompt($session_id),
                'talk',
                $metadata + ['socket_id' => $socket_id]
            );
        }

        unset($socket_id, $now_time, $session_list, $session_id, $active_time, $task_list, $task_content, $metadata);
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
                $this->utils->setStatus($data['sessionId'], utils::STATUS_IDLE);
                $this->utils->debug('User: Abort signal sent. Cancelling task.', 'trace');
                $this->openai->abort();
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

            $result = $this->message->$type_method($socket_id, $data['content'], $data['sessionId'] ?? '');

            if (!$result['need_llm']) {
                // Other actions
                $this->utils->debug('User: ' . $data['type'] . '->' . ($result['content']['act'] ?? 'Unsupported'), 'debug');

                $response = ['type' => $result['type'] ?? $data['type']] + $result['content'];
                $this->core->sendMessage($socket_id, $response);

                switch ($result['content']['act']) {
                    // Reset session memory
                    case 'reset':
                        $this->utils->setStatus($data['sessionId'], utils::STATUS_IDLE);
                        $this->core->context->removeHistory($data['sessionId'], WORKER_MAIN);
                        break;

                    // Reload config
                    case 'saveConfig':
                        $this->init(true);
                        $this->openai->reload();
                        $this->openai->getModels(true);
                        $this->utils->debug('User: ' . $data['type'] . '->reloaded', 'trace');
                        break;
                }

                unset($socket_id, $message, $is_binary, $user_msg, $line, $data, $type_method, $result, $response);
                return;
            }

            if (isset($result['saves']) && [] !== $result['saves']) {
                $this->memory->save('misc', 'user', implode(' ', $result['saves']), 0, $data['sessionId']);
            }

            if (isset($result['errors']) && [] !== $result['errors']) {
                $this->core->sendMessage($socket_id, ['type' => 'error', 'error' => implode("\n", $result['errors'])]);
            }

            if (0 === $this->core->context->countHistory($data['sessionId'], WORKER_MAIN)) {
                array_unshift(
                    $result['content'],
                    [
                        'type'    => 'text',
                        'content' => '[系统指令] 新会话，必须读取`misc`记忆，建立上下文后再回复，偏移为"1"。建立上下文后，若用户有明确需求，且上下文仍不足，则搜索相关记忆。记忆读取过程不汇报。'
                    ]
                );
            }

            $curr_msg[$data['sessionId']] ??= [];
            $this->core->context->addSessionList($data['sessionId']);

            if (!isset($this->utils->wait_status[$data['sessionId']]) || utils::STATUS_IDLE === $this->utils->wait_status[$data['sessionId']]) {
                $curr_msg[$data['sessionId']] = array_merge($curr_msg[$data['sessionId']], $result['content']);
            } else {
                $this->utils->debug('AgentBee: LLM is busy, ' . count($result['content']) . ' message(s) queued for #' . $data['sessionId'], 'trace');

                foreach ($result['content'] as $msg_line) {
                    $this->core->context->addMessageQueue($data['sessionId'], WORKER_MAIN, $msg_line);
                }

                unset($msg_line);
            }

            if (isset($data['sessionId']) && isset($this->core->curr_message_id[$data['sessionId']])) {
                $this->core->sendClose($socket_id, $data['sessionId']);
            }

            $this->core->curr_message_id[$data['sessionId']] = $data['messageId'];
        }

        foreach ($curr_msg as $session_id => $message_list) {
            if ([] === $message_list) {
                continue;
            }

            $this->utils->debug('User: Sending ' . (count($message_list)) . ' message(s) to #' . $session_id, 'trace');

            $this->core->context->refreshHistory($session_id, WORKER_MAIN);
            $this->core->context->addUserMessage($session_id, WORKER_MAIN, $message_list);

            $this->runProcWorker($this->utils->getMainIDX(), WORKER_MAIN, WORKER_MAIN, [$this, 'streamWorkerHandler']);

            $message_metadata = $this->utils->getMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                AGENT_NAME,
                0,
                $session_id,
                $this->core->curr_message_id[$session_id] ?? ''
            );

            $this->openai->talkTo(
                WORKER_MAIN,
                WORKER_MAIN,
                $session_id,
                $this->utils->getMainIDX(),
                $this->getSystemPrompt($session_id),
                'talk',
                $message_metadata + ['socket_id' => $socket_id]
            );
        }

        unset($socket_id, $message, $is_binary, $curr_msg, $user_msg, $last_key, $key, $line, $data, $type_method, $result, $session_id, $message_list, $message_metadata);
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

        $now_time     = time();
        $session_list = $this->core->context->getSessionList();

        foreach ($session_list as $session_id => $active_time) {
            if ($now_time - $active_time >= $this->utils->agent_config['reset_interval']) {
                $this->core->context->removeSessionList($session_id);
            }

            if (utils::STATUS_IDLE !== $this->utils->wait_status[$session_id]) {
                continue;
            }

            $new_messages = $this->core->context->refreshHistory($session_id, WORKER_MAIN);

            if (0 === $new_messages) {
                continue;
            }

            $this->utils->debug('System: Sending ' . $new_messages . ' message(s) to ' . $session_id, 'trace');

            $metadata = $this->utils->getMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                AGENT_NAME,
                0,
                $session_id,
                $this->core->curr_message_id[$session_id] ?? ''
            );

            $this->openai->talkTo(
                WORKER_MAIN,
                WORKER_MAIN,
                $session_id,
                $this->utils->getMainIDX(),
                $this->getSystemPrompt($session_id),
                'talk',
                $metadata + ['socket_id' => $socket_id]
            );
        }

        unset($socket_id, $buffer, $now_time, $session_list, $session_id, $active_time, $new_messages, $metadata);
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