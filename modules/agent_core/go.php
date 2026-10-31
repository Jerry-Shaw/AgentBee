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
use Nervsys\Core\Mgr\SocketMgr;

class go extends Factory
{
    public core    $core;
    public utils   $utils;
    public message $message;

    public memory $memory;
    public openai $openai;

    public int $keep_pairs = 2;

    public bool $ctx_warning = false;

    public array $session_list  = [];
    public array $session_calls = [];
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

        $this->core->app->setLocale($this->utils->agent_config['agent_server']['locale'] ?? 'zh_CN.UTF-8');
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
        $this->utils->debug('Model-ID: ' . ($this->utils->agent_config['agent_llm']['model_id'] ?? 'NONE'), 'trace');
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
    public function runWorker(int $proc_idx, string $worker_type, string $worker_name, callable $output_handler): int
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

        $this->runWorker(
            $this->utils->getMainIDX(),
            WORKER_MAIN,
            WORKER_MAIN,
            [$this, 'workerHandler']
        );

        $this->session_list = array_column($this->memory->listSessions(0)['sessions'], 'session_id');

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
                ->setEventListener('onUserFunc', [$this, 'scheduleTask'])
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
        $prompt   = $this->utils->getMainPrompt();
        $memories = $this->memory->read('system');

        if ([] !== $memories['data']) {
            $memory = ['## 用户与人物设定'];

            foreach ($memories['data'] as $content) {
                $memory[] = '- ' . $content['content'];
            }

            $prompt .= "\n\n" . implode("\n", $memory);

            unset($memory, $content);
        }

        $prompt .= "\n\n" . '---' . "\n\n";

        if ('' !== $session_id) {
            $session = $this->memory->listSessions(0, $session_id, false);
            $prompt  .= isset($session['sessions'][0]['session_id']) && $session['sessions'][0]['session_id'] === $session_id
                ? '【当前会话】**' . $session['sessions'][0]['session_name'] . '**，【会话ID】`' . $session_id . '`，读写记忆时必传。'
                : '【会话ID】`' . $session_id . '`，读写记忆时必传。';

            unset($session);
        } else {
            $prompt .= '【会话ID】未分配，本次禁止读写`daily`/`misc`记忆。';
        }

        $prompt .= "\n\n" . '---' . "\n\n";
        $prompt .= $this->utils->getNowTimePrompt();

        unset($session_id, $memories);
        return $prompt;
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
    public function workerHandler(string $ext_id, array $context): void
    {
        $stdout_buffer = [];
        $stdout_stream = $context['stdout'];

        // Free the stdout pipe by reading all data immediately.
        while (false !== ($line = fgets($stdout_stream))) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            $stdout_buffer[] = $line;
        }

        if ([] === $stdout_buffer) {
            return;
        }

