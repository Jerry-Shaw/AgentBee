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
use Nervsys\Core\Lib\App;

class config extends Factory
{
    public array $config = [];

    public string $config_dir  = '';
    public string $conf_system = '';

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->config_dir  = App::new()->root_path . DIRECTORY_SEPARATOR . 'config';
        $this->conf_system = $this->config_dir . DIRECTORY_SEPARATOR . 'AgentBee.json';
    }

    /**
     * @return array
     * @throws \ReflectionException
     */
    public function get(): array
    {
        if (empty($this->config)) {
            if (is_file($this->conf_system)) {
                $config_data = json_decode(file_get_contents($this->conf_system), true) ?? [];
            }

            if (empty($config_data)) {
                $config_data = $this->getDefault();
                file_put_contents($this->conf_system, json_encode($config_data, JSON_PRETTY));
            }

            $this->config = $config_data;
            unset($config_data);
        }

        return $this->config;
    }

    /**
     * @param string $config_json
     *
     * @return int
     */
    public function save(string $config_json): int
    {
        $save_bytes = file_put_contents($this->conf_system, $config_json);

        unset($config_json);
        return (int)$save_bytes;
    }

    /**
     * @return array
     * @throws \ReflectionException
     */
    public function getDefault(): array
    {
        return [
            'agent_server' => [
                'host'          => '127.0.0.1',
                'port'          => 8686,
                'ping_interval' => 60,
            ],
            'agent_llm'    => [
                'api_url'      => 'http://127.0.0.1:1234/v1',
                'api_key'      => 'sk-lm-J78sqMgX:IUMBn3qsotGyfViPMaRS',
                'model'        => 'gemma-4-26b-a4b-it-apex',
                'org_id'       => '',
                'provider'     => 'agent_openai',
                'worker_name'  => 'procWorker',
                'timeout'      => 7200,
                'keep_reasons' => true,
                'params'       => [
                    'max_tokens'        => 16384,
                    'temperature'       => 0.8,
                    'min_p'             => 0.05,
                    'top_p'             => 0.9,
                    'frequency_penalty' => 0,
                    'presence_penalty'  => 0.5,
                    'stop'              => [],
                    'extra_body'        => [
                        'thinking'             => false,
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
                'provider' => 'agent_memory',
            ],
            'agent_memory' => [
                'provider'    => 'agent_memory',
                'max_history' => 50,
            ],
            'agent_tools'  => [
                'enabled'        => true,
                'in_sandbox'     => true,
                'workspace_path' => App::new()->root_path . DIRECTORY_SEPARATOR . 'workspace',
                'list'           => [
                    ['name' => 'agent_memory'],
                    ['name' => 'agent_tools'],
                    ['name' => 'agent_claw'],
                ],
            ],
            'memory_limit' => '4G',
            'agent_debug'  => 'trace',
            'socket_debug' => false,
        ];
    }
}