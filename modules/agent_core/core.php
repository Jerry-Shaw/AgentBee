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
use Nervsys\Ext\Algo\NGram;

final class core extends Factory
{
    use System;

    public utils     $utils;
    public NGram     $NGram;
    public context   $context;
    public SocketMgr $socketMgr;

    public int $flush_time_at  = 0;
    public int $flush_interval = 30;

    public array $llm_tools       = [];
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
        $this->NGram     = NGram::new();
        $this->IOData    = IOData::new();
        $this->context   = context::new();
        $this->socketMgr = SocketMgr::new(WORKER_MAIN);

        $this->utils->agent_config = $this->utils->config->get(true, $reload);

        if ('' === $this->utils->agent_config['workspace_path'] || !is_dir($this->utils->agent_config['workspace_path'])) {
            $this->utils->agent_config['workspace_path'] = $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
        }

        $this->utils->agent_config['workspace_path'] ??= $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
    }

    /**
     * @param string $type
     * @param string $worker_name
     * @param string $token_type
     *
     * @return int
     * @throws \ReflectionException
     */
    public function countTokens(string $type, string $worker_name, string $token_type = 'input'): int
    {
        $llm_tools  = [] !== $this->llm_tools ? $this->llm_tools : [];
        $llm_params = $this->utils->getChildWorker($type, $worker_name, 'llm_params');

        if (isset($llm_tools['tools']) && [] !== $llm_tools['tools']) {
            $llm_tools['tools'] = $this->context->formatTools($llm_tools['tools']);
            $llm_tools['tools'] = $this->context->buildTools($llm_tools['tools']);
        }

        $context = json_encode($llm_params + $llm_tools + ['messages' => $this->context->getHistory($worker_name)], JSON_FORMAT);

        $split   = $this->NGram->splitText($context);
        $inputs  = ceil(strlen(str_replace(' ', '', $split['asian'])) / 2.8);
        $inputs  += ceil(strlen($split['latin']) / 4);
        $outputs = ($this->utils->agent_config['agent_llm']['model_ctx'] ?? 131072) - $inputs;
        $tokens  = 'input' === $token_type ? $inputs : $outputs;

        $llm_params['max_tokens'] = $outputs;

        $this->utils->setChildWorker($type, $worker_name, 'llm_params', $llm_params);

        unset($type, $worker_name, $token_type, $llm_tools, $llm_params, $context, $split, $inputs, $outputs);
        return $tokens;
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