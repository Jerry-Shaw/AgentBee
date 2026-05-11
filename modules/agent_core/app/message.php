<?php

namespace modules\agent_core\app;

use modules\agent_core\core;
use Nervsys\Core\Factory;

class message extends Factory
{
    use core;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->initCore();
    }

    /**
     * @param int   $socket_id
     * @param array $data
     *
     * @return false[]
     */
    public function process_getHistory(int $socket_id, array $data): array
    {
        return [
            'llm' => false
        ];
    }

    /**
     * @param int   $socket_id
     * @param array $data
     *
     * @return array
     */
    public function process_text(int $socket_id, array $data): array
    {
        return [
            'llm'  => true,
            'text' => $data['message']
        ];
    }
}