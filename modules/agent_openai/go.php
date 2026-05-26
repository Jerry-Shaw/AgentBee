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
use modules\agent_openai\app\procWorker;
use Nervsys\Core\Factory;
use Nervsys\Ext\libOpenAI;

class go extends Factory
{
    public core   $core;
    public \Shmop $shmop;

    public libOpenAI $libOpenAI;

    public procWorker $procWorker;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core       = core::new();
        $this->procWorker = procWorker::new();

        $this->core->initCore();
        $this->core->initTools();

        $this->libOpenAI = libOpenAI::new(
            $this->core->agent_config['agent_llm']['api_url'],
            $this->core->agent_config['agent_llm']['api_key'],
            '[DONE]'
        );

        $this->libOpenAI->setOrgId($this->core->agent_config['agent_llm']['org_id']);
        $this->libOpenAI->setTimeout($this->core->agent_config['agent_llm']['timeout']);
        $this->libOpenAI->setApiModel($this->core->agent_config['agent_llm']['model']);
        $this->libOpenAI->setModelParams($this->core->getLLMParams());
    }

    /**
     * @param int $worker_pid
     *
     * @return $this
     */
    public function buildShmop(int $worker_pid): static
    {
        $shm_key = crc32($worker_pid) & 0x7FFFFFFF;
        $shmop   = shmop_open($shm_key, "c", 0644, 1);

        if (false === $shmop) {
            throw new \RuntimeException('Failed to create shared memory');
        } else {
            $this->shmop = $shmop;
        }

        $this->libOpenAI->setShmop($shmop);

        unset($worker_pid, $shm_key, $shmop);
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
        $task = [
            'socket_id' => $socket_id,
            'msg_meta'  => $message_metadata,
            'history'   => $session_history,
        ];

        $this->core->procMgr->writeProc(core::PROC_IDX_OPENAI, json_encode($task));

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
        shmop_write($this->shmop, "\x01", 0);
        unset($socket_id);
    }

    /**
     * Worker process main loop.
     *
     * @return void
     * @throws \ReflectionException
     */
    public function procWorker(): void
    {
        ini_set('memory_limit', $this->core->agent_config['memory_limit'] ?? '4G');

        $this->buildShmop(getmypid());
        $this->procWorker = procWorker::new();

        while (true) {
            $job_line = fgets(STDIN);

            if (false === $job_line) {
                break;
            }

            $job_line = trim($job_line);
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
}