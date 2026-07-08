<?php

namespace skills\Calculator;

use Nervsys\Core\Factory;

class go extends Factory
{
    /**
     * 加法运算
     *
     * @param int $a
     * @param int $b
     *
     * @return array
     */
    public function add(int $a, int $b): array
    {
        return ['status' => 'success', 'result' => $a + $b];
    }

    /**
     * 乘法运算
     *
     * @param int $a
     * @param int $b
     *
     * @return array
     */
    public function multiply(int $a, int $b): array
    {
        return ['status' => 'success', 'result' => $a * $b];
    }
}