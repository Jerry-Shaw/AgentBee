<?php

namespace modules\agent_core\app;

use Nervsys\Core\Factory;
use Nervsys\Core\Lib\App;

class config extends Factory
{
    public array $config = [];

    /**
     * @return array
     * @throws \ReflectionException
     */
    public function get(): array
    {
        if (empty($this->config)) {
            $config_file = App::new()->root_path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'AgentBee.json';

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
                'ping_interval' => 30,
            ],
            'worker'       => [
                'count'          => 4,
                'max_executions' => 20000,
            ],
            'llm'          => [
                'api_url' => 'http://127.0.0.1:1234/v1',
                'api_key' => '',
                'model'   => 'qwen3.6-35b-a3b',
                'org_id'  => '',
                'timeout' => 300,
                'params'  => [
                    'temperature'       => 0.5,
                    'max_tokens'        => 32768,
                    'top_p'             => 1.0,
                    'frequency_penalty' => 0,
                    'presence_penalty'  => 0
                ],
            ],
            'conversation' => [
                'max_history_messages' => 20,
            ],
            'memory_limit' => '4G',
            'debug'        => true,
        ];

        file_put_contents($config_file, json_encode($config, JSON_PRETTY));

        unset($config_file);
        return $config;
    }
}