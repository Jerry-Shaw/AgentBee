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

namespace modules\agent_core;

use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Core\Lib\Error;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Core\Reflect;
use Nervsys\Core\System;

final class core extends Factory
{
    use System;

    public utils     $utils;
    public Error     $error;
    public SocketMgr $socketMgr;

    public int $flush_time_at  = 0;
    public int $flush_interval = 30;

    public array $llm_tools       = [];
    public array $llm_params      = [];
    public array $agent_tools     = [];
    public array $flush_buffers   = [];
    public array $curr_message_id = [];

    /**
     * Initialize core components.
     *
     * @param bool $reload
     *
     * @return void
     * @throws \ReflectionException
     */
    public function initCore(bool $reload = false): void
    {
        $this->init();

        $this->utils     = utils::new();
        $this->error     = Error::new();
        $this->socketMgr = SocketMgr::new(WORKER_MAIN);

        $this->utils->agent_config = $this->utils->config->get(true, $reload);

        $this->llm_params = $this->utils->agent_config['agent_llm']['params'] ?? [];

        if ('' === $this->utils->agent_config['workspace_path'] || !is_dir($this->utils->agent_config['workspace_path'])) {
            $this->utils->agent_config['workspace_path'] = $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
        }

        $this->utils->agent_config['workspace_path'] ??= $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
    }

    /**
     * @param array $fetched_skills
     *
     * @return void
     * @throws \ReflectionException
     */
    public function addSkills(array $fetched_skills): void
    {
        $skill_metadata = [];

        foreach ($fetched_skills as $skill) {
            try {
                $this->agent_tools[$skill['name']] = ($skill['class'])::new();

                $skill_metadata = array_merge($skill_metadata, $skill['meta']);
            } catch (\Throwable $throwable) {
                $this->error->exceptionHandler($throwable, false, false);
                unset($throwable);
            }
        }

        if ([] !== $skill_metadata) {
            $this->llm_tools['tools']               = array_merge($this->llm_tools['tools'] ?? [], $skill_metadata);
            $this->llm_tools['tool_choice']         = 'auto';
            $this->llm_tools['parallel_tool_calls'] = true;
        }

        unset($fetched_skills, $skill_metadata, $skill);
    }

    /**
     * Execute tool calls.
     *
     * @param array $tool_calls
     *
     * @return array
     * @throws \ReflectionException
     */
    public function execTools(array $tool_calls): array
    {
        $results = [];

        foreach ($tool_calls as $tool_call) {
            $fn_name = $tool_call['function']['name'];
            $fn_args = json_decode($tool_call['function']['arguments'], true) ?? [];

            if (!str_contains($fn_name, '-')) {
                $results[] = [
                    'tool_call_id'  => $tool_call['id'],
                    'function_name' => $fn_name,
                    'content'       => json_encode(['status' => 'error', 'message' => 'Invalid tool format: ' . $fn_name], JSON_FORMAT)
                ];
                continue;
            }

            [$module_name, $method_name] = explode('-', $fn_name);

            try {
                $params         = Reflect::getCallable([$this->agent_tools[$module_name], $method_name])->getParameters();
                $args           = Factory::buildArgs($params, (array)$fn_args);
                $tool_result    = $this->agent_tools[$module_name]->$method_name(...$args);
                $result_content = json_encode($tool_result, JSON_FORMAT);

                if (false === $result_content) {
                    $result_content = json_encode(['status' => 'error', 'message' => json_last_error_msg()], JSON_FORMAT);
                }
            } catch (\Throwable $throwable) {
                $this->error->exceptionHandler(
                    new \InvalidArgumentException($fn_name . ': ' . $throwable->getMessage(), $throwable->getCode(), $throwable),
                    false,
                    false
                );

                $result_content = json_encode(['status' => 'error', 'message' => mb_substr($throwable->getMessage(), 0, 256, 'UTF-8')], JSON_FORMAT);
                unset($throwable);
            }

            $results[] = [
                'tool_call_id'  => $tool_call['id'],
                'function_name' => $fn_name,
                'content'       => $result_content
            ];
        }

        unset($tool_calls, $tool_call, $fn_name, $fn_args, $module_name, $method_name, $params, $args, $tool_result, $result_content);
        return $results;
    }

    /**
     * Get LLM parameters (including tools).
     *
     * @return array
     */
    public function getLLMParams(): array
    {
        return $this->llm_params + $this->llm_tools;
    }

    /**
     * @param string $socket_id
     * @param array  $message
     *
     * @return void
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    public function sendMessage(string $socket_id, array $message): void
    {
        if (!isset($message['type'])) {
            $this->utils->debug('Missing message type: ' . json_encode($message, JSON_FORMAT), 'trace');
            return;
        }

        if (!isset($this->utils->socket_session[$socket_id]) || 'ready' !== $this->utils->socket_session[$socket_id]) {
            $this->utils->message_buffers[] = $message;
            return;
        }

        if ([] !== $this->flush_buffers) {
            $microtime = (int)(microtime(true) * 1000);

            if (
                $microtime - $this->flush_time_at > $this->flush_interval
                || !isset($message['messageId'])
                || $message['type'] !== $this->flush_buffers['type']
                || $message['messageId'] !== $this->flush_buffers['messageId']
            ) {
                $success = $this->socketMgr->sendMessage($socket_id, $this->socketMgr->wsEncode(json_encode($this->flush_buffers, JSON_FORMAT)));

                if (!$success) {
                    $this->utils->message_buffers[] = $this->flush_buffers;
                } elseif (
                    isset($this->curr_message_id['messageId'])
                    && $this->flush_buffers['messageId'] === $this->curr_message_id['messageId']
                ) {
                    $this->curr_message_id = [];
                }

                $this->flush_time_at = $microtime;
                $this->flush_buffers = [];
            }

            unset($microtime);
        }

        if (in_array($message['type'], ['content', 'think'], true)) {
            if ([] !== $this->flush_buffers) {
                $this->flush_buffers['data'] .= $message['data'];
            } else {
                $this->flush_buffers = $message;
            }

            return;
        }

        $success = $this->socketMgr->sendMessage($socket_id, $this->socketMgr->wsEncode(json_encode($message, JSON_FORMAT)));

        if (!$success) {
            $this->utils->message_buffers[] = $message;
        }

        unset($socket_id, $message, $success);
    }

    /**
     * @param string $socket_id
     * @param array  $message
     * @param string $data_url
     * @param string $image_prompt
     *
     * @return void
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    public function sendImageMessage(string $socket_id, array $message, string $data_url, string $image_prompt = ''): void
    {
        $message['type']   = 'image';
        $message['data']   = $data_url;
        $message['prompt'] = $image_prompt;

        $this->sendMessage($socket_id, $message);
        unset($socket_id, $message, $data_url, $image_prompt);
    }
}