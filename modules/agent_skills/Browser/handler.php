<?php

/**
 * Agent Browser module for AgentBee core
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

namespace modules\agent_skills\Browser;

use modules\agent_core\go as agent_core;
use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\OSMgr;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Ext\libHttp;

class handler extends Factory
{
    public OSMgr $OSMgr;

    public int $cmd_id = 0;

    private array $screenshot = [];

    private const START_ARGS = [
        '--remote-debugging-port=0',
        '--new-window',
        '--disable-gpu',
        '--disable-dev-shm-usage',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-extensions',
        '--disable-sync',
        '--disable-prompt-on-repost',
        '--enable-automation',
        '--window-size=1280,720',
        '--no-sandbox',
        '--disable-background-timer-throttling',
        '--disable-backgrounding-occluded-windows',
        '--disable-renderer-backgrounding',
    ];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->OSMgr = OSMgr::new();
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function start(array $payload_data, agent_core $agent_core): void
    {
        $worker_info = $agent_core->utils->getChildWorker('Browser', $payload_data['worker_name']);

        if (!empty($worker_info)) {
            $agent_core->utils->onsend_messages[] = '[Browser] 浏览器实例 "' . $payload_data['worker_name'] . '" 已存在，可直接调用。';
            return;
        }

        $start_args = self::START_ARGS;

        if ($payload_data['headless']) {
            $start_args[] = '--headless=new';
        }

        $data_dir     = rtrim($agent_core->utils->agent_config['workspace_path'], '/\\') . DIRECTORY_SEPARATOR . 'browser-' . $payload_data['worker_name'];
        $start_args[] = '--user-data-dir=' . $data_dir;

        $browser_idx = $agent_core->utils
            ->procMgr->command(
                [
                    $agent_core->utils->agent_config['chrome_path'],
                    ...$start_args
                ]
            )
            ->run($agent_core->utils->getProcIDX());

        $browser_pid = $agent_core->utils->procMgr->getPid($browser_idx);

        $agent_core->utils->debug('Browser started: ' . $payload_data['worker_name'] . ' (PID: ' . $browser_pid . ')', 'trace');

        $browser_address = '';

        for ($i = 0; $i < 10; ++$i) {
            $err_msg = trim($agent_core->utils->procMgr->readProc($browser_idx, 'stderr'));

            if ('' !== $err_msg && str_starts_with($err_msg, 'DevTools listening on')) {
                $browser_address = substr($err_msg, strpos($err_msg, 'ws'));
                break;
            }

            usleep(50000);
        }

        if ('' === $browser_address) {
            $agent_core->utils->debug('Browser ERROR: Failed to fetch address, closing ' . $payload_data['worker_name'] . ' (PID: ' . $browser_pid . ')', 'trace');
            $this->closeBrowser($browser_pid);
            $agent_core->utils->procMgr->close($browser_idx);
            $agent_core->utils->onsend_messages[] = '[Browser] 无法获取浏览器 WebSocket 地址，浏览器实例已关闭。';
            return;
        }

        $ws_address = '';
        $local_port = parse_url($browser_address, PHP_URL_PORT);
        $json_addr  = 'http://127.0.0.1:' . $local_port . '/json';
        $dev_json   = libHttp::new()->fetch($json_addr);
        $dev_data   = json_decode($dev_json, true);

        if (is_array($dev_data) && !empty($dev_data)) {
            foreach ($dev_data as $target) {
                if (isset($target['type']) && 'page' === $target['type'] && isset($target['webSocketDebuggerUrl'])) {
                    $ws_address = $target['webSocketDebuggerUrl'];
                    break;
                }
            }

            if ('' === $ws_address && isset($dev_data[0]['webSocketDebuggerUrl'])) {
                $ws_address = $dev_data[0]['webSocketDebuggerUrl'];
            }
        }

        if ('' === $ws_address) {
            $this->closeBrowser($browser_pid);
            $agent_core->utils->procMgr->close($browser_idx);
            $agent_core->utils->onsend_messages[] = '[Browser] 无法获取目标页面 WebSocket 地址，浏览器实例已关闭。';
            return;
        }

        $worker_idx = $agent_core->utils->procMgr
            ->command(
                [
                    $agent_core->utils->OSMgr->getPhpPath(),
                    $agent_core->utils->app->script_path,
                    '-c', '/' . __CLASS__ . '/startClient',
                    '-d', 'address=' . $ws_address,
                ]
            )
            ->run($agent_core->utils->getProcIDX());

        $worker_pid = $agent_core->utils->procMgr->getPid($worker_idx);

        $agent_core->utils->debug('Browser Bridge started (PID: ' . $worker_pid . ')', 'trace');

        $agent_core->core->socketMgr->addExternalProc(
            $agent_core->utils->procMgr->getProc($worker_idx, 'stdout', 'process'),
            [
                'stdout' => function (string $socket_id, array $context) use ($agent_core, $payload_data): void
                {
                    $line = fgets($context['stdout']);

                    if (false === $line) {
                        return;
                    }

                    $line = trim($line);

                    if ('' === $line) {
                        return;
                    }

                    $data = json_decode($line, true);

                    if (is_array($data)) {
                        $this->handleResponse($data, $agent_core, $payload_data['worker_name']);
                    }

                    unset($socket_id, $context, $line, $data);
                }
            ]
        );

        $agent_core->utils->addChildWorker(
            'Browser',
            $payload_data['worker_name'],
            [
                'worker_idx'  => $worker_idx,
                'worker_pid'  => $worker_pid,
                'browser_idx' => $browser_idx,
                'browser_pid' => $browser_pid,
                'socket_id'   => $payload_data['socket_id'],
                'worker_name' => $payload_data['worker_name'],
                'status'      => 'ready',
                'action'      => 'idle',
                'open_at'     => date('Y-m-d H:i:s'),
                'data_dir'    => $data_dir
            ]
        );

        $agent_core->utils->debug('Browser: ' . $payload_data['worker_name'] . ' is ready!', 'trace');
        $agent_core->utils->onsend_messages[] = '[Browser] "' . $payload_data['worker_name'] . '" 已就绪。';

        unset($payload_data, $agent_core, $worker_info, $start_args, $browser_idx, $browser_pid, $browser_address, $i, $err_msg, $ws_address, $local_port, $json_addr, $dev_json, $dev_data, $target, $worker_idx, $worker_pid, $payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function navigate(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function click(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function type(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function submit(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function getUrl(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function getTitle(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function getContent(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function getValue(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function getAttribute(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function setAttribute(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function scrollIntoView(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function selectOption(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function evaluate(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function screenshot(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function waitForSelector(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function waitForPageLoad(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function waitForText(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function waitForElementVisible(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function waitForUrl(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function hover(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function pressKey(array $payload_data, agent_core $agent_core): void
    {
        $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
        unset($payload_data, $agent_core);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function list(array $payload_data, agent_core $agent_core): void
    {
        $workers = $agent_core->utils->getChildWorker('Browser');

        if (empty($workers)) {
            $agent_core->utils->onsend_messages[] = '[Browser] 当前没有活跃的浏览器实例。';
            return;
        }

        $lines = ['[Browser] 浏览器实例:'];

        foreach ($workers as $name => $info) {
            $lines[] = '- "' . $name . '"'
                . ' | 状态:' . ($info['status'])
                . ' | 当前操作:' . ($info['action'])
                . ' | 用户目录:' . ($info['data_dir'])
                . ' | 运行:' . (time() - strtotime($info['open_at'])) . '秒';
        }

        $agent_core->utils->onsend_messages[] = implode("\n", $lines);

        unset($payload_data, $agent_core, $workers, $lines, $name, $info);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return void
     */
    public function close(array $payload_data, agent_core $agent_core): void
    {
        $worker_info = $agent_core->utils->getChildWorker('Browser', $payload_data['worker_name']);

        if (empty($worker_info)) {
            $agent_core->utils->onsend_messages[] = '[Browser] 实例 "' . $payload_data['worker_name'] . '" 已关闭。';
            return;
        }

        $this->closeBrowser($worker_info['browser_pid']);
        $agent_core->utils->procMgr->close($worker_info['worker_idx']);
        $agent_core->utils->procMgr->close($worker_info['browser_idx']);
        $agent_core->utils->removeChildWorker('Browser', $payload_data['worker_name']);
        $agent_core->utils->libFileIO->delDir($worker_info['data_dir']);

        $agent_core->utils->onsend_messages[] = '[Browser] 实例 "' . $payload_data['worker_name'] . '" 已关闭。';

        unset($payload_data, $agent_core, $worker_info);
    }

    /**
     * @param string $address
     *
     * @return void
     * @throws \Exception
     * @throws \Throwable
     */
    public function startClient(string $address): void
    {
        $memory_limit = utils::new()->agent_config['memory_limit'] ?? '4G';
        ini_set('memory_limit', $memory_limit);

        $pending   = [];
        $SocketMgr = SocketMgr::new();

        $SocketMgr->addExternalProc(
            ['stdin' => STDIN],
            [
                'stdin' => function (string $ext_id, array $context) use ($SocketMgr, &$pending): void
                {
                    $line = trim(fgets($context['stdin']));

                    if ('' === $line) {
                        return;
                    }

                    $data = json_decode($line, true);

                    if (!is_array($data)) {
                        return;
                    }

                    $cdp_cmd = $this->buildCommand($data['action'], $data['params'], $data['id']);

                    if ('' === $cdp_cmd) {
                        return;
                    }

                    if ('' === $SocketMgr->master_id || !isset($SocketMgr->connections[$SocketMgr->master_id])) {
                        return;
                    }

                    $pending[$data['id']] = $data['action'];

                    $SocketMgr->sendMessage($SocketMgr->master_id, $SocketMgr->wsEncode($cdp_cmd, false, true));

                    unset($ext_id, $context, $line, $data, $cdp_cmd);
                }
            ]
        );

        $SocketMgr->setEventListener(
            'onMessage',
            function (string $socket_id, string $message, bool $is_binary) use (&$pending): void
            {
                if ('' === $message) {
                    return;
                }

                if (true === $is_binary) {
                    $message = base64_encode($message);
                }

                $response = json_decode($message, true);

                if (!is_array($response)) {
                    return;
                }

                $cmd_id = $response['id'];
                $action = $pending[$cmd_id] ?? 'Browser-Action';

                unset($pending[$cmd_id]);

                $output = ['action' => $action, 'id' => $cmd_id];

                if (isset($response['result'])) {
                    $output['result'] = $response['result'];
                } elseif (isset($response['error'])) {
                    $output['error'] = $response['error']['message'] ?? 'CDP error';
                } else {
                    $output['result'] = $response;
                }

                echo json_encode($output, JSON_FORMAT) . "\n";

                flush();
                fflush(STDOUT);

                unset($socket_id, $message, $is_binary, $response, $cmd_id, $action, $output);
            }
        );

        $SocketMgr->connectTo($address, true);
    }

    /**
     * @param int $browser_pid
     *
     * @return void
     */
    private function closeBrowser(int $browser_pid): void
    {
        $this->OSMgr->killProc($browser_pid);
    }

    /**
     * @param agent_core $agent_core
     * @param array      $payload_data
     * @param string     $action
     *
     * @return void
     */
    private function sendCommand(agent_core $agent_core, array $payload_data, string $action): void
    {
        $worker_info = $agent_core->utils->getChildWorker('Browser', $payload_data['worker_name']);

        if (empty($worker_info)) {
            $agent_core->utils->debug('Browser: ' . $payload_data['worker_name'] . ' NOT found!', 'trace');
            $agent_core->utils->onsend_messages[] = '[Browser] 实例 "' . $payload_data['worker_name'] . '" 不存在，请启动后操作。';
            return;
        }

        if ('ready' !== $worker_info['status'] || 'idle' !== $worker_info['action']) {
            $agent_core->utils->debug('Browser: ' . $payload_data['worker_name'] . ' is busy on ' . $action . '!', 'trace');
            $agent_core->utils->onsend_messages[] = '[Browser] ' . $payload_data['worker_name'] . ' 忙碌中 (操作: ' . $worker_info['action'] . ')，请等待结果返回，不要重复发送命令。';
            return;
        }

        try {
            $cmd_id = $this->cmd_id++;

            if ('screenshot' === $action && isset($payload_data['params']['save_path'])) {
                $this->screenshot[$cmd_id] = $payload_data['params']['save_path'];
            }

            $agent_core->utils->debug('Browser: ' . $payload_data['worker_name'] . ' is working on ' . $action . '!', 'trace');
            $agent_core->utils->setChildWorker('Browser', $payload_data['worker_name'], 'status', 'busy');
            $agent_core->utils->setChildWorker('Browser', $payload_data['worker_name'], 'action', $action);

            $agent_core->utils->procMgr->writeProc(
                $worker_info['worker_idx'],
                json_encode([
                    'action' => $action,
                    'params' => $payload_data['params'] ?? [],
                    'id'     => $cmd_id
                ], JSON_FORMAT)
            );
        } catch (\Throwable) {
            $agent_core->utils->debug('Browser: ' . $payload_data['worker_name'] . ' closed due to communication errors.', 'trace');
            $agent_core->utils->onsend_messages[] = '[Browser] 实例 "' . $payload_data['worker_name'] . '" 发生错误，无法通信，已强制关闭。';
            $this->closeBrowser($worker_info['browser_pid']);
            $agent_core->utils->procMgr->close($worker_info['worker_idx']);
            $agent_core->utils->procMgr->close($worker_info['browser_idx']);
            $agent_core->utils->removeChildWorker('Browser', $payload_data['worker_name']);
        }

        unset($agent_core, $payload_data, $action, $worker_info, $cmd_id);
    }

    /**
     * @param array      $data
     * @param agent_core $agent_core
     * @param string     $worker_name
     *
     * @return void
     * @throws \ReflectionException
     */
    private function handleResponse(array $data, agent_core $agent_core, string $worker_name): void
    {
        $data['action'] ??= '未知操作';

        $agent_core->utils->debug('Browser: ' . $worker_name . ' returned from ' . $data['action'] . '.', 'trace');

        $agent_core->utils->setChildWorker('Browser', $worker_name, 'status', 'ready');
        $agent_core->utils->setChildWorker('Browser', $worker_name, 'action', 'idle');

        if ('screenshot' === $data['action'] && isset($data['id']) && isset($this->screenshot[$data['id']])) {
            $save_path = $agent_core->utils->securePath($this->screenshot[$data['id']]);
            $agent_core->utils->libFileIO->mkPath(dirname($save_path));

            if (isset($data['result']['data'])) {
                $image_data = base64_decode($data['result']['data']);

                if (false !== $image_data && 0 < file_put_contents($save_path, $image_data)) {
                    $data['result'] = ['saved_path' => $save_path];
                } else {
                    $data['error'] = '写入截图文件失败: ' . $save_path;
                }
            } else {
                $data['error'] = '截图数据缺失';
            }

            unset($save_path, $image_data, $this->screenshot[$data['id']]);
        }

        $wait_actions = ['waitForSelector', 'waitForPageLoad', 'waitForText', 'waitForElementVisible', 'waitForUrl'];

        if (in_array($data['action'], $wait_actions, true) && isset($data['result']) && false === $data['result']) {
            $agent_core->utils->debug('Browser: ' . $worker_name . ' timed out on ' . $data['action'] . '.', 'trace');
            $data['error'] = '等待超时';
            unset($data['result']);
        }

        $prefix = '[Browser] "' . $worker_name . '"';

        if (isset($data['error'])) {
            $agent_core->utils->debug('Browser: ' . $worker_name . ' error on ' . $data['action'] . '.', 'trace');
            $agent_core->utils->onsend_messages[] = $prefix . ' "' . $data['action'] . '" 错误: ' . $data['error'];
            return;
        }

        if (isset($data['result'])) {
            $message = $prefix . ' "' . $data['action'] . '" 已完成';

            if (!empty($data['result'])) {
                $message .= ': ' . json_encode($data['result'], JSON_FORMAT);
            }

            $agent_core->utils->onsend_messages[] = $message;
            unset($message);
        } else {
            $agent_core->utils->onsend_messages[] = $prefix . ' "' . $data['action'] . '" 返回未知格式: ' . json_encode($data, JSON_FORMAT);
        }

        unset($data, $agent_core, $worker_name, $wait_actions, $prefix);
    }

    /**
     * @param string $action
     * @param array  $params
     * @param int    $cmd_id
     *
     * @return string
     */
    private function buildCommand(string $action, array $params, int $cmd_id): string
    {
        $timeout_ms = (int)($params['timeout'] ?? 30) * 1000;

        switch ($action) {
            case 'navigate':
                $method     = 'Page.navigate';
                $cdp_params = ['url' => $params['url']];
                break;

            case 'click':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . json_encode($params['selector'], JSON_FORMAT) . ').click();', 'returnByValue' => false];
                break;

            case 'type':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . json_encode($params['selector'], JSON_FORMAT) . ').value = ' . json_encode($params['text'], JSON_FORMAT) . ';', 'returnByValue' => false];
                break;

            case 'submit':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . json_encode($params['selector'] ?? 'form', JSON_FORMAT) . ').submit();', 'returnByValue' => false];
                break;

            case 'getUrl':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'window.location.href', 'returnByValue' => true];
                break;

            case 'getTitle':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.title', 'returnByValue' => true];
                break;

            case 'getContent':
                $html       = $params['html'] ?? false;
                $expr       = (true === $html) ? 'document.documentElement.outerHTML' : 'document.body.innerText';
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => $expr, 'returnByValue' => true];
                break;

            case 'getValue':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . json_encode($params['selector'], JSON_FORMAT) . ').value', 'returnByValue' => true];
                break;

            case 'getAttribute':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . json_encode($params['selector'], JSON_FORMAT) . ').getAttribute(' . json_encode($params['attribute'], JSON_FORMAT) . ')', 'returnByValue' => true];
                break;

            case 'setAttribute':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . json_encode($params['selector'], JSON_FORMAT) . ').setAttribute(' . json_encode($params['attribute'], JSON_FORMAT) . ', ' . json_encode($params['value'], JSON_FORMAT) . ');', 'returnByValue' => false];
                break;

            case 'scrollIntoView':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . json_encode($params['selector'], JSON_FORMAT) . ').scrollIntoView();', 'returnByValue' => false];
                break;

            case 'selectOption':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . json_encode($params['selector'], JSON_FORMAT) . ').value = ' . json_encode($params['value'], JSON_FORMAT) . ';', 'returnByValue' => false];
                break;

            case 'evaluate':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => $params['script'], 'returnByValue' => $params['return_by_value'] ?? true];
                break;

            case 'screenshot':
                $method     = 'Page.captureScreenshot';
                $cdp_params = ['format' => $params['format'] ?? 'jpeg', 'quality' => $params['quality'] ?? 80];
                break;

            case 'waitForSelector':
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { let s=Date.now(); const c=()=>{ if(document.querySelector(' . json_encode($params['selector'], JSON_FORMAT) . ')) resolve(true); else if(Date.now()-s>' . $timeout_ms . ') resolve(false); else setTimeout(c,100); }; c(); })',
                    'returnByValue' => true
                ];
                break;

            case 'waitForPageLoad':
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { let s=Date.now(); const c=()=>{ if(document.readyState==="complete") resolve(true); else if(Date.now()-s>' . $timeout_ms . ') resolve(false); else setTimeout(c,100); }; c(); })',
                    'returnByValue' => true
                ];
                break;

            case 'waitForText':
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { let s=Date.now(); const c=()=>{ if(document.body.innerText.includes(' . json_encode($params['text'], JSON_FORMAT) . ')) resolve(true); else if(Date.now()-s>' . $timeout_ms . ') resolve(false); else setTimeout(c,100); }; c(); })',
                    'returnByValue' => true
                ];
                break;

            case 'waitForElementVisible':
                $method     = 'Runtime.evaluate';
                $expr       = 'new Promise(resolve => { let s=Date.now(); const c=()=>{ const el=document.querySelector(' . json_encode($params['selector'], JSON_FORMAT) . '); if(el && getComputedStyle(el).display!=="none" && getComputedStyle(el).visibility!=="hidden") resolve(true); else if(Date.now()-s>' . $timeout_ms . ') resolve(false); else setTimeout(c,100); }; c(); })';
                $cdp_params = ['expression' => $expr, 'returnByValue' => true];
                break;

            case 'waitForUrl':
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { let s=Date.now(); const c=()=>{ if(window.location.href.includes(' . json_encode($params['url_pattern'], JSON_FORMAT) . ')) resolve(true); else if(Date.now()-s>' . $timeout_ms . ') resolve(false); else setTimeout(c,100); }; c(); })',
                    'returnByValue' => true
                ];
                break;

            case 'hover':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . json_encode($params['selector'], JSON_FORMAT) . ').dispatchEvent(new MouseEvent("mouseover", {bubbles:true}));', 'returnByValue' => false];
                break;

            case 'pressKey':
                $mod_code = '';
                foreach (($params['modifiers'] ?? []) as $mod) {
                    $mod_code .= 'event.' . $mod . 'Key = true;';
                }
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.activeElement?.dispatchEvent(new KeyboardEvent("keydown", { key:' . json_encode($params['key'], JSON_FORMAT) . ', bubbles:true })); ' . $mod_code, 'returnByValue' => false];
                break;

            case 'close':
            default:
                return '';
        }

        $command = json_encode(['id' => $cmd_id, 'method' => $method, 'params' => $cdp_params], JSON_FORMAT);

        unset($action, $params, $cmd_id, $method, $cdp_params, $timeout_ms);
        return $command;
    }
}