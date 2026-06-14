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
use modules\agent_core\lib\utils;
use modules\agent_openai\lib\procWorker;
use Nervsys\Core\Factory;
use Nervsys\Ext\libOpenAI;

class go extends Factory
{
    const CMD_RELOAD = '__RELOAD__';

    const WORKER_MAIN  = 'WorkerMain';
    const WORKER_CHILD = 'WorkerBee';

    public core  $core;
    public utils $utils;

    public libOpenAI $libOpenAI;

    public procWorker $procWorker;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core       = core::new();
        $this->utils      = utils::new();
        $this->procWorker = procWorker::new();

        $this->init();
    }

    /**
     * @param bool $reload
     *
     * @return void
     * @throws \ReflectionException
     */
    public function init(bool $reload = false): void
    {
        $this->core->initCore($reload);

        if ($reload) {
            Factory::destroy($this->libOpenAI);
        }

        $this->libOpenAI = libOpenAI::new(
            $this->core->agent_config['agent_llm']['api_url'],
            $this->core->agent_config['agent_llm']['api_key'],
            '[DONE]'
        );

        $this->libOpenAI->setOrgId($this->core->agent_config['agent_llm']['org_id']);
        $this->libOpenAI->setTimeout($this->core->agent_config['agent_llm']['timeout']);
        $this->libOpenAI->setApiModel($this->core->agent_config['agent_llm']['model']);
    }

    /**
     * @return void
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function reload(): void
    {
        $this->init(true);
        $this->setShmop($this->core->procMgr->getPid($this->core->openai_idx));
        $this->core->procMgr->writeProc($this->core->openai_idx, self::CMD_RELOAD);
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
     * @param int $worker_pid
     *
     * @return $this
     */
    public function setShmop(int $worker_pid): static
    {
        $shm_key = crc32($worker_pid) & 0x7FFFFFFF;
        $this->libOpenAI->openShmop($shm_key);

        unset($worker_pid, $shm_key);
        return $this;
    }

    /**
     * Start a chat session.
     *
     * @param string $socket_id
     * @param array  $message_metadata
     * @param array  $session_history
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function chat(string $socket_id, array $message_metadata, array $session_history): void
    {
        $message = [
            'socket_id' => $socket_id,
            'msg_meta'  => $message_metadata,
            'history'   => $session_history,
        ];

        $this->libOpenAI->resumeStream();
        $this->core->procMgr->writeProc($this->core->openai_idx, json_encode($message));

        unset($socket_id, $message_metadata, $session_history);
    }

    /**
     * Abort current LLM request (for procWorker).
     *
     * @param string $socket_id
     *
     * @return void
     * @throws \Exception
     */
    public function abort(string $socket_id): void
    {
        $this->libOpenAI->abortStream();
        unset($socket_id);
    }

    /**
     * Main Worker process
     *
     * @return void
     * @throws \ReflectionException
     */
    public function WorkerMain(): void
    {
        ini_set('memory_limit', $this->core->agent_config['memory_limit'] ?? '4G');

        $agent_skills = $this->utils->fetchSkills('modules/agent_skills');
        $this->core->addSkills($agent_skills);
        $custom_skills = $this->utils->fetchSkills('skills');
        $this->core->addSkills($custom_skills);

        $this->libOpenAI->setModelParams($this->core->getLLMParams());

        $this->setShmop(getmypid());

        while (true) {
            $job_line = fgets(STDIN);

            if (false === $job_line) {
                break;
            }

            $job_line = trim($job_line);

            if (self::CMD_RELOAD === $job_line) {
                $this->init(true);
                $this->setShmop(getmypid());
                continue;
            }

            $job_data = json_decode($job_line, true);

            if (!is_array($job_data)) {
                continue;
            }

            $this->procWorker->talk(
                $job_data['socket_id'],
                $job_data['msg_meta'],
                $job_data['history'],
                $this->libOpenAI
            );

            unset($job_line, $job_data);
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
        ini_set('memory_limit', $this->core->agent_config['memory_limit'] ?? '4G');

        $core_skills = ['System', 'OfficeSuite', 'WebCrawler'];

        foreach ($core_skills as $core_skill) {
            $agent_skills = $this->utils->fetchSkills('modules/agent_skills', $core_skill);
            $this->core->addSkills($agent_skills);
        }

        $custom_skills = $this->utils->fetchSkills('skills');
        $this->core->addSkills($custom_skills);

        $this->libOpenAI->setModelParams($this->core->getLLMParams());

        $socket_id = '';

        $this->setShmop(getmypid());

        while (true) {
            $line = fgets(STDIN);

            if (false === $line) {
                break;
            }

            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            $data = json_decode($line, true);

            if (!is_array($data)) {
                continue;
            }

            switch ($data['cmd']) {
                case 'start':
                    $socket_id = $data['socket_id'];

                case 'talk':
                    $worker_history = $data['history'];
                    $message_meta   = $data['msg_meta'] + ['talk_count' => count($worker_history), 'socket_id' => $socket_id];

                    $this->procWorker->talk(
                        $socket_id,
                        $message_meta,
                        $worker_history,
                        $this->libOpenAI
                    );
                    break;

                case 'close':
                    break 2;
            }
        }
    }
}