        // Process buffer frames
        foreach ($stdout_buffer as $line) {
            $message = json_decode($line, true);

            if (!is_array($message) || !isset($message['payload']['sessionId'])) {
                continue;
            }

            $payload      = $message['payload'];
            $payload_type = $payload['type'];

            $this->session_calls[$payload['sessionId']] ??= 0;

            if (0 < $this->session_calls[$payload['sessionId']]) {
                $this->session_calls[$payload['sessionId']] = 0;

                // Suspend only if there are new messages in queue
                if (0 < count($this->core->context->message_queue)) {
                    $this->utils->debug('System: Calls executed for #' . $payload['sessionId'] . '.', 'trace');
                    $this->utils->setStatus(utils::STATUS_IDLE, $payload['sessionId']);
                    \Fiber::suspend();
                }
            }

            $this->utils->debug($payload['workerName'] . ': ' . $message['type'] . '->' . $payload_type, 'debug');
            $worker_info = $this->utils->getChildWorker($payload['sender'], $payload['workerName']);
            $llm_params  = $worker_info['llm_params'] ?? [];

            switch ($message['type']) {
                case 'stream':
                    if (WORKER_MAIN === $payload['sender']) {
                        $this->utils->setStatus(utils::STATUS_WAIT, $payload['sessionId']);
                    } else {
                        $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'streaming');
                        $this->utils->debug('Collaborator: ' . $payload['workerName'] . ' working on streaming', 'debug');
                    }

                    switch ($payload_type) {
                        case 'length':
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
                                    $metadata
                                );
                            } else {
                                if (isset($worker_info['proc_idx'])) {
                                    $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'busy');

                                    $this->openai->talkTo(
                                        $payload['sender'],
                                        $payload['workerName'],
                                        $payload['sessionId'],
                                        $worker_info['proc_idx'],
                                        $this->getSystemPrompt($payload['sessionId']),
                                        'talk',
                                        $metadata
                                    );
                                }
                            }

                            unset($tool_count, $history_count);
                            break;

                        case 'error':
                            if (WORKER_MAIN === $payload['sender']) {
                                $this->utils->setStatus(utils::STATUS_IDLE, $payload['sessionId']);
                            } else {
                                $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'ready');
                            }

                            if (is_array($payload['data']) && isset($payload['data']['message'])) {
                                $this->core->sendMessage($payload);
                            }

                            break;

                        case 'abort':
                            $this->core->sendMessage($payload);
                            $this->utils->setStatus(utils::STATUS_IDLE, $payload['sessionId']);
                            break;

                        default:
                            $this->core->sendMessage($payload);
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
                    if (WORKER_MAIN !== $payload['sender'] || '' === ($payload['data'] ?? '')) {
                        break;
                    }

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
                    break;

                case 'context':
                    // Reset main worker status
                    if (WORKER_MAIN === $payload['sender']) {
                        $this->utils->setStatus(utils::STATUS_IDLE, $payload['sessionId']);
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

                                $this->core->sendMessage($metadata + $msg_data);
                            }

                            unset($tool_calls, $tool_results, $data, $handler, $result, $metadata, $assistant_message, $tool_result, $msg_data);
                            break;
                    }
                    break;

                case 'end':
                    $this->last_response[$payload['sessionId']] = time();

                    if (WORKER_MAIN === $payload['sender']) {
                        ++$this->session_calls[$payload['sessionId']];
                        $this->utils->setStatus('tools' === $payload_type ? utils::STATUS_BUSY : utils::STATUS_IDLE, $payload['sessionId']);
                    } else {
                        $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'ready');
                        $this->utils->debug('Collaborator: ' . $payload['workerName'] . ' reply completed, ready.', 'trace');
                    }

                    $new_messages = $this->core->context->refreshHistory($payload['sessionId'], $payload['workerName']);

                    if (isset($worker_info['proc_idx'])) {
                        for ($i = 0; $i < 3; ++$i) {
                            $remain_tokens = $this->core->getMaxTokens($payload['sender'], $payload['sessionId'], $payload['workerName'], $llm_params);
                            $this->utils->debug($payload['sender'] . ': Token remains ' . $remain_tokens . ' for #' . $payload['sessionId'] . '.', 'trace');

                            // Skip when token > 1024
                            if (1024 < $remain_tokens) {
                                if (WORKER_MAIN === $payload['sender']) {
                                    $this->ctx_warning = false;
                                    $this->keep_pairs  = 2;
                                }

                                break;
                            }

                            // Send notice when token > 256
                            if (256 < $remain_tokens) {
                                $this->utils->debug($payload['sender'] . ': Remain tokens too low (' . $remain_tokens . '/' . $this->utils->agent_config['agent_llm']['model_ctx'] . ')', 'trace');

                                $content = WORKER_MAIN === $payload['sender']
                                    ? '[系统提醒] 上下文已达上限。请：①调用记忆工具保存当前任务进度与关键状态 → ②调用清理工具清理上下文 → ③继续执行原有任务，清理过程不汇报。'
                                    : '[协作者] `' . $payload['workerName'] . '` | ' . $payload['workerRole'] . '：上下文已满。请：①生成任务摘要（目标+进度+待办） → ②结束并重新邀请`' . $payload['workerName'] . '` → ③提供任务摘要，继续原有任务，邀请过程不汇报。';

                                $main_notice  = (WORKER_MAIN === $payload['sender'] && !$this->ctx_warning);
                                $child_notice = WORKER_CHILD === $payload['sender'];

                                if ($main_notice || $child_notice) {
                                    if ($main_notice) {
                                        $this->ctx_warning = true;
                                    }

                                    $this->core->context->addMessageQueue(
                                        $payload['sessionId'],
                                        WORKER_MAIN,
                                        [
                                            'type'    => 'text',
                                            'content' => $content
                                        ]
                                    );
                                }

                                break;
                            }

                            // Force clean history context when token < 256
                            $history_count = $this->core->context->countHistory($payload['sessionId'], $payload['workerName']);
                            $keep_count    = ceil($history_count * ($this->keep_pairs + 2) / 10);
                            $this->core->context->cleanHistory($payload['sessionId'], $payload['workerName'], $keep_count, $this->keep_pairs);
                            $now_count = $this->core->context->countHistory($payload['sessionId'], $payload['workerName']);

                            $this->utils->debug($payload['sender'] . ': Context truncated from ' . $history_count . ' to ' . $now_count . ' due to token overflow.', 'trace');

                            if (2 === $this->keep_pairs) {
                                $this->core->sendMessage(
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

                        unset($i, $remain_tokens, $content, $main_notice, $child_notice, $history_count, $keep_count, $now_count);
                    }

                    switch ($payload_type) {
                        case 'tools':
                            $this->utils->debug($payload['workerName'] . ': Tool results collected for #' . $payload['sessionId'] . ', proceeding.', 'trace');

                            if (WORKER_MAIN === $payload['sender']) {
                                $worker_idx = $this->utils->getMainIDX();
                            } else {
                                if ([] === $worker_info) {
                                    $this->utils->debug($payload['workerName'] . ': Worker already closed.', 'trace');
                                    break;
                                }

                                $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'calling_tools');
                                $worker_idx = $worker_info['proc_idx'];
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
                                $metadata
                            );

                            unset($worker_idx);
                            break;

                        case 'end':
                            if (WORKER_MAIN === $payload['sender']) {
                                if (0 < $new_messages || $this->core->context->isMessageOnSend($payload['sessionId'])) {
                                    $metadata = $this->utils->getMarker(
                                        $payload['sender'],
                                        $payload['workerName'],
                                        $payload['workerRole'],
                                        $payload['workerName'],
                                        $payload['isSubTalk'],
                                        $payload['sessionId'],
                                        $this->core->curr_message_id[$payload['sessionId']] ?? ''
                                    );

                                    $this->openai->resume();
                                    $this->openai->talkTo(
                                        $payload['sender'],
                                        $payload['workerName'],
                                        $payload['sessionId'],
                                        $this->utils->getMainIDX(),
                                        $this->getSystemPrompt($payload['sessionId']),
                                        'talk',
                                        $metadata
                                    );
                                }
                            } else {
                                if ('' !== $payload['data']) {
                                    $this->core->context->addMessageQueue(
                                        $payload['sessionId'],
                                        WORKER_MAIN,
                                        [
                                            'type'    => 'text',
                                            'content' => '[协作者消息]' . "\n\n" . '`' . $payload['workerName'] . '`：' . $payload['workerRole'] . "\n\n" . '消息内容：' . "\n" . $payload['data']
                                        ]
                                    );
                                }

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
                                        $this->utils->debug('Collaborator: ' . $payload['workerName'] . ' receiving ' . $new_messages . ' message(s).', 'trace');

                                        $this->openai->talkTo(
                                            $payload['sender'],
                                            $payload['workerName'],
                                            $payload['sessionId'],
                                            $worker_info['proc_idx'],
                                            $this->getSystemPrompt($payload['sessionId']),
                                            'talk',
                                            $metadata
                                        );
                                    }
                                }
                            }

                            break;
                    }

                    unset($new_messages);
                    break;
            }
        }

        unset($ext_id, $context, $stdout_buffer, $stdout_stream, $line, $message, $payload, $payload_type, $worker_info, $llm_params, $metadata);
    }

    /**
     * Schedule task callback.
     *
     * @param SocketMgr $socketMgr
     *
     * @return void
     * @throws \ReflectionException
     */
    public function scheduleTask(SocketMgr $socketMgr): void
    {
        $session_list = $this->memory->listSessions(0, '', true);

        foreach ($session_list['sessions'] as $session) {
            $task_list = $this->memory->runTask($session['session_id']);

            if ([] === $task_list) {
                continue;
            }

            // Remove all tasks if session is deleted
            if (1 !== $session['session_status']) {
                foreach ($task_list as $task) {
                    $this->memory->removeTask($task['create_id']);
                }

                continue;
            }

            $this->utils->debug('ScheduledTask: Running ' . count($task_list) . ' task jobs on #' . $session['session_id'], 'trace');

            $task_content = '[定时任务]' . "\n"
                . '会话ID：`' . $session['session_id'] . '`' . "\n\n"
                . '待执行任务：' . "\n" . implode("\n", array_column($task_list, 'prompt')) . "\n\n"
                . '严格按流程执行：①执行任务并详细汇总；②按会话ID存储：重要→daily，特别重要→important，琐事不存；③完整输出详细结果，简述存储层级或未存原因，必要时补充分析并总结。';

            $this->core->context->addUserMessage($session['session_id'], WORKER_MAIN, [['type' => 'text', 'content' => $task_content]]);

            $metadata = $this->utils->getMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                AGENT_NAME,
                0,
                $session['session_id']
            );

            $this->openai->resume();
            $this->openai->talkTo(
                WORKER_MAIN,
                WORKER_MAIN,
                $session['session_id'],
                $this->utils->getMainIDX(),
                $this->getSystemPrompt($session['session_id']),
                'talk',
                $metadata
            );
        }

        unset($socketMgr, $session_list, $session, $task_list, $task_content, $metadata);
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
                $this->utils->setStatus(utils::STATUS_IDLE, $session_id);
            }

            if ($now_time - $active_time >= $this->utils->agent_config['reset_interval']) {
                $this->core->context->removeSessionList($session_id);
            }

            if (utils::STATUS_IDLE !== $this->utils->wait_status) {
                if ($this->utils->wait_until > $now_time) {
                    continue;
                }

                $this->utils->setStatus(utils::STATUS_IDLE, $session_id, true);
            }
        }

        unset($socket_id, $now_time, $session_list, $session_id, $active_time);
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
        $main_pid = $this->utils->getMainIDX();

        $other_clients = array_diff_key($this->utils->socket_session, [$socket_id => 'ready']);

        foreach ($user_msg as $line) {
            $data = json_decode($line, true);

            if (!is_array($data) || !isset($data['type'])) {
                $this->utils->debug('System: Message data ERROR: ' . $line . '.', 'debug');
                continue;
            }

            if ('/abort' === $data['type']) {
                $this->openai->abort();
                $this->utils->debug('User: Abort signal sent. Cancelling task.', 'trace');
                $this->utils->setStatus(utils::STATUS_IDLE, $data['sessionId'] ?? 'Unknown-Session-ID');
                continue;
            }

            $type_method = 'process_' . $data['type'];

            if (!method_exists($this->message, $type_method)) {
                $this->utils->debug('User: Incorrect message type: ' . $data['type'], 'debug');
                continue;
            }

            // broadcast input message to all online clients
            if ([] !== $other_clients && '' !== ($data['sessionId'] ?? '')) {
                $marker = $this->utils->getMarker(
                    WORKER_USER,
                    WORKER_MAIN,
                    WORKER_USER,
                    WORKER_MAIN,
                    0,
                    $data['sessionId']
                );

                $feedback = $this->message->buildFeedback($data['content'], $marker);

                if ([] !== $feedback) {
                    $this->core->sendMessage($feedback, $other_clients);
                }

                unset($marker, $feedback);
            }

            if ('memory' === $data['type']) {
                $data['content']['memory'] = $this->memory;
            }

            $result = $this->message->$type_method($data['content'], $data['sessionId'] ?? '');

            if (!$result['need_llm']) {
                // Other actions
                $this->utils->debug('User: ' . $data['type'] . '->' . ($result['content']['act'] ?? 'Unsupported'), 'debug');

                $response = ['type' => $result['type'] ?? $data['type']] + $result['content'];
                $this->core->sendMessage($response);

                switch ($result['content']['act']) {
                    // Reset session memory
                    case 'reset':
                        $this->openai->reload();
                        $this->openai->getModels(true);
                        $this->core->context->messageOnSend($data['sessionId'], false);
                        $this->core->context->removeHistory($data['sessionId'], WORKER_MAIN);
                        $this->utils->setStatus(utils::STATUS_IDLE, $data['sessionId'] ?? 'Unknown-Session-ID');
                        break;

                    // Reload config
                    case 'saveConfig':
                        $this->init(true);

                        $this->utils->setChildWorker(
                            WORKER_MAIN,
                            WORKER_MAIN,
                            'llm_params',
                            $this->utils->agent_config['agent_llm']['params']
                        );

                        $this->openai->reload();
                        $this->openai->getModels(true);

                        $this->utils->debug('User: ' . $data['type'] . '->reloaded', 'trace');
                        break;
                }

                unset($socket_id, $message, $is_binary, $user_msg, $line, $data, $type_method, $result, $response);
                return;
            }

            // Save to misc memory
            if (isset($result['saves']) && [] !== $result['saves']) {
                $memory = implode(' ', $result['saves']);

                // Save to session
                if (!in_array($data['sessionId'], $this->session_list)) {
                    $this->session_list[] = $data['sessionId'];
                    $this->memory->createSession($data['sessionId'], mb_substr($memory, 0, 32, 'UTF-8'));
                }

                $this->memory->save('misc', 'user', $memory, 0, $data['sessionId']);

                unset($memory);
            }

            if (isset($result['errors']) && [] !== $result['errors']) {
                $this->core->sendMessage(['type' => 'error', 'error' => implode("\n", $result['errors'])]);
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

            if (utils::STATUS_IDLE === $this->utils->wait_status) {
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

            $message_metadata = $this->utils->getMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                AGENT_NAME,
                0,
                $session_id,
                $this->core->curr_message_id[$session_id] ?? ''
            );

            $this->openai->resume();
            $this->openai->talkTo(
                WORKER_MAIN,
                WORKER_MAIN,
                $session_id,
                $main_pid,
                $this->getSystemPrompt($session_id),
                'talk',
                $message_metadata
            );
        }

        unset($socket_id, $message, $is_binary, $curr_msg, $user_msg, $last_key, $main_pid, $other_clients, $line, $data, $type_method, $result, $session_id, $message_list, $message_metadata);
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
                $this->core->sendMessage($buffer);
            }
        }

        $session_list = $this->core->context->getSessionList();

        foreach ($session_list as $session_id => $active_time) {
            $message_onsend = $this->core->context->isMessageOnSend($session_id);

            if (utils::STATUS_IDLE !== $this->utils->wait_status && !$message_onsend) {
                $this->utils->debug('System: Proc #' . $session_id . ' is BUSY with no message onsend.', 'debug');
                continue;
            }

            $new_messages = $this->core->context->refreshHistory($session_id, WORKER_MAIN);

            if (0 === $new_messages && !$message_onsend) {
                continue;
            }

            $message_onsend
                ? $this->utils->debug('System: Resending messages to #' . $session_id, 'trace')
                : $this->utils->debug('System: Sending ' . $new_messages . ' new message(s) to #' . $session_id, 'trace');

            $metadata = $this->utils->getMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                AGENT_NAME,
                0,
                $session_id,
                $this->core->curr_message_id[$session_id] ?? ''
            );

            $this->openai->resume();
            $this->openai->talkTo(
                WORKER_MAIN,
                WORKER_MAIN,
                $session_id,
                $this->utils->getMainIDX(),
                $this->getSystemPrompt($session_id),
                'talk',
                $metadata
            );
        }

        unset($socket_id, $buffer, $session_list, $session_id, $active_time, $message_onsend, $new_messages, $metadata);
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