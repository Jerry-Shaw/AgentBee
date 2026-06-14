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

use modules\agent_core\lib\config;
use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Core\Lib\Error;
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Core\Reflect;
use Nervsys\Core\System;
use Nervsys\Ext\libFileIO;

final class core extends Factory
{
    use System;

    public utils     $utils;
    public SocketMgr $socketMgr;

    public int $openai_idx = 0;

    public array $llm_tools  = [];
    public array $llm_params = [];

    public array $agent_tools   = [];
    public array $agent_config  = [];
    public array $agent_modules = [];

    public array $session_history       = [];
    public int   $session_history_limit = 20;

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
        $this->socketMgr = SocketMgr::new();

        $this->agent_config = $this->utils->config->get(true, $reload);
        $this->llm_params   = $this->agent_config['agent_llm']['params'] ?? [];

        if (isset($this->agent_config['max_ctx_len'])) {
            $this->session_history_limit = $this->agent_config['max_ctx_len'];
        }

        if ('' === $this->agent_config['workspace_path'] || !is_dir($this->agent_config['workspace_path'])) {
            $this->agent_config['workspace_path'] = $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
        }

        $this->agent_config['workspace_path'] ??= $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
    }

    /**
     * Initialize all modules.
     *
     * @return void
     * @throws \ReflectionException
     */
    public function initProvider(): void
    {
        foreach ($this->agent_config as $name => $config) {
            if (!isset($config['provider'])) {
                continue;
            }

            $module = '\\' . strtr($config['provider'], '/', '\\') . '\\go';

            try {
                $this->agent_modules[$name] = $module::new();
                $this->utils->debug('Loading Provider: ' . $config['provider'] . '...', 'trace');
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, false, false);
                unset($throwable);
            }
        }

        unset($name, $config, $module);
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
                Error::new()->exceptionHandler($throwable, false, false);
                unset($throwable);
            }
        }

        if (!empty($skill_metadata)) {
            $this->llm_tools['tools']               = array_merge($this->llm_tools['tools'] ?? [], $skill_metadata);
            $this->llm_tools['tool_choice']         = 'auto';
            $this->llm_tools['parallel_tool_calls'] = true;
        }

        unset($fetched_skills, $skill_metadata, $skill);
    }

    /**
     * Get current session history.
     *
     * @return array
     */
    public function getSessionHistory(): array
    {
        return $this->session_history;
    }

    /**
     * @return int
     * @throws \Exception
     */
    public function cleanSessionHistory(): int
    {
        $keep_user_assistant = 8;
        $keep_tool_calls     = 2;
        $keep_tool_results   = 2;

        $new_history = [];
        $last_role   = '';
        $last_key    = count($this->session_history) - 1;

        for ($i = $last_key; $i > 0; --$i) {
            $message_role  = $this->session_history[$i]['role'];
            $is_tool_calls = $this->session_history[$i]['tool_calls'] ?? false;

            if (0 < $keep_user_assistant) {
                if ('user' === $message_role) {
                    $new_history[$i] = $this->session_history[$i];
                    --$keep_user_assistant;
                    $last_role = 'user';
                } elseif ('assistant' === $message_role && !$is_tool_calls) {
                    $new_history[$i] = $this->session_history[$i];
                    --$keep_user_assistant;
                    $last_role = 'assistant';
                }

                if ('user' !== $last_role && !$is_tool_calls && in_array($message_role, ['user', 'assistant'], true)) {
                    ++$keep_user_assistant;
                }
            }

            if (0 < $keep_tool_results) {
                if ('tool' === $message_role) {
                    $new_history[$i] = $this->session_history[$i];
                    --$keep_tool_results;
                }
            }

            if (0 < $keep_tool_calls) {
                if ('assistant' === $message_role && $is_tool_calls) {
                    $new_history[$i] = $this->session_history[$i];
                    --$keep_tool_calls;
                }
            }

            if (0 >= $keep_user_assistant && 0 >= $keep_tool_results && 0 >= $keep_tool_calls) {
                break;
            }
        }

        ksort($new_history, SORT_NUMERIC);
        array_unshift($new_history, $this->getSystemMemory());
        $this->session_history = array_values($new_history);

        unset($keep_user_assistant, $keep_tool_calls, $keep_tool_results, $new_history, $last_role, $last_key, $i, $message_role, $is_tool_calls);
        return count($this->session_history);
    }

    /**
     * Get system memory prompt.
     *
     * @return array
     * @throws \Exception
     */
    public function getSystemMemory(): array
    {
        $system_default = $this->utils->getMainPrompt();
        $system_memory  = \modules\agent_skills\Memory\go::new()->read('system', 0, 0);

        if (!empty($system_memory['messages'])) {
            $memory = ['=== 重要个性设定 ==='];

            foreach ($system_memory['messages'] as $message) {
                $memory[] = $message['content'];
            }

            $system_default['content'] .= implode("\n", $memory);
        }

        unset($system_memory, $memory, $message);
        return $system_default;
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

            if (!str_contains($fn_name, '/')) {
                $results[] = [
                    'tool_call_id'  => $tool_call['id'],
                    'function_name' => $fn_name,
                    'content'       => json_encode(['status' => 'error', 'message' => 'Invalid tool format: ' . $fn_name], JSON_FORMAT)
                ];
                continue;
            }

            [$module_name, $method_name] = explode('/', $fn_name);

            try {
                $params         = Reflect::getCallable([$this->agent_tools[$module_name], $method_name])->getParameters();
                $args           = Factory::buildArgs($params, (array)$fn_args);
                $tool_result    = $this->agent_tools[$module_name]->$method_name(...$args);
                $result_content = json_encode(['status' => 'success', 'data' => $tool_result], JSON_FORMAT);

                if (false === $result_content) {
                    $result_content = json_encode(['status' => 'error', 'message' => json_last_error_msg()], JSON_FORMAT);
                }
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler(
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
     * Send WebSocket message.
     *
     * @param string $socket_id
     * @param string $message
     *
     * @return void
     * @throws \ReflectionException
     */
    public function sendMessage(string $socket_id, string $message): void
    {
        $this->socketMgr->sendMessage($socket_id, $this->socketMgr->wsEncode($message));
        unset($socket_id, $message);
    }
}