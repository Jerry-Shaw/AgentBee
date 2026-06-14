<?php

/**
 * Agent Worker module for AgentBee
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

namespace modules\agent_skills\WorkerBee;

use Nervsys\Core\Factory;

class go extends Factory
{
    /**
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @param string $worker_name
     * @param string $worker_role
     * @param string $init_prompt
     *
     * @return string[]
     */
    public function start(string $worker_name, string $worker_role, string $init_prompt): array
    {
        return ['status' => 'pending', 'action' => 'start', 'worker_name' => $worker_name, 'worker_role' => $worker_role, 'init_prompt' => $init_prompt];
    }

    /**
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @param string $worker_name
     * @param string $message
     *
     * @return string[]
     */
    public function talk(string $worker_name, string $message): array
    {
        return ['status' => 'pending', 'action' => 'talk', 'worker_name' => $worker_name, 'message' => $message];
    }

    /**
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @param string $worker_name
     *
     * @return string[]
     */
    public function close(string $worker_name): array
    {
        return ['status' => 'pending', 'action' => 'close', 'worker_name' => $worker_name];
    }

    /**
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @return string[]
     */
    public function list(): array
    {
        return ['status' => 'pending', 'action' => 'list'];
    }
}