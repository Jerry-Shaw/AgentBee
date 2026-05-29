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

namespace modules\agent_core\lib;

use Nervsys\Core\Factory;

class utils extends Factory
{
    public array $agent_config;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->agent_config = config::new()->get();
    }

    /**
     * @param string $string
     * @param string $level Debug level (none, trace, debug)
     *
     * @return void
     */
    public function debug(string $string, string $level = 'trace'): void
    {
        $debug_level = strtolower($this->agent_config['agent_debug']);

        if ('none' === $debug_level) {
            return;
        }

        if ('trace' === $debug_level && 'debug' === strtolower($level)) {
            return;
        }

        echo '[' . date('Y-m-d H:i:s') . '][' . strtoupper($level) . '] ' . $string . PHP_EOL;
        unset($string, $level, $debug_level);
    }
}