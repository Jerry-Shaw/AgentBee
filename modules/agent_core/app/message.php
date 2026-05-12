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

namespace modules\agent_core\app;

use modules\agent_core\core;
use Nervsys\Core\Factory;

class message extends Factory
{
    use core;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->initCore();
    }

    /**
     * @param int   $socket_id
     * @param array $data
     *
     * @return false[]
     */
    public function process_getHistory(int $socket_id, array $data): array
    {
        return [
            'llm' => false
        ];
    }

    /**
     * @param int   $socket_id
     * @param array $data
     *
     * @return array
     */
    public function process_text(int $socket_id, array $data): array
    {
        return [
            'llm'  => true,
            'text' => $data['message']
        ];
    }
}