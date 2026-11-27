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

use modules\agent_core\go as agent_core;
use Nervsys\Core\Factory;

class handler extends Factory
{
    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     */
    public function cleanContext(array $payload_data, agent_core $agent_core): array
    {
        $agent_core->core->context->cleanHistory(
            $payload_data['session_id'],
            $payload_data['worker_name'],
            $payload_data['keep_normal'],
            $payload_data['max_tool_pairs'],
        );

        $result = [
            'status'  => 'success',
            'message' => '[系统提醒] 上下文已清理，继续原有任务',
            'history' => '[上下文摘要]' . "\n\n" . ($payload_data['history_summary'] ?? '无重要摘要，可直接继续原有话题')
        ];

        unset($payload_data, $agent_core);
        return $result;
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return string[]
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    public function readFile(array $payload_data, agent_core $agent_core): array
    {
        $file_path = $agent_core->utils->securePath($payload_data['file_path']);

        if (!is_file($file_path)) {
            return ['status' => 'error', 'error' => '文件不存在：' . $file_path];
        }

        $handle = fopen($file_path, 'rb');

        if (false === $handle) {
            return ['status' => 'error', 'error' => '文件打开失败：' . $file_path];
        }

        try {
            $file_sample = fread($handle, 8192);

            if (false === $file_sample) {
                return ['status' => 'error', 'error' => '文件读取失败：' . $file_path];
            }

            $payload_data['file_path'] = $file_path;

            if ('' !== $agent_core->utils->getImageType($file_sample)) {
                $result = $this->readImage($payload_data, $agent_core);
            } elseif (str_contains($file_sample, "\0")) {
                $range = $payload_data['read_range'];

                if (
                    !array_is_list($range)
                    || 2 < count($range)
                    || ([] !== $range && (!is_int($range[0]) || 0 > $range[0]))
                    || (2 === count($range) && (!is_int($range[1]) || 0 > $range[1]))
                ) {
                    return ['status' => 'error', 'error' => '二进制read_range为[]、[字节偏移]或[字节偏移,最大字节数]，均须非负整数；长度0表示读至末尾'];
                }

                if ('' !== $payload_data['pattern']) {
                    return ['status' => 'error', 'error' => '二进制文件不支持pattern搜索'];
                }

                $result = $this->readBinaryFile($handle, $file_path, $range[0] ?? 0, $range[1] ?? 0);
            } else {
                $range   = $payload_data['read_range'];
                $pattern = $payload_data['pattern'];
                $context = $payload_data['context_lines'];

                if (
                    [] !== $range
                    && (
                        !array_is_list($range)
                        || 2 !== count($range)
                        || !is_int($range[0])
                        || !is_int($range[1])
                        || 1 > $range[0]
                        || $range[1] < $range[0]
                    )
                ) {
                    return ['status' => 'error', 'error' => 'read_range为[]或[起始行,结束行]，1≤起始行≤结束行'];
                }

                if (
                    !array_is_list($context)
                    || 2 !== count($context)
                    || !is_int($context[0])
                    || !is_int($context[1])
                    || 0 > min($context)
                ) {
                    return ['status' => 'error', 'error' => 'context_lines为[上文行数,下文行数]，须为两个非负整数'];
                }

                if (false !== strpbrk($pattern, "\r\n\0")) {
                    return ['status' => 'error', 'error' => 'pattern须为不含换行和NUL的UTF-8文本'];
                }

                if (!rewind($handle)) {
                    return ['status' => 'error', 'error' => '文件定位失败：' . $file_path];
                }

                $result = $this->readTextFile($handle, $file_path, $range, $pattern, $context);
            }
        } finally {
            fclose($handle);
        }

        unset($payload_data, $agent_core, $file_path, $handle, $file_sample, $range, $pattern, $context);
        return $result;
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array|string[]
     */
    public function editFile(array $payload_data, agent_core $agent_core): array
    {
        $source_handle = false;
        $temp_handle   = false;
        $temp_path     = false;

        try {
            $edits = $payload_data['edits'] ?? null;

            if (!is_array($edits) || !array_is_list($edits) || [] === $edits) {
                return ['status' => 'error', 'error' => 'edits须为非空编辑数组'];
            }

            foreach ($edits as $index => $edit) {
                $error = $this->validateEdit($edit);

                if ('' !== $error) {
                    return ['status' => 'error', 'error' => '第' . ($index + 1) . '项：' . $error];
                }
            }

            $file_path = $agent_core->utils->securePath($payload_data['file_path']);
            $file_dir  = dirname($file_path);
            $exists    = is_file($file_path);

            if (file_exists($file_path) && !$exists) {
                return ['status' => 'error', 'error' => '目标不是文件'];
            }

            if (!$exists && 'write' !== $edits[0]['op']) {
                return ['status' => 'error', 'error' => '文件不存在，首项须为全文写入'];
            }

            if (!is_dir($file_dir)) {
                try {
                    $mkdir = mkdir($file_dir, 0777, true);
                } catch (\Throwable) {
                    $mkdir = true;
                }

                if (!$mkdir) {
                    return ['status' => 'error', 'error' => '无法创建目标目录'];
                }

                unset($mkdir);
            }

            $source_handle = $exists ? fopen($file_path, 'rb') : tmpfile();

            if (false === $source_handle) {
                return ['status' => 'error', 'error' => '无法打开输入文件'];
            }

            $results = [];

            foreach ($edits as $index => $edit) {
                $temp_handle = tmpfile();

                if (false === $temp_handle) {
                    return ['status' => 'error', 'error' => '无法创建临时流'];
                }

                $result = $this->applyEdit($source_handle, $temp_handle, $edit);

                if ('success' !== $result['status']) {
                    return ['status' => 'error', 'error' => '第' . ($index + 1) . '项：' . $result['error'] . '，目标文件未改'];
                }

                fclose($source_handle);
                $source_handle = $temp_handle;

                $results[]   = $result;
                $temp_handle = false;
            }

            $temp_path   = tempnam($file_dir, '.edit-');
            $temp_handle = false !== $temp_path ? fopen($temp_path, 'w+b') : false;

            if (false === $temp_handle || false === stream_copy_to_stream($source_handle, $temp_handle) || !fflush($temp_handle)) {
                return ['status' => 'error', 'error' => '最终文件准备失败，目标文件未改'];
            }

            $bytes_written = ftell($temp_handle);

            fclose($temp_handle);
            $temp_handle = false;
            fclose($source_handle);
            $source_handle = false;

            if (false === $bytes_written || !@rename($temp_path, $file_path)) {
                return ['status' => 'error', 'error' => '无法提交编辑文件，目标文件未改'];
            }

            clearstatcache(true, $file_path);

            return [
                'status'        => 'success',
                'file_path'     => $file_path,
                'edit_type'     => 'batch',
                'bytes_written' => $bytes_written,
                'edits'         => $results,
                'replacements'  => array_sum(array_column($results, 'replacements'))
            ];
        } catch (\Throwable) {
            return ['status' => 'error', 'error' => '文件编辑失败，请检查文件权限及输入'];
        } finally {
            if (is_resource($source_handle)) {
                fclose($source_handle);
            }

            if (is_resource($temp_handle)) {
                fclose($temp_handle);
            }

            if (false !== $temp_path && is_file($temp_path)) {
                unlink($temp_path);
            }

            unset($payload_data, $agent_core, $source_handle, $temp_handle, $temp_path, $edits, $index, $edit, $error, $file_path, $file_dir, $exists, $results, $result, $bytes_written);
        }
    }

    /**
     * @param        $handle
     * @param string $file_path
     * @param array  $range
     * @param string $pattern
     * @param array  $context
     *
     * @return array|string[]
     */
    private function readTextFile($handle, string $file_path, array $range, string $pattern, array $context): array
    {
        $start     = $range[0] ?? 1;
        $end       = $range[1] ?? PHP_INT_MAX;
        $total     = 0;
        $matches   = 0;
        $remaining = 0;
        $previous  = [];
        $output    = [];
        $matched   = [];

        while (true) {
            $result = $this->readTextLine($handle, true);

            if (false === $result['line']) {
                if ('' !== $result['error']) {
                    return ['status' => 'error', 'error' => '第' . ($total + 1) . '行：' . $result['error']];
                }

                break;
            }

            ++$total;

            $line = $result['line'];

            if (1 === $total && str_starts_with($line, "\xEF\xBB\xBF")) {
                $line = substr($line, 3);
            }

            if (str_ends_with($line, "\n")) {
                $line = substr($line, 0, -1);
            }

            if ($total < $start || $total > $end) {
                continue;
            }

            if ('' === $pattern) {
                $output[$total] = $line;
                continue;
            }

            if (false !== mb_stripos($line, $pattern, 0, 'UTF-8')) {
                ++$matches;

                foreach ($previous as $number => $text) {
                    $output[$number] ??= $text;
                }

                $output[$total]  = $line;
                $matched[$total] = true;

                $remaining = $context[1];
            } elseif (0 < $remaining) {
                $output[$total] = $line;
                --$remaining;
            }

            if (0 < $context[0]) {
                $previous[$total] = $line;

                if (count($previous) > $context[0]) {
                    unset($previous[array_key_first($previous)]);
                }
            }
        }

        $result = [
            'status'      => 'success',
            'file_type'   => 'text',
            'file_path'   => $file_path,
            'total_lines' => $total,
            'content'     => ''
        ];

        if ('' !== $pattern) {
            $result['total_matches'] = $matches;
        }

        if ([] === $output) {
            if (0 < $total && $start > $total) {
                $result['message'] = '起始行超出总行数';
            } elseif ('' !== $pattern) {
                $result['message'] = '范围内无匹配行';
            }

            return $result;
        }

        $formatted = [];
        $last      = 0;

        foreach ($output as $number => $text) {
            if (0 < $last && $number > $last + 1) {
                $formatted[] = '...';
            }

            $formatted[] = (isset($matched[$number]) ? 'MATCH ' : '') . 'LINE ' . $number . ': ' . $text;
            $last        = $number;
        }

        $result['content'] = implode("\n", $formatted);

        unset($handle, $file_path, $range, $pattern, $context, $start, $end, $total, $matches, $remaining, $previous, $output, $matched, $line, $number, $text, $formatted, $last);
        return $result;
    }

    /**
     * @param      $handle
     * @param bool $tolerant
     *
     * @return array
     */
    private function readTextLine($handle, bool $tolerant = false): array
    {
        $bytes = 16777216; // max line bytes 16MB
        $line  = fgets($handle, $bytes + 3);

        if (false === $line) {
            return ['line' => false, 'error' => feof($handle) ? '' : '文本文件读取失败'];
        }

        $line_length   = strlen($line);
        $ending_length = str_ends_with($line, "\r\n") ? 2 : (str_ends_with($line, "\n") ? 1 : 0);

        if ($bytes < $line_length - $ending_length || (0 === $ending_length && !feof($handle))) {
            return ['line' => false, 'error' => '单行长度超过16MB限制'];
        }

        if ($tolerant) {
            try {
                $detected = mb_detect_encoding($line, ['GB18030', 'UTF-8', 'BIG-5', 'ASCII'], true);
                $line     = iconv($detected ?: 'UTF-8', 'UTF-8//IGNORE', $line) ?: '';

                if ('' === $line) {
                    throw new \RuntimeException('文本编码转换失败');
                }
            } catch (\Throwable) {
                return ['line' => false, 'error' => '文本编码转换失败'];
            }
        } elseif (!mb_check_encoding($line, 'UTF-8') || str_contains($line, "\0")) {
            return ['line' => false, 'error' => '仅支持不含NUL的UTF-8文本'];
        }

        $body = 0 < $ending_length ? substr($line, 0, -$ending_length) : $line;

        if (str_contains($body, "\r")) {
            return ['line' => false, 'error' => '换行仅支持LF或CRLF'];
        }

        if (2 === $ending_length) {
            $line = $body . "\n";
        }

        unset($handle, $tolerant, $bytes, $line_length, $ending_length, $detected, $body);
        return ['line' => $line, 'error' => ''];
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array|string[]
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    private function readImage(array $payload_data, agent_core $agent_core): array
    {
        $file_path  = $payload_data['file_path'];
        $image_info = getimagesize($file_path);

        if (false === $image_info) {
            return ['status' => 'error', 'error' => '图片无法识别或格式不支持'];
        }

        if (!in_array($image_info['mime'], ['image/jpeg', 'image/tiff', 'image/webp', 'image/png', 'image/bmp', 'image/gif'], true)) {
            return ['status' => 'error', 'error' => '图片格式不支持：' . $image_info['mime']];
        }

        $filename    = basename($file_path);
        $binary_data = file_get_contents($file_path);

        if (false === $binary_data) {
            return ['status' => 'error', 'error' => '图片读取失败：' . $file_path];
        }

        $data_url = $agent_core->utils->resizeImage($binary_data);

        $agent_core->core->context->addUserMessage(
            $payload_data['session_id'],
            $payload_data['process_name'],
            [
                ['type' => 'text', 'content' => '"' . $filename . '"：图片已载入上下文，可直接识别，无需重读。'],
                ['type' => 'image', 'content' => $data_url]
            ]
        );

        if ($payload_data['render_image']) {
            $message = $agent_core->utils->getMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                $payload_data['process_name'],
                0,
                $payload_data['session_id']
            );

            $agent_core->core->sendImageMessage(
                $message,
                $data_url,
                $filename
            );

            $agent_core->memory->saveChat(
                $payload_data['session_id'],
                $agent_core->utils->UUIDv4(),
                $payload_data['process_name'],
                0,
                'assistant',
                [
                    'content' => $filename,
                    'images'  => [
                        'filename'   => $filename,
                        'file_path'  => $file_path,
                        'img_base64' => $data_url
                    ]
                ]
            );
        }

        $result = [
            'status'    => 'success',
            'file_type' => 'image',
            'file_path' => $file_path,
            'mime_type' => $image_info['mime'],
            'width'     => $image_info[0],
            'height'    => $image_info[1],
            'message'   => '图片已载入上下文'
        ];

        unset($payload_data, $agent_core, $file_path, $image_info, $filename, $binary_data, $data_url, $message);
        return $result;
    }

    /**
     * @param        $handle
     * @param string $file_path
     * @param int    $offset
     * @param int    $length Maximum bytes; 0 reads to EOF.
     *
     * @return array|string[]
     */
    private function readBinaryFile($handle, string $file_path, int $offset, int $length = 0): array
    {
        $file_stat = fstat($handle);

        if (false === $file_stat) {
            return ['status' => 'error', 'error' => '无法获取文件大小：' . $file_path];
        }

        $total_bytes = $file_stat['size'];

        $result = [
            'status'      => 'success',
            'file_type'   => 'binary',
            'file_path'   => $file_path,
            'total_bytes' => $total_bytes,
            'content'     => ''
        ];

        if ($offset >= $total_bytes) {
            $result['message'] = '起始位置已到文件末尾或超出文件大小';
            return $result;
        }

        if (0 !== fseek($handle, $offset)) {
            return ['status' => 'error', 'error' => '二进制文件定位失败：' . $file_path];
        }

        $rows      = [];
        $position  = $offset;
        $remaining = 0 === $length ? $total_bytes - $offset : min($length, $total_bytes - $offset);

        while (0 < $remaining && !feof($handle)) {
            $chunk = fread($handle, min(8192, $remaining));

            if (false === $chunk || ('' === $chunk && !feof($handle))) {
                return ['status' => 'error', 'error' => '二进制文件读取失败：' . $file_path];
            }

            if ('' === $chunk) {
                break;
            }

            $remaining -= strlen($chunk);

            foreach (str_split($chunk, 16) as $binary_line) {
                $rows[]   = sprintf('%08X | %s', $position, implode(' ', str_split(strtoupper(bin2hex($binary_line)), 2)));
                $position += strlen($binary_line);
            }
        }

        $result['content'] = implode("\n", $rows);

        unset($handle, $file_path, $offset, $length, $file_stat, $total_bytes, $rows, $position, $remaining, $chunk, $binary_line);
        return $result;
    }

    /**
     * Validate one operation before execution.
     *
     * @param mixed $edit
     *
     * @return string
     */
    private function validateEdit(mixed $edit): string
    {
        if (!is_array($edit)) {
            return '编辑项须为对象';
        }

        $op = $edit['op'] ?? null;

        if (!is_string($op)) {
            return 'op须为字符串';
        }

        $allowed = match ($op) {
            'write' => ['op', 'new'],
            'replace' => ['op', 'old', 'new', 'limit'],
            'insert_lines' => ['op', 'line', 'new'],
            'replace_lines' => ['op', 'start', 'end', 'new'],
            default => []
        };

        if ([] === $allowed) {
            return '不支持的op';
        }

        if ([] !== array_diff(array_keys($edit), $allowed)) {
            return '包含不适用于' . $op . '的字段';
        }

        $error = $this->validateEditText($edit['new'] ?? null, 'new');

        if ('' !== $error) {
            return $error;
        }

        $result = match ($op) {
            'write' => '',
            'replace' => $this->validateReplaceEdit($edit),
            'insert_lines', 'replace_lines' => $this->validateLineEdit($edit)
        };

        unset($edit, $op, $allowed, $error);
        return $result;
    }

    /**
     * Validate required UTF-8 text without coercing missing or null values.
     *
     * @param mixed  $text
     * @param string $field
     *
     * @return string
     */
    private function validateEditText(mixed $text, string $field): string
    {
        if (!is_string($text)) {
            return $field . '须为字符串';
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            return $field . '须为有效UTF-8文本';
        }

        if (str_contains($text, "\0")) {
            return $field . '不能包含NUL';
        }

        unset($text, $field);
        return '';
    }

    /**
     * Validate line numbers; file-dependent upper bounds are checked during execution.
     *
     * @param array $edit
     *
     * @return string
     */
    private function validateLineEdit(array $edit): string
    {
        $fields = 'insert_lines' === $edit['op'] ? ['line'] : ['start', 'end'];

        foreach ($fields as $field) {
            if (!is_int($edit[$field] ?? null)) {
                return $field . '须为整数';
            }

            if (1 > $edit[$field]) {
                return $field . '须大于0';
            }
        }

        if ('replace_lines' === $edit['op'] && $edit['start'] > $edit['end']) {
            return 'start不能大于end';
        }

        unset($edit, $fields, $field);
        return '';
    }

    /**
     * Validate literal replacement fields.
     *
     * @param array $edit
     *
     * @return string
     */
    private function validateReplaceEdit(array $edit): string
    {
        $error = $this->validateEditText($edit['old'] ?? null, 'old');

        if ('' !== $error) {
            return $error;
        }

        if ('' === $edit['old']) {
            return 'old不能为空';
        }

        if (array_key_exists('limit', $edit)) {
            if (!is_int($edit['limit'])) {
                return 'limit须为整数';
            }

            if (0 > $edit['limit']) {
                return 'limit不能为负数';
            }
        }

        unset($edit, $error);
        return '';
    }

    /**
     * @param        $handle
     * @param string $data
     *
     * @return bool
     */
    private function writeAll($handle, string $data): bool
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset, 1048576));

