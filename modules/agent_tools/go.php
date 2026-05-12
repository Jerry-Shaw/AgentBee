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
     * @param string $program
     * @param array  $argv
     * @param string $path
     *
     * @return array|string[]
     * @throws \ReflectionException
     */
    public function exec(string $program, array $argv = [], string $path = ''): array
    {
        $result = [
            'output' => '',
            'error'  => ''
        ];

        if ('' === $path) {
            $path = $this->agent_config['tools']['workspace_path'];
        }

        $this->procMgr
            ->command($this->buildCommand($program, $argv))
            ->setWorkDir($path)
            ->run()
            ->awaitProc(
                function (string $output) use (&$result): void
                {
                    $result['output'] = $output;
                    unset($output);
                },
                function (string $output) use (&$result): void
                {
                    $result['error'] = $output;
                    unset($output);
                }
            );

        unset($program, $argv, $path);
        return $result;
    }

    /**
     * @param string $program
     * @param array  $argv
     *
     * @return array
     */
    private function buildCommand(string $program, array $argv): array
    {
        $internals = [
            'dir', 'echo', 'type', 'cd', 'del', 'erase',
            'copy', 'move', 'rename', 'ren', 'mkdir', 'md',
            'rmdir', 'rd', 'cls', 'color', 'title', 'pushd', 'popd'
        ];

        $command = PHP_OS_FAMILY === 'Windows' && in_array(strtolower($program), $internals, true)
            ? ['cmd.exe', '/c', $program, ...$argv]
            : [$program, ...$argv];

        unset($program, $argv, $internals);
        return $command;
    }
}