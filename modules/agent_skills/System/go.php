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

namespace modules\agent_skills\System;

use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;

class go extends Factory
{
    public utils $utils;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->utils = utils::new();
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
    public function exec(string $program, array|string $argv = [], int $timeout = 30, string $work_path = ''): array
    {
        $result = [
            'output' => '',
            'error'  => ''
        ];

        $work_path = '' === $work_path
            ? $this->utils->agent_config['workspace_path']
            : $this->utils->securePath($work_path);

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
        $procMgr = $this->utils->procMgr;

        $proc_idx = $procMgr
            ->command([$program, ...$argv])
            ->setWorkDir($work_path)
            ->run($this->utils->getProcIDX());

        $proc_pid = $procMgr->getPid($proc_idx);

        $procMgr->awaitProc(
            $proc_idx,
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
                    $this->utils->OSMgr->killProc($proc_pid);
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
     * Prune session history to stay within context limits.
     * Assumes input history is valid. Does NOT auto-save; use memory tool first.
     *
     * @param int  $keep_normal     Min normal messages (user + assistant w/o tool_calls)
     * @param int  $keep_tool_pairs Min tool pairs (assistant with tool_calls + tools)
     * @param bool $force_prune     Allow zero limits (fresh start)
     *
     * @return array{status:string, message:string, remained:int}
     */
    public function cleanContext(int $keep_normal = 6, int $keep_tool_pairs = 2, bool $force_prune = false): array
    {
        $cleaned = $this->utils->pruneSessionHistory(WORKER_MAIN, $keep_normal, $keep_tool_pairs, $force_prune);

        return [
            'status'  => 'success',
            'message' => 'Cleaned ' . $cleaned['removed_normal'] . ' normal messages and ' . $cleaned['removed_tools'] . ' tool pairs. Total messages remained: ' . $cleaned['current_count'] . '.'
        ];
    }

    /**
     * Read image file and return Data URL.
     *
     * @param string $file_path
     *
     * @return array
     */
    public function readImage(string $file_path): array
    {
        $full_path = $this->utils->securePath($file_path);

        if (!is_file($full_path)) {
            return ['status' => 'error', 'error' => 'File not found: ' . $full_path];
        }

        if (!is_readable($full_path)) {
            return ['status' => 'error', 'error' => 'File not readable: ' . $full_path];
        }

        $info = getimagesize($full_path);

        if (false === $info || empty($info['mime'])) {
            return ['status' => 'error', 'error' => 'Cannot identify image type or unsupported format'];
        }

        $mime_type = $info['mime'];
        $allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];

        if (!in_array($mime_type, $allowed, true)) {
            return ['status' => 'error', 'error' => 'Unsupported image type: ' . $mime_type];
        }

        $data = file_get_contents($full_path);

        if (false === $data) {
            return ['status' => 'error', 'error' => 'Failed to read image data: ' . $full_path];
        }

        $base64    = base64_encode($data);
        $image_url = 'data:' . $mime_type . ';base64,' . $base64;

        $result = [
            'status'    => 'success',
            'content'   => $image_url,
            'filename'  => basename($full_path),
            'mime_type' => $mime_type,
            'file_path' => $full_path
        ];

        unset($file_path, $full_path, $info, $mime_type, $allowed, $data, $base64, $image_url);
        return $result;
    }

    /**
     * Read file content.
     *
     * @param string $file_path
     * @param int    $offset
     * @param int    $limit
     *
     * @return array
     */
    public function readFile(string $file_path, int $offset = 0, int $limit = 8192): array
    {
        $full_path = $this->utils->securePath($file_path);

        if (!is_file($full_path)) {
            return ['status' => 'error', 'error' => 'File not found: ' . $full_path];
        }

        if (!is_readable($full_path)) {
            return ['status' => 'error', 'error' => 'File not readable: ' . $full_path];
        }

        $file_handle = fopen($full_path, 'rb');

        if (false === $file_handle) {
            return ['status' => 'error', 'error' => 'Failed to open file: ' . $full_path];
        }

        fseek($file_handle, $offset);
        $content = fread($file_handle, (0 === $limit) ? filesize($full_path) : $limit);
        $content = (string)mb_convert_encoding($content, 'UTF-8', 'auto');
        fclose($file_handle);

        $result = [
            'status'    => 'success',
            'content'   => $content,
            'file_path' => $full_path
        ];

        unset($file_path, $offset, $limit, $full_path, $file_handle, $content);
        return $result;
    }

    /**
     * Write content to file.
     *
     * @param string $file_path
     * @param string $content
     * @param bool   $append
     *
     * @return array
     */
    public function writeFile(string $file_path, string $content, bool $append = false): array
    {
        $full_path = $this->utils->securePath($file_path);

        $dir_path = dirname($full_path);
        $dir_path = $this->mkPath($dir_path);

        $file_handle = fopen($full_path, $append ? 'ab' : 'wb');

        if (false === $file_handle) {
            return ['status' => 'error', 'error' => 'Failed to open file for writing: ' . $full_path];
        }

        $bytes = fwrite($file_handle, $content);
        fclose($file_handle);

        $result = [
            'status'        => 'success',
            'file_path'     => $full_path,
            'bytes_written' => $bytes ?: 0
        ];

        unset($file_path, $content, $append, $dir_path, $file_handle, $bytes);
        return $result;
    }

    /**
     * Copy a file.
     *
     * @param string $src_file_path
     * @param string $dst_file_path
     *
     * @return array
     */
    public function copyFile(string $src_file_path, string $dst_file_path): array
    {
        $src_full = $this->utils->securePath($src_file_path);
        $dst_full = $this->utils->securePath($dst_file_path);

        if (!is_file($src_full)) {
            return ['status' => 'error', 'error' => 'File not found: ' . $src_full];
        }

        $copy = copy($src_full, $dst_full);

        if (!$copy) {
            return ['status' => 'error', 'error' => 'Failed to copy file: ' . $src_full];
        }

        $result = [
            'status'        => 'success',
            'src_file_path' => $src_full,
            'dst_file_path' => $dst_full
        ];

        unset($src_file_path, $dst_file_path, $src_full, $dst_full, $copy);
        return $result;
    }

    /**
     * Delete a file.
     *
     * @param string $file_path
     *
     * @return array
     */
    public function deleteFile(string $file_path): array
    {
        $full_path = $this->utils->securePath($file_path);

        if (!is_file($full_path)) {
            return [
                'status' => 'error',
                'error'  => 'File does not exist: ' . $full_path
            ];
        }

        $deleted = unlink($full_path);

        if ($deleted) {
            $result = [
                'status'    => 'success',
                'file_path' => $full_path
            ];
        } else {
            $result = [
                'status' => 'error',
                'error'  => 'Failed to delete file: ' . $full_path
            ];
        }

        unset($file_path, $full_path, $deleted);
        return $result;
    }

    /**
     * Get file size.
     *
     * @param string $file_path
     *
     * @return array
     */
    public function getFileSize(string $file_path): array
    {
        $full_path = $this->utils->securePath($file_path);

        if (!is_file($full_path)) {
            return ['status' => 'error', 'error' => 'File not found: ' . $full_path];
        }

        $size = filesize($full_path);

        $result = [
            'status'    => 'success',
            'file_path' => $full_path,
            'filesize'  => $size
        ];

        unset($file_path, $full_path, $size);
        return $result;
    }

    /**
     * Search files by glob pattern.
     *
     * @param string $dir_path
     * @param string $pattern
     * @param bool   $recursive
     *
     * @return array
     */
    public function searchFiles(string $dir_path, string $pattern, bool $recursive = false): array
    {
        $full_path = $this->utils->securePath($dir_path);

        if (!is_dir($full_path)) {
            return ['status' => 'error', 'error' => 'Directory not found: ' . $full_path];
        }

        $files = $this->utils->libFileIO->findFiles($full_path, $pattern, $recursive);

        $result = [
            'status'   => 'success',
            'dir_path' => $full_path,
            'files'    => $files
        ];

        unset($dir_path, $pattern, $recursive, $full_path, $files);
        return $result;
    }

    /**
     * List directory contents.
     *
     * @param string $dir_path
     *
     * @return array
     */
    public function listDirectory(string $dir_path): array
    {
        $full_path = $this->utils->securePath($dir_path);

        if (!is_dir($full_path)) {
            return ['status' => 'error', 'error' => 'Directory not found: ' . $full_path];
        }

        $contents = $this->utils->libFileIO->getDirContents($full_path);

        $result = [
            'status'   => 'success',
            'dir_path' => $full_path,
            'contents' => $contents
        ];

        unset($dir_path, $full_path, $contents);
        return $result;
    }

    /**
     * Create directory.
     *
     * @param string $dir_path
     *
     * @return array
     */
    public function createDirectory(string $dir_path): array
    {
        $full_path = $this->utils->securePath($dir_path);
        $created   = $this->mkPath($full_path);

        $result = [
            'status'   => 'success',
            'dir_path' => $created
        ];

        unset($dir_path, $full_path, $created);
        return $result;
    }

    /**
     * Copy directory recursively.
     *
     * @param string $src_dir_path
     * @param string $dst_dir_path
     * @param bool   $overwrite
     *
     * @return array
     */
    public function copyDirectory(string $src_dir_path, string $dst_dir_path, bool $overwrite = false): array
    {
        $src_full = $this->utils->securePath($src_dir_path);
        $dst_full = $this->utils->securePath($dst_dir_path);

        if (!is_dir($src_full)) {
            return ['status' => 'error', 'error' => 'Source directory not found: ' . $src_full];
        }

        if (!$overwrite && is_dir($dst_full)) {
            return ['status' => 'error', 'error' => 'Destination directory already exists: ' . $dst_full];
        }

        $copied = $this->utils->libFileIO->copyDir($src_full, $dst_full);

        if (0 > $copied) {
            return ['status' => 'error', 'error' => 'Failed to copy directory: ' . $src_full];
        }

        $result = [
            'status'       => 'success',
            'src_dir_path' => $src_full,
            'dst_dir_path' => $dst_full,
            'copied_files' => $copied
        ];

        unset($src_dir_path, $dst_dir_path, $src_full, $dst_full, $copied);
        return $result;
    }

    /**
     * Delete directory recursively.
     *
     * @param string $dir_path
     *
     * @return array
     */
    public function deleteDirectory(string $dir_path): array
    {
        $full_path = $this->utils->securePath($dir_path);

        if (!is_dir($full_path)) {
            return ['status' => 'error', 'error' => 'Directory does not exist: ' . $full_path];
        }

        $removed = $this->utils->libFileIO->delDir($full_path);

        if (0 > $removed) {
            return ['status' => 'error', 'error' => 'Failed to delete directory: ' . $full_path];
        }

        $result = [
            'status'        => 'success',
            'dir_path'      => $full_path,
            'files_removed' => $removed
        ];

        unset($dir_path, $full_path, $removed);
        return $result;
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