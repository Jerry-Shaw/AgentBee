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
        '--disable-background-timer-throttling',
        '--disable-backgrounding-occluded-windows',
        '--disable-component-update',
        '--disable-client-side-phishing-detection',
        '--disable-default-apps',
        '--disable-dev-shm-usage',
        '--disable-domain-reliability',
        '--disable-blink-features=AutomationControlled',
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
        if ($this->isBrowserAlive($payload_data, $agent_core)) {
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

        for ($i = 0; $i < 100; ++$i) {
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

        $local_port = parse_url($browser_addr, PHP_URL_PORT);
        $debugger   = $this->fetchDebugger($local_port);
        $tab_list   = [];
        $ws_addr    = '';

        foreach ($debugger as $item) {
            if (isset($item['type']) && 'page' === $item['type'] && isset($item['webSocketDebuggerUrl'])) {
                $tab_id = $item['id'] ?? '';
                $tab_ws = $item['webSocketDebuggerUrl'];

                if ('' !== $tab_id && '' !== $tab_ws) {
                    if ('' === $ws_addr) {
                        $ws_addr       = $tab_ws;
                        $this->curr_id = $tab_id;
                    }

                    $tab_list[$tab_id] = [
                        'ws_addr'  => $tab_ws,
                        'url'      => $item['url'] ?? '',
                        'title'    => $item['title'] ?? '',
                        'status'   => 'ready',
                        'action'   => 'idle',
                        'injected' => false
                    ];
                }
            }
        }

        if ([] === $tab_list) {
            $agent_core->utils->OSMgr->killPid($browser_pid);
            $agent_core->utils->procMgr->close($browser_idx);
            $agent_core->utils->removePid($browser_pid);
            return '无法获取目标页面WebSocket地址，浏览器实例已关闭。';
        }

        $this->tab_list = $tab_list;

        $this->browser_data = [
            'browser_idx'  => $browser_idx,
            'browser_pid'  => $browser_pid,
            'browser_addr' => $browser_addr,
            'debug_addr'   => $ws_addr,
            'debug_port'   => $local_port,
            'data_dir'     => $data_dir,
        ];

        $agent_core->utils->debug('Browser ready for connections.', 'trace');
        $message = '浏览器已就绪，当前标签页`target_id`：`' . $this->curr_id . '`，可继续操作。';

        unset($payload_data, $agent_core, $start_args, $data_dir, $browser_idx, $browser_pid, $browser_addr, $i, $err_msg, $local_port, $debugger, $tab_list, $ws_addr);
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
        if ([] === $this->browser_data) {
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    public function switchTab(array $payload_data, agent_core $agent_core): array
    {
        return $this->sendCommand($agent_core, $payload_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return array|string[]
     * @throws \ReflectionException
     */
    public function listTabs(array $payload_data, agent_core $agent_core): array
    {
        if ([] === $this->browser_data) {
            return ['status' => 'error', 'error' => '浏览器实例未启动，请先启动浏览器。'];
        }

        $tab_list = [];
        $debugger = $this->fetchDebugger($this->browser_data['debug_port']);

        foreach ($debugger as $item) {
            if (isset($item['type']) && 'page' === $item['type'] && isset($item['webSocketDebuggerUrl'])) {
                $tab_id = $item['id'] ?? '';
                $tab_ws = $item['webSocketDebuggerUrl'];

                if ('' === $tab_id || '' === $tab_ws) {
                    continue;
                }

                $tab_list[$tab_id] = [
                    'target_id' => $tab_id,
                    'ws_addr'   => $tab_ws,
                    'url'       => $item['url'] ?? '',
                    'title'     => $item['title'] ?? '',
                    'status'    => $this->tab_list[$tab_id]['status'] ?? 'ready',
                    'action'    => $this->tab_list[$tab_id]['action'] ?? 'idle',
                    'injected'  => $this->tab_list[$tab_id]['injected'] ?? false,
                ];
            }
        }

        $this->tab_list = $tab_list;

        if (!isset($this->tab_list[$this->curr_id])) {
            $this->curr_id = array_key_first($this->tab_list) ?: '';

            if ('' !== $this->curr_id && isset($this->tab_list[$this->curr_id]['ws_addr'])) {
                $this->browser_data['debug_addr'] = $this->tab_list[$this->curr_id]['ws_addr'];
            }
        }

        foreach ($tab_list as $tab_id => $item) {
            unset($tab_list[$tab_id]['ws_addr'], $tab_list[$tab_id]['injected']);
            $tab_list[$tab_id]['is_current'] = $tab_id === $this->curr_id;
        }

        unset($payload_data, $agent_core, $debugger, $item, $tab_id, $tab_ws);
        return [
            'status'  => 'success',
            'data'    => array_values($tab_list),
            'message' => '共' . count($tab_list) . '个标签页。'
        ];
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
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
     * @return string[]
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    public function hover(array $payload_data, agent_core $agent_core): array
    {
        $position_data = $payload_data;
        $selector      = json_encode($payload_data['params']['selector'], JSON_FORMAT);

        $position_data['params']['script'] = '(function(){const el=document.querySelector(' . $selector . ');if(!el)throw new Error("Element not found: "+' . $selector . ');el.scrollIntoView({behavior:"auto",block:"center",inline:"center"});const rect=el.getBoundingClientRect();if(rect.width<=0||rect.height<=0||el.getClientRects().length===0)throw new Error("Element is not visible: "+' . $selector . ');return{x:rect.left+rect.width/2,y:rect.top+rect.height/2};})()';

        $position_data['params']['return_by_value'] = true;

        $result = $this->sendCommand($agent_core, $position_data, 'evaluate');

        if ('success' !== ($result['status'] ?? 'error')) {
            return $result;
        }

        $position = $result['data'] ?? [];

        if (
            !is_array($position)
            || !isset($position['x'], $position['y'])
            || !is_numeric($position['x'])
            || !is_numeric($position['y'])
        ) {
            return [
                'status' => 'error',
                'error'  => '无法获取目标元素的有效坐标。',
            ];
        }

        $hover_data = [
            'params' => [
                'x' => (float)$position['x'],
                'y' => (float)$position['y'],
            ],
        ];

        if (isset($payload_data['params']['target_id'])) {
            $hover_data['params']['target_id'] = $payload_data['params']['target_id'];
        }

        unset($payload_data, $position_data, $selector, $result, $position);
        return $this->sendCommand($agent_core, $hover_data, __FUNCTION__);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return string[]
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    public function pressKey(array $payload_data, agent_core $agent_core): array
    {
        $payload_data['params']['event_type'] = 'keyDown';

        $result = $this->sendCommand($agent_core, $payload_data, __FUNCTION__);

        if ('success' !== ($result['status'] ?? 'error')) {
            return $result;
        }

        $payload_data['params']['event_type'] = 'keyUp';

        $result = $this->sendCommand($agent_core, $payload_data, __FUNCTION__);

        if ('success' === ($result['status'] ?? 'error')) {
            $result['message'] = $this->getMessage(__FUNCTION__);
        }

        unset($payload_data, $agent_core);
        return $result;
    }

    /**
     * @param string     $target_id
     * @param agent_core $agent_core
     *
     * @return void
     * @throws \Random\RandomException
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function injectScript(string $target_id, agent_core $agent_core): void
    {
        if (true === ($this->tab_list[$target_id]['injected'] ?? false)) {
            return;
        }

        $ws_addr = $this->tab_list[$target_id]['ws_addr'] ?? '';

        if ('' === $ws_addr) {
            throw new \RuntimeException('无法注入脚本：标签页WebSocket地址无效。');
        }

        $source = '(function(){try{Object.defineProperty(Navigator.prototype,"webdriver",{configurable:true,enumerable:true,get:function(){return undefined;}});}catch(error){try{Object.defineProperty(navigator,"webdriver",{configurable:true,enumerable:true,get:function(){return undefined;}});}catch(ignored){}}})();';

        $socketMgr = SocketMgr::new('injector');
        $socketMgr->createWSClient($ws_addr);

        $commands = [
            [
                'method' => 'Page.addScriptToEvaluateOnNewDocument',
                'params' => [
                    'source' => $source,
                ],
            ],
            [
                'method' => 'Runtime.evaluate',
                'params' => [
                    'expression'    => $source,
                    'returnByValue' => true,
                ],
            ],
        ];

        foreach ($commands as $command) {
            $cmd_id  = $this->cmd_id++;
            $message = json_encode(
                [
                    'id'     => $cmd_id,
                    'method' => $command['method'],
                    'params' => $command['params'],
                ],
                JSON_FORMAT
            );

            $socketMgr->sendMessage($socketMgr->master_id, $socketMgr->wsEncode($message, false, true));

            $read     = $socketMgr->master_sock;
            $write    = [];
            $except   = [];
            $selected = stream_select($read, $write, $except, 10);

            if (in_array($selected, [0, false], true)) {
                continue;
            }

            $socketMgr->readMessage($socketMgr->master_id, true);
        }

        Factory::destroy($socketMgr);

        $this->tab_list[$target_id]['injected'] = true;
        $agent_core->utils->debug('Browser: Injection commands sent to ' . $target_id, 'trace');

        unset($target_id, $agent_core, $ws_addr, $source, $socketMgr, $commands, $command, $cmd_id, $message, $read, $write, $except, $selected);
    }

    /**
     * @param array      $payload_data
     * @param agent_core $agent_core
     *
     * @return bool
     * @throws \ReflectionException
     */
    public function isBrowserAlive(array $payload_data, agent_core $agent_core): bool
    {
        if ([] === $this->browser_data) {
            return false;
        }

        $socketMgr = SocketMgr::new('checker');

        try {
            $socketMgr->createWSClient($this->browser_data['browser_addr'] ?? '');
            $alive = true;
        } catch (\Throwable) {
            $this->close($payload_data, $agent_core);
            $alive = false;
        }

        Factory::destroy($socketMgr);

        unset($payload_data, $agent_core, $socketMgr);
        return $alive;
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
     * @param string     $img_base64
     * @param string     $file_path
     * @param agent_core $agent_core
     *
     * @return string
     */
    public function saveScreenshot(string $img_base64, string $file_path, agent_core $agent_core): string
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

        unset($img_base64, $agent_core, $image_data, $dir_path);
        return false !== $save ? $file_path : '';
    }

    /**
     * @param array      $payload_data
     * @param array      $msg_data
     * @param agent_core $agent_core
     *
     * @return array|string[]
     * @throws \ReflectionException
     */
    public function handleCreateTab(array $payload_data, array $msg_data, agent_core $agent_core): array
    {
        if (!isset($msg_data['result']['targetId'])) {
            return ['status' => 'error', 'error' => '创建标签页失败：未返回targetId。'];
        }

        $ws_addr   = '';
        $debugger  = $this->fetchDebugger($this->browser_data['debug_port']);
        $target_id = $msg_data['result']['targetId'];

        if ([] !== $debugger) {
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
            'ws_addr'  => $ws_addr,
            'url'      => $payload_data['params']['url'] ?? 'about:blank',
            'title'    => '',
            'status'   => 'ready',
            'action'   => 'idle',
            'injected' => false
        ];

        $this->curr_id = $target_id;

        try {
            $this->injectScript($target_id, $agent_core);
        } catch (\Throwable $throwable) {
            $agent_core->utils->debug('Browser: Failed to inject to ' . $target_id, 'trace');
            unset($throwable);
        }

        unset($payload_data, $msg_data, $agent_core, $ws_addr, $debugger, $item);
        return [
            'status'  => 'success',
            'data'    => ['target_id' => $target_id],
            'message' => '新标签页已创建，当前页已切换至`' . $target_id . '`，可继续操作。'
        ];
    }

    /**
     * @param array      $payload_data
     * @param array      $msg_data
     * @param agent_core $agent_core
     *
     * @return string[]
     */
    public function handleCloseTab(array $payload_data, array $msg_data, agent_core $agent_core): array
    {
        if (isset($msg_data['result']['success']) && false === $msg_data['result']['success']) {
            return ['status' => 'error', 'error' => '标签页关闭失败。'];
        }

        $target_id = $payload_data['params']['target_id'] ?? '';

        if ('' !== $target_id && isset($this->tab_list[$target_id])) {
            unset($this->tab_list[$target_id]);
        }

        if ([] === $this->tab_list) {
            $this->close($payload_data, $agent_core);
            unset($payload_data, $msg_data, $agent_core, $target_id);
            return ['status' => 'success', 'message' => '最后一个标签页已关闭，浏览器实例已退出。'];
        }

        if ($this->curr_id === $target_id) {
            $tab_ids       = array_keys($this->tab_list);
            $this->curr_id = [] !== $tab_ids ? $tab_ids[0] : '';

            if ('' !== $this->curr_id) {
                $this->browser_data['debug_addr'] = $this->tab_list[$this->curr_id]['ws_addr'];
            }

            unset($tab_ids);
        }

        unset($payload_data, $msg_data, $agent_core, $target_id);
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

            case 'switchTab':
                $method     = 'Target.activateTarget';
                $cdp_params = ['targetId' => $params['target_id']];
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
                    'expression'    => '(function(){const el=document.querySelector(' . $selector . ');if(!el)throw new Error("Element not found: "+' . $selector . ');if(typeof el.click!=="function")throw new Error("Element is not clickable: "+' . $selector . ');if(el.disabled)throw new Error("Element is disabled: "+' . $selector . ');el.scrollIntoView({behavior:"auto",block:"center",inline:"center"});if(typeof el.focus==="function")el.focus({preventScroll:true});el.click();return true;})()',
                    'returnByValue' => true,
                    'userGesture'   => true,
                ];
                break;

            case 'type':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $text       = json_encode($params['text'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => '(function() { const el = document.querySelector(' . $selector . '); if (!el) throw new Error("Element not found: " + ' . $selector . '); let proto; if (el instanceof HTMLInputElement) proto = HTMLInputElement.prototype; else if (el instanceof HTMLTextAreaElement) proto = HTMLTextAreaElement.prototype; else throw new Error("Element does not support text input: " + ' . $selector . '); if (el.disabled) throw new Error("Element is disabled: " + ' . $selector . '); if (el.readOnly) throw new Error("Element is read-only: " + ' . $selector . '); const setter = Object.getOwnPropertyDescriptor(proto, "value").set; el.focus(); setter.call(el, ' . $text . '); el.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "insertText", data: ' . $text . ' })); el.dispatchEvent(new Event("change", { bubbles: true })); return true; })()',
                    'returnByValue' => true,
                    'userGesture'   => true
                ];
                break;

            case 'submit':
                $selector   = json_encode($params['selector'] ?? 'form', JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => '(function() { const el = document.querySelector(' . $selector . '); if (!el) throw new Error("Form not found: " + ' . $selector . '); if (!(el instanceof HTMLFormElement)) throw new Error("Element is not a form: " + ' . $selector . '); HTMLFormElement.prototype.requestSubmit.call(el); return true; })()',
                    'returnByValue' => true,
                    'userGesture'   => true
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
                $expr       = (true === $html)
                    ? 'document.documentElement ? document.documentElement.outerHTML : ""'
                    : 'document.body ? document.body.innerText : ""';
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => $expr,
                    'returnByValue' => true
                ];
                break;

            case 'getValue':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => '(function() { const el = document.querySelector(' . $selector . '); if (!el) throw new Error("Element not found: " + ' . $selector . '); if (!("value" in el)) throw new Error("Element has no value property: " + ' . $selector . '); return el.value; })()',
                    'returnByValue' => true
                ];
                break;

            case 'getAttribute':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $attribute  = json_encode($params['attribute'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => '(function() { const el = document.querySelector(' . $selector . '); if (!el) throw new Error("Element not found: " + ' . $selector . '); return el.getAttribute(' . $attribute . '); })()',
                    'returnByValue' => true
                ];
                break;

            case 'setAttribute':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $attribute  = json_encode($params['attribute'], JSON_FORMAT);
                $value      = json_encode($params['value'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => '(function() { const el = document.querySelector(' . $selector . '); if (!el) throw new Error("Element not found: " + ' . $selector . '); el.setAttribute(' . $attribute . ', ' . $value . '); return true; })()',
                    'returnByValue' => true
                ];
                break;

            case 'scrollIntoView':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => '(function() { const el = document.querySelector(' . $selector . '); if (!el) throw new Error("Element not found: " + ' . $selector . '); el.scrollIntoView({ behavior: "auto", block: "center", inline: "nearest" }); return true; })()',
                    'returnByValue' => true
                ];
                break;

            case 'selectOption':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $value      = json_encode($params['value'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => '(function() { const el = document.querySelector(' . $selector . '); if (!el) throw new Error("Element not found: " + ' . $selector . '); if (!(el instanceof HTMLSelectElement)) throw new Error("Element is not a select: " + ' . $selector . '); const value = ' . $value . '; const exists = Array.from(el.options).some(option => option.value === value); if (!exists) throw new Error("Option value not found: " + value); const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, "value").set; setter.call(el, value); el.dispatchEvent(new Event("input", { bubbles: true })); el.dispatchEvent(new Event("change", { bubbles: true })); return true; })()',
                    'returnByValue' => true
                ];
                break;

            case 'evaluate':
                $method     = 'Runtime.evaluate';
                $cdp_params = ['expression' => $params['script'], 'returnByValue' => $params['return_by_value'] ?? true];
                break;

            case 'screenshot':
                $format     = $params['format'] ?? 'jpeg';
                $method     = 'Page.captureScreenshot';
                $cdp_params = ['format' => $format];

                if ('jpeg' === $format) {
                    $cdp_params['quality'] = $params['quality'] ?? 80;
                }
                break;

            case 'waitForSelector':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { const s = Date.now(); const c = () => { if (document.querySelector(' . $selector . ')) resolve(true); else if (Date.now() - s >= ' . $timeout_ms . ') resolve(false); else setTimeout(c, 100); }; c(); })',
                    'returnByValue' => true,
                    'awaitPromise'  => true
                ];
                break;

            case 'waitForPageLoad':
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { const s = Date.now(); const c = () => { if (document.readyState === "complete") resolve(true); else if (Date.now() - s >= ' . $timeout_ms . ') resolve(false); else setTimeout(c, 100); }; c(); })',
                    'returnByValue' => true,
                    'awaitPromise'  => true
                ];
                break;

            case 'waitForText':
                $text       = json_encode($params['text'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { const s = Date.now(); const c = () => { const body = document.body; if (body && body.innerText.includes(' . $text . ')) resolve(true); else if (Date.now() - s >= ' . $timeout_ms . ') resolve(false); else setTimeout(c, 100); }; c(); })',
                    'returnByValue' => true,
                    'awaitPromise'  => true
                ];
                break;

            case 'waitForElementVisible':
                $selector   = json_encode($params['selector'], JSON_FORMAT);
                $method     = 'Runtime.evaluate';
                $cdp_params = [
                    'expression'    => 'new Promise(resolve => { const s = Date.now(); const c = () => { const el = document.querySelector(' . $selector . '); if (el) { const style = getComputedStyle(el); const visible = !el.hidden && style.display !== "none" && style.visibility !== "hidden" && style.visibility !== "collapse" && style.opacity !== "0" && el.getClientRects().length > 0; if (visible) { resolve(true); return; } } if (Date.now() - s >= ' . $timeout_ms . ') resolve(false); else setTimeout(c, 100); }; c(); })',
                    'returnByValue' => true,
                    'awaitPromise'  => true
                ];
                break;

            case 'waitForUrl':
                $url_pattern = json_encode($params['url_pattern'], JSON_FORMAT);
                $method      = 'Runtime.evaluate';
                $cdp_params  = [
                    'expression'    => 'new Promise(resolve => { const s = Date.now(); const c = () => { if (window.location.href.includes(' . $url_pattern . ')) resolve(true); else if (Date.now() - s >= ' . $timeout_ms . ') resolve(false); else setTimeout(c, 100); }; c(); })',
                    'returnByValue' => true,
                    'awaitPromise'  => true
                ];
                break;

            case 'hover':
                $method     = 'Input.dispatchMouseEvent';
                $cdp_params = [
                    'type' => 'mouseMoved',
                    'x'    => (float)$params['x'],
                    'y'    => (float)$params['y'],
                ];
                break;

            case 'pressKey':
                $key        = (string)$params['key'];
                $mod_bits   = 0;
                $modifiers  = $params['modifiers'] ?? [];
                $event_type = 'keyUp' === ($params['event_type'] ?? 'keyDown')
                    ? 'keyUp'
                    : 'keyDown';

                // CDP modifiers：Alt=1、Control=2、Meta=4、Shift=8
                if (in_array('Alt', $modifiers, true)) {
                    $mod_bits |= 1;
                }

                if (in_array('Control', $modifiers, true)) {
                    $mod_bits |= 2;
                }

                if (in_array('Meta', $modifiers, true)) {
                    $mod_bits |= 4;
                }

                if (in_array('Shift', $modifiers, true)) {
                    $mod_bits |= 8;
                }

                $key_aliases = [
                    'Esc'   => 'Escape',
                    'Space' => ' ',
                    'Left'  => 'ArrowLeft',
                    'Right' => 'ArrowRight',
                    'Up'    => 'ArrowUp',
                    'Down'  => 'ArrowDown',
                    'Del'   => 'Delete',
                ];

                $key = $key_aliases[$key] ?? $key;

                $key_map = [
                    'Enter'      => ['Enter', 13],
                    'Tab'        => ['Tab', 9],
                    'Backspace'  => ['Backspace', 8],
                    'Escape'     => ['Escape', 27],
                    ' '          => ['Space', 32],
                    'PageUp'     => ['PageUp', 33],
                    'PageDown'   => ['PageDown', 34],
                    'End'        => ['End', 35],
                    'Home'       => ['Home', 36],
                    'ArrowLeft'  => ['ArrowLeft', 37],
                    'ArrowUp'    => ['ArrowUp', 38],
                    'ArrowRight' => ['ArrowRight', 39],
                    'ArrowDown'  => ['ArrowDown', 40],
                    'Insert'     => ['Insert', 45],
                    'Delete'     => ['Delete', 46],
                    'F1'         => ['F1', 112],
                    'F2'         => ['F2', 113],
                    'F3'         => ['F3', 114],
                    'F4'         => ['F4', 115],
                    'F5'         => ['F5', 116],
                    'F6'         => ['F6', 117],
                    'F7'         => ['F7', 118],
                    'F8'         => ['F8', 119],
                    'F9'         => ['F9', 120],
                    'F10'        => ['F10', 121],
                    'F11'        => ['F11', 122],
                    'F12'        => ['F12', 123],
                ];

                $code = '';
                $vk   = 0;

                if (isset($key_map[$key])) {
                    [$code, $vk] = $key_map[$key];
                } elseif (1 === preg_match('/^[a-zA-Z]$/', $key)) {
                    $upper = strtoupper($key);
                    $code  = 'Key' . $upper;
                    $vk    = ord($upper);
                    $key   = in_array('Shift', $modifiers, true)
                        ? $upper
                        : strtolower($key);
                } elseif (1 === preg_match('/^[0-9]$/', $key)) {
                    $code = 'Digit' . $key;
                    $vk   = ord($key);
                }

                $method     = 'Input.dispatchKeyEvent';
                $cdp_params = [
                    'type'                  => $event_type,
                    'modifiers'             => $mod_bits,
                    'key'                   => $key,
                    'code'                  => $code,
                    'windowsVirtualKeyCode' => $vk,
                    'nativeVirtualKeyCode'  => $vk,
                    'autoRepeat'            => false,
                    'isKeypad'              => false,
                    'isSystemKey'           => in_array('Alt', $modifiers, true),
                    'location'              => 0,
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
     * @return array|string[]
     * @throws \Random\RandomException
     * @throws \ReflectionException
     */
    public function sendCommand(agent_core $agent_core, array $payload_data, string $action): array
    {
        if ([] === $this->browser_data) {
            return ['status' => 'error', 'error' => '浏览器实例未启动，请先启动浏览器。'];
        }

        $target_id = $payload_data['params']['target_id'] ?? '';

        if ('' === $target_id) {
            $target_id = $this->curr_id;
        }

        if ('' === $target_id) {
            return ['status' => 'error', 'error' => '无可用标签页，请先创建或切换。'];
        }

        // Sync debugger data from browser
        if (!isset($this->tab_list[$target_id])) {
            $debugger = $this->fetchDebugger($this->browser_data['debug_port']);

            if ([] !== $debugger) {
                foreach ($debugger as $item) {
                    if (isset($item['id']) && $item['id'] === $target_id && $item['type'] === 'page') {
                        $this->tab_list[$target_id] = [
                            'ws_addr'  => $item['webSocketDebuggerUrl'] ?? '',
                            'url'      => $item['url'] ?? '',
                            'title'    => $item['title'] ?? '',
                            'status'   => 'ready',
                            'action'   => 'idle',
                            'injected' => false
                        ];
                        break;
                    }
                }
            }

            if (!isset($this->tab_list[$target_id])) {
                return ['status' => 'error', 'error' => '标签页`' . $target_id . '`不存在或已关闭。'];
            }

            unset($debugger, $item);
        }

        $tab_info = $this->tab_list[$target_id];

        if ('busy' === $tab_info['status']) {
            return ['status' => 'error', 'error' => '标签页`' . $target_id . '`正忙（操作: ' . $tab_info['action'] . '），请稍后重试。'];
        }

        try {
            if (!in_array($action, ['createTab', 'closeTab'], true)) {
                $this->injectScript($target_id, $agent_core);
            }
        } catch (\Throwable $throwable) {
            $agent_core->utils->debug('Browser: Failed to inject to ' . $target_id, 'trace');
            unset($throwable);
        }

        $this->tab_list[$target_id]['status'] = 'busy';
        $this->tab_list[$target_id]['action'] = $action;

        $ws_addr = $tab_info['ws_addr'] ?? '';

        if ('' === $ws_addr) {
            unset($this->tab_list[$target_id]);

            if ($this->curr_id === $target_id) {
                $tab_ids       = array_keys($this->tab_list);
                $this->curr_id = [] !== $tab_ids ? $tab_ids[0] : '';
                unset($tab_ids);
            }

            return ['status' => 'error', 'error' => '标签页`' . $target_id . '`已失效，已自动移除。请创建或切换新标签页。'];
        }

        $socketMgr     = SocketMgr::new('browser');
        $this->timeout = $agent_core->utils->agent_config['chrome_timeout'] ?? 60;

        try {
            $agent_core->utils->debug('Browser: working on ' . $action . '.', 'trace');

            $socketMgr->createWSClient($ws_addr);

            $cmd_id    = $this->cmd_id++;
            $master_id = $socketMgr->master_id;

            $raw_msg  = '';
            $msg_data = [];
            $timeout  = $payload_data['params']['timeout'] ?? $this->timeout;
            $deadline = microtime(true) + $timeout + 1;

            $socketMgr->sendMessage(
                $master_id,
                $socketMgr->wsEncode(
                    $this->buildCommand(
                        $action,
                        $payload_data['params'] ?? [],
                        $cmd_id
                    ),
                    false,
                    true
                )
            );

            while (microtime(true) < $deadline) {
                $remaining = $deadline - microtime(true);

                if ($remaining <= 0) {
                    break;
                }

                $seconds      = (int)$remaining;
                $microseconds = (int)(($remaining - $seconds) * 1000000);

                $read   = $socketMgr->master_sock;
                $write  = [];
                $except = [];

                $selected = stream_select($read, $write, $except, $seconds, $microseconds);

                if (false === $selected) {
                    throw new \RuntimeException('等待CDP响应失败。');
                }

                if (0 === $selected) {
                    break;
                }

                $response_msg  = $socketMgr->readMessage($master_id, true);
                $response_data = json_decode($response_msg, true);

                if (!is_array($response_data)) {
                    $raw_msg = $response_msg;
                    break;
                }

                if (!array_key_exists('id', $response_data)) {
                    continue;
                }

                if ((int)$response_data['id'] !== $cmd_id) {
                    continue;
                }

                $msg_data = $response_data;
                break;
            }

            $this->tab_list[$target_id]['status'] = 'ready';
            $this->tab_list[$target_id]['action'] = 'idle';

            if ([] === $msg_data) {
                if ('' !== $raw_msg) {
                    return [
                        'status'  => 'error',
                        'error'   => '执行"' . $action . '"失败，响应格式异常，请重试或重启实例。',
                        'content' => $raw_msg,
                    ];
                }

                return [
                    'status' => 'error',
                    'error'  => '执行"' . $action . '"超时未响应（' . $timeout . '秒），请增加超时时间或重启实例。',
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
                $runtime_error = $msg_data['result']['exceptionDetails']['exception']['description']
                    ?? $msg_data['result']['exceptionDetails']['text']
                    ?? '脚本异常';
            } elseif (isset($msg_data['result']['result']['subtype']) && 'error' === $msg_data['result']['result']['subtype']) {
                $runtime_error = $msg_data['result']['result']['description'] ?? '脚本执行错误';
            }

            if ('' !== $runtime_error) {
                return [
                    'status' => 'error',
                    'error'  => '脚本执行错误，请检查选择器或页面状态: ' . $runtime_error,
                ];
            }

            if ('screenshot' === $action) {
                if (!isset($msg_data['result']['data'])) {
                    return [
                        'status' => 'error',
                        'error'  => '截图数据缺失，请重试截图操作。',
                    ];
                }

                $saved_path = $this->saveScreenshot($msg_data['result']['data'], $payload_data['params']['save_path'], $agent_core);

                if ('' === $saved_path) {
                    return [
                        'status' => 'error',
                        'error'  => '截图保存失败，请检查保存路径和磁盘空间。',
                    ];
                }

                $data = ['saved_path' => $saved_path];
            } else {
                $data = $msg_data['result']['result']['value'] ?? $msg_data['result'] ?? [];
            }

            if (
                in_array($action, ['waitForSelector', 'waitForPageLoad', 'waitForText', 'waitForElementVisible', 'waitForUrl',], true)
                && false === $data
            ) {
                return [
                    'status' => 'error',
                    'error'  => '执行"' . $action . '"等待超时，条件未满足，请检查页面是否正常加载或增加超时时间。',
                ];
            }

            if ('createTab' === $action) {
                return $this->handleCreateTab($payload_data, $msg_data, $agent_core);
            }

            if ('closeTab' === $action) {
                return $this->handleCloseTab($payload_data, $msg_data, $agent_core);
            }

            if ('switchTab' === $action) {
                $this->curr_id = $target_id;

                if (isset($this->tab_list[$target_id]['ws_addr'])) {
                    $this->browser_data['debug_addr'] = $this->tab_list[$target_id]['ws_addr'];
                }

                return [
                    'status'  => 'success',
                    'message' => '当前标签页已切换至`' . $target_id . '`，可继续操作。',
                ];
            }

            if ('navigate' === $action && isset($data['url'])) {
                $this->tab_list[$target_id]['url'] = $data['url'];
            }

            return [
                'status'  => 'success',
                'data'    => $data,
                'message' => $this->getMessage($action),
            ];
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
            unset($agent_core, $payload_data, $action, $target_id, $tab_info, $ws_addr, $socketMgr, $cmd_id, $master_id, $raw_msg, $msg_data, $timeout, $deadline, $remaining, $seconds, $microseconds, $read, $write, $except, $selected, $response_msg, $response_data, $runtime_error, $saved_path, $data);
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