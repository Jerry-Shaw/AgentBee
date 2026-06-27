<?php

namespace modules\agent_skills\WorkerBee;

use modules\agent_core\go as agent_core;
use Nervsys\Core\Factory;

class handler extends Factory
{
    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     * @throws \Exception
     */
    public function start(array $payload_data, agent_core $agent_core): void
    {
        $worker_info = $agent_core->utils->getChildWorker('WorkerBee', $payload_data['worker_name']);

        if (!empty($worker_info)) {
            // WorkerBee already exists, change name or talk
            $agent_core->utils->debug('WorkerBee already exists: ' . $payload_data['worker_name'] . ' | ' . $worker_info['worker_role'] . ' already exists!', 'trace');
            $agent_core->utils->onsend_messages[] = '[WorkerBee] "' . $payload_data['worker_name'] . '" 已存在。请换名或直接使用 (角色: ' . $worker_info['worker_role'] . ')';
            return;
        }

        $proc_idx = $agent_core->runProcWorker(
            $agent_core->utils->getWorkerIDX(),
            WORKER_CHILD,
            [$agent_core, 'streamWorkerHandler']
        );

        $agent_core->utils->debug('WorkerBee started: ' . $payload_data['worker_name'] . ' (WorkerID: ' . $proc_idx . ', ' . $payload_data['worker_role'] . ')', 'trace');

        $agent_core->utils->addChildWorker(
            'WorkerBee',
            $payload_data['worker_name'], [
                'proc_idx'    => $proc_idx,
                'socket_id'   => $payload_data['socket_id'],
                'worker_name' => $payload_data['worker_name'],
                'worker_role' => $payload_data['worker_role'],
                'status'      => 'processing',
                'last_talk'   => date('Y-m-d H:i:s'),
                'talk_count'  => 0
            ]
        );

        $child_prompt = $agent_core->utils->getChildPrompt(
            $payload_data['worker_name'],
            $payload_data['worker_role']
        );

        $agent_core->utils->setSessionHistory($payload_data['worker_name'], [$child_prompt]);
        $agent_core->utils->addSessionHistory(
            $payload_data['worker_name'],
            ['role' => 'user', 'content' => '[用户要求] ' . $payload_data['init_prompt'] . ' | 用一句话概述你的名字，角色，阅读用户要求，并回复“已就绪”']
        );

        $metadata = $agent_core->utils->getMessageMarker(
            WORKER_CHILD,
            $payload_data['worker_name'],
            $payload_data['worker_role'],
            $payload_data['worker_name'],
            1
        );

        $agent_core->openai->talk(
            $proc_idx,
            'start',
            $agent_core->utils->getSessionHistory($payload_data['worker_name']),
            $metadata + ['socket_id' => $payload_data['socket_id']]
        );

        unset($payload_data, $agent_core, $proc_idx, $child_prompt, $metadata);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     * @throws \ReflectionException
     */
    public function talk(array $payload_data, agent_core $agent_core): void
    {
        $worker_info = $agent_core->utils->getChildWorker('WorkerBee', $payload_data['worker_name']);

        if (empty($worker_info) || 0 === $agent_core->core->utils->procMgr->getStatus($worker_info['proc_idx'])) {
            // WorkerBee died, notice main worker
            $agent_core->utils->onsend_messages[] = '[WorkerBee] "' . $payload_data['worker_name'] . '" 进程已终止，消息发送失败';
            return;
        }

        if ('ready' !== $worker_info['status']) {
            $agent_core->utils->debug('WorkerBee: ' . $worker_info['worker_name'] . ' is busy', 'trace');
            $agent_core->utils->onsend_messages[] = '[WorkerBee] "' . $worker_info['worker_name'] . '" 之前任务未完成，请等回复后再继续。';
            return;
        }

        $agent_core->utils->setChildWorker('WorkerBee', $worker_info['worker_name'], 'status', 'processing');

        $worker_message = json_encode(
            $agent_core->utils->getMessageMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                $worker_info['worker_name'],
                1
            ) + [
                'type' => 'content',
                'data' => AGENT_NAME . ': ' . $payload_data['message']
            ], JSON_FORMAT);

        if (isset($agent_core->utils->socket_session[$worker_info['socket_id']])) {
            $agent_core->core->sendMessage($worker_info['socket_id'], $worker_message);
        } else {
            $agent_core->utils->debug('WorkerBee: Client offline, message from ' . AGENT_NAME . ' queued', 'trace');
            $agent_core->utils->message_buffers[] = $worker_message;
        }

        $agent_core->utils->debug('WorkerBee: ' . $worker_info['worker_name'] . ' started task', 'trace');

        $agent_core->utils->addSessionHistory(
            $worker_info['worker_name'],
            ['role' => 'user', 'content' => $payload_data['message']]
        );

        $metadata = $agent_core->utils->getMessageMarker(
            WORKER_CHILD,
            $worker_info['worker_name'],
            $worker_info['worker_role'],
            $worker_info['worker_name'],
            1
        );

        $agent_core->openai->talk(
            $worker_info['proc_idx'],
            'talk',
            $agent_core->utils->getSessionHistory($worker_info['worker_name']),
            $metadata + ['socket_id' => $payload_data['socket_id']]
        );

        unset($payload_data, $agent_core, $worker_info, $worker_message, $metadata);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     * @throws \Exception
     */
    public function close(array $payload_data, agent_core $agent_core): void
    {
        $worker_info = $agent_core->utils->getChildWorker('WorkerBee', $payload_data['worker_name']);

        if (!empty($worker_info) && 0 < $agent_core->core->utils->procMgr->getStatus($worker_info['proc_idx'])) {
            $agent_core->utils->debug('WorkerBee closed: ' . $worker_info['worker_name'] . ' (WorkerID:' . $worker_info['proc_idx'] . ', ' . $worker_info['worker_role'] . ')', 'trace');

            $metadata = $agent_core->utils->getMessageMarker(
                WORKER_CHILD,
                $worker_info['worker_name'],
                $worker_info['worker_role'],
                $worker_info['worker_name'],
                1
            );

            $agent_core->openai->talk(
                $worker_info['proc_idx'],
                'close',
                $agent_core->utils->getSessionHistory($worker_info['worker_name']),
                $metadata + ['socket_id' => $payload_data['socket_id']]
            );

            $agent_core->core->utils->procMgr->close($worker_info['proc_idx']);
            $agent_core->core->utils->removeSessionHistory($worker_info['worker_name']);
            $agent_core->utils->removeChildWorker('WorkerBee', $payload_data['worker_name']);
        } else {
            $agent_core->utils->debug('WorkerBee not found: ' . $worker_info['worker_name'], 'trace');
        }

        $agent_core->utils->onsend_messages[] = '[WorkerBee] "' . $payload_data['worker_name'] . '" 进程已终止';

        unset($payload_data, $agent_core, $worker_info, $metadata);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function list(array $payload_data, agent_core $agent_core): void
    {
        $lines = ['[WorkerBee] 当前活跃Worker列表：'];
        $list  = $agent_core->utils->getChildWorker('WorkerBee');

        foreach ($list as $worker) {
            $lines[] = '- "' . $worker['worker_name']
                . '" (ID:' . $worker['proc_idx'] . ')'
                . ' | 角色:' . $worker['worker_role']
                . ' | 状态:' . $worker['status']
                . ' | 对话:' . $worker['talk_count'] . '轮'
                . ' | 沉默:' . (time() - strtotime($worker['last_talk'])) . '秒';
        }

        $agent_core->utils->onsend_messages[] = implode("\n", $lines);

        unset($payload_data, $agent_core, $lines, $list, $worker);
    }
}