            if (false === $written || 0 === $written) {
                return false;
            }

            $offset += $written;
        }

        unset($handle, $data, $offset, $length, $written);
        return true;
    }

    /**
     * Apply one validated operation to streams without committing the target file.
     *
     * @param       $source_handle
     * @param       $temp_handle
     * @param array $edit
     *
     * @return array|string[]
     */
    private function applyEdit($source_handle, $temp_handle, array $edit): array
    {
        $edit_type = $edit['op'];
        $content   = $this->normalizeLineEndings($edit['new']);

        $replacements = 0;

        if (in_array($edit_type, ['insert_lines', 'replace_lines'], true)) {
            $sample = fread($source_handle, 3);

            if (false === $sample || !rewind($source_handle)) {
                return ['status' => 'error', 'error' => '输入流读取失败'];
            }

            if ("\xEF\xBB\xBF" === $sample && (0 !== fseek($source_handle, 3) || !$this->writeAll($temp_handle, $sample))) {
                return ['status' => 'error', 'error' => 'BOM处理失败'];
            }
        }

        $error = match ($edit_type) {
            'write' => $this->overwriteTextFile($source_handle, $temp_handle, $content),
            'replace' => $this->replaceTextFile($source_handle, $temp_handle, $content, $this->normalizeLineEndings($edit['old']), $edit['limit'] ?? 0, $replacements),
            'insert_lines' => $this->editTextLines($source_handle, $temp_handle, $content, [$edit['line']], 'insert'),
            'replace_lines' => $this->editTextLines($source_handle, $temp_handle, $content, [$edit['start'], $edit['end']], 'lines')
        };

        if ('' !== $error || ('replace' === $edit_type && 0 === $replacements)) {
            return ['status' => 'error', 'error' => $error ?: '未找到匹配文本'];
        }

        if (!fflush($temp_handle) || !rewind($temp_handle)) {
            return ['status' => 'error', 'error' => '临时流定位失败'];
        }

        while (true) {
            $line_result = $this->readTextLine($temp_handle);

            if ('' !== $line_result['error']) {
                return ['status' => 'error', 'error' => '编辑后：' . $line_result['error']];
            }

            if (false === $line_result['line']) {
                break;
            }
        }

        if (!rewind($temp_handle)) {
            return ['status' => 'error', 'error' => '临时流定位失败'];
        }

        $result = ['status' => 'success', 'edit_type' => $edit_type, 'replacements' => $replacements];

        unset($source_handle, $temp_handle, $edit, $edit_type, $content, $replacements, $sample, $error, $line_result);
        return $result;
    }

    /**
     * @param        $source_handle
     * @param        $temp_handle
     * @param string $content
     * @param array  $line_range
     * @param string $edit_type
     *
     * @return string
     */
    private function editTextLines($source_handle, $temp_handle, string $content, array $line_range, string $edit_type): string
    {
        $line_number         = 0;
        $start_line          = $line_range[0];
        $end_line            = $line_range[1] ?? 0;
        $write_failed        = false;
        $last_had_newline    = false;
        $inserted            = false;
        $replacement_written = false;

        while (true) {
            $line_result = $this->readTextLine($source_handle);

            if (false === $line_result['line']) {
                if ('' !== $line_result['error']) {
                    return $line_result['error'];
                }

                break;
            }

            $line = $line_result['line'];

            ++$line_number;

            $last_had_newline = str_ends_with($line, "\n");

            if ('insert' === $edit_type) {
                if (!$inserted && $line_number === $start_line) {
                    $insert_content = $content;

                    if ('' !== $insert_content && !str_ends_with($insert_content, "\n")) {
                        $insert_content .= "\n";
                    }

                    if (!$this->writeAll($temp_handle, $insert_content)) {
                        $write_failed = true;
                        break;
                    }

                    $inserted = true;
                }

                if (!$this->writeAll($temp_handle, $line)) {
                    $write_failed = true;
                    break;
                }

                continue;
            }

            if ($line_number < $start_line) {
                if (!$this->writeAll($temp_handle, $line)) {
                    $write_failed = true;
                    break;
                }

                continue;
            }

            if ($line_number <= $end_line) {
                continue;
            }

            if (!$replacement_written) {
                $replace_content = $content;

                if ('' !== $replace_content && !str_ends_with($replace_content, "\n")) {
                    $replace_content .= "\n";
                }

                if (!$this->writeAll($temp_handle, $replace_content)) {
                    $write_failed = true;
                    break;
                }

                $replacement_written = true;
            }

            if (!$this->writeAll($temp_handle, $line)) {
                $write_failed = true;
                break;
            }
        }

        if (!$write_failed && 'insert' === $edit_type && !$inserted) {
            if ($start_line !== $line_number + 1) {
                return '插入行须在1–' . ($line_number + 1) . '内';
            }

            $append_content = $content;
            $separator      = '' !== $append_content && 0 < $line_number && !$last_had_newline ? "\n" : '';
            $write_failed   = !$this->writeAll($temp_handle, $separator . $append_content);
        } elseif (!$write_failed && 'lines' === $edit_type) {
            if ($end_line > $line_number) {
                return '结束行' . $end_line . '超出总行数' . $line_number;
            }

            if (!$replacement_written) {
                $replace_content = $content;

                if ('' !== $replace_content && $last_had_newline && !str_ends_with($replace_content, "\n")) {
                    $replace_content .= "\n";
                }

                $write_failed = !$this->writeAll($temp_handle, $replace_content);
            }
        }

        unset($source_handle, $temp_handle, $content, $line_range, $edit_type, $line_number, $start_line, $end_line, $last_had_newline, $inserted, $replacement_written, $line_result, $line, $insert_content, $replace_content, $append_content, $separator);
        return $write_failed ? '临时文件写入失败' : '';
    }

    /**
     * @param        $source_handle
     * @param        $temp_handle
     * @param string $content
     * @param string $matched_text
     * @param int    $replace_limit
     * @param int    $replacements
     *
     * @return string
     */
    private function replaceTextFile($source_handle, $temp_handle, string $content, string $matched_text, int $replace_limit, int &$replacements): string
    {
        $carry        = '';
        $write_failed = false;
        $match_length = strlen($matched_text);

        while (true) {
            $line_result = $this->readTextLine($source_handle);

            if (false === $line_result['line']) {
                if ('' !== $line_result['error']) {
                    return $line_result['error'];
                }

                break;
            }

            $line   = $line_result['line'];
            $buffer = $carry . $line;

            while ((0 === $replace_limit || $replacements < $replace_limit) && false !== ($match_position = strpos($buffer, $matched_text))) {
                if (!$this->writeAll($temp_handle, substr($buffer, 0, $match_position)) || !$this->writeAll($temp_handle, $content)) {
                    $write_failed = true;
                    break 2;
                }

                ++$replacements;

                $buffer = substr($buffer, $match_position + $match_length);
            }

            $keep_length = 0 < $replace_limit && $replacements >= $replace_limit
                ? 0
                : min(strlen($buffer), $match_length - 1);

            $flush_length = strlen($buffer) - $keep_length;

            if (0 < $flush_length && !$this->writeAll($temp_handle, substr($buffer, 0, $flush_length))) {
                $write_failed = true;
                break;
            }

            $carry = 0 < $keep_length ? substr($buffer, -$keep_length) : '';
        }

        if (!$write_failed && '' !== $carry && !$this->writeAll($temp_handle, $carry)) {
            $write_failed = true;
        }

        unset($source_handle, $temp_handle, $content, $matched_text, $replace_limit, $replacements, $carry, $match_length, $line_result, $line, $buffer, $match_position, $keep_length, $flush_length);
        return $write_failed ? '临时文件写入失败' : '';
    }

    /**
     * @param        $source_handle
     * @param        $temp_handle
     * @param string $content
     *
     * @return string
     */
    private function overwriteTextFile($source_handle, $temp_handle, string $content): string
    {
        while (true) {
            $line_result = $this->readTextLine($source_handle);

            if (false === $line_result['line']) {
                if ('' !== $line_result['error']) {
                    return $line_result['error'];
                }

                break;
            }
        }

        $result = $this->writeAll($temp_handle, $content) ? '' : '临时文件写入失败';

        unset($source_handle, $temp_handle, $content, $line_result);
        return $result;
    }

    /**
     * @param string $text
     *
     * @return string
     */
    private function normalizeLineEndings(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}