<?php

/**
 * Agent Core module for AgentBee
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

namespace modules\agent_core\lib;

use modules\agent_openai\api\completions;
use modules\agent_openai\api\messages;
use modules\agent_openai\api\responses;
use Nervsys\Core\Factory;

class context extends Factory
{
    public config $config;

    public responses|completions|messages $api_object;

    public array $tools   = [];
    public array $history = [];

    public array $message_queue = [];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->config     = config::new();
        $this->api_object = parent::getObj('\\modules\\agent_openai\\api\\' . $this->config->config['agent_llm']['api_type']);
    }

    /**
     * @param array $tools
     *
     * @return array
     * @throws \ReflectionException
     */
    public function formatTools(array $tools): array
    {
        $first = reset($tools);

        if (!isset($first['function']) && !isset($first['input_schema'])) {
            $format = 'responses';
        } elseif (isset($first['function']['name'])) {
            $format = 'completions';
        } else {
            $format = 'messages';
        }

        $toolsets = match ($format) {
            'responses' => responses::new()->formatTools($tools),
            'completions' => completions::new()->formatTools($tools),
            'messages' => messages::new()->formatTools($tools),
            default => [],
        };

        unset($tools, $first, $format);
        return $toolsets;
    }

    /**
     * @param array $formatted
     *
     * @return array
     */
    public function buildTools(array $formatted): array
    {
        return $this->api_object->buildTools($formatted);
    }

    /**
     * Add a user message.
     *
     * @param string $worker_name
     * @param array  $contents [{type=text|image, content=xxxxx}]
     *
     * @return void
     */
    public function addUserMessage(string $worker_name, array $contents): void
    {
        $message = ['role' => 'user'];

        if ([] !== $contents) {
            $message['contents'] = $contents;
        }

        $this->history[$worker_name]   ??= [];
        $this->history[$worker_name][] = $message;

        unset($worker_name, $contents, $message);
    }

    /**
     * Add an assistant message.
     *
     * @param string $worker_name
     * @param string $content
     * @param array  $tool_calls
     * @param string $reasoning_content
     *
     * @return void
     */
    public function addAssistantMessage(string $worker_name, string $content = '', array $tool_calls = [], string $reasoning_content = ''): void
    {
        $message = ['role' => 'assistant', 'content' => $content];

        if ('' !== $reasoning_content) {
            $message['reasoning_content'] = $reasoning_content;
        }

        if ([] !== $tool_calls) {
            $message['tool_calls'] = array_map(fn(array $tool_call): array => $this->normalizeToolCall($tool_call), $tool_calls);
        }

        $this->history[$worker_name]   ??= [];
        $this->history[$worker_name][] = $message;

        unset($worker_name, $content, $tool_calls, $reasoning_content, $message);
    }

    /**
     * Add a tool result.
     *
     * @param string $worker_name
     * @param string $call_id
     * @param string $content
     *
     * @return void
     */
    public function addToolResult(string $worker_name, string $call_id, string $content): void
    {
        $this->history[$worker_name]   ??= [];
        $this->history[$worker_name][] = [
            'role'    => 'tool',
            'call_id' => $call_id,
            'content' => $content,
        ];

        unset($worker_name, $call_id, $content);
    }

    /**
     * @param string $worker_name
     * @param array  $message
     *
     * @return void
     */
    public function addMessageQueue(string $worker_name, array $message): void
    {
        $this->message_queue[$worker_name]   ??= [];
        $this->message_queue[$worker_name][] = $message;

        unset($worker_name, $message);
    }

    /**
     * @param string $worker_name
     * @param bool   $popout
     *
     * @return array
     */
    public function getMessageQueue(string $worker_name, bool $popout = false): array
    {
        $messages = $this->message_queue[$worker_name] ?? [];

        if ($popout) {
            unset($this->message_queue[$worker_name]);
        }

        unset($worker_name, $popout);
        return $messages;
    }

    /**
     * @param string $worker_name
     *
     * @return void
     */
    public function removeMessageQueue(string $worker_name): void
    {
        unset($this->message_queue[$worker_name], $worker_name);
    }

    /**
     * Get normalized conversation events for a worker.
     *
     * @param string $worker_name
     *
     * @return array
     */
    public function getHistory(string $worker_name): array
    {
        if (!isset($this->history[$worker_name]) || [] === $this->history[$worker_name]) {
            return [];
        }

        $worker_history = $this->api_object->build($this->history[$worker_name]);

        unset($worker_name, $api_type, $api_object);
        return $worker_history;
    }

    /**
     * Count all events or events for one role.
     *
     * @param string $worker_name Worker/session name.
     * @param string $role_name   Optional role filter.
     *
     * @return int Event count.
     */
    public function countHistory(string $worker_name, string $role_name = ''): int
    {
        if ('' === $role_name) {
            return count($this->history[$worker_name] ?? []);
        }

        $role_history = array_column($this->history[$worker_name], 'role');
        $role_count   = array_count_values($role_history);
        $msg_count    = $role_count[$role_name] ?? 0;

        unset($worker_name, $role_name, $role_history, $role_count);
        return $msg_count;
    }

    /**
     * Remove conversation events and tool definitions for a worker.
     *
     * @param string $worker_name Worker/session name.
     */
    public function removeHistory(string $worker_name): void
    {
        unset($this->message_queue[$worker_name], $this->history[$worker_name], $this->tools[$worker_name], $worker_name);
    }

    /**
     * Flush the message queue for a worker and append all queued text messages as a single user message.
     *
     * @param string $worker_name
     *
     * @return int Number of messages moved from queue to history.
     */
    public function refreshHistory(string $worker_name): int
    {
        if (!isset($this->message_queue[$worker_name]) || [] === $this->message_queue[$worker_name]) {
            return 0;
        }

        $messages = [];
        while (null !== ($message = array_shift($this->message_queue[$worker_name]))) {
            $messages[] = $message;
        }

        $count_messages = count($messages);

        if ($count_messages > 0) {
            $this->addUserMessage($worker_name, $messages);
        }

        unset($worker_name, $messages, $message);
        return $count_messages;
    }

    /**
     * Compact history while preserving valid tool-call groups.
     *
     * @param string $worker_name    Worker/session name.
     * @param int    $keep_normal    Minimum number of recent normal messages to keep.
     * @param int    $max_tool_pairs Maximum number of recent complete tool groups to keep.
     *
     * @return array Removal and retention statistics.
     */
    public function cleanHistory(string $worker_name, int $keep_normal = 6, int $max_tool_pairs = 2): array
    {
        $history = $this->history[$worker_name] ?? [];

        $keep_normal    = max(6, $keep_normal);
        $max_tool_pairs = max(2, $max_tool_pairs);

        $messages     = [];
        $total_normal = 0;
        $total_tools  = 0;

        foreach ($history as $message) {
            if (isset($message['tool_calls']) && [] === $message['tool_calls']) {
                unset($message['tool_calls']);
            }

            $is_tool_calls = 'assistant' === $message['role'] && isset($message['tool_calls']);
            $total_normal  += (int)('user' === $message['role'] || ('assistant' === $message['role'] && !$is_tool_calls));
            $total_tools   += (int)$is_tool_calls;
            $messages[]    = $message;
        }

        $first_user = null;

        foreach ($messages as $index => $message) {
            if ('user' === $message['role']) {
                $first_user = $index;
                break;
            }
        }

        if (null === $first_user) {
            $this->history[$worker_name] = [];

            $result = [
                'removed_normal' => $total_normal,
                'removed_tools'  => $total_tools,
                'current_count'  => count($this->history[$worker_name]),
            ];

            unset($worker_name, $keep_normal, $max_tool_pairs, $history, $messages, $total_normal, $total_tools, $message, $is_tool_calls, $first_user, $index);
            return $result;
        }

        $messages = array_values(array_slice($messages, $first_user));

        $groups         = [];
        $results        = [];
        $valid_groups   = [];
        $normal_indices = [];

        foreach ($messages as $index => $message) {
            if ('user' === $message['role'] || ('assistant' === $message['role'] && !isset($message['tool_calls']))) {
                $normal_indices[] = $index;
            } elseif ('tool' === $message['role']) {
                $results[$message['call_id']] = $index;
            } elseif ('assistant' === $message['role']) {
                $groups[$index] = array_column($message['tool_calls'], 'id');
            }
        }

        foreach ($groups as $group_index => $call_ids) {
            $group_results = [];

            foreach ($call_ids as $call_id) {
                $result = $results[$call_id] ?? -1;

                if ($result <= $group_index) {
                    continue 2;
                }

                $group_results[$result] = true;
            }

            $valid_groups[$group_index] = $group_results;
        }

        if ([] === $normal_indices) {
            $this->history[$worker_name] = [];

            $result = [
                'removed_normal' => $total_normal,
                'removed_tools'  => $total_tools,
                'current_count'  => count($this->history[$worker_name]),
            ];

            unset($worker_name, $keep_normal, $max_tool_pairs, $history, $messages, $total_normal, $total_tools, $message, $is_tool_calls, $first_user, $index, $groups, $results, $valid_groups, $normal_indices, $group_index, $call_ids, $group_results, $call_id);
            return $result;
        }

        $start = $normal_indices[max(0, count($normal_indices) - $keep_normal)];

        while ('user' !== $messages[$start]['role']) {
            --$start;
        }

        $selected_groups = array_filter(
            $valid_groups,
            function (int $group_index) use ($start): bool
            {
                return $group_index >= $start;
            },
            ARRAY_FILTER_USE_KEY
        );

        $selected_groups = array_slice($selected_groups, -$max_tool_pairs, null, true);

        $selected_results = [];
        foreach ($selected_groups as $group_results) {
            $selected_results += $group_results;
        }

        $kept_normal = 0;
        $new_history = [];

        foreach ($messages as $index => $message) {
            if ($index < $start) {
                continue;
            }

            if ('user' === $message['role'] || ('assistant' === $message['role'] && !isset($message['tool_calls']))) {
                $new_history[] = $message;
                ++$kept_normal;
            } elseif ('assistant' === $message['role'] && isset($selected_groups[$index])) {
                $new_history[] = $message;
            } elseif ('tool' === $message['role'] && isset($selected_results[$index])) {
                $new_history[] = $message;
            }
        }

        $this->history[$worker_name] = $new_history;

        $result = [
            'removed_normal' => $total_normal - $kept_normal,
            'removed_tools'  => $total_tools - count($selected_groups),
            'current_count'  => count($new_history),
        ];

        unset($worker_name, $keep_normal, $max_tool_pairs, $history, $messages, $total_normal, $total_tools, $message, $is_tool_calls, $first_user, $index, $groups, $results, $valid_groups, $normal_indices, $group_index, $call_ids, $group_results, $call_id, $start, $selected_groups, $selected_results, $kept_normal, $new_history);
        return $result;
    }

    /**
     * Normalize one tool call from completions, responses, or messages formats.
     *
     * @param array $tool_call
     *
     * @return array
     */
    private function normalizeToolCall(array $tool_call): array
    {
        $function   = $tool_call['function'] ?? $tool_call;
        $normalized = [
            'id'        => $tool_call['call_id'] ?? $tool_call['id'],
            'type'      => $tool_call['type'],
            'name'      => $function['name'],
            'arguments' => $function['arguments'],
        ];

        unset($tool_call, $function);
        return $normalized;
    }
}