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
    public FiberMgr $fiberMgr;

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

        $this->fiberMgr = FiberMgr::new();
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
        $stack_id = 'chat_' . $session_id;

        // 创建堆栈
        $this->fiberMgr->createStack($stack_id);

        // 预先构建所有对话轮次
        $current_message = $llm_message;
        $need_continue = true;
        $round_count = 0;
        $max_rounds = 10;

        while (true === $need_continue && $round_count < $max_rounds) {
            // 捕获当前状态供闭包使用
            $captured_message = $current_message;

            $this->fiberMgr->addTask($stack_id, function() use ($socket_id, $captured_message, $user_message, $llm_params, &$need_continue, &$current_message) {
                $result = $this->runOneRound($socket_id, $captured_message, $user_message, $llm_params);

                $need_continue = $result['need_continue'];
                $current_message = $result['llm_message'];

                return $result;
            });

            // 更新状态继续判断
            if (false === $need_continue) {
                break;
            }

            $round_count++;
        }

        // 执行堆栈
        $this->fiberMgr->runStack($stack_id);

        // 清理堆栈
        $this->fiberMgr->clearStack($stack_id);

        // 发送结束标记
        $this->sendMessage($socket_id, json_encode(['type' => 'end'] + $user_message, JSON_FORMAT));
    }

    /**
     * 执行一轮对话（流式）
     *
     * @param int $socket_id
     * @param array $llm_message
     * @param array $user_message
     * @param array $llm_params
     *
     * @return array
     * @throws \ReflectionException
     * @throws \Throwable
     */
    private function runOneRound(int $socket_id, array $llm_message, array $user_message, array $llm_params): array
    {
        $content    = '';
        $tool_calls = [];
        $stream_key = 'stream_' . uniqid('', true);

        $has_tool_calls = false;
        $new_llm_message = $llm_message;
        $stream_completed = false;

        $this->libOpenAI->addStreamCallback(
            $stream_key,
            function (int|string $key, array $data, bool $finished) use (
                $socket_id, &$new_llm_message, $user_message, $llm_params,
                &$content, &$tool_calls, &$has_tool_calls, &$stream_completed
            ): void {
                if (true === $finished) {
                    $stream_completed = true;

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
                            $tool_memories[] = $tool_msg;
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

                if (true === isset($delta['content']) && '' !== $delta['content']) {
                    $text = $delta['content'];
                    $content .= $text;

                    $this->sendMessage($socket_id, json_encode([
                            'type' => 'content',
                            'data' => $text
                        ] + $user_message, JSON_FORMAT));
                } elseif (true === isset($delta['reasoning_content']) && '' !== $delta['reasoning_content']) {
                    $text = $delta['reasoning_content'];

                    $this->sendMessage($socket_id, json_encode([
                            'type' => 'think',
                            'data' => $text
                        ] + $user_message, JSON_FORMAT));
                } elseif (true === isset($delta['tool_calls']) && false === empty($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $chunk) {
                        $index = $chunk['index'];

                        if (false === isset($tool_calls[$index])) {
                            $tool_calls[$index] = [
                                'id'       => '',
                                'type'     => '',
                                'function' => ['name' => '', 'arguments' => '']
                            ];
                        }

                        if (true === isset($chunk['id']) && '' !== $chunk['id']) {
                            $tool_calls[$index]['id'] = $chunk['id'];
                        }

                        if (true === isset($chunk['type']) && '' !== $chunk['type']) {
                            $tool_calls[$index]['type'] = $chunk['type'];
                        }

                        if (true === isset($chunk['function']['name']) && '' !== $chunk['function']['name']) {
                            $tool_calls[$index]['function']['name'] = $chunk['function']['name'];
                        }

                        if (true === isset($chunk['function']['arguments']) && '' !== $chunk['function']['arguments']) {
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

        // 等待流式完成
        $timeout = 300;

        while (false === $stream_completed && 0 < $timeout) {
            usleep(100000);
            $timeout--;
        }

        return [
            'need_continue' => $has_tool_calls,
            'llm_message'   => $new_llm_message
        ];
    }
}