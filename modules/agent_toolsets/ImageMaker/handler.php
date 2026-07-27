<?php

/**
 * Agent ImageMaker tools for AgentBee
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

namespace modules\agent_toolsets\ImageMaker;

use modules\agent_core\go as agent_core;
use Nervsys\Core\Factory;
use Nervsys\Ext\libOpenAI;

class handler extends Factory
{
    private array $config = [];

    /**
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \Exception
     */
    private function loadConfig(agent_core $agent_core): array
    {
        if (!empty($this->config)) {
            return $this->config;
        }

        $config_path = $agent_core->utils->config->config_dir . DIRECTORY_SEPARATOR . 'ImageMaker.json';

        if (!is_file($config_path)) {
            throw new \Exception('配置文件不存在：' . $config_path);
        }

        $config = json_decode(file_get_contents($config_path), true);

        if (!is_array($config)) {
            throw new \Exception('配置文件JSON格式错误：' . $config_path);
        }

        foreach (['model_id', 'base_url', 'api_key'] as $value) {
            if (!isset($config[$value]) || '' === $config[$value]) {
                throw new \Exception('配置项[' . $value . ']缺失或为空。请检查文件：' . $config_path);
            }
        }

        $this->config = $config;

        unset($agent_core, $config_path);
        return $config;
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \Random\RandomException
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function create(array $payload_data, agent_core $agent_core): array
    {
        $config = $this->loadConfig($agent_core);
        $openai = libOpenAI::new($config['base_url'], $config['api_key'], '', '/ImageCreator');
        $result = $openai->createImage(
            $payload_data['prompt'],
            $config['model_id'],
            [
                'n'             => $payload_data['n'],
                'size'          => $payload_data['size'],
                'quality'       => $payload_data['quality'],
                'background'    => $payload_data['background'],
                'style'         => $payload_data['style'],
                'output_format' => $payload_data['output_format'],
                'moderation'    => $payload_data['moderation'],
            ]
        );

        $result = $this->handleResponse($agent_core, $payload_data['socket_id'], $payload_data['process_name'], $result);

        unset($payload_data, $agent_core, $config, $openai);
        return $result;
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \Random\RandomException
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function edit(array $payload_data, agent_core $agent_core): array
    {
        $config = $this->loadConfig($agent_core);
        $openai = libOpenAI::new($config['base_url'], $config['api_key'], '', '/ImageEditor');
        $result = $openai->editImage(
            $payload_data['image_path'],
            $payload_data['prompt'],
            $config['default_model'],
            $payload_data['mask_path'],
            [
                'n'             => $payload_data['n'],
                'size'          => $payload_data['size'],
                'quality'       => $payload_data['quality'],
                'background'    => $payload_data['background'],
                'style'         => $payload_data['style'],
                'output_format' => $payload_data['output_format'],
                'moderation'    => $payload_data['moderation'],
            ]
        );

        $result = $this->handleResponse($agent_core, $payload_data['socket_id'], $payload_data['process_name'], $result);

        unset($payload_data, $agent_core, $config, $openai);
        return $result;
    }

    /**
     * @param agent_core $agent_core
     * @param string     $socket_id
     * @param string     $process_name
     * @param array      $response
     *
     * @return array
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    private function handleResponse(agent_core $agent_core, string $socket_id, string $process_name, array $response): array
    {
        if (isset($response['data']) && true === $response['success']) {
            $message_id = hash('md5', uniqid(microtime(), true));
            $save_path  = rtrim($agent_core->utils->agent_config['workspace_path'], '\\/') . DIRECTORY_SEPARATOR . substr($message_id, 0, 8) . DIRECTORY_SEPARATOR;

            if (!is_dir($save_path)) {
                try {
                    mkdir($save_path, 0777, true);
                } catch (\Throwable) {
                }
            }

            foreach ($response['data'] as $value) {
                $value['output_format'] = $response['output_format'];
                $this->sendMessage($agent_core, $socket_id, $message_id, $process_name, $value);

                $image_binary = base64_decode($value['b64_json']);
                if (false !== $image_binary) {
                    if ('jpeg' === $value['output_format']) {
                        $value['output_format'] = 'jpg';
                    }

                    file_put_contents($save_path . substr(hash('md5', uniqid(microtime(), true)), 0, 8) . '.' . $value['output_format'], $image_binary);
                }

                unset($image_binary);
            }

            $response = [
                'status'  => 'success',
                'message' => '图片已生成，保存路径：' . $save_path
            ];

            unset($message_id, $save_path, $value);
        } elseif (isset($response['error'])) {
            $response['status'] = 'error';
        } else {
            $response['status'] = 'error';
            $response['error']  = '图片生成失败，原因未知。告知用户，禁止重试。';
        }

        unset($agent_core, $socket_id, $process_name);
        return $response;
    }

    /**
     * @param agent_core $agent_core
     * @param string     $socket_id
     * @param string     $message_id
     * @param string     $process_name
     * @param array      $image_data
     *
     * @return void
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    private function sendMessage(agent_core $agent_core, string $socket_id, string $message_id, string $process_name, array $image_data): void
    {
        $message = json_encode(
            $agent_core->utils->getMessageMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                $process_name,
                0,
                $message_id
            ) + [
                'type'   => 'image',
                'data'   => 'data:image/' . $image_data['output_format'] . ';base64,' . $image_data['b64_json'],
                'prompt' => $image_data['revised_prompt']
            ], JSON_FORMAT);

        $agent_core->core->sendMessage($socket_id, $message);
        unset($agent_core, $socket_id, $message_id, $process_name, $image_data, $message);
    }
}