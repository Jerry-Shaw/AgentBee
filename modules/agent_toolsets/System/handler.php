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
     * @return array
     */
    public function cleanContext(array $payload_data, agent_core $agent_core): array
    {
        $agent_core->utils->cleanSessionHistory(
            $payload_data['worker_name'],
            $payload_data['keep_normal'],
            $payload_data['max_tool_pairs'],
        );

        $result = [
            'status'  => 'success',
            'message' => '[系统提醒] 上下文已清理，继续原有任务',
            'history' => '[上下文摘要]' . "\n\n" . $payload_data['history_summary'] ?? '无重要摘要，可直接继续原有话题'
        ];

        unset($payload_data, $agent_core);
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

        if (!in_array($image_info['mime'], ['image/jpeg', 'image/tiff', 'image/webp', 'image/png', 'image/bmp', 'image/gif'], true)) {
            return ['status' => 'error', 'error' => 'Unsupported image type: ' . $image_info['mime']];
        }

        $filename    = basename($file_path);
        $binary_data = file_get_contents($file_path);

        if (false === $binary_data) {
            return ['status' => 'error', 'error' => 'Failed to read image data: ' . $file_path];
        }

        $data_url = $agent_core->utils->resizeImage($binary_data);

        $agent_core->utils->addSessionHistory(
            $payload_data['process_name'],
            [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $filename],
                    ['type' => 'image_url', 'image_url' => ['url' => $data_url]]
                ]
            ]
        );

        if ($payload_data['rendering']) {
            $message = $agent_core->utils->getMessageMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                $payload_data['process_name'],
                0,
                hash('md5', uniqid(microtime(), true))
            );

            $agent_core->core->sendImageMessage(
                $payload_data['socket_id'],
                $message,
                $data_url,
                $filename
            );
        }

        $result = [
            'status'    => 'success',
            'message'   => '[系统提醒] 图片已加载，按需使用',
            'width'     => $image_info[0],
            'height'    => $image_info[1],
            'filename'  => $filename,
            'mime_type' => $image_info['mime']
        ];

        unset($payload_data, $agent_core, $file_path, $image_info, $filename, $binary_data);
        return $result;
    }
}