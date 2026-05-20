<?php

/**
 * agent_openai module for AgentBee
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

namespace modules\agent_openai;

use modules\agent_core\core;
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\FiberMgr;
use Nervsys\Ext\libOpenAI;

class go extends Factory
{
    public core $core;

    public libOpenAI $libOpenAI;
    public FiberMgr  $fiberMgr;

    public string $message_type = 'content';

    public string $last_tool_calls  = '';
    public int    $last_error_count = 0;

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
        $this->libOpenAI->setTimeout($this->core->agent_config['agent_llm']['timeout']);
        $this->libOpenAI->setApiModel($this->core->agent_config['agent_llm']['model']);
        $this->libOpenAI->setModelParams($this->core->agent_config['agent_llm']['params']);
    }

    /**
     * Start multi-turn conversation
     *
     * @param int   $socket_id
     * @param array $msg_meta User message metadate (contains sessionId)
     * @param array $llm_params
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function chat(int $socket_id, array $msg_meta, array $llm_params): void
    {
        $go_next  = true;
        $stack_id = 'chat_' . ($msg_meta['sessionId'] ?? 'default');

        $this->fiberMgr->createStack($stack_id);

        while ($go_next) {
            $history = $this->core->getSessionHistory();

            var_dump(11111, $history);

            $this->fiberMgr->addTask(
                $stack_id,
                function () use ($socket_id, $history, $msg_meta, $llm_params, &$go_next): array
                {
                    $talk    = $this->talk($socket_id, $history, $msg_meta, $llm_params);
                    $go_next = $talk['next'];

                    return ['next' => $go_next];
                }
            );

            $this->fiberMgr->runStack($stack_id);
        }

        $this->fiberMgr->clearStack($stack_id);
        $this->sendMsg($socket_id, $msg_meta, 'end', null);

        unset($socket_id, $msg_meta, $llm_params, $go_next, $stack_id, $history);
    }

    /**
     * Execute single conversation turn (streaming + tool calls)
     *
     * @param int   $socket_id
     * @param array $history  Current conversation history (snapshot)
     * @param array $msg_meta User message
     * @param array $llm_params
     *
     * @return array ['next' => bool]
     * @throws \ReflectionException
     */
    private function talk(int $socket_id, array $history, array $msg_meta, array $llm_params): array
    {
        $reasons = '';
        $content = '';
        $tools   = [];
        $key     = 'stream_' . uniqid('', true);

        $tool_calls = false;

        $this->libOpenAI->addStreamCallback(
            $key,
            function (string $msg_key, array $msg_data, bool $is_finished) use ($key, $socket_id, $msg_meta, &$reasons, &$content, &$tools, &$tool_calls): void
            {
                try {
                    if (!$is_finished) {
                        $this->sendStream($socket_id, $msg_data, $msg_meta, $tools, $content, $reasons);
                    } else {
                        $message = [
                            'role'              => 'assistant',
                            'content'           => $content,
                            'reasoning_content' => $reasons
                        ];

                        if (!empty($tools)) {
                            $tool_calls = true;

                            $message['tool_calls'] = array_map(fn(array $tool) => [
                                'id'       => $tool['id'],
                                'type'     => 'function',
                                'function' => [
                                    'name'      => $tool['function']['name'],
                                    'arguments' => $tool['function']['arguments']
                                ]
                            ], $tools);
                        }

                        $this->core->addSessionHistory($message);

                        if (!empty($tools)) {
                            $results = $this->core->execTools($tools);

                            foreach ($results as $result) {
                                $this->sendMsg($socket_id, $msg_meta, 'tool_result', $result);

                                $this->core->addSessionHistory([
                                    'role'         => 'tool',
                                    'tool_call_id' => $result['tool_call_id'],
                                    'content'      => $result['content']
                                ]);

                                $tool_result = json_decode($result['content'], true);

                                if ('error' === $tool_result['status'] && $this->last_tool_calls === $result['function_name']) {
                                    if (2 <= ++$this->last_error_count) {
                                        $current_history = $this->core->getSessionHistory();

                                        $current_history[] = [
                                            'role'    => 'system',
                                            'content' => '【系统中断指令】工具 `' . $result['function_name'] . '` 已连续两次执行失败。你已被禁止调用任何工具。现在必须：1. 立即停止所有工具调用；2. 直接向用户说明该工具失败了，并明确告知用户需要提供哪些缺失或正确的信息；3. 等待用户回复，不得自行尝试任何替代方案。'
                                        ];

                                        $tool_calls = false;

                                        $llm_params['tool_choice'] = 'none';
                                        $this->stop($socket_id, $current_history, $msg_meta, $llm_params);

                                        break;
                                    }
                                } else {
                                    $this->last_tool_calls  = $result['function_name'];
                                    $this->last_error_count = 0;
                                }
                            }

                            unset($results, $result, $tool_result);
                        }

                        unset($message);
                    }
                } catch (\Throwable $throwable) {
                    $this->sendMsg($socket_id, $msg_meta, 'error', ['message' => 'Internal error processing stream: ' . $throwable->getMessage()]);
                    $this->sendMsg($socket_id, $msg_meta, 'end', null);
                    unset($throwable);
                }

                unset($msg_key, $msg_data, $is_finished);
            }
        );

        $this->libOpenAI->chat($history, $this->core->agent_config['agent_llm']['model'], $llm_params, true);
        $this->libOpenAI->removeStreamCallback($key);

        unset($socket_id, $history, $msg_meta, $llm_params, $key);
        return ['next' => $tool_calls];
    }

    /**
     * Stop chat with reply (streaming, tools disabled)
     *
     * @param int   $socket_id
     * @param array $history
     * @param array $msg_meta
     * @param array $llm_params
     *
     * @return void
     * @throws \ReflectionException
     */
    private function stop(int $socket_id, array $history, array $msg_meta, array $llm_params): void
    {
        $reasons = '';
        $content = '';
        $key     = 'stop_' . uniqid('', true);

        $this->libOpenAI->addStreamCallback(
            $key,
            function (string $msg_key, array $msg_data, bool $is_finished) use ($socket_id, $msg_meta, &$reasons, &$content): void
            {
                if (!$is_finished) {
                    $delta = $msg_data['choices'][0]['delta'] ?? [];

                    // Normal content
                    if (isset($delta['content']) && '' !== $delta['content']) {
                        $content .= $delta['content'];
                        $this->sendMsg($socket_id, $msg_meta, 'content', $delta['content']);
                    }

                    // Reasoning content
                    if (isset($delta['reasoning_content']) && '' !== $delta['reasoning_content']) {
                        if ($this->core->agent_config['agent_llm']['keep_reasons']) {
                            $reasons .= $delta['reasoning_content'];
                        }
                        $this->sendMsg($socket_id, $msg_meta, 'think', $delta['reasoning_content']);
                    }
                } else {
                    $message = [
                        'role'              => 'assistant',
                        'content'           => $content,
                        'reasoning_content' => $reasons
                    ];

                    $this->core->addSessionHistory($message);
                    unset($message);
                }

                unset($msg_key, $msg_data, $is_finished, $delta);
            }
        );

        $this->libOpenAI->chat($history, $this->core->agent_config['agent_llm']['model'], $llm_params, true);
        $this->libOpenAI->removeStreamCallback($key);

        unset($socket_id, $history, $msg_meta, $llm_params, $reasons, $content, $key);
    }

    /**
     * Stream send formatted data
     *
     * @param int    $socket_id
     * @param array  $msg_data
     * @param array  $msg_meta
     * @param array  $tools
     * @param string $content
     * @param string $reasons
     *
     * @return void
     * @throws \ReflectionException
     */
    private function sendStream(int $socket_id, array $msg_data, array $msg_meta, array &$tools, string &$content, string &$reasons): void
    {
        $delta = $msg_data['choices'][0]['delta'] ?? [];

        // Normal content
        if (isset($delta['content']) && '' !== $delta['content']) {
            if ('<|channel>' === $delta['content']) {
                // For Gemma models enter "thought" mode
                $this->message_type = 'think';
            } elseif ('<channel|>' === $delta['content']) {
                // For Gemma models exit "thought" mode
                $this->message_type = 'content';
            } else {
                if ('content' === $this->message_type) {
                    $content .= $delta['content'];
                } elseif ('think' === $this->message_type) {
                    $reasons .= $delta['content'];
                }

                $this->sendMsg($socket_id, $msg_meta, $this->message_type, $delta['content']);
            }
        }

        // Reasoning content
        if (isset($delta['reasoning_content']) && '' !== $delta['reasoning_content']) {
            if ($this->core->agent_config['agent_llm']['keep_reasons']) {
                $reasons .= $delta['reasoning_content'];
            }

            $this->sendMsg($socket_id, $msg_meta, 'think', $delta['reasoning_content']);
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

            $this->sendMsg($socket_id, $msg_meta, 'tool_calls', $tools);
        }

        unset($socket_id, $msg_data, $msg_meta, $delta);
    }

    /**
     * Send websocket message
     *
     * @param int    $socket_id
     * @param array  $msg_meta
     * @param string $type
     * @param mixed  $data
     *
     * @return void
     * @throws \ReflectionException
     */
    private function sendMsg(int $socket_id, array $msg_meta, string $type, mixed $data): void
    {
        $payload = ['type' => $type];

        if (!is_null($data)) {
            $payload['data'] = $data;
        }

        $this->core->sendMessage($socket_id, json_encode($payload + $msg_meta, JSON_FORMAT));

        unset($socket_id, $msg_meta, $type, $data, $payload);
    }
}
