<?php

/**
 * Agent OpenAI module for AgentBee
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

namespace modules\agent_openai\app;

use modules\agent_core\core;
use Nervsys\Core\Factory;
use Nervsys\Ext\libOpenAI;

class procWorker extends Factory
{
    public core $core;

    private string $message_type = 'content';

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core = core::new();
        $this->core->initCore();
    }

    /**
     * Send a chat task to the worker process (via pipe).
     *
     * @param string    $socket_id
     * @param array     $message_metadata
     * @param array     $session_history
     * @param libOpenAI $libOpenAI
     *
     * @return void
     * @throws \Exception
     */
    public function chat(string $socket_id, array $message_metadata, array $session_history, libOpenAI $libOpenAI): void
    {
        $task = [
            'socket_id' => $socket_id,
            'msg_meta'  => $message_metadata,
            'history'   => $session_history,
        ];

        $this->core->procMgr->writeProc(core::PROC_IDX_OPENAI, json_encode($task));

        unset($socket_id, $message_metadata, $session_history, $libOpenAI, $task);
    }

    /**
     * Interrupt current task (send STOP to stdin).
     *
     * @param string $socket_id
     *
     * @return void
     * @throws \Exception
     */
    public function interrupt(string $socket_id): void
    {
        $this->core->procMgr->writeProc(core::PROC_IDX_OPENAI, '__STOP__');
        unset($socket_id);
    }

    /**
     * Execute a single LLM request (called by worker).
     *
     * @param string    $socket_id
     * @param array     $message_metadata
     * @param array     $session_history
     * @param libOpenAI $libOpenAI
     *
     * @return void
     */
    public function talk(string $socket_id, array $message_metadata, array $session_history, libOpenAI $libOpenAI): void
    {
        $assistant_content = '';
        $reasons_content   = '';
        $stop_requested    = false;
        $check_counter     = 0;
        $tool_calls        = [];
        $run_tools         = false;

        // Sync session history from main process
        $this->core->session_history = $session_history;

        $check_stop = function () use (&$stop_requested, &$check_counter): void
        {
            if (++$check_counter % 5 !== 0) {
                return;
            }

            $read_streams   = [STDIN];
            $write_streams  = null;
            $except_streams = null;

            if (1 === stream_select($read_streams, $write_streams, $except_streams, 0, 0)) {
                $line = fgets(STDIN);

                if (false !== $line && trim($line) === '__STOP__') {
                    $stop_requested = true;
                }
            }
        };

        $stream_callback = function (string $key, array $data, bool $finished) use ($socket_id, $message_metadata, &$reasons_content, &$assistant_content, &$tool_calls, &$run_tools, $libOpenAI, &$stop_requested, $check_stop): void
        {
            $check_stop();

            if ($stop_requested) {
                throw new \Exception('Interrupted by user');
            }

            try {
                if (!$finished) {
                    $this->sendStream($socket_id, $data, $message_metadata, $tool_calls, $assistant_content, $reasons_content);
                } else {
                    $assistant_message = [
                        'role'              => 'assistant',
                        'content'           => $assistant_content,
                        'reasoning_content' => $reasons_content
                    ];

                    if (!empty($tool_calls)) {
                        $run_tools = true;

                        $assistant_message['tool_calls'] = array_map(
                            fn($tool) => [
                                'id'       => $tool['id'],
                                'type'     => 'function',
                                'function' => [
                                    'name'      => $tool['function']['name'],
                                    'arguments' => $tool['function']['arguments']
                                ]
                            ], $tool_calls
                        );
                    }

                    $this->core->session_history[] = $assistant_message;
                    $this->sendMsg('history', $socket_id, $message_metadata, 'assistant', $assistant_message);

                    if (!empty($tool_calls)) {
                        $session_history   = $this->core->session_history;
                        $execution_results = $this->core->execTools($tool_calls);
                        $current_history   = $this->core->session_history;

                        if (count($current_history) < count($session_history)) {
                            $this->sendMsg('sync', $socket_id, $message_metadata, 'assistant', $current_history);
                        }

                        foreach ($execution_results as $result) {
                            $this->sendMsg('message', $socket_id, $message_metadata, 'tool_result', $result);

                            $tool_history = [
                                'role'         => 'tool',
                                'tool_call_id' => $result['tool_call_id'],
                                'content'      => $result['content']
                            ];

                            $this->core->session_history[] = $tool_history;
                            $this->sendMsg('history', $socket_id, $message_metadata, 'tool', $tool_history);
                        }
                    }
                }
            } catch (\Throwable $exception) {
                $this->sendMsg('message', $socket_id, $message_metadata, 'error', ['message' => $exception->getMessage()]);
                $this->sendMsg('action', $socket_id, $message_metadata, 'end', null);
                unset($exception);
            }
        };

        try {
            $libOpenAI->completions($session_history, $this->core->agent_config['agent_llm']['model'], [], $stream_callback);
        } catch (\Throwable $exception) {
            if ($exception->getMessage() !== 'Interrupted by user') {
                $this->sendMsg('message', $socket_id, $message_metadata, 'error', ['message' => $exception->getMessage()]);
            }
        }

        $this->sendMsg('action', $socket_id, $message_metadata, 'complete', ['tool_calls' => $run_tools]);
        $this->sendMsg('message', $socket_id, $message_metadata, 'end', null);

        unset($socket_id, $message_metadata, $session_history, $libOpenAI, $reasons_content, $assistant_content, $tool_calls, $run_tools, $stop_requested, $check_counter);
    }

    /**
     * Process stream delta and send to main process.
     *
     * @param string $socket_id
     * @param array  $data
     * @param array  $message_metadata
     * @param array  $tool_calls_buffer
     * @param string $assistant_content
     * @param string $reasons_content
     *
     * @return void
     */
    private function sendStream(string $socket_id, array $data, array $message_metadata, array &$tool_calls_buffer, string &$assistant_content, string &$reasons_content): void
    {
        $delta = $data['choices'][0]['delta'] ?? [];

        // Normal content
        if (isset($delta['content']) && '' !== $delta['content']) {
            if ('<|channel>' === $delta['content']) {
                $this->message_type = 'think';
            } elseif ('<channel|>' === $delta['content']) {
                $this->message_type = 'content';
            } else {
                if ('content' === $this->message_type) {
                    $assistant_content .= $delta['content'];
                } elseif ('think' === $this->message_type) {
                    $reasons_content .= $delta['content'];
                }
                $this->sendMsg('message', $socket_id, $message_metadata, $this->message_type, $delta['content']);
            }
        }

        // Reasoning content
        if (isset($delta['reasoning_content']) && '' !== $delta['reasoning_content']) {
            if ($this->core->agent_config['agent_llm']['keep_reasons']) {
                $reasons_content .= $delta['reasoning_content'];
            }
            $this->sendMsg('message', $socket_id, $message_metadata, 'think', $delta['reasoning_content']);
        }

        // Tool calls
        if (isset($delta['tool_calls']) && !empty($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $tool_call_chunk) {
                $tool_index = $tool_call_chunk['index'];

                if (!isset($tool_calls_buffer[$tool_index])) {
                    $tool_calls_buffer[$tool_index] = [
                        'id'       => '',
                        'type'     => '',
                        'function' => ['name' => '', 'arguments' => '']
                    ];
                }

                if (isset($tool_call_chunk['id']) && '' !== $tool_call_chunk['id']) {
                    $tool_calls_buffer[$tool_index]['id'] = $tool_call_chunk['id'];
                }

                if (isset($tool_call_chunk['type']) && '' !== $tool_call_chunk['type']) {
                    $tool_calls_buffer[$tool_index]['type'] = $tool_call_chunk['type'];
                }

                if (isset($tool_call_chunk['function']['name']) && '' !== $tool_call_chunk['function']['name']) {
                    $tool_calls_buffer[$tool_index]['function']['name'] = $tool_call_chunk['function']['name'];
                }

                if (isset($tool_call_chunk['function']['arguments']) && '' !== $tool_call_chunk['function']['arguments']) {
                    $tool_calls_buffer[$tool_index]['function']['arguments'] .= $tool_call_chunk['function']['arguments'];
                }
            }

            $this->sendMsg('message', $socket_id, $message_metadata, 'tool_calls', $tool_calls_buffer);
        }

        unset($socket_id, $data, $message_metadata, $delta, $tool_call_chunk, $tool_index);
    }

    /**
     * Send a message to main process (via stdout).
     *
     * @param string $action
     * @param string $socket_id
     * @param array  $metadata
     * @param string $payload_type
     * @param mixed  $payload_data
     *
     * @return void
     */
    private function sendMsg(string $action, string $socket_id, array $metadata, string $payload_type, mixed $payload_data): void
    {
        $payload = ['type' => $payload_type];

        if (null !== $payload_data) {
            $payload['data'] = $payload_data;
        }

        $stream_data = json_encode([
            'action'    => $action,
            'socket_id' => $socket_id,
            'payload'   => $payload + $metadata,
        ], JSON_FORMAT);

        echo $stream_data . "\n";

        flush();
        fflush(STDOUT);

        unset($action, $socket_id, $metadata, $payload_type, $payload_data, $payload, $stream_data);
    }
}