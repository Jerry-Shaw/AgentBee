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

namespace tools\WorkerBee;

use Nervsys\Core\Factory;

class go extends Factory
{
    /**
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @param string $name
     * @param string $role
     * @param string $prompt
     *
     * @return string[]
     */
    public function start(string $name, string $role, string $prompt): array
    {
        return ['status' => 'pending', 'action' => 'start', 'name' => $name, 'role' => $role, 'prompt' => $prompt];
    }

    /**
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @param int    $worker_id
     * @param string $message
     *
     * @return string[]
     */
    public function talk(int $worker_id, string $message): array
    {
        return ['status' => 'pending', 'action' => 'talk', 'worker_id' => $worker_id, 'message' => $message];
    }

    /**
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @param int $worker_id
     *
     * @return string[]
     */
    public function close(int $worker_id): array
    {
        return ['status' => 'pending', 'action' => 'close', 'worker_id' => $worker_id];
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