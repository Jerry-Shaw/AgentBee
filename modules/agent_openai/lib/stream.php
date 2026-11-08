<?php

/**
 * Agent OpenAI module for AgentBee
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

namespace modules\agent_openai\lib;

use modules\agent_core\core;
use Nervsys\Core\Factory;

class stream extends Factory
{
    public core $core;

    public array $options = [];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core = core::new();
    }

    /**
     * @param string       $message_type
     * @param string       $payload_type
     * @param array        $payload_metadata
     * @param string|array $payload_content
     *
     * @return void
     */
    public function output(string $message_type, string $payload_type, array $payload_metadata, string|array $payload_content = ''): void
    {
        $stream_data = json_encode(
            [
                'type'    => $message_type,
                'payload' => [
                        'type' => $payload_type,
                        'data' => $payload_content
                    ] + $payload_metadata
            ], JSON_FORMAT
        );

        $buffer  = $stream_data . "\n";
        $length  = strlen($buffer);
        $written = 0;

        while ($written < $length) {
            $bytes = fwrite(STDOUT, substr($buffer, $written));

            if (false === $bytes) {
                break;
            }

            if (0 === $bytes) {
                usleep(20000);
                continue;
            }

            $written += $bytes;
        }

        fflush(STDOUT);

        unset($message_type, $payload_type, $payload_metadata, $payload_content, $stream_data, $buffer, $length, $written, $bytes);
    }
}