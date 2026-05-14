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
        $this->initModules();

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
     * Start multi-turn conversation
     *
     * @param int   $socket_id
     * @param array $history  Conversation history
     * @param array $user_msg User message (contains sessionId)
     * @param array $llm_params
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function chat(int $socket_id, array $history, array $user_msg, array $llm_params): void
    {
        $stack_id = 'chat_' . ($user_msg['sessionId'] ?? 'default');
        $go_next  = true;

        $this->fiberMgr->createStack($stack_id);

        while ($go_next) {
            $snapshot = $history;

            $this->fiberMgr->addTask(
                $stack_id,
                function () use ($socket_id, $snapshot, $user_msg, $llm_params, &$go_next, &$history): array
                {
                    $talk = $this->talk($socket_id, $snapshot, $user_msg, $llm_params);

                    $go_next = $talk['next'];
                    $history = $talk['history'];

                    $result = ['next' => $go_next, 'history' => $history];

                    unset($talk);
                    return $result;
                }
            );

            $this->fiberMgr->runStack($stack_id);
        }

        $this->fiberMgr->clearStack($stack_id);

        $this->sendMsg($socket_id, $user_msg, 'end', null);

        unset($socket_id, $history, $user_msg, $llm_params, $stack_id, $go_next, $snapshot);
    }

    /**
     * Execute single conversation turn (streaming + tool calls)
     *
     * @param int   $socket_id
     * @param array $history  Current conversation history
     * @param array $user_msg User message
     * @param array $llm_params
     *
     * @return array ['next' => bool, 'history' => array]
     * @throws \ReflectionException
     */
    private function talk(int $socket_id, array $history, array $user_msg, array $llm_params): array
    {
        $content = '';
        $tools   = [];
        $key     = 'stream_' . uniqid('', true);

        $tool_calls   = false;
        $full_history = $history;

        $this->libOpenAI->addStreamCallback(
            $key,
            function (string $msg_key, array $msg_data, bool $is_finished) use ($socket_id, $user_msg, &$content, &$tools, &$tool_calls, &$full_history): void
            {
                if (!$is_finished) {
                    $this->sendStream($socket_id, $msg_data, $user_msg, $tools, $content);
                } else {
                    if ('' !== $content) {
                        $this->memory->addSessionHistory(['role' => 'assistant', 'content' => $content]);
                    }

                    if (!empty($tools)) {
                        $tool_calls = true;

                        $this->memory->addSessionHistory(['role' => 'assistant', 'content' => json_encode($tools, JSON_FORMAT)]);

                        $tools_msg = [
                            'role'       => 'assistant',
                            'content'    => $content,
                            'tool_calls' => []
                        ];

                        foreach ($tools as $tool) {
                            $tools_msg['tool_calls'][] = [
                                'id'       => $tool['id'],
                                'type'     => 'function',
                                'function' => [
                                    'name'      => $tool['function']['name'],
                                    'arguments' => $tool['function']['arguments']
                                ]
                            ];
                        }

                        $full_history[] = $tools_msg;

                        $results = $this->execTools($tools);

                        foreach ($results as $result) {
                            $this->sendMsg($socket_id, $user_msg, 'tool_result', $result);

                            $full_history[] = [
                                'role'         => 'tool',
                                'tool_call_id' => $result['tool_call_id'],
                                'content'      => $result['result']
                            ];
                        }

                        $this->memory->addSessionHistory(['role' => 'tool', 'content' => json_encode($results, JSON_FORMAT)]);

                        unset($tools_msg, $tool, $results, $result);
                    }
                }

                unset($msg_key, $msg_data, $is_finished);
            }
        );

        $this->libOpenAI->chat($history, $this->agent_config['llm']['model'], $llm_params, true);
        $this->libOpenAI->removeStreamCallback($key);

        $result = [
            'next'    => $tool_calls,
            'history' => $full_history
        ];

        unset($socket_id, $history, $user_msg, $llm_params, $content, $tools, $key, $tool_calls, $full_history);
        return $result;
    }

    /**
     * Stream send formated data
     *
     * @param int    $socket_id
     * @param array  $msg_data
     * @param array  $user_msg
     * @param array  $tools
     * @param string $content
     *
     * @return void
     * @throws \ReflectionException
     */
    private function sendStream(int $socket_id, array $msg_data, array $user_msg, array &$tools, string &$content): void
    {
        $delta = $msg_data['choices'][0]['delta'] ?? [];

        // Normal content
        if (isset($delta['content']) && '' !== $delta['content']) {
            $content .= $delta['content'];
            $this->sendMsg($socket_id, $user_msg, 'content', $delta['content']);

            unset($delta);
            return;
        }

        // Reasoning content
        if (isset($delta['reasoning_content']) && '' !== $delta['reasoning_content']) {
            $this->sendMsg($socket_id, $user_msg, 'think', $delta['reasoning_content']);

            unset($delta);
            return;
        }

        // Tool calls
        if (isset($delta['tool_calls']) && !empty($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $chunk) {
                $idx = $chunk['index'];

                if (!isset($tools[$idx])) {
                    $tools[$idx] = [
                        'id'       => '',
                        'type'     => '',
                        'function' => ['name' => '', 'arguments' => '']
                    ];
                }

                if (isset($chunk['id']) && '' !== $chunk['id']) {
                    $tools[$idx]['id'] = $chunk['id'];
                }

                if (isset($chunk['type']) && '' !== $chunk['type']) {
                    $tools[$idx]['type'] = $chunk['type'];
                }

                if (isset($chunk['function']['name']) && '' !== $chunk['function']['name']) {
                    $tools[$idx]['function']['name'] = $chunk['function']['name'];
                }

                if (isset($chunk['function']['arguments']) && '' !== $chunk['function']['arguments']) {
                    $tools[$idx]['function']['arguments'] .= $chunk['function']['arguments'];
                }

                unset($chunk, $idx);
            }

            $this->sendMsg($socket_id, $user_msg, 'tool_calls', $tools);
        }

        unset($socket_id, $user_msg, $delta);
    }

    /**
     * Send websocket message
     *
     * @param int    $socket_id
     * @param array  $user_msg
     * @param string $type
     * @param mixed  $data
     *
     * @return void
     * @throws \ReflectionException
     */
    private function sendMsg(int $socket_id, array $user_msg, string $type, mixed $data): void
    {
        $payload = ['type' => $type];

        if (!is_null($data)) {
            $payload['data'] = $data;
        }

        $this->sendMessage($socket_id, json_encode($payload + $user_msg, JSON_FORMAT));

        unset($socket_id, $user_msg, $type, $data, $payload);
    }
}