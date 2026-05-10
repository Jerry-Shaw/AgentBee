<?php

/**
 * llm_openai module for AgentBee
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

namespace modules\llm_openai;

use modules\agent_core\app\config;
use modules\agent_core\processor;
use Nervsys\Core\Factory;
use Nervsys\Ext\libOpenAI;

class go extends Factory
{
    const END_MARKER = '[DONE]';

    public libOpenAI $libOpenAI;

    public array $config = [];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->config = config::new()->get();

        $this->libOpenAI = libOpenAI::new(
            $this->config['llm']['api_url'],
            $this->config['llm']['api_key'],
            $this->config['llm']['org_id']
        );

        $this->libOpenAI->setApiModel($this->config['llm']['model']);
        $this->libOpenAI->setModelParams($this->config['llm']['params']);
    }

    /**
     * @param array $messages
     *
     * @return void
     * @throws \ReflectionException
     */
    public function chat(array $messages): void
    {
        $content    = '';
        $stream_key = 'stream_' . uniqid('', true);

        $this->libOpenAI->addStreamCallback(
            $stream_key,
            function (int|string $key, array $data, bool $finished) use (&$content): void
            {
                if ($finished) {
                    echo json_encode([
                            'type' => 'end',
                            'data' => $content
                        ], JSON_FORMAT) . "\n";

                    echo self::END_MARKER . "\n";

                    flush();
                    return;
                }

                if (isset($data['choices'][0]['delta']['content'])) {
                    $text    = $data['choices'][0]['delta']['content'];
                    $type    = 'content';
                    $content .= $text;
                } elseif (isset($data['choices'][0]['delta']['reasoning_content'])) {
                    $text = $data['choices'][0]['delta']['reasoning_content'];
                    $type = 'think';
                } else {
                    return;
                }

                if ('' !== $text) {
                    echo json_encode([
                            'type' => $type,
                            'data' => $text
                        ], JSON_FORMAT) . "\n";

                    flush();
                }
            });

        $this->libOpenAI->chat($messages, $this->config['llm']['model'], $this->config['llm']['params'], true);
        $this->libOpenAI->removeStreamCallback($stream_key);
    }

    /**
     * @param int       $socket_id
     * @param string    $output
     * @param processor $processor
     *
     * @return void
     * @throws \ReflectionException
     */
    public function onWorkerOutput(int $socket_id, string $output, processor $processor): void
    {
        $data = json_decode($output, true);

        if (is_null($data)) {
            return;
        }

        if ($data['type'] === 'end') {
            if (isset($data['data'])) {
                $processor->addMemory(time(), 'assistant', $data['data']);
            }

            $processor->sendMessage($socket_id, json_encode(['type' => 'end'], JSON_FORMAT));
        } else {
            $processor->sendMessage($socket_id, json_encode([
                'type' => $data['type'],
                'data' => $data['data']
            ], JSON_FORMAT));
        }
    }

    /**
     * @param int    $socket_id
     * @param string $output
     * @param object $agent_core
     *
     * @return void
     */
    public function onWorkerError(int $socket_id, string $output, object $agent_core): void
    {
    }
}