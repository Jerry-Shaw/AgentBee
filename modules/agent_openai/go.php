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
use modules\agent_openai\app\fiberStack;
use modules\agent_openai\app\procWorker;
use Nervsys\Core\Factory;
use Nervsys\Ext\libOpenAI;

class go extends Factory
{
    public core $core;

    public libOpenAI $libOpenAI;

    /** @var procWorker|fiberStack */
    public fiberStack|procWorker $handler;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core = core::new();

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
     * Set work type (procWorker or fiberStack).
     *
     * @param string $work_type
     *
     * @return void
     * @throws \ReflectionException
     */
    public function setWorkType(string $work_type): void
    {
        $this->handler = ('procWorker' === $work_type) ? procWorker::new() : fiberStack::new();
        unset($work_type);
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
        $this->handler->chat($socket_id, $message_metadata, $session_history, $this->libOpenAI);
        unset($socket_id, $message_metadata, $session_history);
    }

    /**
     * Interrupt current LLM request (for procWorker or fiberStack).
     *
     * @param string $socket_id
     *
     * @return void
     */
    public function interrupt(string $socket_id): void
    {
        $this->handler->interrupt($socket_id);
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
        $this->handler = procWorker::new();

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

            $this->handler->talk(
                $job_data['socket_id'],
                $job_data['msg_meta'],
                $job_data['history'],
                $this->libOpenAI
            );

            unset($job_line, $job_data);
        }
    }
}