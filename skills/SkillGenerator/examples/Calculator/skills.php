<?php

namespace skills\Calculator;

class skills
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'add',
                'description' => '计算两个整数的和。返回: {status, result}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'a' => ['type' => 'integer', 'description' => '第一个加数'],
                        'b' => ['type' => 'integer', 'description' => '第二个加数']
                    ],
                    'required'   => ['a', 'b']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'multiply',
                'description' => '计算两个整数的乘积。返回: {status, result}。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'a' => ['type' => 'integer', 'description' => '第一个乘数'],
                        'b' => ['type' => 'integer', 'description' => '第二个乘数']
                    ],
                    'required'   => ['a', 'b']
                ]
            ]
        ]
    ];
}