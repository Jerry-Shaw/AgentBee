<?php

namespace modules\agent_skills\System;

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
            $payload_data['tool_call_id']
        );

        return '[系统提醒] 上下文已清理，继续原有任务。';
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array|string[]
     */
    public function readImage(array $payload_data, agent_core $agent_core): array
    {
        $full_path = $agent_core->utils->securePath($payload_data['file_path']);

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

        $agent_core->utils->addSessionHistory(
            WORKER_MAIN,
            [
                'role'    => 'user',
                'content' => [
                    [
                        'type'      => 'image_url',
                        'image_url' => ['url' => $image_url]
                    ]
                ]
            ]
        );

        $result = [
            'status'    => 'success',
            'message'   => '[系统提醒] 图片已加载，按需使用',
            'filename'  => basename($full_path),
            'mime_type' => $mime_type
        ];

        unset($file_path, $full_path, $info, $mime_type, $allowed, $data, $base64, $image_url);
        return $result;
    }
}