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
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Ext\libOpenAI;

class openAi extends Factory
{
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
     * @param int   $socket_id
     * @param array $messages
     *
     * @return void
     * @throws \ReflectionException
     */
    public function chat(int $socket_id, array $messages): void
    {
        $content    = '';
        $stream_key = 'stream_' . uniqid('', true);

        $this->libOpenAI->addStreamCallback(
            $stream_key,
            function (int|string $key, array $data, bool $finished) use ($socket_id, &$content): void
            {
                if ($finished) {
                    echo json_encode([
                            'type'       => 'end',
                            'socket_id'  => $socket_id,
                            'full_reply' => $content
                        ], JSON_FORMAT) . "\n";

                    echo '[DONE]' . "\n";

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

                if ($text !== '') {
                    echo json_encode([
                            'type'      => $type,
                            'data'      => $text,
                            'socket_id' => $socket_id
                        ], JSON_FORMAT) . "\n";

                    flush();
                }
            });

        $this->libOpenAI->chat($messages, $this->config['llm']['model'], $this->config['llm']['params'], true);
        $this->libOpenAI->removeStreamCallback($stream_key);
    }

    public function onWorkerOutput(int $socket_id, string $output, object $agent_core): void
    {
        $data = json_decode($output, true);
        if (!$data) return;

        $socket_id = $data['socket_id'];

        if ($data['type'] === 'end') {
            // 更新会话历史
            if (!empty($data['full_reply'])) {
                $agent_core->sessions[$socket_id][] = ['role' => 'assistant', 'content' => $data['full_reply']];
            }
            $agent_core->socketMgr->sendMessage($socket_id, $agent_core->socketMgr->wsEncode(json_encode(['type' => 'end'])));
        } else {
            $agent_core->socketMgr->sendMessage($socket_id, $agent_core->socketMgr->wsEncode(json_encode([
                'type' => $data['type'],
                'data' => $data['data']
            ])));
        }
    }

    public function onWorkerError(int $socket_id, string $output, object $agent_core): void
    {
        var_dump($output);
    }
}