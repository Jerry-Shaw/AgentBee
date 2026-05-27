<?php

namespace modules\agent_tools;

class tools
{
    public const META = [
        // exec
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'exec',
                'description' => "执行系统命令。\n" .
                    "限制：program 必须是独立可执行文件（如 powershell.exe, git, python, ls）。\n" .
                    "禁止 cmd 内置命令：dir, echo, type, cd, mkdir, copy, del, move, ren, rd。\n" .
                    "argv 必须是字符串数组，如 [\"-l\", \"-a\"]。禁止传单个字符串。\n" .
                    "timeout（秒，默认300），work_path（可选）。\n" .
                    "返回：{\"output\":\"...\", \"error\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'program'   => ['type' => 'string', 'description' => '可执行文件路径或文件名'],
                        'argv'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '参数数组，如 ["-l","-a"]'],
                        'timeout'   => ['type' => 'integer', 'default' => 300, 'description' => '空闲超时秒数，0=无超时'],
                        'work_path' => ['type' => 'string', 'description' => '工作目录，默认为工作区'],
                    ],
                    'required'   => ['program', 'argv'],
                ],
            ],
        ],
        // getTime
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getTime',
                'description' => "获取当前系统时间。返回：{\"datetime\":\"YYYY-MM-DD HH:MM:SS\", \"timestamp\":秒数}",
            ],
        ],
        // cleanContext
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'cleanContext',
                'description' => '清理上下文：保留最近 max_dialog_messages 条普通消息和最近 keep_tool_pairs 组工具调用对。非强制模式（默认）强制至少保留2组工具对和10条对话，避免上下文丢失。强制模式(force_clean=true)允许设为0，需谨慎。重要：调用前必须先用记忆工具保存即将被删除的重要内容。',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'max_dialog_messages' => [
                            'type'        => 'integer',
                            'description' => '保留普通消息条数上限，默认10（非强制时最小2）',
                            'default'     => 10,
                        ],
                        'keep_tool_pairs'     => [
                            'type'        => 'integer',
                            'description' => '保留最近工具调用对组数，默认2（非强制时最小1）',
                            'default'     => 2,
                        ],
                        'force_clean'         => [
                            'type'        => 'boolean',
                            'description' => '是否强制清理（允许0值），默认false',
                            'default'     => false,
                        ],
                    ],
                    'required'   => [],
                ],
            ],
        ],
        // readFile
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readFile',
                'description' => "读取文件内容。\n" .
                    "参数：path（必填），offset（默认0），limit（默认8192，0=整个文件）。\n" .
                    "返回：{\"content\":\"...\"} 或 {\"error\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => ['type' => 'string', 'description' => '文件路径'],
                        'offset' => ['type' => 'integer', 'default' => 0, 'description' => '起始字节'],
                        'limit'  => ['type' => 'integer', 'default' => 8192, 'description' => '读取字节数，0=全部'],
                    ],
                    'required'   => ['path'],
                ],
            ],
        ],
        // writeFile
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeFile',
                'description' => "写入文件（自动创建目录）。\n" .
                    "参数：path（必填），content（必填），append（默认false）。\n" .
                    "单次 content 建议不超过4096字符，大文件必须分多次追加（append=true）。\n" .
                    "返回：{\"bytes_written\": N} 或 {\"error\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'    => ['type' => 'string', 'description' => '文件路径'],
                        'content' => ['type' => 'string', 'description' => '要写入的内容（≤4096字符）'],
                        'append'  => ['type' => 'boolean', 'default' => false, 'description' => '是否追加'],
                    ],
                    'required'   => ['path', 'content'],
                ],
            ],
        ],
        // copyFile
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyFile',
                'description' => "复制文件。\n参数：src（源文件路径），dst（目标路径）。\n目标文件存在时将被覆盖。\n返回：成功 → {\"file_copied\": \"File copied to: 目标路径\"}，失败 → {\"error\": \"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src' => ['type' => 'string', 'description' => '源文件路径'],
                        'dst' => ['type' => 'string', 'description' => '目标文件路径'],
                    ],
                    'required'   => ['src', 'dst'],
                ],
            ],
        ],
        // deleteFile
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => "永久删除文件（危险）。参数：path（必填）。返回：{\"deleted\": true} 或 {\"deleted\":false,\"message\":\"...\"}。操作前须用户确认。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => '文件路径']],
                    'required'   => ['path'],
                ],
            ],
        ],
        // searchFiles
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'searchFiles',
                'description' => "glob 模式搜索文件。\n参数：path（起始目录），pattern（如 *.php），recursive（默认false）。\n返回：{\"files\": [...]}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'      => ['type' => 'string', 'description' => '搜索起始目录'],
                        'pattern'   => ['type' => 'string', 'description' => '文件名模式，如 *.php'],
                        'recursive' => ['type' => 'boolean', 'default' => false, 'description' => '是否递归'],
                    ],
                    'required'   => ['path', 'pattern'],
                ],
            ],
        ],
        // getFileSize
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getFileSize',
                'description' => "获取文件字节数。参数：file_path。返回：{\"filesize\": N} 或 {\"error\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['file_path' => ['type' => 'string', 'description' => '文件路径']],
                    'required'   => ['file_path'],
                ],
            ],
        ],
        // listDirectory
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => "列出目录内容（非递归）。参数：path。返回：{\"contents\": [{\"filename\":\"...\",\"filesize\":N,\"isFile\":bool}, ...]}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => '目录路径']],
                    'required'   => ['path'],
                ],
            ],
        ],
        // createDirectory
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => "创建目录（自动创建父目录）。参数：path。返回：{\"created_path\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => '目录路径']],
                    'required'   => ['path'],
                ],
            ],
        ],
        // copyDirectory
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => "递归复制目录，目标必须不存在。参数：src, dst。返回：{\"copied_files\":N, \"destination\":\"...\"} 或 {\"error\":\"...\"}",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src' => ['type' => 'string', 'description' => '源目录'],
                        'dst' => ['type' => 'string', 'description' => '目标目录（必须不存在）'],
                    ],
                    'required'   => ['src', 'dst'],
                ],
            ],
        ],
        // deleteDirectory
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteDirectory',
                'description' => "递归删除目录（危险）。参数：path。返回：{\"deleted\":true,\"files_removed\":N} 或 {\"deleted\":false,\"message\":\"...\"}。操作前须用户确认。",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => '目录路径']],
                    'required'   => ['path'],
                ],
            ],
        ],
    ];
}