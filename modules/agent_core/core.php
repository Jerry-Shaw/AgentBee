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
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Core\Mgr\SocketMgr;

trait core
{
    public config    $config;
    public ProcMgr   $procMgr;
    public SocketMgr $socketMgr;

    public array $agent_config  = [];
    public array $agent_modules = [];

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function initCore(): void
    {
        $this->config       = config::new();
        $this->socketMgr    = SocketMgr::new();
        $this->procMgr      = ProcMgr::new('socket');
        $this->agent_config = $this->config->get();

        $this->initModules();
    }

    /**
     * @return void
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
                $object = $module::new();
            } else {
                $object = $this;
            }

            $this->agent_modules[$name] = $object;
        }

        unset($called_class, $pos_start, $pos_end, $called_module, $name, $config, $module, $object);
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