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
            [$this, 'stdoutHandler']
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
                ->setUserFuncInterval(10)
                ->setAliveTimeout($this->utils->agent_config['agent_server']['ping_interval'])
                ->setEventListener('onUserFunc', [$this, 'scheduler'])
                ->setEventListener('onHandshake', [$this, 'onHandshake'])
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
        $memories = $this->memory->read('system', 0, 0, 0);

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
    public function stdoutHandler(string $ext_id, array $context): void
    {
        $stdout_buffer = [];
        $stdout_stream = $context['stdout'];

        // Free the stdout pipe by reading all data immediately.
        while (false !== ($line = fgets($stdout_stream))) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            $message = json_decode($line, true);

            if (!is_array($message) || !isset($message['payload']['sessionId'])) {
                continue;
            }

            $stdout_buffer[] = $message;
        }

        if ([] === $stdout_buffer) {
            return;
        }

        // Process buffer frames
        foreach ($stdout_buffer as $message) {
            $payload     = $message['payload'];
            $worker_info = $this->utils->getChildWorker($payload['sender'], $payload['workerName']);

            if ([] === $worker_info) {
                $this->utils->debug($payload['sender'] . ': ' . $payload['workerName'] . ' already closed.', 'trace');
                continue;
            }

            $llm_params = $worker_info['llm_params'];

            $this->session_calls[$payload['sessionId']] ??= 0;

            $this->parsePayload($message['type'], $payload, $llm_params, $worker_info);

            if (0 < $this->session_calls[$payload['sessionId']]) {
                $session_queue = $this->core->context->message_queue;
                unset($session_queue[$payload['sessionId']]);

                // Suspend only if there are new messages in other sessions
                if (0 < count($session_queue)) {
                    $this->utils->debug('System: #' . $payload['sessionId'] . ' suspending.', 'trace');
                    $this->utils->setPipeStatus(utils::STATUS_IDLE, $payload['sessionId']);
                    $this->session_calls[$payload['sessionId']] = 0;
                    \Fiber::suspend();
                }
            }
        }

        unset($ext_id, $context, $stdout_buffer, $stdout_stream, $line, $message, $payload, $worker_info, $llm_params, $session_queue);
    }

    /**
     * scheduler callback.
     *
     * @param SocketMgr $socketMgr
     *
     * @return void
     * @throws \ReflectionException
     */
    public function scheduler(SocketMgr $socketMgr): void
    {
        $now_time       = time();
        $session_active = $this->core->context->getSessionList();
        $session_list   = $this->memory->listSessions(0, '', true);

        foreach ($session_list['sessions'] as $session) {
            // Remove session history when last response duration is too long
            if (
                isset($this->last_response[$session['session_id']])
                && 0 < $this->last_response[$session['session_id']]
                && ($this->last_response[$session['session_id']] + $this->utils->agent_config['reset_interval']) < $now_time
            ) {
                $this->core->context->removeHistory($session['session_id'], WORKER_MAIN);
                unset($this->last_response[$session['session_id']]);
            }

            // Reset status to IDLE when now time is newer wait until time
            if (!$this->utils->isPipeIdle($session['session_id']) && $this->utils->wait_until[$session['session_id']] < $now_time) {
                $this->utils->setPipeStatus(utils::STATUS_IDLE, $session['session_id'], true);
            }

            // Remove session from list when active duration is too long
            if (
                isset($session_active[$session['session_id']])
                && ($session_active[$session['session_id']] + $this->utils->agent_config['reset_interval']) < $now_time
            ) {
                $this->core->context->removeSessionList($session['session_id']);
            }

            // Check session pending message/new message/task
            $pending_message = $this->core->context->isMessagePending($session['session_id']);
            $new_messages    = $this->core->context->refreshHistory($session['session_id'], WORKER_MAIN);
            $task_list       = $this->memory->runTask($session['session_id']);

            // Remove all tasks if session is deleted
            if ([] !== $task_list && 1 !== $session['session_status']) {
                foreach ($task_list as $task) {
                    $this->memory->removeTask($task['create_id']);
                }

                $task_list = [];
            }

            if (!$pending_message && 0 === $new_messages && [] === $task_list) {
                continue;
            }

            // Resending context
            if ($pending_message) {
                $this->utils->debug('System: resending context to #' . $session['session_id'], 'trace');
            }

            // Sending new messages
            if (0 < $new_messages) {
                $this->utils->debug('System: sending ' . $new_messages . ' new message(s) to #' . $session['session_id'], 'trace');
            }

            // Run scheduled tasks
            if ([] !== $task_list) {
                $this->utils->debug('Scheduler: dispatching ' . count($task_list) . ' task(s) to #' . $session['session_id'], 'trace');

                $task_content = '[定时任务]' . "\n"
                    . '会话ID：`' . $session['session_id'] . '`' . "\n\n"
                    . '待执行任务：' . "\n" . implode("\n", array_column($task_list, 'prompt')) . "\n\n"
                    . '严格按流程执行：①执行任务并详细汇总；②按会话ID存储：重要→daily，特别重要→important，琐事不存；③完整输出详细结果，简述存储层级或未存原因，必要时补充分析并总结。';

                $this->core->context->addSessionList($session['session_id']);
                $this->core->context->addUserMessage($session['session_id'], WORKER_MAIN, [['type' => 'text', 'content' => $task_content]]);
            }

            if (!$this->utils->isPipeIdle()) {
                $this->core->context->messagePending($session['session_id'], true);
                continue;
            }

            $this->openai->resume();
            $this->openai->talkTo(
                WORKER_MAIN,
                WORKER_MAIN,
                $session['session_id'],
                $this->utils->getMainIDX(),
                $this->getSystemPrompt($session['session_id']),
                'talk',
                $this->utils->getMarker(
                    WORKER_MAIN,
                    WORKER_MAIN,
                    'Assistant',
                    AGENT_NAME,
                    0,
                    $session['session_id']
                )
            );
        }

        unset($socketMgr, $now_time, $session_active, $session_list, $session, $pending_message, $new_messages, $task_list, $task_content);
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
                $this->utils->setPipeStatus(utils::STATUS_IDLE, $data['sessionId'] ?? 'Unknown-Session-ID');
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
                        $this->core->context->messagePending($data['sessionId'], false);
                        $this->core->context->removeHistory($data['sessionId'], WORKER_MAIN);
                        $this->utils->setPipeStatus(utils::STATUS_IDLE, $data['sessionId'] ?? 'Unknown-Session-ID');
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

            if ($this->utils->isPipeIdle()) {
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

            $this->openai->resume();
            $this->openai->talkTo(
                WORKER_MAIN,
                WORKER_MAIN,
                $session_id,
                $main_pid,
                $this->getSystemPrompt($session_id),
                'talk',
                $this->utils->getMarker(
                    WORKER_MAIN,
                    WORKER_MAIN,
                    'Assistant',
                    AGENT_NAME,
                    0,
                    $session_id,
                    $this->core->curr_message_id[$session_id] ?? ''
                )
            );
        }

        unset($socket_id, $message, $is_binary, $curr_msg, $user_msg, $last_key, $main_pid, $other_clients, $line, $data, $type_method, $result, $session_id, $message_list);
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
        if ([] === $this->utils->message_buffers) {
            return [];
        }

        while (null !== ($buffer = array_shift($this->utils->message_buffers))) {
            $this->core->sendMessage($buffer);
        }

        unset($socket_id, $buffer);
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
     * @param string $message_type
     * @param array  $payload
     * @param array  $worker_info
     * @param array  $llm_params
     *
     * @return void
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    private function parsePayload(string $message_type, array $payload, array $llm_params, array $worker_info): void
    {
        switch ($message_type) {
            // Stream output
            case 'stream':
                // think/content/tool_calls/tool_result
                if (WORKER_MAIN === $payload['sender']) {
                    $this->utils->setPipeStatus(utils::STATUS_WAIT, $payload['sessionId']);
                } else {
                    $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'streaming');
                }

                $this->core->sendMessage($payload);
                break;

            // Save to session memory
            case 'memory':
                $this->handleMemory($payload);
                break;

            // Add session history
            case 'history':
                // addUserMessage/addAssistantMessage/addToolCalls/addToolResults
                $this->handleHistory($payload);
                break;

            // toolCalls call handler
            case 'toolCalls':
                $this->handleToolCalls($payload);
                break;

            // End reply
            case 'end':
                // end/tools/length/abort/error
                if (WORKER_MAIN === $payload['sender']) {
                    $this->utils->setPipeStatus(utils::STATUS_IDLE, $payload['sessionId']);
                } else {
                    $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'ready');
                }

                $this->handleEnd($payload, $llm_params, $worker_info);
                break;
        }

        unset($message_type, $payload, $llm_params, $worker_info);
    }

    /**
     * @param array $payload
     *
     * @return void
     * @throws \ReflectionException
     */
    private function handleMemory(array $payload): void
    {
        if (WORKER_MAIN !== $payload['sender'] || '' === ($payload['data'] ?? '')) {
            return;
        }

        switch ($payload['type']) {
            case 'add':
                $this->utils->memory_buffer .= $payload['data'];
                break;

            case 'save':
                $this->utils->memory_buffer .= $payload['data'];
                $this->memory->save('misc', 'assistant', $this->utils->memory_buffer, 0, $payload['sessionId']);
                $this->utils->memory_buffer = '';
                break;
        }

        unset($payload);
    }

    /**
     * @param array $payload
     *
     * @return void
     */
    private function handleHistory(array $payload): void
    {
        switch ($payload['type']) {
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
                    $payload['data']['reasoning_content'] ?? ''
                );
                break;

            case 'addToolCalls':
                $this->core->context->addToolCalls(
                    $payload['sessionId'],
                    $payload['workerName'],
                    $payload['data']['tool_calls']
                );
                break;

            case 'addToolResults':
                $this->core->context->addToolResults(
                    $payload['sessionId'],
                    $payload['workerName'],
                    $payload['data']['call_id'],
                    $payload['data']['content']
                );
                break;
        }

        unset($payload);
    }

    /**
     * @param array $payload
     *
     * @return void
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    private function handleToolCalls(array $payload): void
    {
        $tool_calls   = [];
        $tool_results = [];

        foreach ($payload['data'] as $data) {
            if (!isset($data['handler_args']['action'])) {
                $this->utils->debug('Handler: handler or action NOT found!', 'debug');
                continue;
            }

            $this->utils->debug('Handler: Calling ' . $data['tool_calls']['name'], 'trace');

            try {
                $this->core->IOData->src_cmd  = $data['tool_calls']['name'];
                $this->core->IOData->src_argv = $data['handler_args'];

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
            return;
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

        $this->core->context->addToolCalls($payload['sessionId'], $payload['workerName'], $tool_calls);

        foreach ($tool_results as $tool_result) {
            $this->core->context->addToolResults(
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

        unset($payload, $tool_calls, $tool_results, $data, $handler, $result, $metadata, $tool_result, $msg_data);
    }

    /**
     * @param array $payload
     * @param array $llm_params
     * @param array $worker_info
     *
     * @return void
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    private function handleEnd(array $payload, array $llm_params, array $worker_info): void
    {
        switch ($payload['type']) {
            case 'end':
            case 'tools':
                $this->last_response[$payload['sessionId']] = time();

                if ('tools' === $payload['type']) {
                    $this->utils->debug($payload['workerName'] . ': #' . $payload['sessionId'] . ' calling tools.', 'trace');
                    $new_messages = 1;
                } else {
                    $this->utils->debug($payload['workerName'] . ': #' . $payload['sessionId'] . ' reply ended.', 'trace');
                    $new_messages = $this->core->context->refreshHistory($payload['sessionId'], $payload['workerName']);
                }

                // Recalculate max-tokens
                $this->setMaxTokens($payload, $llm_params);

                if (WORKER_MAIN === $payload['sender']) {
                    ++$this->session_calls[$payload['sessionId']];

                    if (0 === $new_messages) {
                        break;
                    }

                    if (!$this->utils->isPipeIdle()) {
                        $this->core->context->messagePending($payload['sessionId'], true);
                        break;
                    }
                } else {
                    $this->utils->setChildWorker(
                        WORKER_CHILD,
                        $payload['workerName'],
                        'last_talk',
                        time()
                    );

                    $this->utils->setChildWorker(
                        WORKER_CHILD,
                        $payload['workerName'],
                        'status',
                        'tools' === $payload['type'] ? 'calling_tools' : 'ready'
                    );

                    // Send message from sub-worker to main-worker
                    if ('' !== $payload['data']) {
                        $this->core->context->addMessageQueue(
                            $payload['sessionId'],
                            WORKER_MAIN,
                            [
                                'type'    => 'text',
                                'content' => '[协作者消息]' . "\n\n"
                                    . '`' . $payload['workerName'] . '`' . "\n\n"
                                    . '--- 消息开始 ---' . "\n"
                                    . $payload['data'] . "\n"
                                    . '--- 消息结束 ---' . "\n"
                            ]
                        );
                    }

                    if (0 === $new_messages) {
                        break;
                    }

                    $this->utils->debug($payload['sender'] . ': ' . $payload['workerName'] . ' receiving ' . $new_messages . ' message(s).', 'trace');
                    $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'busy');
                }

                $this->openai->talkTo(
                    $payload['sender'],
                    $payload['workerName'],
                    $payload['sessionId'],
                    $worker_info['proc_idx'],
                    $this->getSystemPrompt($payload['sessionId']),
                    'talk',
                    $this->utils->getMarker(
                        $payload['sender'],
                        $payload['workerName'],
                        $payload['workerRole'],
                        $payload['workerName'],
                        $payload['isSubTalk'],
                        $payload['sessionId'],
                        $payload['messageId']
                    )
                );

                unset($new_messages);
                break;

            case 'length':
                $tool_count    = $this->core->context->countHistory($payload['sessionId'], $payload['workerName'], 'tool');
                $history_count = $this->core->context->countHistory($payload['sessionId'], $payload['workerName']);

                $this->utils->debug('System: Context auto-truncated to resume (length limit reached).', 'trace');
                $this->core->context->cleanHistory($payload['sessionId'], $payload['workerName'], ceil($history_count * 0.6), ceil($tool_count * 0.4));

                $llm_params['max_tokens'] = 12288;

                $this->utils->setChildWorker($payload['sender'], $payload['workerName'], 'llm_params', $llm_params);

                if (WORKER_MAIN === $payload['sender']) {
                    if (!$this->utils->isPipeIdle()) {
                        $this->core->context->messagePending($payload['sessionId'], true);
                        break;
                    }
                } else {
                    $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'busy');
                }

                $this->openai->talkTo(
                    $payload['sender'],
                    $payload['workerName'],
                    $payload['sessionId'],
                    $worker_info['proc_idx'],
                    $this->getSystemPrompt($payload['sessionId']),
                    'talk',
                    $this->utils->getMarker(
                        $payload['sender'],
                        $payload['workerName'],
                        $payload['workerRole'],
                        $payload['workerName'],
                        $payload['isSubTalk'],
                        $payload['sessionId'],
                        $payload['messageId']
                    )
                );

                unset($tool_count, $history_count);
                break;

            case 'abort':
                $this->utils->debug($payload['workerName'] . ': stream->abort', 'trace');
                $this->utils->setPipeStatus(utils::STATUS_IDLE, $payload['sessionId']);
                $this->core->sendMessage($payload);
                break;

            case 'error':
                $this->utils->debug($payload['workerName'] . ': stream->error', 'trace');

                if (WORKER_MAIN === $payload['sender']) {
                    $this->utils->setPipeStatus(utils::STATUS_IDLE, $payload['sessionId']);
                } else {
                    $this->utils->setChildWorker(WORKER_CHILD, $payload['workerName'], 'status', 'ready');
                }

                if (is_array($payload['data']) && isset($payload['data']['message'])) {
                    $this->core->sendMessage($payload);
                }
                break;
        }

        unset($payload, $llm_params, $worker_info);
    }

    /**
     * @param array $payload
     * @param array $llm_params
     *
     * @return void
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    private function setMaxTokens(array $payload, array $llm_params): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $remain_tokens = $this->core->getMaxTokens($payload['sender'], $payload['sessionId'], $payload['workerName'], $llm_params);
            $this->utils->debug($payload['sender'] . ': #' . $payload['sessionId'] . ' has ' . $remain_tokens . ' tokens left.', 'trace');

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

        $llm_params['max_tokens'] = max(128, $remain_tokens);
        $this->utils->setChildWorker($payload['sender'], $payload['workerName'], 'llm_params', $llm_params);

        unset($payload, $llm_params, $i, $remain_tokens, $content, $main_notice, $child_notice, $history_count, $keep_count, $now_count);
    }
}