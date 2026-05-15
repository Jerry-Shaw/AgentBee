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
    public core $core;

    public libOpenAI $libOpenAI;
    public FiberMgr  $fiberMgr;

    public int $call_round = 0;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core = core::new();

        $this->core->initCore();
        $this->core->initTools();

        $this->fiberMgr = FiberMgr::new();

        $this->libOpenAI = libOpenAI::new(
            $this->core->agent_config['agent_llm']['api_url'],
            $this->core->agent_config['agent_llm']['api_key'],
            '[DONE]'
        );

        $this->libOpenAI->setOrgId($this->core->agent_config['agent_llm']['org_id']);
        $this->libOpenAI->setApiModel($this->core->agent_config['agent_llm']['model']);
        $this->libOpenAI->setModelParams($this->core->agent_config['agent_llm']['params']);
    }

    /**
     * Start multi-turn conversation
     *
     * @param int   $socket_id
     * @param array $user_msg User message (contains sessionId)
     * @param array $llm_params
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function chat(int $socket_id, array $user_msg, array $llm_params): void
    {
        $go_next  = true;
        $stack_id = 'chat_' . ($user_msg['sessionId'] ?? 'default');

        $this->call_round = 0;

        $this->fiberMgr->createStack($stack_id);

        while ($go_next) {
            $history = $this->core->getSessionHistory();

            $this->fiberMgr->addTask(
                $stack_id,
                function () use ($socket_id, $history, $user_msg, $llm_params, &$go_next): array
                {
                    $talk    = $this->talk($socket_id, $history, $user_msg, $llm_params);
                    $go_next = $talk['next'];

                    return ['next' => $go_next];
                }
            );

            $this->fiberMgr->runStack($stack_id);
        }

        $this->fiberMgr->clearStack($stack_id);
        $this->sendMsg($socket_id, $user_msg, 'end', null);

        unset($socket_id, $user_msg, $llm_params, $go_next, $stack_id, $history);
    }

    /**
     * Execute single conversation turn (streaming + tool calls)
     *
     * @param int   $socket_id
     * @param array $history  Current conversation history (snapshot)
     * @param array $user_msg User message
     * @param array $llm_params
     *
     * @return array ['next' => bool]
     * @throws \ReflectionException
     */
    private function talk(int $socket_id, array $history, array $user_msg, array $llm_params): array
    {
        $content = '';
        $tools   = [];
        $key     = 'stream_' . uniqid('', true);

        $tool_calls = false;

        $this->libOpenAI->addStreamCallback(
            $key,
            function (string $msg_key, array $msg_data, bool $is_finished) use ($socket_id, $user_msg, &$content, &$tools, &$tool_calls): void
            {
                try {
                    if (!$is_finished) {
                        $this->sendStream($socket_id, $msg_data, $user_msg, $tools, $content);
                    } else {
                        if (!empty($tools)) {
                            $tool_calls   = true;
                            $tool_command = [];

                            foreach ($tools as $tool) {
                                $tool_command[] = [
                                    'id'       => $tool['id'],
                                    'type'     => 'function',
                                    'function' => [
                                        'name'      => $tool['function']['name'],
                                        'arguments' => $tool['function']['arguments']
                                    ]
                                ];
                            }

                            $this->core->addSessionHistory([
                                'role'       => 'assistant',
                                'content'    => trim($content),
                                'tool_calls' => $tool_command
                            ]);

                            $results = $this->core->execTools($tools);

                            foreach ($results as $result) {
                                $this->sendMsg($socket_id, $user_msg, 'tool_result', $result);
                                $this->core->addSessionHistory([
                                    'role'         => 'tool',
                                    'tool_call_id' => $result['tool_call_id'],
                                    'content'      => $result['result']
                                ]);
                            }

                            ++$this->call_round;

                            if (0 === ($this->call_round % 5)) {
                                $this->core->addSessionHistory([
                                    'role'    => 'user',
                                    'content' => '请根据以上工具执行结果，总结当前已完成步骤，然后继续执行后续操作。如需调用更多工具，请继续调用；若任务已完成，请直接给出最终答案。此外，建议将关键结果记录到 Daily 记忆或保存到临时文件中，以便后续内容截断后仍能追溯。'
                                ]);
                            }
                        } elseif ('' !== $content) {
                            $this->core->addSessionHistory(['role' => 'assistant', 'content' => $content]);
                        }
                    }
                } catch (\Throwable $throwable) {
                    $this->sendMsg($socket_id, $user_msg, 'error', ['message' => 'Internal error processing stream: ' . $throwable->getMessage()]);
                    $this->sendMsg($socket_id, $user_msg, 'end', null);
                    unset($throwable);
                }

                unset($msg_key, $msg_data, $is_finished, $tool_command, $tool, $results, $result);
            }
        );

        $this->libOpenAI->chat($history, $this->core->agent_config['agent_llm']['model'], $llm_params, true);
        $this->libOpenAI->removeStreamCallback($key);

        unset($socket_id, $history, $user_msg, $llm_params, $key);
        return ['next' => $tool_calls];
    }

    /**
     * Stream send formatted data
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

        unset($socket_id, $msg_data, $user_msg, $delta);
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

        $this->core->sendMessage($socket_id, json_encode($payload + $user_msg, JSON_FORMAT));

        unset($socket_id, $user_msg, $type, $data, $payload);
    }
}