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

use modules\agent_core\core;
use Nervsys\Core\Factory;
use Nervsys\Ext\libFileIO;

class go extends Factory
{
    public core $core;

    public libFileIO $libFileIO;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core      = core::new();
        $this->libFileIO = libFileIO::new();

        $this->core->initCore();
    }

    /**
     * @param string       $program
     * @param array|string $argv
     * @param int          $timeout
     * @param string       $work_path
     *
     * @return array|string[]
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function exec(string $program, array|string $argv = [], int $timeout = 300, string $work_path = ''): array
    {
        $result = [
            'output' => '',
            'error'  => ''
        ];

        $work_path = '' === $work_path
            ? $this->core->agent_config['agent_tools']['workspace_path']
            : $this->core->securePath($work_path);

        if (empty($argv) && str_contains($program, ' ')) {
            [$program, $args] = explode(' ', $program, 2);
            $argv = explode(' ', $args);
        }

        if (is_string($argv)) {
            $parsed = json_decode($argv, true);

            if (is_array($parsed)) {
                $argv = $parsed;
            } else {
                $argv = str_contains($argv, ' ') ? explode(' ', $argv) : [$argv];
            }

            unset($parsed);
        }

        $active  = time();
        $procMgr = $this->core->procMgr;

        $procMgr
            ->command([$program, ...$argv])
            ->setWorkDir($work_path)
            ->run(core::PROC_IDX_EXEC);

        $proc_pid = $procMgr->getPid(core::PROC_IDX_EXEC);

        $procMgr->awaitProc(
            function (string $output) use (&$active, &$result): void
            {
                $active           = time();
                $result['output'] .= $output;
                unset($output);
            },
            function (string $output) use (&$active, &$result): void
            {
                $active          = time();
                $result['error'] .= $output;
                unset($output);
            },
            function () use ($active, $timeout, $proc_pid, $procMgr, &$result): void
            {
                if (0 === $timeout) {
                    return;
                }

                if (time() - $active > $timeout) {
                    $this->core->OSMgr->killProc($proc_pid);
                    $result['error'] .= 'Process has been killed due to timeout reached.';
                }
            }
        );

        $procMgr->exit();

        unset($program, $argv, $timeout, $work_path, $active, $procMgr, $proc_pid);
        return $result;
    }

    /**
     * @return array
     */
    public function getTime(): array
    {
        $timestamp = time();
        $datetime  = date('Y-m-d H:i:s', $timestamp);

        return ['datetime' => $datetime, 'timestamp' => $timestamp];
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
        $full_path = $this->core->securePath($path);

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
        $content = fread($file_handle, 0 === $limit ? filesize($full_path) : $limit);
        $content = (string)mb_convert_encoding($content, 'UTF-8', 'auto');
        fclose($file_handle);

        unset($path, $offset, $limit, $full_path, $file_handle);
        return ['content' => $content];
    }

    /**
     * Write content to file
     *
     * @param string $path
     * @param string $content
     * @param bool   $append
     *
     * @return array
     */
    public function writeFile(string $path, string $content, bool $append = false): array
    {
        $path = $this->core->securePath($path);

        $dir_path = dirname($path);
        $dir_path = $this->mkPath($dir_path);

        $file_handle = fopen($path, $append ? 'ab' : 'wb');

        if (false === $file_handle) {
            return ['error' => 'Failed to open file for writing: ' . $path];
        }

        $bytes = fwrite($file_handle, $content);
        fclose($file_handle);

        unset($path, $content, $append, $dir_path, $file_handle);
        return ['bytes_written' => $bytes ?: 0];
    }

    /**
     * @param string $source
     * @param string $destination
     *
     * @return string[]
     */
    public function copyFile(string $source, string $destination): array
    {
        if (!is_file($source)) {
            return ['error' => "File not found: {$source}"];
        }

        $copy = copy($source, $destination);

        if (!$copy) {
            return ['error' => "Failed to copy file: {$source}"];
        }

        unset($source, $copy);
        return ['file_copied' => "File copied to: {$destination}"];
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
        $full_path = $this->core->securePath($path);

        if (!is_file($full_path)) {
            return ['deleted' => false, 'message' => 'File does not exist'];
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
        $full_path = $this->core->securePath($path);

        if (!is_dir($full_path)) {
            return ['error' => "Directory not found: {$path}"];
        }

        $files = $this->libFileIO->findFiles($full_path, $pattern, $recursive);

        unset($path, $pattern, $recursive, $full_path);
        return ['files' => $files];
    }

    /**
     * @param string $path
     *
     * @return array
     */
    public function getFileSize(string $path): array
    {
        $path = $this->core->securePath($path);

        if (!is_file($path)) {
            return ['error' => 'File not found: ' . $path];
        }

        return ['filesize' => filesize($path)];
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
        $full_path = $this->core->securePath($path);

        if (!is_dir($full_path)) {
            return ['error' => "Directory not found: {$path}"];
        }

        $contents = $this->libFileIO->getDirContents($full_path);

        unset($path, $full_path);
        return ['contents' => $contents];
    }

    /**
     * Create directory
     *
     * @param string $path
     *
     * @return array
     */
    public function createDirectory(string $path): array
    {
        return ['created_path' => $this->mkPath($this->core->securePath($path))];
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
        $full_src = $this->core->securePath($src);
        $full_dst = $this->core->securePath($dst);

        if (!is_dir($full_src)) {
            return ['error' => "Source directory not found: {$src}"];
        }

        if (is_dir($full_dst)) {
            return ['error' => "Destination directory already exists: {$dst}"];
        }

        $copied = $this->libFileIO->copyDir($full_src, $full_dst);

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
        $full_path = $this->core->securePath($path);

        if (!is_dir($full_path)) {
            return ['deleted' => false, 'message' => 'Directory does not exist'];
        }

        $removed = $this->libFileIO->delDir($full_path);

        unset($path, $full_path);

        if ($removed < 0) {
            return ['error' => 'Failed to delete directory'];
        }

        return ['deleted' => true, 'files_removed' => $removed];
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private function mkPath(string $path): string
    {
        if (!is_dir($path)) {
            try {
                mkdir($path, 0777, true);
            } catch (\Throwable) {
            }
        }

        return $path;
    }
}