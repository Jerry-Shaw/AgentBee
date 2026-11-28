<?php

/**
 * Agent System toolset for AgentBee core
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

namespace modules\agent_toolsets\System;

use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\ProcMgr;

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
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @param string $history_summary
     * @param int    $keep_normal
     * @param int    $max_tool_pairs
     *
     * @return array
     */
    public function cleanContext(string $history_summary, int $keep_normal = 6, int $max_tool_pairs = 2): array
    {
        $result = [
            'action'          => __FUNCTION__,
            'worker_name'     => WORKER_MAIN,
            'history_summary' => $history_summary,
            'keep_normal'     => $keep_normal,
            'max_tool_pairs'  => $max_tool_pairs,
            'handler'         => handler::class
        ];

        unset($history_summary, $keep_normal, $max_tool_pairs);
        return $result;
    }

    /**
     * @param string $skill_name
     *
     * @return array|string[]
     */
    public function loadSkill(string $skill_name): array
    {
        $skill_path = $this->utils->app->root_path . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . $skill_name . DIRECTORY_SEPARATOR;
        $skill_md   = $skill_path . 'SKILL.md';

        if (!is_file($skill_md)) {
            return [
                'status' => 'error',
                'error'  => '技能 "' . $skill_name . '" 不存在或缺少 SKILL.md 文件，无法加载，请忽略。'
            ];
        }

        $content = file_get_contents($skill_md);

        if (false === $content) {
            return [
                'status' => 'error',
                'error'  => '无法读取技能 "' . $skill_name . '" 的指令文件，请忽略。'
            ];
        }

        $parts = explode('---', $content, 3);

        if (3 <= count($parts)) {
            $body = trim($parts[2]);
        } elseif (2 === count($parts)) {
            $body = trim($parts[1]);
        } else {
            $body = trim($content);
        }

        $resources    = [];
        $dependencies = [];

        foreach (['requirements.txt', 'package.json', 'scripts/', 'references/', 'examples/', 'assets/'] as $value) {
            $full_path = $skill_path . $value;

            if (is_dir($full_path)) {
                $resources[] = $value;
            } elseif (is_file($full_path)) {
                $dependencies[] = $value;
            }
        }

        $result = ['status' => 'success', 'skill_path' => $skill_path];

        if ([] !== $resources) {
            $result['resources'] = $resources;
        }

        if ([] !== $dependencies) {
            $result['dependencies']  = $dependencies;
            $result['install_guide'] = '本技能有依赖，请先进入技能目录，安装依赖后再执行，详情查看dependencies字段。';
        }

        if ('' !== $body) {
            $result['skill_data'] = $body;
        } else {
            $result['message'] = '警告：技能"' . $skill_name . '"的指令为空';
        }

        unset($skill_name, $skill_path, $skill_md, $content, $parts, $body, $resources, $dependencies, $value, $full_path);
        return $result;
    }

    /**
     * @param string $title
     * @param string $message
     * @param string $level
     *
     * @return string
     */
    public function notification(string $title, string $message, string $level = 'info'): string
    {
        $skips   = ['`', ';', '&', '|', '$', '(', ')', '<', '>', '\\'];
        $title   = str_replace($skips, '', $title);
        $message = str_replace($skips, '', $message);
        $title   = str_replace(["\r\n", "\r"], "\n", $title);
        $message = str_replace(["\r\n", "\r"], "\n", $message);

        switch (PHP_OS) {
            case 'WINNT':
                $title   = str_replace(["\n", "'"], ['\n', "''"], $title);
                $message = str_replace(["\n", "'"], ['\n', "''"], $message);

                $colors = [
                    'info'    => '#4285F4',
                    'error'   => '#EA4335',
                    'warning' => '#FBBC05',
                    'success' => '#34A853',
                ];

                $bg_color   = $colors[$level] ?? $colors['info'];
                $fore_color = ('#FBBC05' === $bg_color) ? '#000000' : '#FFFFFF';

                $cmd = 'start /b powershell -NoP -Exec Bypass -Command "'
                    . 'Add-Type -AssemblyName System.Windows.Forms; '
                    . 'Add-Type -AssemblyName System.Drawing; '
                    . '$f = New-Object Windows.Forms.Form; '
                    . '$f.Width = 360; '
                    . '$f.FormBorderStyle = \'None\'; '
                    . '$f.BackColor = \'White\'; '
                    . '$f.TopMost = $true; '
                    . '$f.StartPosition = \'Manual\'; '
                    . '$p = New-Object Windows.Forms.Panel; '
                    . '$p.BackColor = \'' . $bg_color . '\'; '
                    . '$p.Height = 30; '
                    . '$p.Dock = \'Top\'; '
                    . '$f.Controls.Add($p); '
                    . '$l = New-Object Windows.Forms.Label; '
                    . '$l.Text = \'' . $title . '\'; '
                    . '$l.Font = \'Microsoft YaHei, 11, style=Bold\'; '
                    . '$l.ForeColor = \'' . $fore_color . '\'; '
                    . '$l.Location = \'15, 5\'; '
                    . '$l.AutoSize = $true; '
                    . '$p.Controls.Add($l); '
                    . '$m = New-Object Windows.Forms.Label; '
                    . '$m.Text = \'' . $message . '\'; '
                    . '$m.Text = $m.Text -replace \'\\\\n\', [char]10; '
                    . '$m.Location = \'15, 55\'; '
                    . '$m.Width = 330; '
                    . '$m.Font = \'Microsoft YaHei, 10\'; '
                    . '$f.Controls.Add($m); '
                    . '$prefSize = $m.GetPreferredSize([System.Drawing.Size]::new(330, 0)); '
                    . '$m.Height = $prefSize.Height; '
                    . '$f.Height = 55 + $m.Height + 28 + 10; '
                    . '$b = New-Object Windows.Forms.Button; '
                    . '$b.Text = \'OK\'; '
                    . '$b.Font = \'Microsoft YaHei, 9\'; '
                    . '$b.Height = 28; '
                    . '$b.Width = 70; '
                    . '$b.Location = New-Object Drawing.Point(270, (55 + $m.Height)); '
                    . '$b.Add_Click({ $f.Close() }); '
                    . '$f.Controls.Add($b); '
                    . '$scr = [System.Windows.Forms.Screen]::PrimaryScreen.WorkingArea; '
                    . '$f.Location = New-Object Drawing.Point(($scr.Width - $f.Width - 20), ($scr.Height - $f.Height - 20)); '
                    . '$path = New-Object System.Drawing.Drawing2D.GraphicsPath; '
                    . '$radius = 10; '
                    . '$rect = New-Object System.Drawing.Rectangle(0, 0, $f.Width, $f.Height); '
                    . '$path.AddArc($rect.X, $rect.Y, $radius*2, $radius*2, 180, 90); '
                    . '$path.AddArc($rect.Right - $radius*2, $rect.Y, $radius*2, $radius*2, 270, 90); '
                    . '$path.AddArc($rect.Right - $radius*2, $rect.Bottom - $radius*2, $radius*2, $radius*2, 0, 90); '
                    . '$path.AddArc($rect.X, $rect.Bottom - $radius*2, $radius*2, $radius*2, 90, 90); '
                    . '$path.CloseAllFigures(); '
                    . '$f.Region = New-Object System.Drawing.Region($path); '
                    . '$f.ControlBox = $false; '
                    . '$f.ShowDialog() | Out-Null"';

                unset($colors, $bg_color, $fore_color);
                break;

            case 'Linux':
                $title   = escapeshellarg($title);
                $message = escapeshellarg($message);

                $cmd = 'notify-send -- ' . $title . ' ' . $message . ' -t 86400000 > /dev/null 2>&1 &';
                break;

            case 'Darwin':
                $title   = str_replace('"', '\"', $title);
                $message = str_replace('"', '\"', $message);

                $cmd = 'osascript -e \'display dialog "' . $message . '" with title "' . $title . '" buttons {"OK"} default button "OK"\' > /dev/null 2>&1 &';
                break;

            default:
                return '不支持的操作系统：' . PHP_OS;
        }

        pclose(popen($cmd, 'r'));

        unset($title, $message, $level, $skips, $cmd);
        return '通知已成功发送。';
    }

    /**
     * @param string       $program
     * @param array|string $argv
     * @param int          $timeout
     * @param string       $descriptor
     * @param string       $work_path
     *
     * @return array|string[]
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function exec(string $program, array|string $argv = [], int $timeout = 30, string $descriptor = 'socket', string $work_path = ''): array
    {
        if ($this->utils->config->agent_config['sandbox_mode'] ?? true) {
            return [
                'status' => 'error',
                'error'  => '沙箱模式已开启，无法调用exec工具，请关闭沙箱模式后执行。'
            ];
        }

        $result = [
            'status' => 'success',
            'stdout' => '',
            'stderr' => ''
        ];

        $work_path = '' === $work_path
            ? $this->utils->config->agent_config['workspace_path']
            : $this->utils->securePath($work_path);

        if ([] === $argv && str_contains($program, ' ')) {
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
        $ProcMgr = ProcMgr::new(in_array($descriptor, ['socket', 'pipe'], true) ? $descriptor : 'socket');

        $proc_idx = $ProcMgr
            ->command([$program, ...$argv])
            ->setWorkDir($work_path)
            ->run($this->utils->getProcIDX());

        $proc_pid = $ProcMgr->getPid($proc_idx);

        $ProcMgr->awaitProc(
            $proc_idx,
            function (string $output) use (&$active, &$result): void
            {
                $active = time();
                $output = trim($output);

                if ('' !== $output) {
                    if ('' !== $result['stdout']) {
                        $result['stdout'] .= "\n";
                    }

                    $result['stdout'] .= $output;
                }

                unset($output);
            },
            function (string $output) use (&$active, &$result): void
            {
                $active = time();
                $output = trim($output);

                if ('' !== $output) {
                    if ('' !== $result['stderr']) {
                        $result['stderr'] .= "\n";
                    }

                    $result['stderr'] .= $output;
                }

                unset($output);
            },
            function () use (&$active, $timeout, $proc_pid, &$result): void
            {
                if (0 === $timeout) {
                    return;
                }

                if ($timeout < time() - $active) {
                    $this->utils->OSMgr->killPid($proc_pid);

                    $result['status'] = 'error';
                    $result['error']  = '进程超时，已被终止。';
                }
            }
        );

        $ProcMgr->exit();

        unset($program, $argv, $timeout, $descriptor, $work_path, $args, $active, $ProcMgr, $proc_idx, $proc_pid);
        return $result;
    }

    /**
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @param string $file_path
     * @param array  $read_range
     * @param string $pattern
     * @param array  $context_lines
     * @param bool   $render_image
     *
     * @return array
     */
    public function readFile(string $file_path, array $read_range = [1, 200], string $pattern = '', array $context_lines = [3, 3], bool $render_image = false): array
    {
        $result = [
            'action'        => __FUNCTION__,
            'file_path'     => $file_path,
            'read_range'    => $read_range,
            'pattern'       => $pattern,
            'context_lines' => $context_lines,
            'render_image'  => $render_image,
            'handler'       => handler::class
        ];

        unset($file_path, $read_range, $pattern, $context_lines, $render_image);
        return $result;
    }

    /**
     * Placeholder — intercepted by procWorker, forwarded to main process.
     *
     * @param string $file_path
     * @param array  $edits
     *
     * @return array
     */
    public function editFile(string $file_path, array $edits): array
    {
        $result = [
            'action'    => __FUNCTION__,
            'file_path' => $file_path,
            'edits'     => $edits,
            'handler'   => handler::class
        ];

        unset($file_path, $edits);
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
     * Search files by glob pattern.
     *
     * @param string $dir_path
     * @param string $pattern
     * @param bool   $recursive
     *
     * @return array
     */
    public function searchFiles(string $dir_path, string $pattern, bool $recursive): array
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

        unset($src_dir_path, $dst_dir_path, $overwrite, $src_full, $dst_full, $copied);
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