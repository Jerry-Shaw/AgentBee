<?php

/**
 * Agent OpenAI responses module for AgentBee
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

namespace modules\agent_openai\api;

use modules\agent_openai\lib\stream;

class responses extends stream
{
    public array $tool_calls = [];

    public string $reasons_content   = '';
    public string $assistant_content = '';

    public string $message_type  = 'content';
    public string $finish_reason = 'undefined';

    /**
     * @param array $events
     *
     * @return array
     */
    public function build(array $events): array
    {
        $messages = [];

        foreach ($events as $event) {
            $contents = [];

            switch ($event['role']) {
                case 'user':
                    if ([] === $event['contents']) {
                        break;
                    }

                    foreach ($event['contents'] as $content) {
                        switch ($content['type']) {
                            case 'text':
                                $contents[] = ['type' => 'input_text', 'text' => $content['content']];
                                break;

                            case 'image':
                                $contents[] = ['type' => 'input_image', 'image_url' => $content['content']];
                                break;
                        }
                    }

                    $messages[] = ['role' => 'user', 'content' => $contents];
                    break;

                case 'tool':
                    $messages[] = [
                        'type'    => 'function_call_output',
                        'call_id' => $event['call_id'],
                        'output'  => $event['content'],
                    ];
                    break;
            }
        }

        unset($events, $event, $contents, $content);
        return $messages;
    }

    /**
     * @param array $tools
     *
     * @return array
     */
    public function buildTools(array $tools): array
    {
        return array_map(
            function (array $tool): array
            {
                $result = [
                    'type'        => $tool['type'],
                    'name'        => $tool['name'],
                    'description' => $tool['description'],
                ];

                if (isset($tool['parameters'])) {
                    $result['parameters'] = $tool['parameters'];
                }

                return $result;
            },
            $tools
        );
    }

    /**
     * @param array $tools
     *
     * @return array
     */
    public function formatTools(array $tools): array
    {
        $normalized = [];

        foreach ($tools as $tool) {
            $definition = [
                'type'        => $tool['type'] ?? 'function',
                'name'        => $tool['name'],
                'description' => $tool['description'],
            ];

            if (isset($tool['parameters']) && [] !== $tool['parameters']) {
                $definition['parameters'] = $tool['parameters'];
            }

            $normalized[] = $definition;
        }

        unset($tools, $tool, $definition);
        return $normalized;
    }

    /**
     * @param string $key
     * @param array  $data
     * @param bool   $finished
     * @param array  $metadata
     *
     * @return void
     * @throws \ReflectionException
     */
    public function streamHandler(string $key, array $data, bool $finished, array $metadata): void
    {
        if (!$finished) {
            $this->handleChunk($data, $metadata);
            return;
        }

        if (isset($data['error'])) {
            $this->finish_reason = 'error';
            unset($this->options['previous_response_id']);
            $this->output('stream', 'error', $metadata, $data['error']);
            return;
        }

        if (isset($data['status']) && 'aborted' === $data['status']) {
            $this->finish_reason = 'abort';
            $this->tool_calls    = [];
            $this->output('stream', 'abort', $metadata, ['message' => '已停止生成']);
            return;
        }

        $this->finishStream($metadata);

        $this->tool_calls        = [];
        $this->reasons_content   = '';
        $this->assistant_content = '';
        $this->message_type      = 'content';
        $this->finish_reason     = 'undefined';

        unset($key, $data, $finished, $metadata);
    }

    /**
     * @param array $chunk
     * @param array $metadata
     *
     * @return void
     */
    private function handleChunk(array $chunk, array $metadata): void
    {
        switch ($chunk['type']) {
            case 'response.completed':
                if (isset($chunk['response']['id'])) {
                    $this->options['previous_response_id'] = $chunk['response']['id'];
                }

                $this->finish_reason = match ($chunk['response']['status']) {
                    'completed' => 'stop',
                    'incomplete' => 'length',
                    default => 'error',
                };
                break;

            case 'response.failed':
            case 'response.cancelled':
                $this->finish_reason = 'error';
                break;

            case 'response.output_text.delta':
                if (isset($chunk['delta']) && '' !== $chunk['delta']) {
                    if ('content' === $this->message_type) {
                        $this->assistant_content .= $chunk['delta'];
                    } elseif ('think' === $this->message_type) {
                        $this->reasons_content .= $chunk['delta'];
                    }

                    $this->output('stream', $this->message_type, $metadata, $chunk['delta']);
                }
                break;

            case 'response.reasoning_summary_text.delta':
                if (isset($chunk['delta']) && '' !== $chunk['delta']) {
                    if ($this->core->utils->agent_config['agent_llm']['keep_reasons'] ?? false) {
                        $this->reasons_content .= $chunk['delta'];
                    }

                    $this->output('stream', 'think', $metadata, $chunk['delta']);
                }
                break;

            case 'response.output_item.added':
                if (isset($chunk['item']['type']) && 'function_call' === $chunk['item']['type']) {
                    $tool_chunk = [
                        'id'        => $chunk['item']['id'],
                        'type'      => 'function',
                        'name'      => $chunk['item']['name'] ?? '',
                        'arguments' => $chunk['item']['arguments'] ?? '',
                    ];

                    $this->mergeToolCallChunk($tool_chunk['id'], $tool_chunk);

                    unset($tool_chunk);
                }
                break;

            case 'response.function_call_arguments.delta':
                if (isset($chunk['item_id']) && isset($chunk['delta'])) {
                    $tool_chunk = ['arguments' => $chunk['delta']];
                    $this->mergeToolCallChunk($chunk['item_id'], $tool_chunk);
                    unset($tool_chunk);
                }
                break;

            case 'response.function_call_arguments.done':
                if (isset($chunk['item_id']) && isset($chunk['arguments'])) {
                    if (isset($this->tool_calls[$chunk['item_id']])) {
                        $this->tool_calls[$chunk['item_id']]['arguments'] = $chunk['arguments'];
                    } else {
                        $this->tool_calls[$chunk['item_id']] = [
                            'id'        => $chunk['item_id'],
                            'type'      => 'function',
                            'name'      => '',
                            'arguments' => $chunk['arguments'],
                        ];
                    }
                }
                break;
        }

        unset($chunk, $metadata);
    }

    /**
     * @param array $metadata
     *
     * @return void
     * @throws \ReflectionException
     */
    private function finishStream(array $metadata): void
    {
        if ('stop' === $this->finish_reason && [] !== $this->tool_calls) {
            $this->finish_reason = 'tool_calls';
        }

        switch ($this->finish_reason) {
            case 'stop':
                if ('' !== $this->assistant_content || '' !== $this->reasons_content) {
                    $this->output('history', 'addAssistantMessage', $metadata, $this->buildAssistantEvent());
                }

                if ('' !== $this->assistant_content) {
                    $this->output('memory', 'save', $metadata, $this->assistant_content);
                }

                $this->output('stream', 'end', $metadata);
                $this->output('end', 'end', $metadata, $this->assistant_content);
                break;

            case 'length':
                $this->output('history', 'addAssistantMessage', $metadata, $this->buildAssistantEvent());
                $this->output('history', 'addUserMessage', $metadata, [
                    'role'    => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => '' !== $this->assistant_content ? '[续写] 紧接末尾，直接续写，不重复。' : '[续写] 直接输出最终内容。',
                    ]],
                ]);

                if ('' !== $this->assistant_content) {
                    $this->output('memory', 'save', $metadata, $this->assistant_content);
                }

                $this->output('stream', 'length', $metadata, '[系统提示] 内容过长被截断。');
                break;

            case 'tool_calls':
                $error_args    = 0;
                $error_names   = 0;
                $error_calls   = 0;
                $correct_calls = [];
                $tool_results  = [];
                $handler_calls = [];

                foreach ($this->tool_calls as $fn_call) {
                    if (!str_contains($fn_call['name'], '-')) {
                        ++$error_names;
                        continue;
                    }

                    if ('' === $fn_call['arguments']) {
                        ++$error_args;
                        continue;
                    }

                    $tool_args = json_decode($fn_call['arguments'], true);

                    if (!is_array($tool_args)) {
                        ++$error_args;
                        continue;
                    }

                    $tool_call = [
                        'id'        => $fn_call['id'],
                        'type'      => $fn_call['type'],
                        'name'      => $fn_call['name'],
                        'arguments' => $fn_call['arguments'],
                    ];

                    $this->output('stream', 'tool_calls', $metadata, $tool_call);

                    try {
                        $exec_result = $this->core->execTools($fn_call['id'], $fn_call['name'], $tool_args);
                    } catch (\Throwable $throwable) {
                        $this->output('stream', 'tool_result', $metadata, [
                            'call_id'       => $fn_call['id'],
                            'function_name' => $fn_call['name'],
                            'error'         => $throwable->getMessage(),
                        ]);

                        unset($throwable);
                        ++$error_calls;
                        continue;
                    }

                    $result_data = json_decode($exec_result['call_result'], true);

                    if (!isset($result_data['handler'])) {
                        $this->output('stream', 'tool_result', $metadata, [
                            'call_id'       => $exec_result['call_id'],
                            'function_name' => $exec_result['call_name'],
                            'content'       => $exec_result['call_result'],
                        ]);

                        $correct_calls[] = $tool_call;
                        $tool_results[]  = [
                            'role'    => 'tool',
                            'call_id' => $exec_result['call_id'],
                            'content' => $exec_result['call_result'],
                        ];
                    } else {
                        $result_data['socket_id']    = $metadata['socket_id'];
                        $result_data['process_name'] = $metadata['workerName'];

                        $handler_calls[] = [
                            'tool_calls'   => $tool_call,
                            'handler_args' => $result_data,
                        ];
                    }
                }

                $assistant_message = $this->buildAssistantEvent();

                if ([] !== $correct_calls) {
                    $assistant_message['tool_calls'] = $correct_calls;
                }

                if ('' !== $this->assistant_content || '' !== $this->reasons_content || [] !== $correct_calls) {
                    $this->output('history', 'addAssistantMessage', $metadata, $assistant_message);
                }

                foreach ($tool_results as $tool_result) {
                    $this->output('history', 'addToolResult', $metadata, $tool_result);
                }

                if ([] !== $handler_calls) {
                    $this->output('context', 'callHandler', $metadata, $handler_calls);
                }

                $error_types = [];

                if (0 < $error_args) {
                    $error_types['argv'] = '部分工具调用参数缺失';
                }

                if (0 < $error_names) {
                    $error_types['name'] = '部分工具调用名称错误';
                }

                if (0 < $error_calls) {
                    $error_types['error'] = '部分工具调用失败';
                }

                if ([] !== $error_types) {
                    $this->output('history', 'addUserMessage', $metadata, [
                        'role'    => 'user',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => '[系统提示] ' . implode('，', $error_types) . '，已自动过滤。请核对工具并补全参数后重新调用。',
                        ]],
                    ]);
                }

                $this->output('stream', 'end', $metadata);
                $this->output('end', 'tools', $metadata, $correct_calls);

                unset($error_args, $error_names, $error_calls, $correct_calls, $tool_results, $handler_calls, $fn_call, $tool_args, $tool_call, $exec_result, $result_data, $assistant_message, $tool_result, $error_types);
                break;

            case 'content_filter':
                $this->output('stream', 'error', $metadata, ['message' => '[系统提示] 内容被安全策略过滤，已终止生成。']);
                $this->finish_reason = 'error';
                break;

            case 'undefined':
                if ([] !== $this->tool_calls) {
                    $this->output('history', 'addUserMessage', $metadata, [
                        'role'    => 'user',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => '[系统提示] 生成中断，未收到结束信号，工具调用已丢弃。请重新调用并确保参数完整。',
                        ]],
                    ]);
                }

                $this->output('stream', 'error', $metadata, ['message' => '[系统提示] 生成异常中断。']);
                $this->finish_reason = 'error';
                break;

            default:
                $this->output('stream', 'error', $metadata, ['message' => '[系统提示] 未知的结束原因（' . $this->finish_reason . '），已终止生成。']);
                $this->finish_reason = 'error';
                break;
        }

        unset($metadata);
    }

    /**
     * @return array
     */
    private function buildAssistantEvent(): array
    {
        $event = ['role' => 'assistant', 'content' => $this->assistant_content];

        if ('' !== $this->reasons_content) {
            $event['reasoning_content'] = $this->reasons_content;
        }

        return $event;
    }

    /**
     * @param string $index
     * @param array  $chunk
     *
     * @return void
     */
    private function mergeToolCallChunk(string $index, array $chunk): void
    {
        if (!isset($this->tool_calls[$index])) {
            $this->tool_calls[$index] = [
                'id'        => '',
                'type'      => '',
                'name'      => '',
                'arguments' => '',
            ];
        }

        if (isset($chunk['id']) && '' !== $chunk['id']) {
            $this->tool_calls[$index]['id'] = $chunk['id'];
        }

        if (isset($chunk['type']) && '' !== $chunk['type']) {
            $this->tool_calls[$index]['type'] = $chunk['type'];
        }

        if (isset($chunk['name']) && '' !== $chunk['name']) {
            $this->tool_calls[$index]['name'] = $chunk['name'];
        }

        if (isset($chunk['arguments'])) {
            $this->tool_calls[$index]['arguments'] .= $chunk['arguments'];
        }

        unset($index, $chunk);
    }
}