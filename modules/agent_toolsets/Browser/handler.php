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

namespace modules\agent_toolsets\Browser;

use modules\agent_core\go as agent_core;
use Nervsys\Core\Factory;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Ext\libHttp;

class handler extends Factory
{
    public int $cmd_id  = 0;
    public int $timeout = 60;

    public string $curr_id = '';

    public array $tab_list     = [];
    public array $browser_data = [];

    public const START_ARGS = [
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
        if (!empty($this->browser_data)) {
            return '浏览器实例已存在，可直接进行操作。当前已打开' . count($this->tab_list) . '个标签页。';
        }

        $start_args = self::START_ARGS;

        if ($payload_data['params']['headless'] ?? false) {
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
        $agent_core->utils->debug('Browser started (PID: ' . $browser_pid . ')', 'trace');

        for ($i = 0; $i < 10; ++$i) {
            $err_msg = trim($agent_core->utils->procMgr->readProc($browser_idx, 'stderr'));

            if ('' !== $err_msg && str_starts_with($err_msg, 'DevTools listening on')) {
                $browser_addr = substr($err_msg, strpos($err_msg, 'ws'));
                break;
            }

            usleep(50000);
        }

        if ('' === $browser_addr) {
            $agent_core->utils->debug('Browser ERROR: Failed to fetch address, closing (PID: ' . $browser_pid . ')', 'trace');
            $agent_core->utils->OSMgr->killPid($browser_pid);
            $agent_core->utils->procMgr->close($browser_idx);
            $agent_core->utils->removePid($browser_pid);

            return '无法获取浏览器WebSocket地址，浏览器实例已关闭。';
        }

        $target    = [];
        $ws_addr   = '';
        $target_id = '';

        $local_port = parse_url($browser_addr, PHP_URL_PORT);
        $debugger   = $this->fetchDebugger($local_port);

        if (!empty($debugger)) {
            foreach ($debugger as $item) {
                if (isset($item['type']) && 'page' === $item['type'] && isset($item['webSocketDebuggerUrl'])) {
                    $ws_addr   = $item['webSocketDebuggerUrl'];
                    $target_id = $item['id'] ?? '';
                    $target    = $item;
                    break;
                }
            }

            if ('' === $ws_addr && isset($debugger[0]['webSocketDebuggerUrl'])) {
                $ws_addr   = $debugger[0]['webSocketDebuggerUrl'];
                $target_id = $debugger[0]['id'] ?? '';
                $target    = $debugger[0];
            }
        }

        if ('' === $ws_addr) {
            $agent_core->utils->OSMgr->killPid($browser_pid);
            $agent_core->utils->procMgr->close($browser_idx);
            $agent_core->utils->removePid($browser_pid);

            return '无法获取目标页面WebSocket地址，浏览器实例已关闭。';
        }

        $socketMgr = SocketMgr::new('browser');
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

        $this->browser_data = [
            'browser_idx' => $browser_idx,
            'browser_pid' => $browser_pid,
            'socket_addr' => $ws_addr,
            'debug_port'  => $local_port,
            'data_dir'    => $data_dir,
        ];

        if (!empty($target) && '' !== $target_id) {
            $this->tab_list[$target_id] = [
                'ws_addr' => $ws_addr,
                'url'     => $target['url'] ?? '',
                'title'   => $target['title'] ?? '',
                'status'  => 'ready',
                'action'  => 'idle',
            ];

            $this->curr_id = $target_id;
        }

        $agent_core->utils->debug('Browser: Debugger Url "' . $ws_addr . '", ready for connections.', 'trace');

        $message = '浏览器已就绪，初始标签页`target_id`: `' . $target_id . '`，可继续操作。';

        unset($payload_data, $agent_core, $start_args, $data_dir, $browser_idx, $browser_addr, $browser_pid, $i, $err_msg, $target, $ws_addr, $target_id, $local_port, $debugger, $item, $socketMgr, $write, $except, $master_id, $master_sock, $browser_script);
        return $message;
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return string
     */
    public function close(array $payload_data, agent_core $agent_core): string
    {
        if (empty($this->browser_data)) {
            return '浏览器实例未启动，无需关闭。';
        }

        try {
            $agent_core->utils->OSMgr->killPid($this->browser_data['browser_pid']);
            $agent_core->utils->procMgr->close($this->browser_data['browser_idx']);
            $agent_core->utils->removePid($this->browser_data['browser_pid']);
        } catch (\Throwable) {
        }

        $this->curr_id      = '';
        $this->tab_list     = [];
        $this->browser_data = [];

        $agent_core->utils->debug('Browser closed.', 'trace');
        return '浏览器实例已关闭。';
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function createTab(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     */
    public function switchTab(array $payload_data, agent_core $agent_core): array
    {
        if (!isset($payload_data['params']['target_id']) || '' === $payload_data['params']['target_id']) {
            return ['status' => 'error', 'error' => '缺少`target_id`'];
        }

        if (!isset($this->tab_list[$payload_data['params']['target_id']])) {
            return ['status' => 'error', 'error' => '标签页`' . $payload_data['params']['target_id'] . '`不存在。'];
        }

        $this->curr_id = $payload_data['params']['target_id'];

        unset($payload_data, $agent_core);
        return ['status' => 'success', 'message' => '当前标签页已切换至`' . $this->curr_id . '`，可继续操作。'];
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array|string[]
     */
    public function listTabs(array $payload_data, agent_core $agent_core): array
    {
        if (empty($this->browser_data)) {
            return ['status' => 'error', 'error' => '浏览器实例未启动，请先启动浏览器。'];
        }

        $list = [];

        foreach ($this->tab_list as $target_id => $item) {
            $list[] = [
                'target_id'  => $target_id,
                'url'        => $item['url'] ?? '',
                'title'      => $item['title'] ?? '',
                'status'     => $item['status'] ?? 'ready',
                'action'     => $item['action'] ?? 'idle',
                'is_current' => ($target_id === $this->curr_id),
            ];
        }

        $result = [
            'status'  => 'success',
            'data'    => $list,
            'message' => '共' . count($list) . '个标签页。'
        ];

        unset($payload_data, $agent_core, $list, $target_id, $item);
        return $result;
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array
     * @throws \ReflectionException
     */
    public function closeTab(array $payload_data, agent_core $agent_core): array
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
     * @param int $port
     *
     * @return array
     * @throws \ReflectionException
     */
    public function fetchDebugger(int $port): array
    {
        $json_addr = 'http://127.0.0.1:' . $port . '/json';
        $response  = libHttp::new()->fetch($json_addr);
        $results   = json_decode($response, true) ?? [];

        unset($port, $json_addr, $response);
        return $results;
    }

    /**
     * @param agent_core $agent_core
     * @param string     $img_base64
     * @param string     $file_path
     *
     * @return string
     */
    public function saveScreenshot(agent_core $agent_core, string $img_base64, string $file_path): string
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
     * @param array $payload_data
     * @param array $msg_data
     *
     * @return array|string[]
     * @throws \ReflectionException
     */
    public function handleCreateTab(array $payload_data, array $msg_data): array
    {
        if (!isset($msg_data['result']['targetId'])) {
            return ['status' => 'error', 'error' => '创建标签页失败：未返回targetId。'];
        }

        $ws_addr   = '';
        $debugger  = $this->fetchDebugger($this->browser_data['debug_port']);
        $target_id = $msg_data['result']['targetId'];

        if (!empty($debugger)) {
            foreach ($debugger as $item) {
                if (isset($item['id']) && $item['id'] === $target_id && $item['type'] === 'page') {
                    $ws_addr = $item['webSocketDebuggerUrl'] ?? '';
                    break;
                }
            }
        }

        if ('' === $ws_addr) {
            return ['status' => 'error', 'error' => '创建标签页失败：无法获取新标签页的WebSocket地址。'];
        }

        $this->tab_list[$target_id] = [
            'ws_addr' => $ws_addr,
            'url'     => $payload_data['params']['url'] ?? 'about:blank',
            'title'   => '',
            'status'  => 'ready',
            'action'  => 'idle',
        ];

        $this->curr_id = $target_id;

        unset($payload_data, $msg_data, $ws_addr, $debugger, $item);
        return [
            'status'  => 'success',
            'data'    => ['target_id' => $target_id],
            'message' => '新标签页已创建，当前页已切换至`' . $target_id . '`，可继续操作。'
        ];
    }

    /**
     * @param array $payload_data
     * @param array $msg_data
     *
     * @return string[]
     */
    public function handleCloseTab(array $payload_data, array $msg_data): array
    {
        if (isset($msg_data['result']['success']) && false === $msg_data['result']['success']) {
            return ['status' => 'error', 'error' => '标签页关闭失败。'];
        }

        $target_id = $payload_data['params']['target_id'] ?? '';

        if ('' !== $target_id && isset($this->tab_list[$target_id])) {
            unset($this->tab_list[$target_id]);
        }

        if ($this->curr_id === $target_id) {
            $tab_ids       = array_keys($this->tab_list);
            $this->curr_id = !empty($tab_ids) ? $tab_ids[0] : '';
            unset($tab_ids);
        }

        unset($payload_data, $msg_data, $target_id);
        return ['status' => 'success', 'message' => '标签页已关闭。'];
    }

    /**
     * @param string $action
     * @param array  $params
     * @param int    $cmd_id
     *
     * @return string
     */
    public function buildCommand(string $action, array $params, int $cmd_id): string
    {
        $timeout_ms = (int)($params['timeout'] ?? $this->timeout) * 1000;

        switch ($action) {
            case 'createTab':
                $method     = 'Target.createTarget';
                $cdp_params = ['url' => $params['url'] ?? 'about:blank'];
                break;

            case 'closeTab':
                $method     = 'Target.closeTarget';
                $cdp_params = ['targetId' => $params['target_id']];
                break;

            case 'navigate':
                $method     = 'Page.navigate';
                $cdp_params = ['url' => $params['url']];
                break;

            case 'click':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => '(function() { const el = document.querySelector(' . $selector . '); if (!el) throw new Error("Element not found: " + ' . $selector . '); el.click(); return true; })()',
                    'returnByValue' => true
                ];
                break;

            case 'type':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $text       = json_encode($params['text'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => '(function() { const el = document.querySelector(' . $selector . '); if (!el) throw new Error("Element not found: " + ' . $selector . '); el.value = ' . $text . '; el.dispatchEvent(new Event("input", { bubbles: true })); el.dispatchEvent(new Event("change", { bubbles: true })); return true; })()',
                    'returnByValue' => true
                ];
                break;

            case 'submit':
                $selector   = json_encode($params['selector'] ?? 'form', JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => '(function() { const el = document.querySelector(' . $selector . '); if (!el) throw new Error("Form not found: " + ' . $selector . '); el.submit(); return true; })()',
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
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . $selector . ').value', 'returnByValue' => true];
                break;

            case 'getAttribute':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $attribute  = json_encode($params['attribute'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . $selector . ').getAttribute(' . $attribute . ')', 'returnByValue' => true];
                break;

            case 'setAttribute':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $attribute  = json_encode($params['attribute'], JSON_FORMAT);
                $value      = json_encode($params['value'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . $selector . ').setAttribute(' . $attribute . ', ' . $value . ');', 'returnByValue' => false];
                break;

            case 'scrollIntoView':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . $selector . ').scrollIntoView();', 'returnByValue' => false];
                break;

            case 'selectOption':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $value      = json_encode($params['value'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . $selector . ').value = ' . $value . ';', 'returnByValue' => false];
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
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { let s=Date.now(); const c=()=>{ if(document.querySelector(' . $selector . ')) resolve(true); else if(Date.now()-s> ' . $timeout_ms . ') resolve(false); else setTimeout(c,100); }; c(); })',
                    'returnByValue' => true
                ];
                break;

            case 'waitForPageLoad':
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { let s=Date.now(); const c=()=>{ if(document.readyState==="complete") resolve(true); else if(Date.now()-s> ' . $timeout_ms . ') resolve(false); else setTimeout(c,100); }; c(); })',
                    'returnByValue' => true
                ];
                break;

            case 'waitForText':
                $text       = json_encode($params['text'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { let s=Date.now(); const c=()=>{ if(document.body.innerText.includes(' . $text . ')) resolve(true); else if(Date.now()-s> ' . $timeout_ms . ') resolve(false); else setTimeout(c,100); }; c(); })',
                    'returnByValue' => true
                ];
                break;

            case 'waitForElementVisible':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { let s=Date.now(); const c=()=>{ const el=document.querySelector(' . $selector . '); if(el && getComputedStyle(el).display!=="none" && getComputedStyle(el).visibility!=="hidden") resolve(true); else if(Date.now()-s> ' . $timeout_ms . ') resolve(false); else setTimeout(c,100); }; c(); })',
                    'returnByValue' => true
                ];
                break;

            case 'waitForUrl':
                $url_pattern = json_encode($params['url_pattern'], JSON_FORMAT);
                $method      = 'Runtime.evaluate';
                $cdp_params  = [
                    'expression'    => 'new Promise(resolve => { let s=Date.now(); const c=()=>{ if(window.location.href.includes(' . $url_pattern . ')) resolve(true); else if(Date.now()-s> ' . $timeout_ms . ') resolve(false); else setTimeout(c,100); }; c(); })',
                    'returnByValue' => true
                ];
                break;

            case 'hover':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => 'document.querySelector(' . $selector . ').dispatchEvent(new MouseEvent("mouseover", {bubbles:true}));', 'returnByValue' => false];
                break;

            case 'pressKey':
                $mod_props = [];
                $modifiers = $params['modifiers'] ?? [];
                foreach ($modifiers as $mod) {
                    $mod_props[] = strtolower($mod) . 'Key: true';
                }
                $mod_str    = implode(',', $mod_props);
                $key        = json_encode($params['key'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => '(function() { const el = document.activeElement; if (!el) return false; const event = new KeyboardEvent("keydown", { key: ' . $key . ', bubbles: true, ' . $mod_str . ' }); el.dispatchEvent(event); return true; })()',
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
     * @param agent_core $agent_core
     * @param array      $payload_data
     * @param string     $action
     *
     * @return array
     * @throws \ReflectionException
     */
    public function sendCommand(agent_core $agent_core, array $payload_data, string $action): array
    {
        if (empty($this->browser_data)) {
            return ['status' => 'error', 'error' => '浏览器实例未启动，请先启动浏览器。'];
        }

        $target_id = $payload_data['params']['target_id'] ?? $this->curr_id ?? '';

        if ('' === $target_id) {
            return ['status' => 'error', 'error' => '无可用标签页，请先创建或切换。'];
        }

        // Sync debugger data from browser
        if (!isset($this->tab_list[$target_id])) {
            $debugger = $this->fetchDebugger($this->browser_data['debug_port']);

            if (!empty($debugger)) {
                foreach ($debugger as $item) {
                    if (isset($item['id']) && $item['id'] === $target_id && $item['type'] === 'page') {
                        $this->tab_list[$target_id] = [
                            'ws_addr' => $item['webSocketDebuggerUrl'] ?? '',
                            'url'     => $item['url'] ?? '',
                            'title'   => $item['title'] ?? '',
                            'status'  => 'ready',
                            'action'  => 'idle',
                        ];
                        break;
                    }
                }
            }

            if (!isset($this->tab_list[$target_id])) {
                return ['status' => 'error', 'error' => '标签页`' . $target_id . '`不存在或已关闭。'];
            }
        }

        $tab_info = $this->tab_list[$target_id];

        if ('busy' === $tab_info['status']) {
            return ['status' => 'error', 'error' => '标签页`' . $target_id . '`正忙（操作: ' . $tab_info['action'] . '），请稍后重试。'];
        }

        $this->tab_list[$target_id]['status'] = 'busy';
        $this->tab_list[$target_id]['action'] = $action;

        $ws_addr = $tab_info['ws_addr'];

        if ('' === $ws_addr) {
            unset($this->tab_list[$target_id]);

            if ($this->curr_id === $target_id) {
                $tab_ids       = array_keys($this->tab_list);
                $this->curr_id = !empty($tab_ids) ? $tab_ids[0] : '';
                unset($tab_ids);
            }

            return ['status' => 'error', 'error' => '标签页`' . $target_id . '`已失效，已自动移除。请创建或切换新标签页。'];
        }

        $socketMgr     = SocketMgr::new('browser');
        $this->timeout = $agent_core->utils->agent_config['chrome_timeout'] ?? 60;

        try {
            $agent_core->utils->debug('Browser: working on ' . $action . '.', 'trace');

            $socketMgr->createWSClient($ws_addr);

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

            $this->tab_list[$target_id]['status'] = 'ready';
            $this->tab_list[$target_id]['action'] = 'idle';

            if (1 === (int)stream_select($master_sock, $write, $except, $this->timeout, 0)) {
                $message  = $socketMgr->readMessage($master_id, true);
                $msg_data = json_decode($message, true);

                if (is_null($msg_data)) {
                    return [
                        'status'  => 'error',
                        'error'   => '执行"' . $action . '"失败，响应格式异常，请重试或重启实例。',
                        'content' => $message
                    ];
                }

                if (isset($msg_data['error'])) {
                    return [
                        'status' => 'error',
                        'error'  => 'CDP协议错误，请重启浏览器实例。错误详情: ' . ($msg_data['error']['message'] ?? '未知错误'),
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
                    in_array($action, ['waitForSelector', 'waitForPageLoad', 'waitForText', 'waitForElementVisible', 'waitForUrl'], true)
                    && is_array($data)
                    && isset($data['value'])
                    && false === $data['value']
                ) {
                    return [
                        'status' => 'error',
                        'error'  => '执行"' . $action . '"等待超时，条件未满足，请检查页面是否正常加载或增加超时时间。',
                    ];
                }

                if ('createTab' === $action) {
                    return $this->handleCreateTab($payload_data, $msg_data);
                }

                if ('closeTab' === $action) {
                    return $this->handleCloseTab($payload_data, $msg_data);
                }

                if ('navigate' === $action && isset($data['url'])) {
                    $this->tab_list[$target_id]['url'] = $data['url'];
                }

                return [
                    'status'  => 'success',
                    'data'    => $data,
                    'message' => $this->getMessage($action),
                ];
            } else {
                return [
                    'status' => 'error',
                    'error'  => '执行"' . $action . '"超时未响应（' . $this->timeout . '秒），请增加超时时间或重启实例。',
                ];
            }
        } catch (\Throwable $throwable) {
            $agent_core->utils->debug('Browser closed due to communication errors. (' . $throwable->getMessage() . ')', 'trace');
            $this->close($payload_data, $agent_core);

            unset($throwable);
            return [
                'status' => 'error',
                'error'  => '浏览器实例通信错误，已关闭。',
            ];
        } finally {
            Factory::destroy($socketMgr);
            unset($agent_core, $payload_data, $action, $socketMgr, $cmd_id, $write, $except, $master_id, $master_sock, $message, $msg_data, $runtime_error);
        }
    }

    /**
     * @param string $action
     *
     * @return string
     */
    public function getMessage(string $action): string
    {
        $messages = [
            'navigate'              => '导航已启动，请等待页面加载完成后（可通过`waitForPageLoad`确认）再继续操作，不要频繁发起请求。',
            'submit'                => '表单已提交，若页面发生跳转请等待加载完成，避免连续操作。',
            'click'                 => '点击操作已执行，若页面发生跳转请等待加载完成后继续。',
            'type'                  => '文本输入成功。',
            'getUrl'                => '当前页面URL已获取。',
            'getTitle'              => '页面标题已获取。',
            'getContent'            => '页面内容已获取。',
            'getValue'              => '元素值已获取。',
            'getAttribute'          => '元素属性值已获取。',
            'setAttribute'          => '属性设置成功。',
            'scrollIntoView'        => '元素已滚动到可视区域。',
            'selectOption'          => '选项已选择。',
            'evaluate'              => '脚本执行完成。',
            'screenshot'            => '截图已保存。',
            'waitForSelector'       => '等待条件已满足。',
            'waitForPageLoad'       => '等待条件已满足。',
            'waitForText'           => '等待条件已满足。',
            'waitForElementVisible' => '等待条件已满足。',
            'waitForUrl'            => '等待条件已满足。',
            'hover'                 => '悬停操作成功。',
            'pressKey'              => '键盘按键模拟成功。',
        ];

        return $messages[$action] ?? '操作已完成。';
    }
}