<?php

/**
 * File I/O Tool module for AgentBee
 *
 * Provides file operation tools conforming to OpenAI's tool/function-calling standard format.
 * All file operations delegate to Nervsys\Ext\libFileIO.
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

use Nervsys\Core\Factory;
use Nervsys\Ext\libFileIO;

class go extends Factory
{
    /** @var string Sandbox boundary */
    public string $root_path = '';

    /** @var libFileIO */
    public libFileIO $fileIO;

    /** @var int Default 1MB */
    public int $max_read_size = 1048576;

    /** @var bool Sandbox enabled by default */
    public bool $sandbox_enabled = true;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->fileIO = libFileIO::new();
    }

    /**
     * Set sandbox root path. Also enables sandbox mode.
     */
    public function setRootPath(string $root_path): static
    {
        $this->root_path = rtrim($root_path, '\\/');
        $this->sandbox_enabled = true;
        return $this;
    }


    /**
     * Enable or disable sandbox mode. When disabled, no path restriction applies.
     */
    public function setSandboxEnabled(bool $enabled): static
    {
        $this->sandbox_enabled = $enabled;
        return $this;
    }

    /**
     * OpenAI standard tool definitions
     */
    public function getToolDefinitions(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'read_file',
                    'description' => 'Read file content with optional byte offset and limit for large files.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path'   => ['type' => 'string', 'description' => 'File path'],
                            'offset' => ['type' => 'integer', 'description' => 'Byte offset to start reading (default: 0)'],
                            'limit'  => ['type' => 'integer', 'description' => 'Max bytes to read, up to max_read_size (default: 8192)']
                        ],
                        'required' => ['path']
                    ]
                ]
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'write_file',
                    'description' => 'Write content to a file, creating it if it does not exist.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path'    => ['type' => 'string', 'description' => 'File path'],
                            'content' => ['type' => 'string', 'description' => 'Content to write'],
                            'append'  => ['type' => 'boolean', 'description' => 'Append instead of overwrite (default: false)']
                        ],
                        'required' => ['path', 'content']
                    ]
                ]
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'list_directory',
                    'description' => 'List directory contents with metadata (filename, filepath, filesize, isFile).',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'Directory path']
                        ],
                        'required' => ['path']
                    ]
                ]
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'create_directory',
                    'description' => 'Create a directory and any necessary parent directories.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'Directory path to create']
                        ],
                        'required' => ['path']
                    ]
                ]
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'delete_file',
                    'description' => 'Delete a file. Idempotent: succeeds even if file does not exist.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'File path to delete']
                        ],
                        'required' => ['path']
                    ]
                ]
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'search_files',
                    'description' => 'Search files matching a glob pattern, optionally recursively.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path'      => ['type' => 'string', 'description' => 'Directory path to search in'],
                            'pattern'   => ['type' => 'string', 'description' => 'Glob pattern, e.g. "*.php" or "*.{json,xml}"'],
                            'recursive' => ['type' => 'boolean', 'description' => 'Search subdirectories recursively (default: false)']
                        ],
                        'required' => ['path']
                    ]
                ]
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'copy_directory',
                    'description' => 'Recursively copy a directory from source to destination.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'src' => ['type' => 'string', 'description' => 'Source directory path'],
                            'dst' => ['type' => 'string', 'description' => 'Destination directory path']
                        ],
                        'required' => ['src', 'dst']
                    ]
                ]
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'delete_directory',
                    'description' => 'Recursively delete a directory and all its contents.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => ['type' => 'string', 'description' => 'Directory path to delete']
                        ],
                        'required' => ['path']
                    ]
                ]
            ]
        ];
    }

    /**
     * Execute a tool call
     */
    public function executeToolCall(string $function_name, array $arguments): array
    {
        return match ($function_name) {
            'read_file'        => $this->readFile($arguments['path'] ?? '', $arguments['offset'] ?? 0, $arguments['limit'] ?? 8192),
            'write_file'       => $this->writeFile($arguments['path'] ?? '', $arguments['content'] ?? '', $arguments['append'] ?? false),
            'list_directory'   => $this->listDirectory($arguments['path'] ?? ''),
            'create_directory' => $this->createDirectory($arguments['path'] ?? ''),
            'delete_file'      => $this->deleteFile($arguments['path'] ?? ''),
            'search_files'     => $this->searchFiles($arguments['path'] ?? '', $arguments['pattern'] ?? '*', $arguments['recursive'] ?? false),
            'copy_directory'   => $this->copyDirectory($arguments['src'] ?? '', $arguments['dst'] ?? ''),
            'delete_directory' => $this->deleteDirectory($arguments['path'] ?? ''),
            default            => ['success' => false, 'error' => 'Unknown tool: ' . $function_name]
        };
    }

    /**
     * Read file with offset/limit support
     */
    public function readFile(string $path, int $offset = 0, int $limit = 8192): array
    {
        $full = $this->resolve($path, true, 'file');
        if (is_array($full)) return $full;

        $limit = min($limit, $this->max_read_size);
        $fp = fopen($full, 'r');
        fseek($fp, $offset);
        $content = fread($fp, $limit);
        fclose($fp);

        $total = filesize($full);
        return [
            'success'    => true,
            'path'       => $path,
            'content'    => $content,
            'truncated'  => ($offset + $limit) < $total,
            'offset'     => $offset,
            'limit'      => $limit,
            'total_size' => $total
        ];
    }

    /**
     * Write or append to file
     */
    public function writeFile(string $path, string $content, bool $append = false): array
    {
        $full = $this->resolve($path);
        if (is_array($full)) return $full;

        // Auto-create parent directories via libFileIO::mkPath (needs relative path + root)
        if (!is_dir(dirname($full))) {
            $relative = $this->root_path !== '' ? substr(dirname($full), strlen($this->root_path) + 1) : dirname($full);
            $this->fileIO->mkPath($relative, $this->root_path ?: '.');
        }

        $result = file_put_contents($full, $content, $append ? FILE_APPEND : 0);
        if ($result === false) {
            return ['success' => false, 'error' => 'Failed to write: ' . $path];
        }

        return ['success' => true, 'path' => $path, 'bytes' => $result, 'mode' => $append ? 'append' : 'write'];
    }

    /**
     * List directory contents via libFileIO::getDirContents
     */
    public function listDirectory(string $path): array
    {
        $full = $this->resolve($path, true, 'dir');
        if (is_array($full)) return $full;

        return [
            'success'  => true,
            'path'     => $path,
            'contents' => $this->fileIO->getDirContents($full)
        ];
    }

    /**
     * Create directory via libFileIO::mkPath (relative path + root)
     */
    public function createDirectory(string $path): array
    {
        $full = $this->resolve($path);
        if (is_array($full)) return $full;

        // mkPath needs relative path from root, not full path
        $relative = $this->root_path !== '' ? substr($full, strlen($this->root_path) + 1) : $full;
        $root = $this->root_path !== '' ? $this->root_path : '.';
        $this->fileIO->mkPath($relative, $root);

        return ['success' => true, 'path' => $path];
    }

    /**
     * Delete file (idempotent)
     */
    public function deleteFile(string $path): array
    {
        $full = $this->resolve($path);
        if (is_array($full)) return $full;

        // Use libFileIO approach: check via getFiles result
        $parent_dir = dirname($full);
        $filename = basename($full);
        $files = $this->fileIO->getFiles($parent_dir);

        if (!in_array($full, $files, true)) {
            return ['success' => true, 'path' => $path, 'note' => 'File did not exist'];
        }

        return unlink($full)
            ? ['success' => true, 'path' => $path]
            : ['success' => false, 'error' => 'Failed to delete: ' . $path];
    }

    /**
     * Search files via libFileIO::findFiles
     */
    public function searchFiles(string $path, string $pattern = '*', bool $recursive = false): array
    {
        $full = $this->resolve($path, true, 'dir');
        if (is_array($full)) return $full;

        return [
            'success' => true,
            'path'    => $path,
            'pattern' => $pattern,
            'files'   => $this->fileIO->findFiles($full, $pattern, $recursive)
        ];
    }

    /**
     * Copy directory via libFileIO::copyDir
     */
    public function copyDirectory(string $src, string $dst): array
    {
        $full_src = $this->resolve($src, true, 'dir');
        $full_dst = $this->resolve($dst);
        if (is_array($full_src)) return $full_src;
        if (is_array($full_dst)) return $full_dst;

        $copied = $this->fileIO->copyDir($full_src, $full_dst);
        if ($copied === -1) {
            return ['success' => false, 'error' => 'Failed to copy: ' . $src . ' -> ' . $dst];
        }

        return ['success' => true, 'src' => $src, 'dst' => $dst, 'copied' => $copied];
    }

    /**
     * Delete directory via libFileIO::delDir
     */
    public function deleteDirectory(string $path): array
    {
        $full = $this->resolve($path, true, 'dir');
        if (is_array($full)) return $full;

        $removed = $this->fileIO->delDir($full);
        if ($removed === -1) {
            return ['success' => false, 'error' => 'Failed to delete directory: ' . $path];
        }

        return ['success' => true, 'path' => $path, 'removed' => $removed];
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve path: returns full_path string on success, or error array on failure.
     *
     * @param string $path       User-provided path
     * @param bool   $mustExist  Whether the path must already exist
     * @param string $type       'file' or 'dir' (for readable error message), '' = no type check
     * @return string|array      Full path on success, error array on failure
     */
    private function resolve(string $path, bool $mustExist = false, string $type = ''): string|array
    {
        $full_path = $this->resolvePath($path);

        if ($full_path === false) {
            return ['success' => false, 'error' => 'Path is outside the allowed root directory'];
        }

        if ($mustExist) {
            if ($type === 'file' && !is_file($full_path)) {
                return ['success' => false, 'error' => 'File not found: ' . $path];
            }
            if ($type === 'dir' && !is_dir($full_path)) {
                return ['success' => false, 'error' => 'Directory not found: ' . $path];
            }
        }

        return $full_path;
    }

    /**
     * Sandbox path resolution: normalize separators, resolve real path, enforce boundary.
     */
    private function resolvePath(string $path): string|false
    {
        // Relative path → prepend root
        if ($this->root_path !== '' && !str_starts_with($path, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:/', $path)) {
            $full_path = $this->root_path . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        } else {
            $full_path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        }

        // Normalize: realpath resolves existing paths; non-existing paths use direct normalization
        $full_path = realpath($full_path) ?: $full_path;

        // Sandbox boundary check (only when enabled)
        if ($this->sandbox_enabled && $this->root_path !== '') {
            $real_root = realpath($this->root_path);
            if ($real_root !== false && !str_starts_with($full_path, $real_root)) {
                return false;
            }
        }

        return $full_path;
    }
}
