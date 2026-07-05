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
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Ext\libHttp;

class handler extends Factory
{
    public int $cmd_id  = 0;
    public int $timeout = 60;

    private const START_ARGS = [
        '--lang=zh-CN',
        '--window-size=1366,768',
        '--excludeSwitches=enable-automation',
        '--disable-background-timer-throttling',
        '--disable-backgrounding-occluded-windows',
        '--disable-component-update',
        '--disable-client-side-phishing-detection',
        '--disable-default-apps',
        '--disable-dev-shm-usage',
        '--disable-domain-reliability',
        '--disable-features=ChromeWhatsNewUI,TranslateUI,AutofillServerCommunication,PrivacySandboxSettings4,PrivacySandboxPromptTrigger',
        '--disable-ipc-flooding-protection',
        '--disable-prompt-on-repost',
        '--disable-renderer-backgrounding',
        '--disable-suggestions-ui',
        '--disable-sync',
        '--no-first-run',
        '--no-default-browser-check',
        '--remote-debugging-port=0',
    ];

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return string
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function start(array $payload_data, agent_core $agent_core): string
    {
        $worker_info = $agent_core->utils->getChildWorker('Browser', $payload_data['worker_name']);

        if (!empty($worker_info)) {
            return '实例 "' . $payload_data['worker_name'] . '" 已存在，可直接调用。';
        }

        $start_args = self::START_ARGS;

        if ($payload_data['headless']) {
            $start_args[] = '--disable-gpu';
            $start_args[] = '--headless=new';
        }

        $data_dir     = rtrim($agent_core->utils->agent_config['workspace_path'], '/\\') . DIRECTORY_SEPARATOR . 'BrowserData';
        $start_args[] = '--user-data-dir=' . $data_dir;

        $browser_idx = $agent_core->utils
            ->procMgr->command(
                [
                    $agent_core->utils->agent_config['chrome_path'],
                    ...$start_args
                ]
            )->run($agent_core->utils->getProcIDX());

        $browser_addr = '';
        $browser_pid  = $agent_core->utils->procMgr->getPid($browser_idx);

        $agent_core->utils->savePid($browser_pid);
        $agent_core->utils->debug('Browser started: ' . $payload_data['worker_name'] . ' (PID: ' . $browser_pid . ')', 'trace');

        for ($i = 0; $i < 10; ++$i) {
            $err_msg = trim($agent_core->utils->procMgr->readProc($browser_idx, 'stderr'));

            if ('' !== $err_msg && str_starts_with($err_msg, 'DevTools listening on')) {
                $browser_addr = substr($err_msg, strpos($err_msg, 'ws'));
                break;
            }

            usleep(50000);
        }

        if ('' === $browser_addr) {
            $agent_core->utils->debug('Browser ERROR: Failed to fetch address, closing ' . $payload_data['worker_name'] . ' (PID: ' . $browser_pid . ')', 'trace');
            $agent_core->utils->OSMgr->killPid($browser_pid);
            $agent_core->utils->procMgr->close($browser_idx);
            $agent_core->utils->removePid($browser_pid);

            return '无法获取浏览器 WebSocket 地址，实例已关闭。';
        }

        $local_port = parse_url($browser_addr, PHP_URL_PORT);
        $json_addr  = 'http://127.0.0.1:' . $local_port . '/json';
        $dev_json   = libHttp::new()->fetch($json_addr);
        $dev_data   = json_decode($dev_json, true);
        $ws_addr    = '';

        if (is_array($dev_data) && !empty($dev_data)) {
            foreach ($dev_data as $target) {
                if (isset($target['type']) && 'page' === $target['type'] && isset($target['webSocketDebuggerUrl'])) {
                    $ws_addr = $target['webSocketDebuggerUrl'];
                    break;
                }
            }

            if ('' === $ws_addr && isset($dev_data[0]['webSocketDebuggerUrl'])) {
                $ws_addr = $dev_data[0]['webSocketDebuggerUrl'];
            }
        }

        if ('' === $ws_addr) {
            $agent_core->utils->OSMgr->killPid($browser_pid);
            $agent_core->utils->procMgr->close($browser_idx);
            $agent_core->utils->removePid($browser_pid);

            return '无法获取目标页面 WebSocket 地址，实例已关闭。';
        }

        $socketMgr = SocketMgr::new($payload_data['worker_name']);
        $socketMgr->createWSClient($ws_addr);

        $write       = $except = [];
        $master_id   = $socketMgr->master_id;
        $master_sock = $socketMgr->master_sock;

        $browser_script = json_encode([
            'id'     => $this->cmd_id++,
            'method' => 'Page.addScriptToEvaluateOnNewDocument',
            'params' => ['source' => '(function() { Object.defineProperty(navigator, "webdriver", { get: () => undefined }); })();']
        ], JSON_FORMAT);

        $socketMgr->sendMessage($master_id, $socketMgr->wsEncode($browser_script, false, true));

        if (1 === (int)stream_select($master_sock, $write, $except, 10, 0)) {
            $socketMgr->readMessage($master_id, true);
        }

        Factory::destroy($socketMgr);

        $agent_core->utils->addChildWorker(
            'Browser',
            $payload_data['worker_name'],
            [
                'browser_idx' => $browser_idx,
                'browser_pid' => $browser_pid,
                'worker_name' => $payload_data['worker_name'],
                'socket_id'   => $payload_data['socket_id'],
                'socket_addr' => $ws_addr,
                'status'      => 'ready',
                'action'      => 'idle',
                'open_at'     => date('Y-m-d H:i:s'),
                'data_dir'    => $data_dir
            ]
        );

        $agent_core->utils->debug('Browser: WebSocket Debugger Url is ' . $ws_addr . ', ready for connections.', 'trace');
        $agent_core->utils->debug('Browser: ' . $payload_data['worker_name'] . ' is ready!', 'trace');

        $message = '实例 "' . $payload_data['worker_name'] . '" 已就绪。';

        unset($payload_data, $agent_core, $worker_info, $start_args, $browser_idx, $browser_pid, $browser_addr, $i, $err_msg, $ws_addr, $local_port, $json_addr, $dev_json, $dev_data, $target, $socketMgr, $write, $except, $master_id, $master_sock, $browser_script);
        return $message;
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function navigate(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function click(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function type(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function submit(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function getUrl(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function getTitle(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function getContent(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function getValue(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function getAttribute(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function setAttribute(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function scrollIntoView(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function selectOption(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function evaluate(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function screenshot(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function waitForSelector(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function waitForPageLoad(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function waitForText(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function waitForElementVisible(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function waitForUrl(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function hover(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function pressKey(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
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
        $now     = time();
        $workers = $agent_core->utils->getChildWorker('Browser');

        foreach ($workers as $name => $info) {
            $list[] = [
                'worker_name'   => $name,
                'status'        => $info['status'],
                'action'        => $info['action'],
                'running_sec'   => $now - strtotime($info['open_at']),
                'user_data_dir' => $info['data_dir'],
            ];
        }

        unset($payload_data, $agent_core, $now, $workers, $name, $info);
        return $list;
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return string
     */
    public function close(array $payload_data, agent_core $agent_core): string
    {
        $worker_info = $agent_core->utils->getChildWorker('Browser', $payload_data['worker_name']);

        if (!empty($worker_info)) {
            $this->closeProcess($agent_core, $worker_info, $payload_data['worker_name']);
        }

        $agent_core->utils->debug('Browser: ' . $payload_data['worker_name'] . ' is closed.', 'trace');
        $message = '实例 "' . $payload_data['worker_name'] . '" 已关闭。';

        unset($payload_data, $agent_core, $worker_info);
        return $message;
    }

    /**
     * @param agent_core $agent_core
     * @param array      $worker_info
     * @param string     $worker_name
     *
     * @return void
     */
    private function closeProcess(agent_core $agent_core, array $worker_info, string $worker_name): void
    {
        try {
            $agent_core->utils->removeChildWorker('Browser', $worker_name);
            $agent_core->utils->OSMgr->killPid($worker_info['browser_pid']);
            $agent_core->utils->procMgr->close($worker_info['browser_idx']);
            $agent_core->utils->removePid($worker_info['browser_pid']);
        } catch (\Throwable) {
        }

        unset($agent_core, $worker_info, $worker_name);
    }

    /**
     * @param agent_core $agent_core
     * @param array      $payload_data
     * @param string     $action
     *
     * @return array
     * @throws \ReflectionException
     */
    private function sendCommand(agent_core $agent_core, array $payload_data, string $action): array
    {
        $worker_info = $agent_core->utils->getChildWorker('Browser', $payload_data['worker_name']);

        if (empty($worker_info)) {
            $agent_core->utils->debug('Browser: ' . $payload_data['worker_name'] . ' NOT found!', 'trace');
            return [
                'status' => 'error',
                'error'  => '实例 "' . $payload_data['worker_name'] . '" 不存在，请先启动实例。'
            ];
        }

        if ('ready' !== $worker_info['status'] || 'idle' !== $worker_info['action']) {
            $agent_core->utils->debug('Browser: ' . $payload_data['worker_name'] . ' is busy on ' . $action . '!', 'trace');
            return [
                'status' => 'error',
                'error'  => '实例 ' . $payload_data['worker_name'] . ' 忙碌中 (操作: ' . $worker_info['action'] . ')，待结束后再操作。',
            ];
        }

        $socketMgr     = SocketMgr::new($payload_data['worker_name']);
        $this->timeout = $agent_core->utils->agent_config['chrome_timeout'] ?? 60;

        try {
            $agent_core->utils->debug('Browser: ' . $payload_data['worker_name'] . ' is working on ' . $action . '.', 'trace');
            $agent_core->utils->setChildWorker('Browser', $payload_data['worker_name'], 'status', 'busy');
            $agent_core->utils->setChildWorker('Browser', $payload_data['worker_name'], 'action', $action);

            $socketMgr->createWSClient($worker_info['socket_addr']);

            $write       = $except = [];
            $cmd_id      = $this->cmd_id++;
            $master_id   = $socketMgr->master_id;
            $master_sock = $socketMgr->master_sock;

            $socketMgr->sendMessage(
                $master_id,
                $socketMgr->wsEncode(
                    $this->buildCommand($action, $payload_data['params'] ?? [], $cmd_id),
                    false,
                    true
                )
            );

            if (1 === (int)stream_select($master_sock, $write, $except, $this->timeout, 0)) {
                $agent_core->utils->setChildWorker('Browser', $payload_data['worker_name'], 'status', 'ready');
                $agent_core->utils->setChildWorker('Browser', $payload_data['worker_name'], 'action', 'idle');

                $message  = $socketMgr->readMessage($master_id, true);
                $msg_data = json_decode($message, true);

                if (is_null($msg_data)) {
                    return [
                        'status'  => 'error',
                        'error'   => '执行失败，响应格式异常，请重试或重启实例。',
                        'content' => $message
                    ];
                }

                if (isset($msg_data['error'])) {
                    return [
                        'status' => 'error',
                        'error'  => 'CDP 协议错误，请重启浏览器实例。错误详情: ' . ($msg_data['error']['message'] ?? '未知错误'),
                    ];
                }

                $runtime_error = '';

                if (isset($msg_data['result']['exceptionDetails'])) {
                    $runtime_error = $msg_data['result']['exceptionDetails']['exception']['description'] ?? $msg_data['result']['exceptionDetails']['text'] ?? '脚本异常';
                } elseif (isset($msg_data['result']['result']['subtype']) && 'error' === $msg_data['result']['result']['subtype']) {
                    $runtime_error = $msg_data['result']['result']['description'] ?? '脚本执行错误';
                } elseif (isset($msg_data['result']['result']['value']) && is_string($msg_data['result']['result']['value']) && str_starts_with($msg_data['result']['result']['value'], 'Error:')) {
                    $runtime_error = $msg_data['result']['result']['value'];
                }

                if ('' !== $runtime_error) {
                    return [
                        'status' => 'error',
                        'error'  => '脚本执行错误，请检查选择器或页面状态: ' . $runtime_error,
                    ];
                }

                if ('screenshot' === $action) {
                    if (!isset($msg_data['result']['data'])) {
                        return ['status' => 'error', 'error' => '截图数据缺失，请重试截图操作。'];
                    }

                    $saved_path = $this->saveScreenshot($agent_core, $msg_data['result']['data'], $payload_data['params']['save_path']);

                    if ('' === $saved_path) {
                        return ['status' => 'error', 'error' => '截图保存失败，请检查保存路径和磁盘空间。'];
                    }

                    $data = ['saved_path' => $saved_path];
                    unset($saved_path);
                } else {
                    $data = $msg_data['result']['result']['value'] ?? $msg_data['result'] ?? [];
                }

                if (
                    false === $data
                    && in_array($action, ['waitForSelector', 'waitForPageLoad', 'waitForText', 'waitForElementVisible', 'waitForUrl'], true)
                ) {
                    return [
                        'status' => 'error',
                        'error'  => '等待超时，条件未满足，请检查页面是否正常加载或增加超时时间。',
                    ];
                }

                return [
                    'status'  => 'success',
                    'data'    => $data,
                    'message' => $this->getMessage($action),
                ];
            } else {
                return [
                    'status' => 'error',
                    'error'  => '执行 "' . $action . '" 超时未响应（' . $this->timeout . '秒），请增加超时时间或重启实例。',
                ];
            }
        } catch (\Throwable $throwable) {
            $agent_core->utils->debug('Browser: ' . $payload_data['worker_name'] . ' closed due to communication errors. (' . $throwable->getMessage() . ')', 'trace');

            $this->closeProcess($agent_core, $worker_info, $payload_data['worker_name']);

            unset($throwable);
            return [
                'status' => 'error',
                'error'  => '实例 "' . $payload_data['worker_name'] . '" 通信错误，已关闭实例。',
            ];
        } finally {
            Factory::destroy($socketMgr);
            unset($agent_core, $payload_data, $action, $worker_info, $socketMgr, $cmd_id, $write, $except, $master_id, $master_sock, $message, $msg_data, $runtime_error);
        }
    }

    /**
     * @param agent_core $agent_core
     * @param string     $img_base64
     * @param string     $file_path
     *
     * @return string
     */
    private function saveScreenshot(agent_core $agent_core, string $img_base64, string $file_path): string
    {
        $image_data = base64_decode($img_base64);

        if (false === $image_data) {
            return '';
        }

        $file_path = $agent_core->utils->securePath($file_path);
        $dir_path  = dirname($file_path);

        if (!is_dir($dir_path) && !mkdir($dir_path, 0755, true)) {
            return '';
        }

        $save = file_put_contents($file_path, $image_data);

        unset($agent_core, $img_base64, $image_data, $dir_path);
        return false !== $save ? $file_path : '';
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
        $timeout_ms = (int)($params['timeout'] ?? $this->timeout) * 1000;

        switch ($action) {
            case 'navigate':
                $method     = 'Page.navigate';
                $cdp_params = ['url' => $params['url']];
                break;

            case 'click':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => "(function() { const el = document.querySelector($selector); if (!el) throw new Error('Element not found: ' + $selector); el.click(); return true; })()",
                    'returnByValue' => true
                ];
                break;

            case 'type':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $text       = json_encode($params['text'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => "(function() { const el = document.querySelector($selector); if (!el) throw new Error('Element not found: ' + $selector); el.value = $text; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); return true; })()",
                    'returnByValue' => true
                ];
                break;

            case 'submit':
                $selector   = json_encode($params['selector'] ?? 'form', JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => "(function() { const el = document.querySelector($selector); if (!el) throw new Error('Form not found: ' + $selector); el.submit(); return true; })()",
                    'returnByValue' => true
                ];
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
                    'expression'    => "new Promise(resolve => { let s=Date.now(); const c=()=>{ if(document.querySelector(" . json_encode($params['selector'], JSON_FORMAT) . ")) resolve(true); else if(Date.now()-s>$timeout_ms) resolve(false); else setTimeout(c,100); }; c(); })",
                    'returnByValue' => true
                ];
                break;

            case 'waitForPageLoad':
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => "new Promise(resolve => { let s=Date.now(); const c=()=>{ if(document.readyState==='complete') resolve(true); else if(Date.now()-s>$timeout_ms) resolve(false); else setTimeout(c,100); }; c(); })",
                    'returnByValue' => true
                ];
                break;

            case 'waitForText':
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => "new Promise(resolve => { let s=Date.now(); const c=()=>{ if(document.body.innerText.includes(" . json_encode($params['text'], JSON_FORMAT) . ")) resolve(true); else if(Date.now()-s>$timeout_ms) resolve(false); else setTimeout(c,100); }; c(); })",
                    'returnByValue' => true
                ];
                break;

            case 'waitForElementVisible':
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => "new Promise(resolve => { let s=Date.now(); const c=()=>{ const el=document.querySelector(" . json_encode($params['selector'], JSON_FORMAT) . "); if(el && getComputedStyle(el).display!=='none' && getComputedStyle(el).visibility!=='hidden') resolve(true); else if(Date.now()-s>$timeout_ms) resolve(false); else setTimeout(c,100); }; c(); })",
                    'returnByValue' => true
                ];
                break;

            case 'waitForUrl':
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => "new Promise(resolve => { let s=Date.now(); const c=()=>{ if(window.location.href.includes(" . json_encode($params['url_pattern'], JSON_FORMAT) . ")) resolve(true); else if(Date.now()-s>$timeout_ms) resolve(false); else setTimeout(c,100); }; c(); })",
                    'returnByValue' => true
                ];
                break;

            case 'hover':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . json_encode($params['selector'], JSON_FORMAT) . ').dispatchEvent(new MouseEvent("mouseover", {bubbles:true}));', 'returnByValue' => false];
                break;

            case 'pressKey':
                $mod_props = [];
                $modifiers = $params['modifiers'] ?? [];
                foreach ($modifiers as $mod) {
                    $mod_props[] = strtolower($mod) . 'Key: true';
                }
                $mod_str    = implode(',', $mod_props);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => "(function() { const el = document.activeElement; if (!el) return false; const event = new KeyboardEvent('keydown', { key: " . json_encode($params['key'], JSON_FORMAT) . ", bubbles: true, " . $mod_str . " }); el.dispatchEvent(event); return true; })()",
                    'returnByValue' => true
                ];
                break;

            default:
                return '';
        }

        $command = json_encode(['id' => $cmd_id, 'method' => $method, 'params' => $cdp_params], JSON_FORMAT);

        unset($action, $params, $cmd_id, $timeout_ms, $method, $cdp_params);
        return $command;
    }

    /**
     * @param string $action
     *
     * @return string
     */
    private function getMessage(string $action): string
    {
        switch ($action) {
            case 'navigate':
                return '导航已启动，请等待页面加载完成后（可通过 waitForPageLoad 确认）再继续操作，不要频繁发起请求。';
            case 'submit':
                return '表单已提交，若页面发生跳转请等待加载完成，避免连续操作。';
            case 'click':
                return '点击操作已执行，若页面发生跳转请等待加载完成后继续。';
            case 'type':
                return '文本输入成功。';
            case 'getUrl':
                return '当前页面 URL 已获取。';
            case 'getTitle':
                return '页面标题已获取。';
            case 'getContent':
                return '页面内容已获取。';
            case 'getValue':
                return '元素值已获取。';
            case 'getAttribute':
                return '元素属性值已获取。';
            case 'setAttribute':
                return '属性设置成功。';
            case 'scrollIntoView':
                return '元素已滚动到可视区域。';
            case 'selectOption':
                return '选项已选择。';
            case 'evaluate':
                return '脚本执行完成。';
            case 'screenshot':
                return '截图已完成（数据已返回）。';
            case 'waitForSelector':
            case 'waitForPageLoad':
            case 'waitForText':
            case 'waitForElementVisible':
            case 'waitForUrl':
                return '等待条件已满足。';
            case 'hover':
                return '悬停操作成功。';
            case 'pressKey':
                return '键盘按键模拟成功。';
            default:
                return '操作已完成。';
        }
    }
}