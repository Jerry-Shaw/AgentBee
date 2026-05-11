<?php

/**
 * File I/O Tool Metadata for AgentBee
 *
 * Provides tool definitions conforming to OpenAI's function-calling standard format.
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

namespace modules\tool_fileio;

class tool
{
    public const META = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'readFile',
                'description' => 'Read file content. For large files, use offset and limit to read in chunks. Returns content as a string.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'   => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: Absolute or relative path to the file. Must be within workspace directory. Use "/" as directory separator.'
                        ],
                        'offset' => [
                            'type'        => 'integer',
                            'description' => 'Byte offset to start reading from. Default: 0.'
                        ],
                        'limit'  => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of bytes to read. Default: 8192 (8KB). Maximum: 1048576 (1MB).'
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'writeFile',
                'description' => 'Write content to a file, creating it if it does not exist. Use append to add to existing files.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'    => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: Absolute or relative path to the file. Must be within workspace directory.'
                        ],
                        'content' => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: Content to write to the file.'
                        ],
                        'append'  => [
                            'type'        => 'boolean',
                            'description' => 'If true, append to end of file instead of overwriting. Default: false.'
                        ]
                    ],
                    'required'   => ['path', 'content']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'listDirectory',
                'description' => 'List directory contents. Returns JSON array with items: {"name": filename, "path": fullpath, "size": bytes, "is_file": bool, "is_dir": bool}.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: Directory path to list. Must be within workspace directory.'
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'createDirectory',
                'description' => 'Create a directory and any necessary parent directories. Succeeds if directory already exists.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: Directory path to create. Must be within workspace directory.'
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteFile',
                'description' => '⚠️ DANGEROUS: Permanently delete a file. This action cannot be undone. Idempotent: succeeds even if file does not exist.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: File path to delete. Must be within workspace directory.'
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'searchFiles',
                'description' => 'Search files matching a glob pattern. Returns JSON array of file paths.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path'      => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: Directory path to search in. Must be within workspace directory.'
                        ],
                        'pattern'   => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: Glob pattern, e.g. "*.php" or "*.{json,xml}". Use "*" for any characters, "?" for single character.'
                        ],
                        'recursive' => [
                            'type'        => 'boolean',
                            'description' => 'If true, search subdirectories recursively. Default: false.'
                        ]
                    ],
                    'required'   => ['path', 'pattern']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'copyDirectory',
                'description' => 'Recursively copy a directory from source to destination. Will not overwrite existing files; throws error if destination exists.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'src' => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: Source directory path. Must be within workspace directory.'
                        ],
                        'dst' => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: Destination directory path. Must be within workspace directory.'
                        ]
                    ],
                    'required'   => ['src', 'dst']
                ]
            ]
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'deleteDirectory',
                'description' => '⚠️ DANGEROUS: Recursively delete a directory and all its contents. This action cannot be undone.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'path' => [
                            'type'        => 'string',
                            'description' => 'REQUIRED: Directory path to delete. Must be within workspace directory.'
                        ]
                    ],
                    'required'   => ['path']
                ]
            ]
        ]
    ];
}