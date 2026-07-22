<?php

namespace modules\agent_toolsets\WorkerBee;

use modules\agent_core\go as agent_core;
use Nervsys\Core\Factory;
use Random\RandomException;

class handler extends Factory
{
    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return string
     * @throws \Exception
     */
    public function start(array $payload_data, agent_core $agent_core): string
    {
        $worker_info = $agent_core->utils->getChildWorker('WorkerBee', $payload_data['worker_name']);

        if (!empty($worker_info)) {
            // WorkerBee already exists, change name or talk
            $agent_core->utils->debug('WorkerBee already exists: ' . $payload_data['worker_name'] . ' | ' . $worker_info['worker_role'] . ' already exists!', 'trace');

            $agent_core->utils->addMessageQueue(
                WORKER_MAIN,
                [
                    'type' => 'text',
                    'text' => '[WorkerBee] "' . $payload_data['worker_name'] . '" 已存在。请换名或直接使用 (角色: ' . $worker_info['worker_role'] . ')'
                ]
            );

            return '[WorkerBee] "' . $payload_data['worker_name'] . '" 已存在。请换名或直接使用 (角色: ' . $worker_info['worker_role'] . ')';
        }

        $proc_idx = $agent_core->runProcWorker(
            $agent_core->utils->getWorkerIDX(),
            WORKER_CHILD,
            [$agent_core, 'streamWorkerHandler']
        );

        $agent_core->utils->debug('WorkerBee started: ' . $payload_data['worker_name'] . ' (WorkerID: ' . $proc_idx . ', ' . $payload_data['worker_role'] . ')', 'trace');

        $init_prompt  = $payload_data['init_prompt'] . ' | 先阅读用户要求，用一句话介绍你的名字和角色，并回复“**已就绪**”。';
        $child_prompt = $agent_core->utils->getChildPrompt(
            $payload_data['worker_name'],
            $payload_data['worker_role']
        );
        $worker_info  = [
            'proc_idx'    => $proc_idx,
            'socket_id'   => $payload_data['socket_id'],
            'worker_name' => $payload_data['worker_name'],
            'worker_role' => $payload_data['worker_role'],
            'status'      => 'processing',
            'last_talk'   => date('Y-m-d H:i:s'),
            'talk_count'  => 0
        ];

        $this->sendMessage($agent_core, $worker_info, ['content' => $init_prompt]);
        $agent_core->utils->addChildWorker('WorkerBee', $payload_data['worker_name'], $worker_info);
        $agent_core->utils->setSessionHistory($payload_data['worker_name'], [$child_prompt]);
        $agent_core->utils->addSessionHistory(
            $payload_data['worker_name'],
            ['role' => 'user', 'content' => '[用户要求] ' . $init_prompt]
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

        $message = 'Worker子进程正在启动。收到"**' . $payload_data['worker_name'] . '**"的“已就绪”信息后，即可调用talk发送消息。';

        unset($payload_data, $agent_core, $proc_idx, $init_prompt, $child_prompt, $worker_info, $metadata);
        return $message;
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return string
     * @throws RandomException
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function talk(array $payload_data, agent_core $agent_core): string
    {
        $worker_info = $agent_core->utils->getChildWorker('WorkerBee', $payload_data['worker_name']);

        if (empty($worker_info) || 0 === $agent_core->utils->procMgr->getStatus($worker_info['proc_idx'])) {
            // WorkerBee died, notice main worker
            $agent_core->utils->addMessageQueue(
                WORKER_MAIN,
                [
                    'type' => 'text',
                    'text' => '[WorkerBee] "' . $payload_data['worker_name'] . '" 进程已终止，消息发送失败'
                ]
            );

            return '[WorkerBee] "' . $payload_data['worker_name'] . '" 进程已终止，消息发送失败';
        }

        if ('ready' !== $worker_info['status']) {
            $agent_core->utils->debug('WorkerBee: ' . $worker_info['worker_name'] . ' is busy, new message queued.', 'trace');

            $this->sendMessage($agent_core, $worker_info, $payload_data);

            $agent_core->utils->addMessageQueue(
                $worker_info['worker_name'],
                [
                    'type' => 'text',
                    'text' => $payload_data['content']
                ]
            );

            return '[WorkerBee] `' . $worker_info['worker_name'] . '` 正忙（' . $worker_info['status'] . '）。消息已入队，请先处理其他事务，回复会主动推送。';
        }

        $agent_core->utils->debug('WorkerBee: ' . $worker_info['worker_name'] . ' is working on task.', 'trace');

        $agent_core->utils->setChildWorker('WorkerBee', $worker_info['worker_name'], 'status', 'processing');
        $agent_core->utils->refreshSessionHistory($worker_info['worker_name']);

        $this->sendMessage($agent_core, $worker_info, $payload_data);

        $agent_core->utils->addSessionHistory(
            $worker_info['worker_name'],
            ['role' => 'user', 'content' => $payload_data['content']]
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

        $message = '消息已发送给"**' . $worker_info['worker_name'] . '**"，请勿重复发送。回复将异步推送。';

        unset($payload_data, $agent_core, $worker_info, $worker_message, $metadata);
        return $message;
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return string
     * @throws \Exception
     */
    public function close(array $payload_data, agent_core $agent_core): string
    {
        $worker_info = $agent_core->utils->getChildWorker('WorkerBee', $payload_data['worker_name']);

        if (!empty($worker_info) && 0 < $agent_core->utils->procMgr->getStatus($worker_info['proc_idx'])) {
            $agent_core->utils->debug('WorkerBee closed: ' . $worker_info['worker_name'] . ' (WorkerID:' . $worker_info['proc_idx'] . ', ' . $worker_info['worker_role'] . ')', 'trace');

            $agent_core->utils->procMgr->close($worker_info['proc_idx']);
            $agent_core->utils->removeSessionHistory($worker_info['worker_name']);
            $agent_core->utils->removeMessageQueue($worker_info['worker_name']);
            $agent_core->utils->removeChildWorker('WorkerBee', $payload_data['worker_name']);

            $this->sendMessage($agent_core, $worker_info, ['content' => '子进程"' . $payload_data['worker_name'] . '"已关闭。']);
        } else {
            $agent_core->utils->debug('WorkerBee not found: ' . $payload_data['worker_name'], 'trace');
        }

        $message = '"' . $payload_data['worker_name'] . '" 进程已终止';

        unset($payload_data, $agent_core, $worker_info, $metadata);
        return $message;
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     */
    public function list(array $payload_data, agent_core $agent_core): array
    {
        $list    = [];
        $workers = $agent_core->utils->getChildWorker('WorkerBee');

        foreach ($workers as $worker) {
            $list[] = [
                'worker_name'   => $worker['worker_name'],
                'worker_role'   => $worker['worker_role'],
                'worker_status' => $worker['status'],
                'talk_count'    => $worker['talk_count'],
                'idle_sec'      => time() - strtotime($worker['last_talk'])
            ];
        }

        unset($payload_data, $agent_core, $workers, $worker);
        return $list;
    }

    /**
     * @param agent_core $agent_core
     * @param array      $worker_info
     * @param array      $payload_data
     *
     * @return void
     * @throws RandomException
     * @throws \ReflectionException
     */
    private function sendMessage(agent_core $agent_core, array $worker_info, array $payload_data): void
    {
        $worker_message = json_encode(
            $agent_core->utils->getMessageMarker(
                WORKER_MAIN,
                WORKER_MAIN,
                'Assistant',
                $worker_info['worker_name'],
                1
            ) + [
                'type' => 'content',
                'data' => AGENT_NAME . ': ' . $payload_data['content']
            ], JSON_FORMAT);

        $agent_core->core->sendMessage($worker_info['socket_id'], $worker_message);
        unset($agent_core, $worker_info, $payload_data, $worker_message);
    }
}