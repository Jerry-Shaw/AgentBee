<?php

/**
 * Agent OpenAI module for AgentBee
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
use modules\agent_core\lib\context;
use modules\agent_core\lib\utils;
use modules\agent_openai\lib\processor;
use Nervsys\Core\Factory;
use Nervsys\Ext\libOpenAI;

class go extends Factory
{
    const CMD_RELOAD = '__RELOAD__';

    public core  $core;
    public utils $utils;

    public libOpenAI $libOpenAI;
    public processor $processor;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core      = core::new();
        $this->utils     = utils::new();
        $this->processor = processor::new();

        $this->init();
    }

    /**
     * @param bool $reload
     *
     * @return void
     * @throws \ReflectionException
     */
    public function initMain(bool $reload = false): void
    {
        $this->init($reload);

        $agent_toolsets = $this->utils->fetchToolset('modules/agent_toolsets');
        $this->core->addSkills($agent_toolsets);
        $custom_skills = $this->utils->fetchToolset('skills');
        $this->core->addSkills($custom_skills);

        unset($reload, $agent_toolsets, $custom_skills);
    }

    /**
     * @param bool $reload
     *
     * @return void
     * @throws \ReflectionException
     */
    public function initChild(bool $reload = false): void
    {
        $llm_params  = [];
        $config_file = $this->utils->config->config_dir . DIRECTORY_SEPARATOR . 'WorkerBee.json';

        if (is_file($config_file)) {
            $llm_params = json_decode(file_get_contents($config_file), true) ?? [];
        }

        $this->init($reload, $llm_params);

        $agent_toolsets = $this->utils->fetchToolset(
            'modules/agent_toolsets',
            'System',
            [
                'loadSkill', 'getTime',
                'readImage', 'readFile', 'writeFile',
                'copyFile', 'deleteFile', 'getFileSize', 'searchFiles',
                'listDirectory', 'createDirectory', 'copyDirectory', 'deleteDirectory',
            ]
        );

        $this->core->addSkills($agent_toolsets);

        foreach (['Browser', 'HttpFetcher', 'OfficeSuite'] as $core_skill) {
            $agent_toolsets = $this->utils->fetchToolset('modules/agent_toolsets', $core_skill);
            $this->core->addSkills($agent_toolsets);
        }

        $custom_skills = $this->utils->fetchToolset('skills');
        $this->core->addSkills($custom_skills);

        unset($reload, $agent_toolsets, $core_skill, $custom_skills);
    }

    /**
     * @return void
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function reload(): void
    {
        $this->init(true);
        $this->utils->procMgr->writeProc($this->utils->main_idx, self::CMD_RELOAD);
    }

    /**
     * @param bool $reload
     *
     * @return array
     * @throws \ReflectionException
     */
    public function getModels(bool $reload = false): array
    {
        if ($reload || [] === $this->utils->model_list) {
            $this->utils->model_list = $this->libOpenAI->listModels();
        }

        unset($reload);
        return $this->utils->model_list;
    }

    /**
     * @param string $worker
     * @param string $receiver
     * @param string $session_id
     * @param int    $proc_idx
     * @param string $system_prompt
     * @param string $command
     * @param array  $metadata
     *
     * @return bool
     * @throws \ReflectionException
     */
    public function talkTo(
        string $worker,
        string $receiver,
        string $session_id,
        int    $proc_idx,
        string $system_prompt,
        string $command,
        array  $metadata
    ): bool
    {
        $this->core->context->messageOnSend($session_id, false);

        if (WORKER_MAIN === $worker) {
            $main_pid = $this->utils->getChildWorker(WORKER_MAIN, WORKER_MAIN, 'worker_pid');

            if (is_int($main_pid)) {
                $this->libOpenAI->resumeStream($main_pid);
            }

            unset($main_pid);
        }

        try {
            $this->utils->procMgr->writeProc(
                $proc_idx,
                json_encode([
                    'cmd'        => $command,
                    'system'     => $system_prompt,
                    'history'    => $this->core->context->getHistory($session_id, $receiver),
                    'metadata'   => $metadata,
                    'llm_params' => $this->utils->getChildWorker($worker, $receiver, 'llm_params')
                ], JSON_FORMAT)
            );

            if (WORKER_MAIN === $worker) {
                $this->utils->setStatus($session_id, utils::STATUS_BUSY);
            }

            $this->core->context->messageOnSend($session_id, false);
        } catch (\Throwable $throwable) {
            if (WORKER_MAIN === $worker) {
                $this->utils->setStatus($session_id, utils::STATUS_IDLE);
            }

            $this->core->context->messageOnSend($session_id, true);
            $this->core->error->exceptionHandler($throwable, false, false);
            $this->utils->debug('Status: #' . $session_id . ' busy: ' . $throwable->getMessage(), 'trace');
            unset($throwable);
            return false;
        }

        unset($worker, $receiver, $session_id, $proc_idx, $system_prompt, $command, $metadata);
        return true;
    }

    /**
     * Abort current LLM request (for procWorker).
     *
     * @return void
     */
    public function abort(): void
    {
        $main_pid = $this->utils->getChildWorker(WORKER_MAIN, WORKER_MAIN, 'worker_pid');

        if (is_int($main_pid)) {
            $this->libOpenAI->abortStream($main_pid);
        }

        unset($main_pid);
    }

    /**
     * Main Worker process
     *
     * @return void
     * @throws \ReflectionException
     */
    public function AgentBee(): void
    {
        ini_set('memory_limit', $this->utils->agent_config['memory_limit'] ?? '4G');

        $this->initMain();

        $context   = context::new();
        $llm_tools = $this->core->llm_tools;

        $llm_tools['tools'] = $context->buildTools($llm_tools['tools']);

        while (true) {
            $job_line = fgets(STDIN);

            if (false === $job_line) {
                break;
            }

            $job_line = trim($job_line);

            if (self::CMD_RELOAD === $job_line) {
                $this->initMain(true);
                continue;
            }

            $talk_data = json_decode($job_line, true);

            if (!is_array($talk_data)) {
                continue;
            }

            $this->libOpenAI->setModelParams($talk_data['llm_params'] + $llm_tools);

            $this->processor->talk(
                $talk_data['metadata'],
                $talk_data['system'],
                $talk_data['history'],
                $this->libOpenAI
            );

            unset($job_line, $talk_data);
        }
    }

    /**
     * Child Worker process
     *
     * @return void
     * @throws \Exception
     */
    public function WorkerBee(): void
    {
        ini_set('memory_limit', $this->utils->agent_config['memory_limit'] ?? '4G');

        $this->initChild();

        $socket_id = '';
        $context   = context::new();
        $llm_tools = $this->core->llm_tools;

        $llm_tools['tools'] = $context->buildTools($llm_tools['tools']);

        while (true) {
            $line = fgets(STDIN);

            if (false === $line) {
                break;
            }

            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            $talk_data = json_decode($line, true);

            if (!is_array($talk_data)) {
                continue;
            }

            $this->libOpenAI->setModelParams($talk_data['llm_params'] + $llm_tools);

            switch ($talk_data['cmd']) {
                case 'start':
                    $socket_id = $talk_data['metadata']['socket_id'];

                case 'talk':
                    $this->processor->talk(
                        $talk_data['metadata'] + ['socket_id' => $socket_id],
                        $talk_data['system'],
                        $talk_data['history'],
                        $this->libOpenAI
                    );
                    break;
            }
        }
    }

    /**
     * @param bool  $reload
     * @param array $llm_params
     *
     * @return void
     * @throws \ReflectionException
     */
    private function init(bool $reload = false, array $llm_params = []): void
    {
        $this->core->initCore($reload);

        if ($reload) {
            Factory::destroy($this->libOpenAI);
        }

        $this->libOpenAI = libOpenAI::new(
            $llm_params['api_url'] ?? $this->utils->agent_config['agent_llm']['api_url'],
            $llm_params['api_key'] ?? $this->utils->agent_config['agent_llm']['api_key'],
            'AgentBee'
        );

        $this->libOpenAI->setOrgId($llm_params['org_id'] ?? $this->utils->agent_config['agent_llm']['org_id']);
        $this->libOpenAI->setTimeout($llm_params['timeout'] ?? $this->utils->agent_config['agent_llm']['timeout']);
        $this->libOpenAI->setApiModel($llm_params['model'] ?? $this->utils->agent_config['agent_llm']['model']);

        unset($reload, $llm_params);
    }
}