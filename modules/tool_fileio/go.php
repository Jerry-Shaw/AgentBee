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
    public libFileIO $fileIO;

    public string    $root_path       = '';
    public int       $max_read_size   = 1048576;
    public bool      $sandbox_enabled = true;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->fileIO = libFileIO::new();
    }

    /**
     * Set sandbox root path
     */
    public function setRootPath(string $root_path): static
    {
        $this->root_path       = rtrim($root_path, '\\/');
        $this->sandbox_enabled = true;
        return $this;
    }

    /**
     * Enable or disable sandbox mode
     */
    public function setSandboxEnabled(bool $enabled): static
    {
        $this->sandbox_enabled = $enabled;
        return $this;
    }

    /**
     * Read file with offset/limit support
     */
    public function readFile(string $path, int $offset = 0, int $limit = 8192): array
    {
        $full = $this->resolve($path, true, 'file');
        if (is_array($full)) {
            return $full;
        }

        $limit = min($limit, $this->max_read_size);
        $fp    = fopen($full, 'rb');
        fseek($fp, $offset);
        $content = fread($fp, $limit);
        fclose($fp);

        $total  = filesize($full);
        $result = [
            'success'    => true,
            'path'       => $path,
            'content'    => $content,
            'truncated'  => ($offset + $limit) < $total,
            'offset'     => $offset,
            'limit'      => $limit,
            'total_size' => $total
        ];

        unset($full, $limit, $fp, $content, $total);
        return $result;
    }

    /**
     * Write or append to file
     */
    public function writeFile(string $path, string $content, bool $append = false): array
    {
        $full = $this->resolve($path);
        if (is_array($full)) {
            return $full;
        }

        if (!is_dir(dirname($full))) {
            $relative = ('' !== $this->root_path)
                ? substr(dirname($full), strlen($this->root_path) + 1)
                : dirname($full);
            $this->fileIO->mkPath($relative, '' !== $this->root_path ? $this->root_path : '.');
        }

        $result = file_put_contents($full, $content, $append ? FILE_APPEND : 0);
        $output = (false === $result)
            ? ['success' => false, 'error' => 'Failed to write: ' . $path]
            : ['success' => true, 'path' => $path, 'bytes' => $result, 'mode' => $append ? 'append' : 'write'];

        unset($full, $relative, $result);
        return $output;
    }

    /**
     * List directory contents
     */
    public function listDirectory(string $path): array
    {
        $full = $this->resolve($path, true, 'dir');
        if (is_array($full)) {
            return $full;
        }

        $result = [
            'success'  => true,
            'path'     => $path,
            'contents' => $this->fileIO->getDirContents($full)
        ];

        unset($full);
        return $result;
    }

    /**
     * Create directory
     */
    public function createDirectory(string $path): array
    {
        $full = $this->resolve($path);
        if (is_array($full)) {
            return $full;
        }

        $relative = ('' !== $this->root_path)
            ? substr($full, strlen($this->root_path) + 1)
            : $full;
        $root     = ('' !== $this->root_path) ? $this->root_path : '.';
        $this->fileIO->mkPath($relative, $root);

        unset($full, $relative, $root);
        return ['success' => true, 'path' => $path];
    }

    /**
     * Delete file (idempotent)
     */
    public function deleteFile(string $path): array
    {
        $full = $this->resolve($path);
        if (is_array($full)) {
            return $full;
        }

        if (!is_file($full)) {
            unset($full);
            return ['success' => true, 'path' => $path, 'note' => 'File did not exist'];
        }

        $result = unlink($full);
        $output = $result
            ? ['success' => true, 'path' => $path]
            : ['success' => false, 'error' => 'Failed to delete: ' . $path];

        unset($full, $result);
        return $output;
    }

    /**
     * Search files via glob pattern
     */
    public function searchFiles(string $path, string $pattern = '*', bool $recursive = false): array
    {
        $full = $this->resolve($path, true, 'dir');
        if (is_array($full)) {
            return $full;
        }

        $result = [
            'success' => true,
            'path'    => $path,
            'pattern' => $pattern,
            'files'   => $this->fileIO->findFiles($full, $pattern, $recursive)
        ];

        unset($full);
        return $result;
    }

    /**
     * Copy directory
     */
    public function copyDirectory(string $src, string $dst): array
    {
        $full_src = $this->resolve($src, true, 'dir');
        $full_dst = $this->resolve($dst);

        if (is_array($full_src)) {
            return $full_src;
        }
        if (is_array($full_dst)) {
            return $full_dst;
        }

        $copied = $this->fileIO->copyDir($full_src, $full_dst);
        if (-1 === $copied) {
            $output = ['success' => false, 'error' => 'Failed to copy: ' . $src . ' -> ' . $dst];
        } else {
            $output = ['success' => true, 'src' => $src, 'dst' => $dst, 'copied' => $copied];
        }

        unset($full_src, $full_dst, $copied);
        return $output;
    }

    /**
     * Delete directory recursively
     */
    public function deleteDirectory(string $path): array
    {
        $full = $this->resolve($path, true, 'dir');
        if (is_array($full)) {
            return $full;
        }

        $removed = $this->fileIO->delDir($full);
        if (-1 === $removed) {
            $output = ['success' => false, 'error' => 'Failed to delete directory: ' . $path];
        } else {
            $output = ['success' => true, 'path' => $path, 'removed' => $removed];
        }

        unset($full, $removed);
        return $output;
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve path with sandbox boundary check
     *
     * @param string $path      User-provided path
     * @param bool   $mustExist Whether the path must already exist
     * @param string $type      'file' or 'dir' for validation
     *
     * @return string|array Full path on success, error array on failure
     */
    private function resolve(string $path, bool $mustExist = false, string $type = ''): string|array
    {
        $full_path = $this->resolvePath($path);

        if (false === $full_path) {
            return ['success' => false, 'error' => 'Path is outside the allowed root directory'];
        }

        if ($mustExist) {
            if ('file' === $type && !is_file($full_path)) {
                return ['success' => false, 'error' => 'File not found: ' . $path];
            }
            if ('dir' === $type && !is_dir($full_path)) {
                return ['success' => false, 'error' => 'Directory not found: ' . $path];
            }
        }

        return $full_path;
    }

    /**
     * Sandbox path resolution
     *
     * @param string $path
     *
     * @return string|false
     */
    private function resolvePath(string $path): string|false
    {
        // Relative path → prepend root
        if ('' !== $this->root_path && !str_starts_with($path, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:/', $path)) {
            $full_path = $this->root_path . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        } else {
            $full_path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        }

        // Normalize
        $full_path = realpath($full_path) ?: $full_path;

        // Sandbox boundary check
        if ($this->sandbox_enabled && '' !== $this->root_path) {
            $real_root = realpath($this->root_path);
            if (false !== $real_root && !str_starts_with($full_path, $real_root)) {
                return false;
            }
            unset($real_root);
        }

        return $full_path;
    }
}