<?php

namespace modules\agent_toolsets\System;

use modules\agent_core\go as agent_core;
use Nervsys\Core\Factory;

class handler extends Factory
{
    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return string
     */
    public function cleanContext(array $payload_data, agent_core $agent_core): string
    {
        $agent_core->utils->cleanSessionHistory(
            $payload_data['worker_name'],
            $payload_data['keep_normal'],
            $payload_data['keep_tool_pairs'],
            $payload_data['aggressive_mode'],
            $payload_data['tool_call_id'],
            1
        );

        if (isset($payload_data['history_summary']) && '' !== $payload_data['history_summary']) {
            $agent_core->utils->addSessionHistory(
                $payload_data['worker_name'],
                [
                    'role'    => 'user',
                    'content' => [[
                        'type' => 'text',
                        'text' => "[上下文摘要]\n\n" . $payload_data['history_summary']
                    ]]
                ]
            );
        }

        unset($payload_data, $agent_core);
        return '[系统提醒] 上下文已清理，继续原有任务。';
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array|string[]
     * @throws \ReflectionException
     */
    public function readImage(array $payload_data, agent_core $agent_core): array
    {
        $file_path = $agent_core->utils->securePath($payload_data['file_path']);

        if (!is_file($file_path)) {
            return ['status' => 'error', 'error' => 'File not found: ' . $file_path];
        }

        if (!is_readable($file_path)) {
            return ['status' => 'error', 'error' => 'File not readable: ' . $file_path];
        }

        $image_info = getimagesize($file_path);

        if (false === $image_info || empty($image_info['mime'])) {
            return ['status' => 'error', 'error' => 'Cannot identify image type or unsupported format'];
        }

        $image_mime = $image_info['mime'];
        $allowed    = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];

        if (!in_array($image_mime, $allowed, true)) {
            return ['status' => 'error', 'error' => 'Unsupported image type: ' . $image_mime];
        }

        $filename    = basename($file_path);
        $binary_data = file_get_contents($file_path);

        if (false === $binary_data) {
            return ['status' => 'error', 'error' => 'Failed to read image data: ' . $file_path];
        }

        $agent_core->utils->addSessionHistory(
            $payload_data['process_name'],
            [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $filename],
                    ['type' => 'image_url', 'image_url' => ['url' => $agent_core->utils->resizeImage($binary_data)]]
                ]
            ]
        );

        $result = [
            'status'    => 'success',
            'message'   => '[系统提醒] 图片已加载，按需使用',
            'filename'  => $filename,
            'mime_type' => $image_mime
        ];

        unset($payload_data, $agent_core, $file_path, $image_info, $image_mime, $allowed, $filename, $binary_data);
        return $result;
    }
}