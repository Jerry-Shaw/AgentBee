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

use modules\agent_core\app\config;
use modules\agent_core\core;
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Ext\libFileIO;

class go extends Factory
{
    use core;

    public libFileIO $fileIO;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->fileIO       = libFileIO::new();
        $this->procMgr      = ProcMgr::new('socket');
        $this->agent_config = config::new()->get();
    }

    /**
     * @param string $program
     * @param array  $argv
     * @param string $path
     *
     * @return array|string[]
     * @throws \ReflectionException
     */
    public function exec(string $program, array $argv = [], string $path = ''): array
    {
        $result = [
            'output' => '',
            'error'  => ''
        ];

        if ('' === $path) {
            $path = $this->agent_config['tools']['workspace_path'];
        }

        $this->procMgr
            ->command($this->buildCommand($program, $argv))
            ->setWorkDir($path)
            ->run()
            ->awaitProc(
                function (string $output) use (&$result): void
                {
                    $result['output'] = $output;
                    unset($output);
                },
                function (string $output) use (&$result): void
                {
                    $result['error'] = $output;
                    unset($output);
                }
            );

        unset($program, $argv, $path);
        return $result;
    }

    /**
     * Read file content
     *
     * @param string $path
     * @param int    $offset
     * @param int    $limit
     *
     * @return array
     */
    public function readFile(string $path, int $offset = 0, int $limit = 8192): array
    {
        $full_path = $this->securePath($path);

        if (!is_file($full_path)) {
            return ['error' => "File not found: {$path}"];
        }

        if (!is_readable($full_path)) {
            return ['error' => "File not readable: {$path}"];
        }

        $file_handle = fopen($full_path, 'rb');

        if (false === $file_handle) {
            return ['error' => "Failed to open file: {$path}"];
        }

        fseek($file_handle, $offset);
        $content = fread($file_handle, $limit);
        fclose($file_handle);

        unset($path, $offset, $limit, $full_path, $file_handle);
        return ['content' => $content ?: ''];
    }

    /**
     * Write content to file
     *
     * @param string $path
     * @param string $content
     * @param bool   $append
     *
     * @return array
     * @throws \ReflectionException
     */
    public function writeFile(string $path, string $content, bool $append = false): array
    {
        $file_path = $this->securePath($path);

        $dir = dirname($file_path);
        $dir = $this->fileIO->mkPath($dir);

        $file_path   = $dir . basename($path);
        $file_handle = fopen($file_path, $append ? 'ab' : 'wb');

        if (false === $file_handle) {
            return ['error' => 'Failed to open file for writing: ' . $path];
        }

        $bytes = fwrite($file_handle, $content);
        fclose($file_handle);

        unset($path, $content, $append, $file_path, $dir, $file_handle);
        return ['bytes_written' => $bytes ?: 0];
    }

    /**
     * List directory contents
     *
     * @param string $path
     *
     * @return array
     */
    public function listDirectory(string $path): array
    {
        $full_path = $this->securePath($path);

        if (!is_dir($full_path)) {
            return ['error' => "Directory not found: {$path}"];
        }

        $contents = $this->fileIO->getDirContents($full_path);

        unset($path, $full_path);
        return ['contents' => $contents];
    }

    /**
     * Create directory
     *
     * @param string $path
     *
     * @return array
     * @throws \ReflectionException
     */
    public function createDirectory(string $path): array
    {
        return ['created_path' => $this->fileIO->mkPath($this->securePath($path))];
    }

    /**
     * Delete file
     *
     * @param string $path
     *
     * @return array
     */
    public function deleteFile(string $path): array
    {
        $full_path = $this->securePath($path);

        if (!is_file($full_path)) {
            return ['deleted' => true, 'message' => 'File does not exist'];
        }

        $result = unlink($full_path);

        unset($path, $full_path);
        return ['deleted' => $result];
    }

    /**
     * Search files by glob pattern
     *
     * @param string $path
     * @param string $pattern
     * @param bool   $recursive
     *
     * @return array
     */
    public function searchFiles(string $path, string $pattern, bool $recursive = false): array
    {
        $full_path = $this->securePath($path);

        if (!is_dir($full_path)) {
            return ['error' => "Directory not found: {$path}"];
        }

        $files = $this->fileIO->findFiles($full_path, $pattern, $recursive);

        unset($path, $pattern, $recursive, $full_path);
        return ['files' => $files];
    }

    /**
     * Copy directory recursively
     *
     * @param string $src
     * @param string $dst
     *
     * @return array
     */
    public function copyDirectory(string $src, string $dst): array
    {
        $full_src = $this->securePath($src);
        $full_dst = $this->securePath($dst);

        if (!is_dir($full_src)) {
            return ['error' => "Source directory not found: {$src}"];
        }

        if (is_dir($full_dst)) {
            return ['error' => "Destination directory already exists: {$dst}"];
        }

        $copied = $this->fileIO->copyDir($full_src, $full_dst);

        unset($src, $full_src, $full_dst);

        if ($copied < 0) {
            return ['error' => 'Failed to copy directory'];
        }

        return ['copied_files' => $copied, 'destination' => $dst];
    }

    /**
     * Delete directory recursively
     *
     * @param string $path
     *
     * @return array
     */
    public function deleteDirectory(string $path): array
    {
        $full_path = $this->securePath($path);

        if (!is_dir($full_path)) {
            return ['deleted' => true, 'message' => 'Directory does not exist'];
        }

        $removed = $this->fileIO->delDir($full_path);

        unset($path, $full_path);

        if ($removed < 0) {
            return ['error' => 'Failed to delete directory'];
        }

        return ['deleted' => true, 'files_removed' => $removed];
    }

    /**
     * Secure target path and prevent path traversal
     *
     * @param string $path
     *
     * @return string
     */
    private function securePath(string $path): string
    {
        $in_sandbox = $this->agent_config['tools']['in_sandbox'] ?? true;

        $path  = strtr($path, '\\/', DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
        $parts = explode(DIRECTORY_SEPARATOR, $path);

        $parts = array_filter(
            $parts,
            function (string $segment) use ($in_sandbox): bool
            {
                $segment = trim($segment, '.');

                if ('' === $segment) {
                    return false;
                }

                if ($in_sandbox && str_contains($segment, ':')) {
                    return false;
                }

                unset($segment);
                return true;
            }
        );

        $parts = array_values($parts);

        $path = $in_sandbox
            ? rtrim($this->agent_config['tools']['workspace_path'] ?? $this->app->root_path, '\\/')
            : (!str_contains($parts[0] ?? '', ':') ? '/' : '');

        $path .= DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);

        unset($in_sandbox, $parts);
        return $path;
    }

    /**
     * @param string $program
     * @param array  $argv
     *
     * @return array
     */
    private function buildCommand(string $program, array $argv): array
    {
        $internals = [
            'dir', 'echo', 'type', 'cd', 'del', 'erase',
            'copy', 'move', 'rename', 'ren', 'mkdir', 'md',
            'rmdir', 'rd', 'cls', 'color', 'title', 'pushd', 'popd'
        ];

        $command = PHP_OS_FAMILY === 'Windows' && in_array(strtolower($program), $internals, true)
            ? ['cmd.exe', '/c', $program, ...$argv]
            : [$program, ...$argv];

        unset($program, $argv, $internals);
        return $command;
    }
}