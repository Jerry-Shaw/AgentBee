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
        $file_path = $agent_core->utils->securePath($payload_data['file_path']);

        if (file_exists($file_path) && !is_file($file_path)) {
            return ['status' => 'error', 'error' => '目标不是可操作文件：' . $file_path];
        }

        $content      = $payload_data['content'];
        $line_range   = $payload_data['line_range'];
        $text_replace = $payload_data['text_replace'];

        if ([] !== $line_range && [] !== $text_replace) {
            return ['status' => 'error', 'error' => 'line_range与text_replace不能同时使用'];
        }

        if (str_contains($content, "\0")) {
            return ['status' => 'error', 'error' => 'content须为不含NUL的UTF-8文本'];
        }

        $content       = $this->normalizeLineEndings($content);
        $edit_type     = 'full';
        $matched_text  = '';
        $replace_limit = 0;
        $replacements  = 0;

        if ([] !== $text_replace) {
            if (
                !array_is_list($text_replace)
                || 2 !== count($text_replace)
                || !is_string($text_replace[0] ?? null)
                || '' === $text_replace[0]
                || !is_int($text_replace[1] ?? null)
                || 0 > $text_replace[1]
            ) {
                return ['status' => 'error', 'error' => 'text_replace为[非空原文,非负替换次数上限]，0表示全部替换'];
            }

            $edit_type     = 'replace';
            $matched_text  = $text_replace[0];
            $replace_limit = $text_replace[1];

            if (str_contains($matched_text, "\0")) {
                return ['status' => 'error', 'error' => '匹配文本须为不含NUL的UTF-8文本'];
            }

            $matched_text = $this->normalizeLineEndings($matched_text);
        } elseif ([] !== $line_range) {
            if (!array_is_list($line_range) || !in_array(count($line_range), [1, 2], true)) {
                return ['status' => 'error', 'error' => 'line_range为[行号]或[起始行,结束行]'];
            }

            foreach ($line_range as $line_number) {
                if (!is_int($line_number) || 1 > $line_number) {
                    return ['status' => 'error', 'error' => '行号须为正整数'];
                }
            }

            if (2 === count($line_range) && $line_range[0] > $line_range[1]) {
                return ['status' => 'error', 'error' => '起始行不能大于结束行'];
            }

            $edit_type = 1 === count($line_range) ? 'insert' : 'lines';
        }

        if (!is_file($file_path)) {
            if ('full' !== $edit_type) {
                return ['status' => 'error', 'error' => '文件不存在，仅支持全文模式创建文件'];
            }

            $file_dir = dirname($file_path);

            if (!is_dir($file_dir)) {
                try {
                    mkdir($file_dir, 0777, true);
                } catch (\Throwable) {
                }
            }

            $lines = explode("\n", $content);

            foreach ($lines as $line) {
                if (strlen($line) > 16777216) {
                    return ['status' => 'error', 'error' => '单行长度超过16MB限制'];
                }
            }

            $new_handle = fopen($file_path, 'wb');

            if (false === $new_handle) {
                return ['status' => 'error', 'error' => '文件创建失败：' . $file_path];
            }

            $written = $this->writeAll($new_handle, $content) && fflush($new_handle);

            fclose($new_handle);

            if (!$written) {
                return ['status' => 'error', 'error' => unlink($file_path) ? '文件写入失败，已清理' : '文件写入失败，清理失败：' . $file_path];
            }

            unset($file_dir, $lines, $line, $new_handle, $written);
            return [
                'status'        => 'success',
                'file_path'     => $file_path,
                'edit_type'     => 'full',
                'bytes_written' => strlen($content)
            ];
        }

        $source_handle = fopen($file_path, 'rb');

        if (false === $source_handle || !flock($source_handle, LOCK_EX)) {
            is_resource($source_handle) && fclose($source_handle);
            return ['status' => 'error', 'error' => '无法打开或锁定文件：' . $file_path];
        }

        $file_sample = fread($source_handle, 8192);

        if (false === $file_sample) {
            flock($source_handle, LOCK_UN);
            fclose($source_handle);
            return ['status' => 'error', 'error' => '文件读取失败：' . $file_path];
        }

        if ('' !== $agent_core->utils->getImageType($file_sample) || str_contains($file_sample, "\0")) {
            flock($source_handle, LOCK_UN);
            fclose($source_handle);
            return ['status' => 'error', 'error' => '只支持编辑文本文件'];
        }

        $temp_path   = tempnam(dirname($file_path), '.' . WORKER_MAIN . '-');
        $temp_handle = false !== $temp_path ? fopen($temp_path, 'w+b') : false;

        if (false === $temp_handle) {
            false !== $temp_path && unlink($temp_path);
            flock($source_handle, LOCK_UN);
            fclose($source_handle);
            return ['status' => 'error', 'error' => '无法创建临时文件'];
        }

        if (!rewind($source_handle)) {
            return $this->closeEditWithError($source_handle, $temp_handle, $temp_path, '文件定位失败');
        }

        if (in_array($edit_type, ['insert', 'lines'], true) && str_starts_with($file_sample, "\xEF\xBB\xBF")) {
            if (0 !== fseek($source_handle, 3) || !$this->writeAll($temp_handle, "\xEF\xBB\xBF")) {
                return $this->closeEditWithError($source_handle, $temp_handle, $temp_path, 'BOM处理失败，原文件未改');
            }
        }

        $edit_error = match ($edit_type) {
            'full' => $this->overwriteTextFile($source_handle, $temp_handle, $content),
            'replace' => $this->replaceTextFile($source_handle, $temp_handle, $content, $matched_text, $replace_limit, $replacements),
            default => $this->editTextLines($source_handle, $temp_handle, $content, $line_range, $edit_type),
        };

        if ('' !== $edit_error) {
            return $this->closeEditWithError($source_handle, $temp_handle, $temp_path, $edit_error);
        }

        if ('replace' === $edit_type && 0 === $replacements) {
            return $this->closeEditWithError($source_handle, $temp_handle, $temp_path, '未找到匹配文本，原文件未改');
        }

        if (!fflush($temp_handle)) {
            return $this->closeEditWithError($source_handle, $temp_handle, $temp_path, '临时文件写入失败');
        }

        if (!rewind($temp_handle)) {
            return $this->closeEditWithError($source_handle, $temp_handle, $temp_path, '临时文件定位失败');
        }

        while (false !== ($output_line = fgets($temp_handle, 16777216 + 3))) {
            $output_length = strlen($output_line) - (str_ends_with($output_line, "\n") ? 1 : 0);

            if ($output_length > 16777216) {
                return $this->closeEditWithError($source_handle, $temp_handle, $temp_path, '编辑后单行长度超过16MB限制，原文件未改');
            }
        }

        if (!feof($temp_handle)) {
            return $this->closeEditWithError($source_handle, $temp_handle, $temp_path, '临时文件读取失败');
        }

        $result = $this->commitTextEdit($source_handle, $temp_handle, $temp_path, $file_path, $edit_type, $replacements);

        unset($payload_data, $agent_core, $file_path, $content, $line_range, $text_replace, $edit_type, $matched_text, $replace_limit, $replacements, $line_number, $source_handle, $file_sample, $temp_path, $temp_handle, $edit_error);
        return $result;
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

            $agent_core->core->memory->saveChat(
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

        if ($line_length - $ending_length > $bytes || (0 === $ending_length && !feof($handle))) {
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

        $result = ['line' => $line, 'error' => ''];

        unset($handle, $tolerant, $bytes, $line, $line_length, $ending_length, $body);
        return $result;
    }

    /**
     * @param        $handle
     * @param string $data
     *
     * @return bool
     */
    private function writeAll($handle, string $data): bool
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset, 1048576));

            if (false === $written || 0 === $written) {
                return false;
            }

            $offset += $written;
        }

        unset($handle, $data, $length, $offset, $written);
        return true;
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

            $carry  = '';
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

        $result = $write_failed ? '临时文件写入失败' : '';

        unset($source_handle, $temp_handle, $content, $matched_text, $replace_limit, $replacements, $write_failed, $match_length, $carry, $line_result, $line, $buffer, $match_position, $keep_length, $flush_length);
        return $result;
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

        $result = $write_failed ? '临时文件写入失败' : '';

        unset($source_handle, $temp_handle, $content, $line_range, $edit_type, $write_failed, $start_line, $end_line, $line_number, $last_had_newline, $inserted, $replacement_written, $line_result, $line, $insert_content, $replace_content, $append_content, $separator);
        return $result;
    }

    /**
     * @param        $source_handle
     * @param        $temp_handle
     * @param string $temp_path
     * @param string $file_path
     * @param string $edit_type
     * @param int    $replacements
     *
     * @return array|string[]
     */
    private function commitTextEdit($source_handle, $temp_handle, string $temp_path, string $file_path, string $edit_type, int $replacements): array
    {
        $bytes_written = ftell($temp_handle);
        $backup_path   = $temp_path . '.original';

        fclose($temp_handle);
        flock($source_handle, LOCK_UN);
        fclose($source_handle);

        if (!rename($file_path, $backup_path)) {
            unlink($temp_path);
            return ['status' => 'error', 'error' => '无法备份原文件，未提交修改内容'];
        }

        if (!rename($temp_path, $file_path)) {
            $restored = rename($backup_path, $file_path);
            unlink($temp_path);

            if (!$restored) {
                return ['status' => 'error', 'error' => '替换失败且原文件恢复失败；原内容保留于：' . $backup_path];
            }

            return ['status' => 'error', 'error' => '替换编辑文件失败，原文件已恢复'];
        }

        clearstatcache(true, $file_path);

        $result = [
            'status'        => 'success',
            'file_path'     => $file_path,
            'edit_type'     => $edit_type,
            'bytes_written' => $bytes_written
        ];

        if ('replace' === $edit_type) {
            $result['replacements'] = $replacements;
        }

        if (!unlink($backup_path)) {
            $result['message'] = '已完成编辑，文件备份删除失败：' . $backup_path;
        }

        unset($source_handle, $temp_handle, $temp_path, $file_path, $edit_type, $replacements, $bytes_written, $backup_path, $restored);
        return $result;
    }

    /**
     * @param        $source_handle
     * @param        $temp_handle
     * @param string $temp_path
     * @param string $error
     *
     * @return string[]
     */
    private function closeEditWithError($source_handle, $temp_handle, string $temp_path, string $error): array
    {
        fclose($temp_handle);
        unlink($temp_path);

        flock($source_handle, LOCK_UN);
        fclose($source_handle);

        unset($source_handle, $temp_handle, $temp_path);
        return ['status' => 'error', 'error' => $error];
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