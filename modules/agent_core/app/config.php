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

use Nervsys\Core\Factory;
use Nervsys\Core\Lib\App;

class config extends Factory
{
    public array $config = [];

    public string $config_dir = '';

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->config_dir = App::new()->root_path . DIRECTORY_SEPARATOR . 'config';
    }

    /**
     * @return array
     */
    public function get(): array
    {
        if (empty($this->config)) {
            $config_file = $this->config_dir . DIRECTORY_SEPARATOR . 'AgentBee.json';

            if (is_file($config_file)) {
                $config_data = json_decode(file_get_contents($config_file), true) ?? [];
            }

            if (empty($config_data)) {
                $config_data = $this->init($config_file);
            }

            $this->config = $config_data;
            unset($config_file, $config_data);
        }

        return $this->config;
    }

    /**
     * @param string $config_file
     *
     * @return array
     */
    private function init(string $config_file): array
    {
        $config = [
            'server'       => [
                'host'          => '0.0.0.0',
                'port'          => 8686,
                'websocket'     => true,
                'ping_interval' => 30
            ],
            'worker'       => [
                'count'          => 4,
                'max_executions' => 20000
            ],
            'llm'          => [
                'provider' => 'llm_openai',
                'api_url'  => 'http://127.0.0.1:1234/v1',
                'api_key'  => 'sk-lm-pkX06d0E:d1uXXht5QK5Iywk4D8Pr',
                'model'    => 'qwen3.6-35b-a3b',
                'org_id'   => '',
                'timeout'  => 300,
                'params'   => [
                    'max_tokens'        => 32768,
                    'temperature'       => 0.5,
                    'top_p'             => 1.0,
                    'frequency_penalty' => 0,
                    'presence_penalty'  => 0
                ]
            ],
            'memory'       => [
                'provider'     => 'agent_memory',
                'init_history' => 20
            ],
            'tools'        => [
                'enabled'        => true,
                'in_sandbox'     => true,
                'workspace_path' => App::new()->root_path . DIRECTORY_SEPARATOR . 'workspace',
                'list'           => [
                    [
                        'name' => 'agent_tools'
                    ],
                    [
                        'name' => 'agent_memory'
                    ]
                ]
            ],
            'logging'      => [
                'level' => 'debug',
                'file'  => 'logs/agentbee.log'
            ],
            'memory_limit' => '4G',
            'debug'        => true
        ];

        file_put_contents($config_file, json_encode($config, JSON_PRETTY));

        unset($config_file);
        return $config;
    }
}