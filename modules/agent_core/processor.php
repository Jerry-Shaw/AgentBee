<?php

namespace modules\agent_core;

use modules\agent_core\app\config;
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\SocketMgr;

class processor extends Factory
{
    public config    $config;
    public SocketMgr $socketMgr;

    public array $agent_config  = [];
    public array $agent_modules = [];

    /**
     * @throws \ReflectionException
     */
    public function __construct(SocketMgr $socketMgr)
    {
        $this->config       = config::new();
        $this->socketMgr    = $socketMgr;
        $this->agent_config = $this->config->get();

        foreach ($this->agent_config as $name => $config) {
            if (isset($config['provider'])) {
                $module = '\\modules\\' . $config['provider'] . '\\go';

                $this->agent_modules[$name] = $module::new();
            }
        }
    }

    /**
     * @return string
     */
    public function getDirPath(): string
    {
        return $this->config->config_dir;
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
     * @param array $messages
     *
     * @return void
     */
    public function chat(array $messages): void
    {
        $this->agent_modules['llm']->chat($messages);
    }

    /**
     * @param int    $socket_id
     * @param string $output
     *
     * @return void
     */
    public function onWorkerOutput(int $socket_id, string $output): void
    {
        $this->agent_modules['llm']->onWorkerOutput($socket_id, $output, $this);
    }

    /**
     * @param int    $socket_id
     * @param string $output
     *
     * @return void
     */
    public function onWorkerError(int $socket_id, string $output): void
    {
        $this->agent_modules['llm']->onWorkerError($socket_id, $output, $this);
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
}