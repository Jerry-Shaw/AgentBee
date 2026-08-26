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
use modules\agent_core\lib\context;
use Nervsys\Core\Factory;
use Nervsys\Core\Lib\Error;
use Nervsys\Ext\libOpenAI;

class processor extends Factory
{
    public core    $core;
    public context $context;

    public array $option_map = [
        'completions' => [
            'max_tokens'          => 'max_completion_tokens',
            'temperature'         => 'temperature',
            'min_p'               => 'min_p',
            'top_p'               => 'top_p',
            'top_k'               => 'top_k',
            'frequency_penalty'   => 'frequency_penalty',
            'presence_penalty'    => 'presence_penalty',
            'repetition_penalty'  => 'repetition_penalty',
            'enable_thinking'     => null,
            'stop'                => 'stop',
            'parallel_tool_calls' => 'parallel_tool_calls',
            'tool_choice'         => 'tool_choice',
        ],

        'responses' => [
            'max_tokens'          => 'max_output_tokens',
            'temperature'         => 'temperature',
            'min_p'               => null,
            'top_p'               => 'top_p',
            'top_k'               => null,
            'frequency_penalty'   => 'frequency_penalty',
            'presence_penalty'    => 'presence_penalty',
            'repetition_penalty'  => null,
            'enable_thinking'     => 'thinking',
            'stop'                => 'stop',
            'parallel_tool_calls' => 'parallel_tool_calls',
            'tool_choice'         => 'tool_choice',
        ],

        'messages' => [
            'max_tokens'          => 'max_tokens',
            'temperature'         => 'temperature',
            'min_p'               => null,
            'top_p'               => 'top_p',
            'top_k'               => 'top_k',
            'frequency_penalty'   => null,
            'presence_penalty'    => null,
            'repetition_penalty'  => null,
            'enable_thinking'     => 'thinking',
            'stop'                => 'stop_sequences',
            'parallel_tool_calls' => null,
            'tool_choice'         => 'tool_choice',
        ],
    ];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core    = core::new();
        $this->context = context::new();
    }

    /**
     * @param array  $params
     * @param string $endpoint
     *
     * @return array
     */
    public function getParams(array $params, string $endpoint): array
    {
        $result = [];
        $config = $this->option_map[$endpoint] ?? [];

        foreach ($params as $key => $value) {
            if (array_key_exists($key, $config)) {
                if (null !== $config[$key]) {
                    $result[$config[$key]] = $value;
                }
            } else {
                $result[$key] = $value;
            }
        }

        unset($params, $endpoint, $config, $key, $value);
        return $result;
    }

    /**
     * Execute a single LLM request (called by worker).
     *
     * @param array     $metadata
     * @param string    $system
     * @param array     $history
     * @param libOpenAI $libOpenAI
     *
     * @return void
     * @throws \ReflectionException
     */
    public function talk(array $metadata, string $system, array $history, libOpenAI $libOpenAI): void
    {
        $stream_handler = function (string $key, array $data, bool $finished) use ($metadata): void
        {
            $this->context->api_object->streamHandler($key, $data, $finished, $metadata);
            unset($key, $data, $finished);
        };

        try {
            $api_type = $this->core->utils->agent_config['agent_llm']['api_type'];
            $libOpenAI->setEndMarker('responses' === $api_type ? 'response.completed' : '[DONE]');
            $libOpenAI->setModelParams($this->getParams($libOpenAI->getModelParams(), $api_type));

            $libOpenAI->$api_type(
                $history,
                $system,
                $this->core->utils->agent_config['agent_llm']['model'],
                $this->context->api_object->options,
                $stream_handler
            );
        } catch (\Throwable $throwable) {
            stream::new()->output('stream', 'error', $metadata, ['error' => $throwable->getMessage()]);
            Error::new()->exceptionHandler($throwable, false, false);
            unset($throwable);
        }

        unset($metadata, $system, $history, $libOpenAI, $stream_handler, $api_type);
    }
}