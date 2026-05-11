<?php

/**
 * llm_openai module for AgentBee
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

namespace modules\llm_openai;

use modules\agent_core\core;
use Nervsys\Core\Factory;
use Nervsys\Ext\libOpenAI;

class go extends Factory
{
    use core;

    public libOpenAI $libOpenAI;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->initCore();
        $this->initTools();

        $this->libOpenAI = libOpenAI::new(
            $this->agent_config['llm']['api_url'],
            $this->agent_config['llm']['api_key'],
            '[DONE]'
        );

        $this->libOpenAI->setOrgId($this->agent_config['llm']['org_id']);
        $this->libOpenAI->setApiModel($this->agent_config['llm']['model']);
        $this->libOpenAI->setModelParams($this->agent_config['llm']['params']);
    }

    /**
     * @param int   $socket_id
     * @param array $llm_message
     * @param array $user_message
     * @param array $llm_params
     *
     * @return void
     * @throws \ReflectionException
     */
    public function chat(int $socket_id, array $llm_message, array $user_message, array $llm_params = []): void
    {
        $content    = '';
        $tool_calls = [];
        $stream_key = 'stream_' . uniqid('', true);

        $this->libOpenAI->addStreamCallback(
            $stream_key,
            function (int|string $key, array $data, bool $finished) use ($socket_id, &$llm_message, $user_message, $llm_params, &$content, &$tool_calls): void
            {
                if ($finished) {
                    if (!empty($tool_calls)) {
                        $this->addMemory('assistant', json_encode($tool_calls, JSON_FORMAT));

                        $tool_memories = [];
                        $tool_results  = $this->execTools($tool_calls);

                        foreach ($tool_results as $tool_result) {
                            $this->sendMessage(
                                $socket_id,
                                json_encode([
                                        'type' => 'tool_result',
                                        'data' => $tool_result
                                    ] + $user_message,
                                    JSON_FORMAT
                                )
                            );

                            $llm_result = [
                                'role'         => 'tool',
                                'tool_call_id' => $tool_result['tool_call_id'],
                                'content'      => $tool_result['result']
                            ];

                            $llm_message[]   = $llm_result;
                            $tool_memories[] = $llm_result;
                        }

                        $this->addMemory('tool', json_encode($tool_memories, JSON_FORMAT));

                        $this->libOpenAI->chat($llm_message, $this->agent_config['llm']['model'], $llm_params, true);
                    } else {
                        $this->addMemory('assistant', $content);
                        $this->sendMessage($socket_id, json_encode(['type' => 'end'] + $user_message, JSON_FORMAT));
                    }

                    return;
                }

                if (isset($data['choices'][0]['delta']['content'])) {
                    $text    = $data['choices'][0]['delta']['content'];
                    $content .= $text;

                    $this->sendMessage(
                        $socket_id,
                        json_encode(
                            [
                                'type' => 'content',
                                'data' => $text
                            ] + $user_message,
                            JSON_FORMAT
                        )
                    );
                } elseif (isset($data['choices'][0]['delta']['reasoning_content'])) {
                    $text = $data['choices'][0]['delta']['reasoning_content'];

                    $this->sendMessage(
                        $socket_id,
                        json_encode(
                            [
                                'type' => 'think',
                                'data' => $text
                            ] + $user_message,
                            JSON_FORMAT
                        )
                    );
                } else {
                    $delta = $data['choices'][0]['delta'] ?? [];

                    if (isset($delta['tool_calls'])) {
                        foreach ($delta['tool_calls'] as $chunk) {
                            $index = $chunk['index'];

                            if (!isset($tool_calls[$index])) {
                                $tool_calls[$index] = [
                                    'id'       => '',
                                    'type'     => '',
                                    'function' => ['name' => '', 'arguments' => '']
                                ];
                            }

                            if (isset($chunk['id'])) {
                                $tool_calls[$index]['id'] = $chunk['id'];
                            }

                            if (isset($chunk['type'])) {
                                $tool_calls[$index]['type'] = $chunk['type'];
                            }

                            if (isset($chunk['function']['name'])) {
                                $tool_calls[$index]['function']['name'] = $chunk['function']['name'];
                            }

                            if (isset($chunk['function']['arguments'])) {
                                $tool_calls[$index]['function']['arguments'] .= $chunk['function']['arguments'];
                            }
                        }

                        $this->sendMessage(
                            $socket_id,
                            json_encode([
                                    'type' => 'tool_calls',
                                    'data' => $tool_calls
                                ] + $user_message,
                                JSON_FORMAT
                            )
                        );
                    }
                }
            }
        );

        $this->libOpenAI->chat($llm_message, $this->agent_config['llm']['model'], $llm_params, true);
        $this->libOpenAI->removeStreamCallback($stream_key);
    }

    /**
     * @param int    $socket_id
     * @param string $output
     *
     * @return void
     * @throws \ReflectionException
     */
    public function onWorkerOutput(int $socket_id, string $output): void
    {
        $data = json_decode($output, true);

        if (is_null($data)) {
            return;
        }

        if ($data['type'] === 'end') {
            if (isset($data['data'])) {
                $this->addMemory('assistant', $data['data']);
            }

            $this->sendMessage($socket_id, json_encode(['type' => 'end'], JSON_FORMAT));
        } else {
            $this->sendMessage($socket_id, json_encode([
                'type' => $data['type'],
                'data' => $data['data']
            ], JSON_FORMAT));
        }
    }
}