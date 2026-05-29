<?php

/**
 * Agent tools module for AgentBee core
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

namespace modules\agent_tools;

class tools
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'exec',
                'description' => '执行系统命令。限制:program须独立可执行文件(如powershell,git)。禁止cmd内置:dir,echo,type,cd,mkdir,copy,del,move,ren,rd。argv为字符串数组(如["-l","-a"])。timeout(秒,默认30):超时杀进程,勿设过长。work_path可选。返回{"output":"","error":""}',
                'parameters'  => ['type' => 'object', 'properties' => ['program' => ['type' => 'string'], 'argv' => ['type' => 'array', 'items' => ['type' => 'string']], 'timeout' => ['type' => 'integer', 'default' => 30], 'work_path' => ['type' => 'string']], 'required' => ['program', 'argv']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getTime',
                'description' => '获取当前系统时间。返回 {"datetime":"YYYY-MM-DD HH:MM:SS","timestamp":秒数}',
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'cleanContext',
                'description' => '清理上下文:保留最近max_dialog_messages条普通消息和keep_tool_pairs组工具调用对。非强制模式(默认)强制至少保留2组工具对和10条对话。强制模式(force_clean=true)允许设0,需谨慎。重要:调用前必须先用记忆工具保存将被删除的内容。参数:max_dialog_messages(默认10,非强制时最小2),keep_tool_pairs(默认2,非强制时最小1),force_clean(默认false)',
                'parameters'  => ['type' => 'object', 'properties' => ['max_dialog_messages' => ['type' => 'integer', 'default' => 10], 'keep_tool_pairs' => ['type' => 'integer', 'default' => 2], 'force_clean' => ['type' => 'boolean', 'default' => false]], 'required' => []],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readImage',
                'description' => '读取图片返回 Data URL。参数: file_path(必填)。返回 {"status":"success","content":"data:image/...","filename":"...","mime_type":"...","file_path":"..."} 或 {"status":"error","error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['file_path' => ['type' => 'string']], 'required' => ['file_path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readFile',
                'description' => '读取文件内容。参数: file_path(必填), offset(默认0), limit(默认8192,0=全部)。返回 {"status":"success","content":"...","file_path":"..."} 或 {"status":"error","error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['file_path' => ['type' => 'string'], 'offset' => ['type' => 'integer', 'default' => 0], 'limit' => ['type' => 'integer', 'default' => 8192]], 'required' => ['file_path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeFile',
                'description' => '写入文件(自动建目录)。参数: file_path, content(必填), append(默认false)。单次content≤4096字符,大文件需分多次追加。返回 {"status":"success","file_path":"...","bytes_written":N} 或 {"status":"error","error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['file_path' => ['type' => 'string'], 'content' => ['type' => 'string'], 'append' => ['type' => 'boolean', 'default' => false]], 'required' => ['file_path', 'content']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyFile',
                'description' => '复制文件。参数: src_file_path, dst_file_path。目标存在则覆盖。返回 {"status":"success","src_file_path":"...","dst_file_path":"..."} 或 {"status":"error","error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['src_file_path' => ['type' => 'string'], 'dst_file_path' => ['type' => 'string']], 'required' => ['src_file_path', 'dst_file_path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => '永久删除文件(危险)。参数: file_path(必填)。返回 {"status":"success","file_path":"..."} 或 {"status":"error","error":"..."}。操作前须用户确认。',
                'parameters'  => ['type' => 'object', 'properties' => ['file_path' => ['type' => 'string']], 'required' => ['file_path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'getFileSize',
                'description' => '获取文件字节数。参数: file_path。返回 {"status":"success","file_path":"...","filesize":N} 或 {"status":"error","error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['file_path' => ['type' => 'string']], 'required' => ['file_path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'searchFiles',
                'description' => 'glob模式搜索文件。参数: dir_path(起始目录), pattern(如*.php), recursive(默认false)。返回 {"status":"success","dir_path":"...","files":[...]} 或 {"status":"error","error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['dir_path' => ['type' => 'string'], 'pattern' => ['type' => 'string'], 'recursive' => ['type' => 'boolean', 'default' => false]], 'required' => ['dir_path', 'pattern']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => '列出目录内容(非递归)。参数: dir_path。返回 {"status":"success","dir_path":"...","contents":[{"filename":"","filesize":N,"isFile":bool}]} 或 {"status":"error","error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['dir_path' => ['type' => 'string']], 'required' => ['dir_path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => '创建目录(自动建父目录)。参数: dir_path。返回 {"status":"success","dir_path":"..."} 或 {"status":"error","error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['dir_path' => ['type' => 'string']], 'required' => ['dir_path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => '递归复制目录。参数: src_dir_path(必填), dst_dir_path(必填), overwrite(可选,默认false,是否覆盖已存在目录)。返回 {"status":"success","src_dir_path":"...","dst_dir_path":"...","copied_files":N} 或 {"status":"error","error":"..."}',
                'parameters'  => ['type' => 'object', 'properties' => ['src_dir_path' => ['type' => 'string'], 'dst_dir_path' => ['type' => 'string'], 'overwrite' => ['type' => 'boolean', 'default' => false]], 'required' => ['src_dir_path', 'dst_dir_path']],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteDirectory',
                'description' => '递归删除目录(危险)。参数: dir_path。返回 {"status":"success","dir_path":"...","files_removed":N} 或 {"status":"error","error":"..."}。操作前须用户确认。',
                'parameters'  => ['type' => 'object', 'properties' => ['dir_path' => ['type' => 'string']], 'required' => ['dir_path']],
            ],
        ],
    ];
}