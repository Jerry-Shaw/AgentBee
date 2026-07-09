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

namespace modules\agent_openai\lib;

use modules\agent_core\core;
use Nervsys\Core\Factory;
use Nervsys\Core\Lib\Error;
use Nervsys\Ext\libOpenAI;

class procWorker extends Factory
{
    public core $core;

    public int    $tool_error_count = 0;
    public string $tool_error_last  = '';

    private string $message_type = 'content';

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core = core::new();
    }

    /**
     * Execute a single LLM request (called by worker).
     *
     * @param array     $metadata
     * @param array     $history
     * @param libOpenAI $libOpenAI
     *
     * @return void
     * @throws \ReflectionException
     */
    public function talk(array $metadata, array $history, libOpenAI $libOpenAI): void
    {
        $finish_reason     = null;
        $reasons_content   = '';
        $assistant_content = '';
        $tool_calls_buffer = [];

        $socket_id = $metadata['socket_id'];

        // Sync session history from main process
        $this->core->utils->setSessionHistory($metadata['workerName'], $history);

        $stream_callback = function (
            string $key,
            array  $data,
            bool   $finished
        ) use (
            $libOpenAI,
            $socket_id,
            $metadata,
            &$tool_calls_buffer,
            &$assistant_content,
            &$reasons_content,
            &$finish_reason
        ): void
        {
            try {
                if (!$finished) {
                    $this->sendStream(
                        $socket_id,
                        $data,
                        $metadata,
                        $tool_calls_buffer,
                        $assistant_content,
                        $reasons_content,
                        $finish_reason
                    );
                } else {
                    // OpenAI API error
                    if (isset($data['error'])) {
                        $finish_reason = 'error';
                        $this->sendMsg($socket_id, 'stream', 'error', $metadata, $data);
                        return;
                    }

                    // User abort
                    if (isset($data['status']) && 'aborted' === $data['status']) {
                        $finish_reason     = 'abort';
                        $tool_calls_buffer = [];
                        $assistant_message = [
                            'role'              => 'assistant',
                            'content'           => '[系统提醒] 用户中断，忽略未完成内容。',
                            'reasoning_content' => ''
                        ];

                        $this->sendMsg($socket_id, 'history', 'add', $metadata, $assistant_message);
                        return;
                    }

                    // Incorrect finish_reason
                    if (is_null($finish_reason)) {
                        $user_message = '[系统提示] 生成异常中断。';

                        if (!empty($tool_calls_buffer)) {
                            // Alert LLM: tool calls blocked due to missing finish_reason.
                            $user_message = '[系统提示] 生成异常中断，已自动拦截无效工具调用。';

                            $this->sendMsg(
                                $socket_id,
                                'history',
                                'add',
                                $metadata,
                                [
                                    'role'    => 'user',
                                    'content' => [[
                                        'type' => 'text',
                                        'text' => '[系统提示] 生成中断，未收到结束信号，工具调用已丢弃。请重新调用并确保参数完整。'
                                    ]]
                                ]
                            );
                        }

                        $this->sendMsg($socket_id, 'stream', 'error', $metadata, $user_message);

                        $finish_reason     = 'error';
                        $tool_calls_buffer = [];
                        return;
                    }

                    $assistant_message = [
                        'role'              => 'assistant',
                        'content'           => $assistant_content,
                        'reasoning_content' => $reasons_content
                    ];

                    if ('length' === $finish_reason) {
                        // Max token reached, content truncated, auto-continue
                        $user_message = [
                            'role'    => 'user',
                            'content' => [[
                                'type' => 'text',
                                'text' => '' !== $assistant_content
                                    ? '[续写] 紧接末尾，直接续写，不重复。'
                                    : '[续写] 直接输出最终内容。'
                            ]]
                        ];

                        $this->sendMsg($socket_id, 'history', 'add', $metadata, $assistant_message);
                        $this->sendMsg($socket_id, 'history', 'add', $metadata, $user_message);
                        $this->sendMsg($socket_id, 'stream', 'length', $metadata, '[系统提示] 内容过长被截断。');

                        $tool_calls_buffer = [];
                        return;
                    }

                    if (!empty($tool_calls_buffer)) {
                        $assistant_message['tool_calls'] = array_map(
                            fn($tool) => [
                                'id'       => $tool['id'],
                                'type'     => 'function',
                                'function' => [
                                    'name'      => $tool['function']['name'],
                                    'arguments' => $tool['function']['arguments']
                                ]
                            ], $tool_calls_buffer
                        );

                        $this->sendMsg($socket_id, 'stream', 'tool_calls', $metadata, $assistant_message['tool_calls']);
                    }

                    if ('' !== $assistant_content || '' !== $reasons_content || !empty($tool_calls_buffer)) {
                        $this->core->utils->addSessionHistory($metadata['workerName'], $assistant_message);
                        $this->sendMsg($socket_id, 'history', 'add', $metadata, $assistant_message);
                    }

                    if (!empty($tool_calls_buffer)) {
                        $tool_results = $this->core->execTools($tool_calls_buffer);

                        foreach ($tool_results as $result) {
                            $result_data = json_decode($result['content'], true);

                            if (!isset($result_data['handler'])) {
                                // Sync tools
                                $tool_history = [
                                    'role'         => 'tool',
                                    'tool_call_id' => $result['tool_call_id'],
                                    'content'      => $result['content']
                                ];

                                $this->core->utils->addSessionHistory($metadata['workerName'], $tool_history);

                                $this->sendMsg($socket_id, 'stream', 'tool_result', $metadata, $result);
                                $this->sendMsg($socket_id, 'history', 'add', $metadata, $tool_history);

                                // Handle tool call errors
                                if (isset($result_data['status']) && 'error' === $result_data['status']) {
                                    $error_message = $result['function_name'] . '->' . ($result_data['message'] ?? 'UncaughtErrors');

                                    if ($error_message === $this->tool_error_last) {
                                        if (2 <= ++$this->tool_error_count) {
                                            $this->sendMsg(
                                                $socket_id,
                                                'context',
                                                'ToolErrors',
                                                $metadata,
                                                ['function_name' => $result['function_name']]
                                            );
                                        }
                                    } else {
                                        $this->tool_error_count = 0;
                                        $this->tool_error_last  = $error_message;
                                    }

                                    unset($error_message);
                                }
                            } else {
                                // Async tools
                                $result_data['socket_id']     = $socket_id;
                                $result_data['process_name']  = $metadata['workerName'];
                                $result_data['tool_call_id']  = $result['tool_call_id'];
                                $result_data['function_name'] = $result['function_name'];

                                $this->sendMsg($socket_id, 'context', 'callHandler', $metadata, $result_data);
                            }
                        }
                    }

                    unset($assistant_message, $tool_results, $result, $result_data, $tool_history);
                }
            } catch (\Throwable $throwable) {
                $this->sendMsg($socket_id, 'stream', 'error', $metadata, $throwable->getMessage());
                Error::new()->exceptionHandler($throwable, false, false);
                $finish_reason = 'error';
                unset($throwable);
            }

            unset($key, $data, $finished);
        };

        try {
            $libOpenAI->completions($history, $this->core->utils->agent_config['agent_llm']['model'], [], $stream_callback);
        } catch (\Throwable $throwable) {
            $this->sendMsg($socket_id, 'stream', 'error', $metadata, $throwable->getMessage());
            Error::new()->exceptionHandler($throwable, false, false);
            unset($throwable);
        }

        if (!in_array($finish_reason, ['length', 'abort', 'error'], true)) {
            $this->sendMsg($socket_id, 'stream', 'end', $metadata);

            if (empty($tool_calls_buffer)) {
                $this->sendMsg($socket_id, 'end', 'end', $metadata, $assistant_content);
            } else {
                $this->sendMsg($socket_id, 'end', 'tools', $metadata, $tool_calls_buffer);
            }
        }

        unset($socket_id, $metadata, $history, $libOpenAI);
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
     * @param string $finish_reason
     *
     * @return void
     */
    private function sendStream(
        string $socket_id,
        array  $data,
        array  $message_metadata,
        array  &$tool_calls_buffer,
        string &$assistant_content,
        string &$reasons_content,
        string &$finish_reason
    ): void
    {
        // Update finish_reason
        if (isset($data['choices'][0]['finish_reason']) && is_string($data['choices'][0]['finish_reason'])) {
            $finish_reason = $data['choices'][0]['finish_reason'];
        }

        $delta = $data['choices'][0]['delta'] ?? [];

        // Normal content
        if (isset($delta['content']) && '' !== $delta['content']) {
            if ('<|channel>thought' === $delta['content']) {
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
            if ($this->core->utils->agent_config['agent_llm']['keep_reasons']) {
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
        $payload = [
            'type' => $payload_type,
            'data' => $payload_data
        ];

        $stream_data = json_encode(
            [
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