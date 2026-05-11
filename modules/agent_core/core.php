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

namespace modules\agent_core;

use modules\agent_core\app\config;
use Nervsys\Core\Lib\Error;
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Core\Mgr\SocketMgr;

trait core
{
    public config    $config;
    public ProcMgr   $procMgr;
    public SocketMgr $socketMgr;

    public string $name  = 'AgentBee';
    public string $uname = '';

    public array $llm_params = [];

    public array $agent_tools   = [];
    public array $agent_config  = [];
    public array $agent_modules = [];

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function initCore(): void
    {
        $this->config    = config::new();
        $this->socketMgr = SocketMgr::new();
        $this->procMgr   = ProcMgr::new('socket');

        $this->agent_config = $this->config->get();
        $this->llm_params   = $this->agent_config['llm']['params'] ?? [];

        $this->uname = php_uname();

        $this->initModules();
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function initModules(): void
    {
        $called_class  = get_class($this);
        $pos_start     = strpos($called_class, '\\') + 1;
        $pos_end       = strpos($called_class, '\\', $pos_start);
        $called_module = substr($called_class, $pos_start, $pos_end - $pos_start);

        foreach ($this->agent_config as $name => $config) {
            if (!isset($config['provider'])) {
                continue;
            }

            if ($called_module !== $config['provider']) {
                $module = '\\modules\\' . $config['provider'] . '\\go';

                try {
                    $this->agent_modules[$name] = $module::new();
                } catch (\Throwable $throwable) {
                    Error::new()->exceptionHandler($throwable, true, false);
                    unset($throwable);
                    continue;
                }
            }
        }

        unset($called_class, $pos_start, $pos_end, $called_module, $name, $config, $module, $object);
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function initTools(): void
    {
        if (!isset($this->agent_config['tools']) || !isset($this->agent_config['tools']['enabled'])) {
            return;
        }

        if (true !== $this->agent_config['tools']['enabled']) {
            return;
        }

        $this->llm_params['tools']           ??= [];
        $this->agent_config['tools']['list'] ??= [];

        foreach ($this->agent_config['tools']['list'] as $tool) {
            $module_class = '\\modules\\' . $tool['name'] . '\\go';
            $module_meta  = '\\modules\\' . $tool['name'] . '\\tool';

            try {
                $tool_meta = $module_meta::META;

                foreach ($tool_meta as $key => $meta) {
                    $tool_meta[$key]['function']['name'] = $tool['name'] . '/' . $meta['function']['name'];
                }

                $this->agent_tools[$tool['name']] = $module_class::new();

                $this->llm_params['tools'] = array_merge($this->llm_params['tools'], $tool_meta);
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, true, false);
                unset($throwable);
                continue;
            }
        }

        if (!empty($this->llm_params['tools'])) {
            $this->llm_params['tool_choice'] = 'auto';
        }

        unset($tool, $module_class, $module_meta, $tool_meta, $key, $meta);
    }

    /**
     * @param array $tool_calls
     *
     * @return array
     * @throws \ReflectionException
     */
    public function execTools(array $tool_calls): array
    {
        $results = [];

        foreach ($tool_calls as $tool_call) {
            $fn_name = $tool_call['function']['name'];
            $fn_argv = json_decode($tool_call['function']['arguments'], true);

            if (!str_contains($fn_name, '/')) {
                continue;
            }

            [$module, $method] = explode('/', $fn_name);

            try {
                $tool_result = $this->agent_tools[$module]->$method(...$fn_argv);
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, true, false);
                $tool_result = $throwable->getMessage();
                unset($throwable);
            }

            $llm_result = [
                'tool_call_id'  => $tool_call['id'],
                'function_name' => $fn_name,
                'result'        => $tool_result
            ];

            $results[] = $llm_result;
        }

        unset($tool_calls, $tool_call, $fn_name, $fn_argv, $module, $method, $tool_result, $llm_result);
        return $results;
    }

    /**
     * @param string $name
     *
     * @return object|null
     */
    public function getModule(string $name): object|null
    {
        return $this->agent_modules[$name] ?? null;
    }

    /**
     * @return array
     */
    public function getLLMParams(): array
    {
        return $this->llm_params;
    }

    /**
     * @param string $socket_id
     * @param string $message
     *
     * @return void
     * @throws \ReflectionException
     */
    public function sendMessage(string $socket_id, string $message): void
    {
        $this->socketMgr->sendMessage($socket_id, $this->socketMgr->wsEncode($message));
    }

    /**
     * @param int $timestamp
     * @param int $offset
     * @param int $length
     *
     * @return array
     */
    public function getMemory(int $timestamp, int $offset = 0, int $length = 100): array
    {
        return $this->agent_modules['memory']->get($timestamp, $offset, $length);
    }

    /**
     * @param int   $timestamp
     * @param array $keywords
     * @param int   $offset
     * @param int   $length
     *
     * @return array
     */
    public function searchMemory(int $timestamp, array $keywords, int $offset = 0, int $length = 100): array
    {
        return $this->agent_modules['memory']->search($timestamp, $keywords, $offset, $length);
    }

    /**
     * @param string $role
     * @param string $content
     *
     * @return void
     */
    public function addMemory(string $role, string $content): void
    {
        $this->agent_modules['memory']->add($role, $content);
    }

    /**
     * @return array
     */
    public function getDefaultMemory(): array
    {
        return $this->agent_modules['memory']->getDefault();
    }

    /**
     * @param string $content
     *
     * @return void
     */
    public function setDefaultMemory(string $content): void
    {
        $this->agent_modules['memory']->setDefault($content);
    }

    /**
     * @param string $content
     *
     * @return void
     */
    public function addDefaultMemory(string $content): void
    {
        $this->agent_modules['memory']->addDefault($content);
    }

    /**
     * @param string $name
     *
     * @return object
     * @throws \Exception
     */
    public function __get(string $name): object
    {
        if (!isset($this->agent_modules[$name])) {
            throw new \Exception('Module NOT found: "' . $name . '"');
        }

        return $this->agent_modules[$name];
    }
}