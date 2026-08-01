<?php

/**
 * Agent Calculator tools example for AgentBee core
 *
 * Copyright 2026 AgentBee self developed
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

namespace skills\Calculator;

use Nervsys\Core\Factory;

class go extends Factory
{
    /**
     * 计算两个整数的和。
     *
     * @param int $a 第一个加数
     * @param int $b 第二个加数
     *
     * @return array{status: string, message: string, data: array{result: int}}
     */
    public function add(int $a, int $b): array
    {
        return $this->success($a + $b);
    }

    /**
     * 计算两个整数的差。
     *
     * @param int $a 被减数
     * @param int $b 减数
     *
     * @return array{status: string, message: string, data: array{result: int}}
     */
    public function subtract(int $a, int $b): array
    {
        return $this->success($a - $b);
    }

    /**
     * 计算两个整数的乘积。
     *
     * @param int $a 第一个乘数
     * @param int $b 第二个乘数
     *
     * @return array{status: string, message: string, data: array{result: int}}
     */
    public function multiply(int $a, int $b): array
    {
        return $this->success($a * $b);
    }

    /**
     * 计算两个整数相除的商。
     *
     * @param int $a 被除数
     * @param int $b 除数，不得为零
     *
     * @return array{status: string, message?: string, data?: array{result: float}, error?: string}
     */
    public function divide(int $a, int $b): array
    {
        if (0 === $b) {
            return [
                'status' => 'error',
                'error'  => '除数不能为零。',
            ];
        }

        return $this->success($a / $b);
    }

    /**
     * 构造统一成功结果。
     *
     * @param int|float $result
     *
     * @return array{status: string, message: string, data: array{result: int|float}}
     */
    private function success(int|float $result): array
    {
        return [
            'status'  => 'success',
            'message' => '计算完成。',
            'data'    => ['result' => $result],
        ];
    }
}