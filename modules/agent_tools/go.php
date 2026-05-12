<?php

/**
 * Agent tools module for AgentBee core
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

namespace modules\agent_tools;

use modules\agent_core\app\config;
use modules\agent_core\core;
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\ProcMgr;

class go extends Factory
{
    use core;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->procMgr      = ProcMgr::new('socket');
        $this->agent_config = config::new()->get();
    }

    /**
     * @param string $command
     * @param array  $argv
     * @param string $workspace_path
     *
     * @return array|string[]
     * @throws \ReflectionException
     */
    public function exec(string $command, array $argv = [], string $workspace_path = ''): array
    {
        $command = str_getcsv($command, ' ', '"', '\\');

        if (!empty($argv)) {
            $command = array_merge($command, $argv);
        }

        if ('' === $workspace_path) {
            $workspace_path = $this->agent_config['tools']['workspace_path'];
        }

        $result = [
            'output' => '',
            'error'  => ''
        ];

        $this->procMgr
            ->command($command)
            ->setWorkDir($workspace_path)
            ->run()
            ->awaitProc(
                function (string $stdout) use (&$result): void
                {
                    $result['output'] = $stdout;
                },
                function (string $stdout) use (&$result): void
                {
                    $result['error'] = $stdout;
                }
            );

        return $result;
    }
}