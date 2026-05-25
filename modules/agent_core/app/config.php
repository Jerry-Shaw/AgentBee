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
     * @throws \ReflectionException
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
     * @throws \ReflectionException
     */
    private function init(string $config_file): array
    {
        $config = [
            'agent_server' => [
                'host'          => '0.0.0.0',
                'port'          => 8686,
                'websocket'     => true,
                'ping_interval' => 30,
            ],
            'agent_llm'    => [
                'provider'     => 'agent_openai',
                'work_name'    => 'procWorker',
                'api_url'      => 'http://127.0.0.1:1234/v1',
                'api_key'      => 'sk-lm-J78sqMgX:IUMBn3qsotGyfViPMaRS',
                'model'        => 'qwen3.6-27b-neo-code',
                'org_id'       => '',
                'timeout'      => 7200,
                'keep_reasons' => false,
                'params'       => [
                    'seed'              => 42,
                    'max_tokens'        => 16384,
                    'temperature'       => 0.3,
                    'top_p'             => 0.85,
                    'frequency_penalty' => 0.3,
                    'presence_penalty'  => 0.2,
                    'stop'              => [],
                    'extra_body'        => [
                        'chat_template_kwargs' => [
                            'enable_thinking' => false,
                        ],
                    ],
                    'thinking'          => [
                        'type' => 'disabled',
                    ],
                ],
            ],
            'agent_task'   => [
                'provider' => 'agent_mem_db',
            ],
            'agent_memory' => [
                'provider'    => 'agent_mem_db',
                'max_history' => 20,
            ],
            'agent_tools'  => [
                'enabled'        => true,
                'in_sandbox'     => true,
                'workspace_path' => App::new()->root_path . DIRECTORY_SEPARATOR . 'workspace',
                'list'           => [
                    ['name' => 'agent_mem_db'],
                    ['name' => 'agent_tools'],
                    ['name' => 'agent_claw'],
                ],
            ],
            'memory_limit' => '4G',
            'debug'        => true,
        ];

        file_put_contents($config_file, json_encode($config, JSON_PRETTY));

        unset($config_file);
        return $config;
    }
}