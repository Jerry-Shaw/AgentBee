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
use Nervsys\Core\Mgr\OSMgr;
use Nervsys\Ext\libCrypt;
use Nervsys\Ext\libKeygen;

class config extends Factory
{
    public libCrypt $libCrypt;

    public array $config = [];

    public string $config_dir  = '';
    public string $conf_system = '';

    public string $hardware_hash = '';

    /**
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function __construct()
    {
        $this->libCrypt = libCrypt::new()->bindKeygen(libKeygen::new());

        $this->config_dir    = App::new()->root_path . DIRECTORY_SEPARATOR . 'config';
        $this->conf_system   = $this->config_dir . DIRECTORY_SEPARATOR . 'AgentBee.json';
        $this->hardware_hash = OSMgr::new()->getHwHash();
    }

    /**
     * @param bool $decrypt
     * @param bool $reload
     *
     * @return array
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function get(bool $decrypt = true, bool $reload = false): array
    {
        if (!$reload && !empty($this->config)) {
            $config_data = $this->config;
        } else {
            if (is_file($this->conf_system)) {
                // Read from file
                $config_data = json_decode(file_get_contents($this->conf_system), true) ?? [];
            }

            if (empty($config_data)) {
                // Read from default
                $config_data = $this->getDefault();
            }

            $config_data['agent_llm']['hw_hash'] ??= '';

            if ('' !== $config_data['agent_llm']['hw_hash']) {
                $config_data['agent_llm']['api_key'] = $this->decryptKey($config_data['agent_llm']['api_key'], $config_data['agent_llm']['hw_hash']);
            } else {
                $to_config = $config_data;
                $key_data  = $this->encryptKey($config_data['agent_llm']['api_key']);

                $to_config['agent_llm']['api_key']   = $key_data['api_key'];
                $to_config['agent_llm']['hw_hash']   = $key_data['hw_hash'];
                $config_data['agent_llm']['hw_hash'] = $key_data['hw_hash'];

                file_put_contents($this->conf_system, json_encode($to_config, JSON_PRETTY));
                unset($to_config, $key_data);
            }

            $this->config = $config_data;
        }

        if (!$decrypt) {
            $key_data = $this->encryptKey($this->config['agent_llm']['api_key']);

            $config_data['agent_llm']['api_key'] = $key_data['api_key'];
            $config_data['agent_llm']['hw_hash'] = $key_data['hw_hash'];

            unset($key_data);
        }

        unset($decrypt);
        return $config_data;
    }

    /**
     * @param array $config_data
     *
     * @return int
     * @throws \Exception
     */
    public function save(array $config_data): int
    {
        $config_data['agent_llm']['api_key'] ??= '';
        $config_data['agent_llm']['hw_hash'] ??= '';

        if ('' !== $config_data['agent_llm']['api_key'] && '' !== $config_data['agent_llm']['hw_hash']) {
            $api_key = $this->decryptKey($config_data['agent_llm']['api_key'], $config_data['agent_llm']['hw_hash']);

            if ($api_key === $config_data['agent_llm']['api_key']) {
                $key_data = $this->encryptKey($config_data['agent_llm']['api_key']);

                $config_data['agent_llm']['api_key'] = $key_data['api_key'];
                $config_data['agent_llm']['hw_hash'] = $key_data['hw_hash'];
            }
        } elseif ('' !== $config_data['agent_llm']['api_key']) {
            $key_data = $this->encryptKey($config_data['agent_llm']['api_key']);

            $config_data['agent_llm']['api_key'] = $key_data['api_key'];
            $config_data['agent_llm']['hw_hash'] = $key_data['hw_hash'];
        }

        $config_json = json_encode($config_data, JSON_PRETTY);
        $save_bytes  = file_put_contents($this->conf_system, $config_json);

        unset($config_data, $api_key, $key_data, $config_json);
        return (int)$save_bytes;
    }

    /**
     * @return array
     * @throws \ReflectionException
     */
    public function getDefault(): array
    {
        return [
            'agent_server'   => [
                'host'          => '127.0.0.1',
                'port'          => 8686,
                'ping_interval' => 60,
            ],
            'agent_llm'      => [
                'api_url'      => 'http://127.0.0.1:1234/v1',
                'api_key'      => 'sk-lm-ru6XiZDE:WImxJO82hxm5L76fNcaK',
                'model'        => 'qwen3.6-35b-a3b-apex',
                'org_id'       => '',
                'hw_hash'      => '',
                'provider'     => 'modules/agent_openai',
                'main_worker'  => 'mainWorker',
                'child_worker' => 'beeWorker',
                'timeout'      => 7200,
                'keep_reasons' => true,
                'params'       => [
                    'max_tokens'           => 131072,
                    'temperature'          => 0.8,
                    'min_p'                => 0,
                    'top_p'                => 0.95,
                    'top_k'                => 40,
                    'frequency_penalty'    => 0,
                    'presence_penalty'     => 1,
                    'repetition_penalty'   => 1,
                    'enable_thinking'      => false,
                    'stop'                 => [
                        '<|im_end|>',
                        '<|endoftext|>',
                    ],
                    'chat_template_kwargs' => [
                        'enable_thinking' => false,
                    ],
                    'extra_body'           => [
                        'enable_thinking' => false,
                    ],
                    'thinking'             => [
                        'type' => 'disabled',
                    ],
                ],
            ],
            'agent_task'     => [
                'provider' => 'tools/Memory',
            ],
            'agent_memory'   => [
                'provider'    => 'tools/Memory',
                'max_history' => 50,
            ],
            'agent_worker'   => [
                'enabled'     => true,
                'max_workers' => 5,
            ],
            'workspace_path' => App::new()->root_path . DIRECTORY_SEPARATOR . 'workspace',
            'sandbox_mode'   => false,
            'memory_limit'   => '4G',
            'agent_debug'    => 'trace',
            'socket_debug'   => false,
        ];
    }

    /**
     * @param string $api_key
     *
     * @return array
     * @throws \Exception
     */
    private function encryptKey(string $api_key): array
    {
        $key_hash = $this->libCrypt->encrypt($api_key, $this->hardware_hash);
        $key_sign = $this->libCrypt->sign($key_hash);
        $hw_hash  = hash('sha256', $this->hardware_hash . $key_sign);

        $result = [
            'api_key' => $key_sign,
            'hw_hash' => $hw_hash,
        ];

        unset($api_key, $key_hash, $key_sign, $hw_hash);
        return $result;
    }

    /**
     * @param string $api_key
     * @param string $hw_hash
     *
     * @return string
     * @throws \Exception
     */
    private function decryptKey(string $api_key, string $hw_hash): string
    {
        if (hash('sha256', $this->hardware_hash . $api_key) === $hw_hash) {
            $key_hash = $this->libCrypt->verify($api_key);
            $api_key  = $this->libCrypt->decrypt($key_hash, $this->hardware_hash);
        }

        unset($hw_hash, $key_hash);
        return $api_key;
    }
}