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

use modules\agent_core\lib\context;
use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Core\Lib\Error;
use Nervsys\Core\Lib\IOData;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Core\Reflect;
use Nervsys\Core\System;

final class core extends Factory
{
    use System;

    public utils     $utils;
    public context   $context;
    public SocketMgr $socketMgr;

    public array $llm_tools       = [];
    public array $agent_tools     = [];
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
        $this->IOData    = IOData::new();
        $this->context   = context::new();
        $this->socketMgr = SocketMgr::new(WORKER_MAIN);

        $this->utils->agent_config = $this->utils->config->get(true, $reload);
    }

    /**
     * @param string $type
     * @param string $session_id
     * @param string $worker_name
     * @param array  $llm_params
     *
     * @return int
     * @throws \ReflectionException
     */
    public function getMaxTokens(string $type, string $session_id, string $worker_name, array $llm_params = []): int
    {
        $llm_tools = [] !== $this->llm_tools ? $this->llm_tools : [];

        if (isset($llm_tools['tools']) && [] !== $llm_tools['tools']) {
            $llm_tools['tools'] = $this->context->formatTools($llm_tools['tools']);
            $llm_tools['tools'] = $this->context->buildTools($llm_tools['tools']);
        }

        $history = $this->context->getHistory($session_id, $worker_name, false);

        foreach ($history as $key => $item) {
            if (!isset($item['contents']) || !is_array($item['contents'])) {
                continue;
            }

            foreach ($item['contents'] as $contents) {
                if (isset($contents['type']) && 'image' === $contents['type']) {
                    unset($history[$key]);
                }
            }
        }

        $context    = json_encode($llm_params + $llm_tools + ['contents' => $history], JSON_FORMAT);
        $compressed = gzdeflate($context, 1);
        $inputs     = (int)(strlen($compressed) * 1.1);
        $outputs    = ($this->utils->agent_config['agent_llm']['model_ctx'] ?? 131072) - $inputs;

        unset($type, $session_id, $worker_name, $llm_params, $llm_tools, $history, $key, $item, $contents, $context, $compressed, $inputs);
        return $outputs;
    }

    /**
     * @return void
     */
    public function resetSkills(): void
    {
        $this->llm_tools = [];
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
                $skill['meta'] = $this->context->formatTools($skill['meta']);

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
     * @param string $call_id
     * @param string $call_name
     * @param array  $tool_call_args
     *
     * @return array
     * @throws \ReflectionException
     */
    public function execTools(string $call_id, string $call_name, array $tool_call_args): array
    {
        $this->IOData->src_cmd  = $call_name;
        $this->IOData->src_argv = $tool_call_args;

        [$module_name, $method_name] = explode('-', $call_name);

        $fn_params   = Reflect::getCallable([$this->agent_tools[$module_name], $method_name])->getParameters();
        $fn_args     = Factory::buildArgs($fn_params, $tool_call_args);
        $tool_result = $this->agent_tools[$module_name]->$method_name(...$fn_args);
        $call_result = json_encode($tool_result, JSON_FORMAT);

        if (false === $call_result) {
            throw new \RuntimeException(json_last_error_msg());
        }

        $this->IOData->src_cmd  = '';
        $this->IOData->src_argv = [];

        $results = [
            'call_id'     => $call_id,
            'call_name'   => $call_name,
            'call_result' => $call_result
        ];

        unset($call_id, $call_name, $tool_call_args, $module_name, $method_name, $fn_params, $fn_args, $tool_result, $call_result);
        return $results;
    }

    /**
     * @param string $socket_id
     * @param string $session_id
     *
     * @return void
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    public function sendClose(string $socket_id, string $session_id): void
    {
        $message = [
            'type'      => 'close',
            'sessionId' => $session_id,
            'messageId' => $this->curr_message_id[$session_id]
        ];

        $buffers = ['create_id' => (int)(microtime(true) * 1000000)] + $message;
        $this->socketMgr->sendMessage($socket_id, $this->socketMgr->wsEncode(json_encode($buffers, JSON_FORMAT)));

        unset($socket_id, $session_id, $message, $buffers);
    }

    /**
     * @param array $message
     * @param array $to_sockets
     *
     * @return void
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    public function sendMessage(array $message, array $to_sockets = []): void
    {
        if (!isset($message['type'])) {
            $this->utils->debug('System', 'Missing type (' . json_encode($message, JSON_FORMAT) . ')', 'debug');
            return;
        }

        if ([] === $to_sockets) {
            $to_sockets = $this->utils->socket_session;
        }

        if ([] === $to_sockets) {
            $this->utils->message_buffers[] = $message;
            $this->utils->debug('System', 'No client online, message queued in buffer for next delivery.', 'debug');
            return;
        }

        $buffers = ['create_id' => (int)(microtime(true) * 1000000)] + $message;

        foreach ($to_sockets as $socket_id => $socket_status) {
            if ('ready' !== $socket_status) {
                continue;
            }

            if (!$this->socketMgr->sendMessage($socket_id, $this->socketMgr->wsEncode(json_encode($buffers, JSON_FORMAT)))) {
                $this->utils->debug('System', 'Failed to send message to #' . $socket_id, 'debug');
            }
        }

        unset($message, $to_sockets, $buffers, $socket_id, $socket_status);
    }

    /**
     * @param array  $message
     * @param string $data_url
     * @param string $image_prompt
     *
     * @return void
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    public function sendImageMessage(array $message, string $data_url, string $image_prompt = ''): void
    {
        $message['type']   = 'image';
        $message['data']   = $data_url;
        $message['prompt'] = $image_prompt;

        $this->sendMessage($message);
        unset($message, $data_url, $image_prompt);
    }
}