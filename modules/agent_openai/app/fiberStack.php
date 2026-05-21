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
use Nervsys\Core\Mgr\FiberMgr;
use Nervsys\Ext\libOpenAI;

class fiberStack extends Factory
{
    public core     $core;
    public FiberMgr $fiberMgr;

    private string $message_type = 'content';

    private array $interrupt_flags = [];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core     = core::new();
        $this->fiberMgr = FiberMgr::new();
    }

    /**
     * Start multi-turn conversation (non-worker mode).
     *
     * @param string    $socket_id
     * @param array     $message_metadata
     * @param array     $session_history
     * @param libOpenAI $libOpenAI
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function chat(string $socket_id, array $message_metadata, array $session_history, libOpenAI $libOpenAI): void
    {
        $continue = true;
        $stack_id = 'STACK_' . uniqid('', true);

        $this->fiberMgr->createStack($stack_id);

        while ($continue) {
            $current_history = $this->core->getSessionHistory();

            $this->fiberMgr->addTask(
                $stack_id,
                function () use ($socket_id, $current_history, $message_metadata, $libOpenAI, &$continue): void
                {
                    $continue = $this->talk($socket_id, $message_metadata, $current_history, $libOpenAI);
                }
            );

            $this->fiberMgr->runStack($stack_id);
        }

        $this->fiberMgr->clearStack($stack_id);
        $this->sendMsg($socket_id, $message_metadata, 'end', null);

        unset($socket_id, $message_metadata, $session_history, $libOpenAI, $continue, $stack_id);
    }

    /**
     * Interrupt current request for given socket.
     *
     * @param string $socket_id
     *
     * @return void
     */
    public function interrupt(string $socket_id): void
    {
        $this->interrupt_flags[$socket_id] = true;
        unset($socket_id);
    }

    /**
     * Execute a single turn (streaming + tools).
     *
     * @param string    $socket_id
     * @param array     $session_history
     * @param array     $message_metadata
     * @param libOpenAI $libOpenAI
     *
     * @return bool
     * @throws \ReflectionException
     */
    private function talk(string $socket_id, array $message_metadata, array $session_history, libOpenAI $libOpenAI): bool
    {
        $assistant_content = '';
        $reasons_content   = '';
        $tool_calls        = [];
        $run_tools         = false;

        $this->interrupt_flags[$socket_id] = false;

        $stream_callback = function (string $key, array $data, bool $finished) use ($socket_id, $message_metadata, &$reasons_content, &$assistant_content, &$tool_calls, &$run_tools, $libOpenAI): void
        {
            try {
                if (isset($this->interrupt_flags[$socket_id]) && $this->interrupt_flags[$socket_id]) {
                    throw new \Exception('Interrupted by user');
                }

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

                    $this->core->addSessionHistory($assistant_message);

                    if (!empty($tool_calls)) {
                        $execution_results = $this->core->execTools($tool_calls);

                        foreach ($execution_results as $result) {
                            $this->sendMsg($socket_id, $message_metadata, 'tool_result', $result);

                            $tool_history = [
                                'role'         => 'tool',
                                'tool_call_id' => $result['tool_call_id'],
                                'content'      => $result['content']
                            ];

                            $this->core->addSessionHistory($tool_history);
                        }
                    }
                }
            } catch (\Throwable $exception) {
                $this->sendMsg($socket_id, $message_metadata, 'error', ['message' => $exception->getMessage()]);
                $this->sendMsg($socket_id, $message_metadata, 'end', null);
                unset($exception);
            }
        };

        try {
            $libOpenAI->completions($session_history, $this->core->agent_config['agent_llm']['model'], [], $stream_callback);
        } catch (\Throwable $exception) {
            if ($exception->getMessage() !== 'Interrupted by user') {
                $this->sendMsg($socket_id, $message_metadata, 'error', ['message' => $exception->getMessage()]);
            }
        }

        unset($reasons_content, $assistant_content, $tool_calls, $this->interrupt_flags[$socket_id]);
        return $run_tools;
    }

    /**
     * Process stream delta and send messages.
     *
     * @param string $socket_id
     * @param array  $data
     * @param array  $message_metadata
     * @param array  $tool_calls_buffer
     * @param string $assistant_content
     * @param string $reasons_content
     *
     * @return void
     * @throws \ReflectionException
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

                $this->sendMsg($socket_id, $message_metadata, $this->message_type, $delta['content']);
            }
        }

        // Reasoning content
        if (isset($delta['reasoning_content']) && '' !== $delta['reasoning_content']) {
            if ($this->core->agent_config['agent_llm']['keep_reasons']) {
                $reasons_content .= $delta['reasoning_content'];
            }

            $this->sendMsg($socket_id, $message_metadata, 'think', $delta['reasoning_content']);
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

            $this->sendMsg($socket_id, $message_metadata, 'tool_calls', $tool_calls_buffer);
        }

        unset($socket_id, $data, $message_metadata, $delta, $tool_call_chunk, $tool_index);
    }

    /**
     * Send WebSocket message to client.
     *
     * @param string $socket_id
     * @param array  $metadata
     * @param string $payload_type
     * @param mixed  $payload_data
     *
     * @return void
     * @throws \ReflectionException
     */
    private function sendMsg(string $socket_id, array $metadata, string $payload_type, mixed $payload_data): void
    {
        $payload = ['type' => $payload_type];

        if (null !== $payload_data) {
            $payload['data'] = $payload_data;
        }

        $this->core->sendMessage($socket_id, json_encode($payload + $metadata, JSON_FORMAT));

        unset($socket_id, $metadata, $payload_type, $payload_data, $payload);
    }
}