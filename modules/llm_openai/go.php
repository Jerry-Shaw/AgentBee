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
use Nervsys\Core\Mgr\FiberMgr;
use Nervsys\Ext\libOpenAI;

class go extends Factory
{
    use core;

    public libOpenAI $libOpenAI;
    public FiberMgr  $fiberMgr;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->initCore();
        $this->initTools();

        $this->fiberMgr = FiberMgr::new();

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
     * @throws \Throwable
     */
    public function chat(int $socket_id, array $llm_message, array $user_message, array $llm_params = []): void
    {
        $session_id = $user_message['sessionId'] ?? 'default';
        $stack_id   = 'chat_' . $session_id;
        $continue   = true;

        $this->fiberMgr->createStack($stack_id);

        while ($continue) {
            $chat_massage = $llm_message;

            $this->fiberMgr->addTask($stack_id, function () use ($socket_id, $chat_massage, $user_message, $llm_params, &$continue, &$llm_message): array
            {
                $result = $this->chatStream($socket_id, $chat_massage, $user_message, $llm_params);

                $continue    = $result['continue'];
                $llm_message = $result['llm_message'];

                return $result;
            });

            if (!$continue) {
                break;
            }
        }

        $this->fiberMgr->runStack($stack_id);
        $this->fiberMgr->clearStack($stack_id);

        $this->sendMessage($socket_id, json_encode(['type' => 'end'] + $user_message, JSON_FORMAT));
    }

    /**
     * @param int   $socket_id
     * @param array $llm_message
     * @param array $user_message
     * @param array $llm_params
     *
     * @return array
     * @throws \ReflectionException
     * @throws \Throwable
     */
    private function chatStream(int $socket_id, array $llm_message, array $user_message, array $llm_params): array
    {
        $content    = '';
        $tool_calls = [];
        $stream_key = 'stream_' . uniqid('', true);

        $has_tool_calls  = false;
        $new_llm_message = $llm_message;

        $this->libOpenAI->addStreamCallback(
            $stream_key,
            function (int|string $key, array $data, bool $finished) use ($socket_id, &$new_llm_message, $user_message, $llm_params, &$content, &$tool_calls, &$has_tool_calls): void
            {
                if (true === $finished) {
                    if (false === empty($tool_calls)) {
                        $has_tool_calls = true;
                        $this->addMemory('assistant', json_encode($tool_calls, JSON_FORMAT));

                        // 构建 assistant 消息
                        $assistant_msg = [
                            'role'       => 'assistant',
                            'content'    => '',
                            'tool_calls' => []
                        ];

                        foreach ($tool_calls as $tool_call) {
                            $assistant_msg['tool_calls'][] = [
                                'id'       => $tool_call['id'],
                                'type'     => 'function',
                                'function' => [
                                    'name'      => $tool_call['function']['name'],
                                    'arguments' => $tool_call['function']['arguments']
                                ]
                            ];
                        }

                        $new_llm_message[] = $assistant_msg;

                        // 执行工具
                        $tool_memories = [];
                        $tool_results  = $this->execTools($tool_calls);

                        foreach ($tool_results as $tool_result) {
                            $this->sendMessage($socket_id, json_encode([
                                    'type' => 'tool_result',
                                    'data' => $tool_result
                                ] + $user_message, JSON_FORMAT));

                            $tool_msg = [
                                'role'         => 'tool',
                                'tool_call_id' => $tool_result['tool_call_id'],
                                'content'      => $tool_result['result']
                            ];

                            $new_llm_message[] = $tool_msg;
                            $tool_memories[]   = $tool_msg;
                        }

                        $this->addMemory('tool', json_encode($tool_memories, JSON_FORMAT));
                    } else {
                        if ('' !== $content) {
                            $this->addMemory('assistant', $content);
                        }
                    }

                    return;
                }

                // 流式数据处理
                $delta = $data['choices'][0]['delta'] ?? [];

                if (isset($delta['content']) && '' !== $delta['content']) {
                    $text    = $delta['content'];
                    $content .= $text;

                    $this->sendMessage($socket_id, json_encode([
                            'type' => 'content',
                            'data' => $text
                        ] + $user_message, JSON_FORMAT));
                } elseif (isset($delta['reasoning_content']) && '' !== $delta['reasoning_content']) {
                    $text = $delta['reasoning_content'];

                    $this->sendMessage($socket_id, json_encode([
                            'type' => 'think',
                            'data' => $text
                        ] + $user_message, JSON_FORMAT));
                } elseif (isset($delta['tool_calls']) && !empty($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $chunk) {
                        $index = $chunk['index'];

                        if (!isset($tool_calls[$index])) {
                            $tool_calls[$index] = [
                                'id'       => '',
                                'type'     => '',
                                'function' => ['name' => '', 'arguments' => '']
                            ];
                        }

                        if (isset($chunk['id']) && '' !== $chunk['id']) {
                            $tool_calls[$index]['id'] = $chunk['id'];
                        }

                        if (isset($chunk['type']) && '' !== $chunk['type']) {
                            $tool_calls[$index]['type'] = $chunk['type'];
                        }

                        if (isset($chunk['function']['name']) && '' !== $chunk['function']['name']) {
                            $tool_calls[$index]['function']['name'] = $chunk['function']['name'];
                        }

                        if (isset($chunk['function']['arguments']) && '' !== $chunk['function']['arguments']) {
                            $tool_calls[$index]['function']['arguments'] .= $chunk['function']['arguments'];
                        }
                    }

                    $this->sendMessage($socket_id, json_encode([
                            'type' => 'tool_calls',
                            'data' => $tool_calls
                        ] + $user_message, JSON_FORMAT));
                }
            }
        );

        $this->libOpenAI->chat($llm_message, $this->agent_config['llm']['model'], $llm_params, true);
        $this->libOpenAI->removeStreamCallback($stream_key);

        return [
            'continue'    => $has_tool_calls,
            'llm_message' => $new_llm_message
        ];
    }
}