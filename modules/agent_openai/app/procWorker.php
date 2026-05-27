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
        $tool_calls        = [];
        $run_tools         = false;

        // Sync session history from main process
        $this->core->session_history = $session_history;

        $stream_callback = function (
            string $key,
            array  $data,
            bool   $finished
        ) use (
            $libOpenAI,
            $socket_id,
            $message_metadata,
            &$reasons_content,
            &$assistant_content,
            &$tool_calls,
            &$run_tools,
        ): void
        {
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
                    $this->sendMsg($socket_id, 'history', 'add', $message_metadata, $assistant_message);

                    if (!empty($tool_calls)) {
                        $session_history   = $this->core->session_history;
                        $execution_results = $this->core->execTools($tool_calls);
                        $current_history   = $this->core->session_history;

                        if (count($current_history) < count($session_history)) {
                            $this->sendMsg($socket_id, 'history', 'sync', $message_metadata, $current_history);
                        }

                        foreach ($execution_results as $result) {
                            $this->sendMsg($socket_id, 'stream', 'tool_result', $message_metadata, $result);

                            $tool_history = [
                                'role'         => 'tool',
                                'tool_call_id' => $result['tool_call_id'],
                                'content'      => $result['content']
                            ];

                            $this->core->session_history[] = $tool_history;
                            $this->sendMsg($socket_id, 'history', 'add', $message_metadata, $tool_history);
                        }
                    }
                }
            } catch (\Throwable $exception) {
                $this->sendMsg($socket_id, 'stream', 'error', $message_metadata, ['message' => $exception->getMessage()]);
                $this->sendMsg($socket_id, 'stream', 'end', $message_metadata);
                unset($exception);
            }
        };

        try {
            $libOpenAI->completions($session_history, $this->core->agent_config['agent_llm']['model'], [], $stream_callback);
            $this->sendMsg($socket_id, 'end', $run_tools ? 'tools' : 'end', $message_metadata, ['tool_calls' => $run_tools]);
        } catch (\Throwable $exception) {
            if ($exception->getMessage() !== 'Interrupted by user') {
                $this->sendMsg($socket_id, 'stream', 'error', $message_metadata, ['message' => $exception->getMessage()]);
            }
        }

        $this->sendMsg($socket_id, 'stream', 'end', $message_metadata);

        unset($socket_id, $message_metadata, $session_history, $libOpenAI, $reasons_content, $assistant_content, $tool_calls, $run_tools);
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
                $this->sendMsg($socket_id, 'stream', $this->message_type, $message_metadata, $delta['content']);
            }
        }

        // Reasoning content
        if (isset($delta['reasoning_content']) && '' !== $delta['reasoning_content']) {
            if ($this->core->agent_config['agent_llm']['keep_reasons']) {
                $reasons_content .= $delta['reasoning_content'];
            }
            $this->sendMsg($socket_id, 'stream', 'think', $message_metadata, $delta['reasoning_content']);
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

            $this->sendMsg($socket_id, 'stream', 'tool_calls', $message_metadata, $tool_calls_buffer);
        }

        unset($socket_id, $data, $message_metadata, $delta, $tool_call_chunk, $tool_index);
    }

    /**
     * Send a message to main process (via stdout).
     *
     * @param string $message_type
     * @param string $socket_id
     * @param array  $payload_meta
     * @param string $payload_type
     * @param mixed  $payload_data
     *
     * @return void
     */
    private function sendMsg(string $socket_id, string $message_type, string $payload_type, array $payload_meta, string|array $payload_data = ''): void
    {
        $payload = ['type' => $payload_type];

        if (!empty($payload_data)) {
            $payload['data'] = $payload_data;
        }

        $stream_data = json_encode([
            'type'      => $message_type,
            'payload'   => $payload + $payload_meta,
            'socket_id' => $socket_id,
        ], JSON_FORMAT
        );

        echo $stream_data . "\n";

        flush();
        fflush(STDOUT);

        unset($socket_id, $message_type, $payload_type, $payload_meta, $payload_data, $payload, $stream_data);
    }
}