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
use Nervsys\Core\Lib\Error;
use Nervsys\Ext\libFileIO;

class utils extends Factory
{
    public App       $app;
    public libFileIO $libFileIO;

    public string $session_id;
    public array  $agent_config;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->app       = App::new();
        $this->libFileIO = libFileIO::new();

        $this->session_id   = hash('md5', uniqid('', true));
        $this->agent_config = config::new()->get();
    }

    /**
     * @param string $sender
     * @param string $worker_name
     * @param string $worker_role
     * @param string $message_from
     * @param int    $is_sub_talk
     *
     * @return array
     */
    public function getMessageMarker(string $sender, string $worker_name, string $worker_role, string $message_from, int $is_sub_talk): array
    {
        $marker = [
            'sender'      => $sender,
            'isSubTalk'   => $is_sub_talk,
            'workerName'  => $worker_name,
            'workerRole'  => $worker_role,
            'sessionId'   => $this->session_id,
            'messageId'   => hash('md5', uniqid(microtime(), true)),
            'messageFrom' => $message_from
        ];

        unset($sender, $worker_name, $worker_role, $message_from, $is_sub_talk);
        return $marker;
    }

    /**
     * @param string $dirname
     * @param string $module
     * @param array  $tool_names
     *
     * @return array
     * @throws \ReflectionException
     */
    public function fetchSkills(string $dirname, string $module = '', array $tool_names = []): array
    {
        $skills   = [];
        $dir_list = [];
        $dirname  = strtr($dirname, ['\\' => DIRECTORY_SEPARATOR, '/' => DIRECTORY_SEPARATOR]);

        if ('' !== $module) {
            $dir_list[] = [
                'name'          => $module,
                'absolute_path' => $this->app->root_path . DIRECTORY_SEPARATOR . $dirname . DIRECTORY_SEPARATOR . $module
            ];
        } else {
            $contents = $this->libFileIO->getDirContents($this->app->root_path . DIRECTORY_SEPARATOR . $dirname);

            foreach ($contents as $item) {
                if ($item['is_file']) {
                    continue;
                }

                $dir_list[] = [
                    'name'          => $item['name'],
                    'absolute_path' => $item['absolute_path']
                ];
            }

            unset($contents);
        }

        foreach ($dir_list as $path) {
            $json_file = $path['absolute_path'] . DIRECTORY_SEPARATOR . 'module.json';
            $meta_file = $path['absolute_path'] . DIRECTORY_SEPARATOR . 'skills.php';

            if (!is_file($json_file) || !is_file($meta_file)) {
                continue;
            }

            $json_data = json_decode(file_get_contents($json_file), true);

            if (
                !is_array($json_data)
                || !isset($json_data['entry'])
                || !isset($json_data['name'])
                || $json_data['name'] !== $path['name']
                || isset($this->agent_tools[$json_data['name']])
            ) {
                continue;
            }

            $namespace = '\\' . $dirname . '\\' . $json_data['name'];

            try {
                $metadata = ($namespace . '\\skills')::META;

                if (!empty($tool_names)) {
                    $metadata = array_filter(
                        $metadata,
                        function (array $item) use ($tool_names): bool
                        {
                            return in_array($item['function']['name'], $tool_names);
                        }
                    );
                }

                foreach ($metadata as $index => $item) {
                    $metadata[$index]['function']['name'] = $json_data['name'] . '/' . $item['function']['name'];
                }

                $skills[] = [
                    'class' => $namespace . '\\' . strstr($json_data['entry'], '.', true),
                    'name'  => $json_data['name'],
                    'meta'  => array_values($metadata)
                ];

                $this->debug('Loading Skills: ' . $json_data['name'] . '...', 'trace');
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, false, false);
                unset($throwable);
            }
        }

        unset($dirname, $module, $tool_names, $dir_list, $item, $path, $json_file, $meta_file, $json_data, $namespace, $metadata, $index);
        return $skills;
    }

    /**
     * @param string $string
     * @param string $level Debug level (none, trace, debug)
     *
     * @return void
     */
    public function debug(string $string, string $level = 'trace'): void
    {
        $debug_level = strtolower($this->agent_config['agent_debug']);

        if ('none' === $debug_level) {
            return;
        }

        if ('trace' === $debug_level && 'debug' === strtolower($level)) {
            return;
        }

        echo '[' . date('Y-m-d H:i:s') . '][' . strtoupper($level) . '] ' . $string . PHP_EOL;
        unset($string, $level, $debug_level);
    }
}