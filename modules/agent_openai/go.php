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
        $this->init($reload);

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
     * @return array
     * @throws \ReflectionException
     */
    public function getModels(): array
    {
        return $this->libOpenAI->listModels();
    }

    /**
     * @param string $type
     * @param string $system
     * @param string $receiver
     * @param int    $proc_idx
     * @param string $cmd
     * @param array  $metadata
     *
     * @return void
     * @throws \Exception
     */
    public function talkTo(string $type, string $system, string $receiver, int $proc_idx, string $cmd, array $metadata): void
    {
        if (WORKER_MAIN === $type) {
            $main_pid = $this->utils->getChildWorker(WORKER_MAIN, WORKER_MAIN, 'worker_pid');

            if (is_int($main_pid)) {
                $this->libOpenAI->resumeStream($main_pid);
            }

            unset($main_pid);
        }

        $this->utils->procMgr->writeProc(
            $proc_idx,
            json_encode([
                'cmd'        => $cmd,
                'system'     => $system,
                'history'    => $this->core->context->getHistory($receiver),
                'metadata'   => $metadata,
                'llm_params' => $this->utils->getChildWorker($type, $receiver, 'llm_params')
            ], JSON_FORMAT)
        );

        unset($type, $system, $receiver, $proc_idx, $cmd, $metadata);
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
     * @param bool $reload
     *
     * @return void
     * @throws \ReflectionException
     */
    private function init(bool $reload = false): void
    {
        $this->core->initCore($reload);

        if ($reload) {
            Factory::destroy($this->libOpenAI);
        }

        $this->libOpenAI = libOpenAI::new(
            $this->utils->agent_config['agent_llm']['api_url'],
            $this->utils->agent_config['agent_llm']['api_key'],
            'AgentBee'
        );

        $this->libOpenAI->setOrgId($this->utils->agent_config['agent_llm']['org_id']);
        $this->libOpenAI->setTimeout($this->utils->agent_config['agent_llm']['timeout']);
        $this->libOpenAI->setApiModel($this->utils->agent_config['agent_llm']['model']);
    }
}