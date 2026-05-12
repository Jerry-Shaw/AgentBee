<?php

/**
 * Agent Memory module for AgentBee
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

namespace modules\agent_memory;

use Nervsys\Core\Factory;
use Nervsys\Core\Lib\App;

class go extends Factory
{
    public array $memories = [];

    public string $memory_path = '';

    public string $default_men = '';

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->memory_path = App::new()->root_path . DIRECTORY_SEPARATOR . 'memory';
        $this->default_men = $this->memory_path . DIRECTORY_SEPARATOR . 'default.mem';

        if (!is_dir($this->memory_path)) {
            try {
                mkdir($this->memory_path, 0777, true);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param string $content
     *
     * @return void
     */
    public function setDefault(string $content): void
    {
        $memory = json_encode(['role' => 'system', 'content' => $content], JSON_FORMAT);
        $mem_fp = fopen($this->default_men, 'wb');

        fwrite($mem_fp, $memory . "\n");
        fclose($mem_fp);
    }

    /**
     * @param string $content
     *
     * @return void
     */
    public function addDefault(string $content): void
    {
        $memory = json_encode(['role' => 'system', 'content' => $content], JSON_FORMAT);
        $mem_fp = fopen($this->default_men, 'ab');

        fwrite($mem_fp, $memory . "\n");
        fclose($mem_fp);
    }

    /**
     * @return array
     */
    public function getDefault(): array
    {
        if (!is_file($this->default_men)) {
            return [];
        }

        $default = [];
        $mem_fp  = fopen($this->default_men, 'rb');

        while (!feof($mem_fp)) {
            $line = fgets($mem_fp);
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            $default[] = $line;
        }

        $default = $this->formatMemories($default);

        fclose($mem_fp);
        return $default;
    }

    /**
     * @param int $timestamp
     * @param int $offset
     * @param int $length
     *
     * @return array
     */
    public function get(int $timestamp, int $offset = 0, int $length = 100): array
    {
        $date = date('Ymd', $timestamp);

        $this->init($date);

        $result = array_filter($this->memories[$date], fn($key) => $key >= $timestamp, ARRAY_FILTER_USE_KEY);
        $result = array_values($result);
        $result = array_slice($result, $offset, $length);

        $result = $this->formatMemories($result);

        unset($timestamp, $offset, $length, $date);
        return $result;
    }

    /**
     * @param int   $timestamp
     * @param array $keywords
     * @param int   $offset
     * @param int   $length
     *
     * @return array
     */
    public function search(int $timestamp, array $keywords, int $offset = 0, int $length = 100): array
    {
        $date = date('Ymd', $timestamp);

        $this->init($date);

        $result = array_filter(
            $this->memories[$date],
            function (string $value) use ($keywords)
            {
                foreach ($keywords as $keyword) {
                    if (false !== stripos($value, $keyword)) {
                        return true;
                    }
                }

                return false;
            }
        );

        $result = array_values($result);
        $result = array_slice($result, $offset, $length);

        $result = $this->formatMemories($result);

        unset($timestamp, $offset, $length, $date);
        return $result;
    }

    /**
     * @param string $role
     * @param string $content
     *
     * @return void
     */
    public function add(string $role, string $content): void
    {
        $timestamp = time();
        $date      = date('Ymd');

        $this->init($date);

        $memory = json_encode(['role' => $role, 'content' => $content], JSON_FORMAT);

        $this->memories[$date][$timestamp] = $memory;

        $mem_file = $this->memory_path . DIRECTORY_SEPARATOR . $date . '.mem';
        $mem_fp   = fopen($mem_file, 'ab');

        fwrite($mem_fp, $timestamp . ':' . $memory . "\n");
        fclose($mem_fp);
    }

    /**
     * @param array $memories
     *
     * @return array
     */
    private function formatMemories(array $memories): array
    {
        foreach ($memories as $key => $memory) {
            $memories[$key] = json_decode($memory, true);
        }

        return $memories;
    }

    /**
     * @param int $date
     *
     * @return void
     */
    private function init(int $date): void
    {
        if (!isset($this->memories[$date])) {
            $mem_file = $this->memory_path . DIRECTORY_SEPARATOR . $date . '.mem';

            if (!is_file($mem_file)) {
                return;
            }

            $mem_fp = fopen($mem_file, 'rb');

            while (!feof($mem_fp)) {
                $line = fgets($mem_fp);

                if (!str_contains($line, ':')) {
                    continue;
                }

                [$timestamp, $memory] = explode(':', $line, 2);

                $this->memories[$date][$timestamp] = $memory;
            }

            fclose($mem_fp);
        }
    }
}