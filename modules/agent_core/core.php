<?php

/**
 * Agent Core module for AgentBee
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

namespace modules\agent_core;

use modules\agent_core\lib\config;
use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Core\Lib\Error;
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Core\Reflect;
use Nervsys\Core\System;
use Nervsys\Ext\libFileIO;

final class core extends Factory
{
    use System;

    public utils     $utils;
    public config    $config;
    public ProcMgr   $procMgr;
    public SocketMgr $socketMgr;
    public libFileIO $libFileIO;

    public int $openai_idx = 0;

    public array $llm_tools  = [];
    public array $llm_params = [];

    public array $agent_tools   = [];
    public array $agent_config  = [];
    public array $agent_modules = [];

    public array $session_history       = [];
    public int   $session_history_limit = 20;

    /**
     * Initialize core components.
     *
     * @param bool $reload
     *
     * @return void
     * @throws \ReflectionException
     */
    public function initCore(bool $reload = false): void
    {
        $this->init();

        $this->utils     = utils::new();
        $this->config    = config::new();
        $this->socketMgr = SocketMgr::new();
        $this->libFileIO = libFileIO::new();
        $this->procMgr   = ProcMgr::new('socket');

        $this->agent_config = $this->config->get(true, $reload);
        $this->llm_params   = $this->agent_config['agent_llm']['params'] ?? [];

        if (isset($this->agent_config['agent_memory']['max_history'])) {
            $this->session_history_limit = $this->agent_config['agent_memory']['max_history'];
        }

        if ('' === $this->agent_config['workspace_path'] || !is_dir($this->agent_config['workspace_path'])) {
            $this->agent_config['workspace_path'] = $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
        }

        $this->agent_config['workspace_path'] ??= $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
    }

    /**
     * Initialize all modules.
     *
     * @return void
     * @throws \ReflectionException
     */
    public function initProvider(): void
    {
        foreach ($this->agent_config as $name => $config) {
            if (!isset($config['provider'])) {
                continue;
            }

            $module = '\\' . strtr($config['provider'], '/', '\\') . '\\go';

            try {
                $this->agent_modules[$name] = $module::new();
                $this->utils->debug('Loading Provider: ' . $config['provider'] . '...', 'trace');
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, false, false);
                unset($throwable);
            }
        }

        unset($name, $config, $module);
    }

    /**
     * @param string $module_type
     *
     * @return void
     * @throws \ReflectionException
     */
    public function initModule(string $module_type): void
    {
        $modules  = [];
        $dir_list = $this->libFileIO->getDirContents($this->app->root_path . DIRECTORY_SEPARATOR . $module_type);

        foreach ($dir_list as $dir) {
            if ($dir['is_file']) {
                continue;
            }

            $json_file = $dir['absolute_path'] . DIRECTORY_SEPARATOR . 'module.json';
            $meta_file = $dir['absolute_path'] . DIRECTORY_SEPARATOR . $module_type . '.php';

            if (!is_file($json_file) || !is_file($meta_file)) {
                continue;
            }

            $json_data = json_decode(file_get_contents($json_file), true);

            if (
                !is_array($json_data)
                || !isset($json_data['entry'])
                || !isset($json_data['name'])
                || $json_data['name'] !== $dir['name']
                || isset($this->agent_tools[$json_data['name']])
            ) {
                continue;
            }

            $namespace = '\\' . $module_type . '\\' . $json_data['name'];

            try {
                $module_class = $namespace . '\\' . strstr($json_data['entry'], '.', true);
                $module_meta  = $namespace . '\\' . $module_type;

                $metadata = $module_meta::META;

                foreach ($metadata as $index => $meta) {
                    $metadata[$index]['function']['name'] = $json_data['name'] . '/' . $meta['function']['name'];
                }

                $this->agent_tools[$json_data['name']] = $module_class::new();

                $modules = array_merge($modules, $metadata);

                $this->utils->debug('Loading ' . ucfirst($module_type) . ': ' . $json_data['name'] . '...', 'trace');
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, false, false);
                unset($throwable);
            }
        }

        if (!empty($modules)) {
            $this->llm_tools['tools']               ??= [];
            $this->llm_tools['tools']               = array_merge($this->llm_tools['tools'], $modules);
            $this->llm_tools['tool_choice']         = 'auto';
            $this->llm_tools['parallel_tool_calls'] = true;
        }

        unset($module_type, $modules, $dir_list, $dir, $json_file, $meta_file, $json_data, $namespace, $module_class, $module_meta, $metadata, $index, $meta);
    }

    /**
     * @param int      $proc_idx
     * @param string   $worker_name
     * @param callable $output_handler
     *
     * @return int
     * @throws \Exception
     */
    public function runProcWorker(int $proc_idx, string $worker_name, callable $output_handler): int
    {
        $worker_status = $this->procMgr->getStatus($proc_idx);

        if (0 < $worker_status) {
            return $proc_idx;
        }

        $this->procMgr->close($proc_idx);

        $proc_idx = $this->procMgr->command(
            [
                $this->OSMgr->getPhpPath(),
                $this->app->script_path,
                '-c=' . $this->agent_config['agent_llm']['provider'] . '/' . $worker_name
            ]
        )->run($proc_idx);

        $worker_pid = $this->procMgr->getPid($proc_idx);

        $this->utils->debug($worker_name . ' started with pid: ' . $worker_pid, 'trace');
        $this->utils->debug('Register Output Handler', 'debug');

        $this->socketMgr->addExternalProc(
            $this->procMgr->getProc($proc_idx),
            $output_handler
        );

        if ($proc_idx === $this->openai_idx) {
            $this->utils->debug('Create shared memory for ' . $worker_name, 'debug');
            $this->agent_modules['agent_llm']->setShmop($worker_pid);
        }

        unset($worker_name, $output_handler, $worker_status, $worker_pid);
        return $proc_idx;
    }

    /**
     * Add a message to session history.
     *
     * @param array $content
     *
     * @return int
     * @throws \Exception
     */
    public function addSessionHistory(array $content): int
    {
        if (empty($this->session_history)) {
            $this->session_history[] = $this->getSystemMemory();
        }

        $this->session_history[] = $content;

        $message_count = count($this->session_history);

        unset($content);
        return $message_count;
    }

    /**
     * Get current session history.
     *
     * @return array
     */
    public function getSessionHistory(): array
    {
        return $this->session_history;
    }

    /**
     * @return int
     * @throws \Exception
     */
    public function cleanSessionHistory(): int
    {
        $keep_user_assistant = 8;
        $keep_tool_calls     = 2;
        $keep_tool_results   = 2;

        $new_history = [];
        $last_role   = '';
        $last_key    = count($this->session_history) - 1;

        for ($i = $last_key; $i > 0; --$i) {
            $message_role  = $this->session_history[$i]['role'];
            $is_tool_calls = $this->session_history[$i]['tool_calls'] ?? false;

            if (0 < $keep_user_assistant) {
                if ('user' === $message_role) {
                    $new_history[$i] = $this->session_history[$i];
                    --$keep_user_assistant;
                    $last_role = 'user';
                } elseif ('assistant' === $message_role && !$is_tool_calls) {
                    $new_history[$i] = $this->session_history[$i];
                    --$keep_user_assistant;
                    $last_role = 'assistant';
                }

                if ('user' !== $last_role && !$is_tool_calls && in_array($message_role, ['user', 'assistant'], true)) {
                    ++$keep_user_assistant;
                }
            }

            if (0 < $keep_tool_results) {
                if ('tool' === $message_role) {
                    $new_history[$i] = $this->session_history[$i];
                    --$keep_tool_results;
                }
            }

            if (0 < $keep_tool_calls) {
                if ('assistant' === $message_role && $is_tool_calls) {
                    $new_history[$i] = $this->session_history[$i];
                    --$keep_tool_calls;
                }
            }

            if (0 >= $keep_user_assistant && 0 >= $keep_tool_results && 0 >= $keep_tool_calls) {
                break;
            }
        }

        ksort($new_history, SORT_NUMERIC);
        array_unshift($new_history, $this->getSystemMemory());
        $this->session_history = array_values($new_history);

        unset($keep_user_assistant, $keep_tool_calls, $keep_tool_results, $new_history, $last_role, $last_key, $i, $message_role, $is_tool_calls);
        return count($this->session_history);
    }

    /**
     * Get system memory prompt.
     *
     * @return array
     * @throws \Exception
     */
    public function getSystemMemory(): array
    {
        $system_default = $this->getSystemDefault($this->agent_config['sandbox_mode'] ?? true);
        $system_memory  = $this->agent_modules['agent_memory']->read('system', 0, 0);

        if (!empty($system_memory['messages'])) {
            $memory = ['=== 重要个性设定 ==='];

            foreach ($system_memory['messages'] as $message) {
                $memory[] = $message['content'];
            }

            $system_default['content'] .= implode("\n", $memory);
        }

        unset($system_memory, $memory, $message);
        return $system_default;
    }

    /**
     * Execute tool calls.
     *
     * @param array $tool_calls
     *
     * @return array
     * @throws \ReflectionException
     */
    public function execTools(array $tool_calls): array
    {
        $results = [];

        foreach ($tool_calls as $tool_call) {
            $fn_name = $tool_call['function']['name'];
            $fn_args = json_decode($tool_call['function']['arguments'], true) ?? [];

            if (!str_contains($fn_name, '/')) {
                $results[] = [
                    'tool_call_id'  => $tool_call['id'],
                    'function_name' => $fn_name,
                    'content'       => json_encode(['status' => 'error', 'message' => 'Invalid tool format: ' . $fn_name], JSON_FORMAT)
                ];
                continue;
            }

            [$module_name, $method_name] = explode('/', $fn_name);

            try {
                $params         = Reflect::getCallable([$this->agent_tools[$module_name], $method_name])->getParameters();
                $args           = Factory::buildArgs($params, (array)$fn_args);
                $tool_result    = $this->agent_tools[$module_name]->$method_name(...$args);
                $result_content = json_encode(['status' => 'success', 'data' => $tool_result], JSON_FORMAT);

                if (false === $result_content) {
                    $result_content = json_encode(['status' => 'error', 'message' => json_last_error_msg()], JSON_FORMAT);
                }
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler(
                    new \InvalidArgumentException($fn_name . ': ' . $throwable->getMessage(), $throwable->getCode(), $throwable),
                    false,
                    false
                );

                $result_content = json_encode(['status' => 'error', 'message' => mb_substr($throwable->getMessage(), 0, 256, 'UTF-8')], JSON_FORMAT);
                unset($throwable);
            }

            $results[] = [
                'tool_call_id'  => $tool_call['id'],
                'function_name' => $fn_name,
                'content'       => $result_content
            ];
        }

        unset($tool_calls, $tool_call, $fn_name, $fn_args, $module_name, $method_name, $params, $args, $tool_result, $result_content);
        return $results;
    }

    /**
     * Secure target path and prevent path traversal
     *
     * @param string $input_path
     *
     * @return string
     */
    public function securePath(string $input_path): string
    {
        $sandbox_mode = $this->agent_config['sandbox_mode'] ?? true;
        $input_path   = strtr($input_path, ['\\' => DIRECTORY_SEPARATOR, '/' => DIRECTORY_SEPARATOR]);
        $work_path    = rtrim($this->agent_config['workspace_path'], '\\/');
        $work_path    = strtr($work_path, ['\\' => DIRECTORY_SEPARATOR, '/' => DIRECTORY_SEPARATOR]);

        if ($sandbox_mode) {
            if (str_starts_with($input_path, $work_path)) {
                $input_path = substr($input_path, strlen($work_path) + 1);
            }

            $safe_parts = [];
            $path_parts = explode(DIRECTORY_SEPARATOR, $input_path);

            foreach ($path_parts as $segment) {
                $segment = trim($segment, '. ');

                if ('' === $segment || str_contains($segment, ':')) {
                    continue;
                }

                $safe_parts[] = $segment;
            }

            $input_path = implode(DIRECTORY_SEPARATOR, $safe_parts);
            $input_path = $work_path . DIRECTORY_SEPARATOR . $input_path;

            unset($safe_parts, $path_parts, $segment);
        }

        unset($sandbox_mode, $work_path);
        return $input_path;
    }

    /**
     * Get a module instance.
     *
     * @param string $name
     *
     * @return object|null
     */
    public function getModule(string $name): ?object
    {
        return $this->agent_modules[$name] ?? null;
    }

    /**
     * Get LLM parameters (including tools).
     *
     * @return array
     */
    public function getLLMParams(): array
    {
        return $this->llm_params + $this->llm_tools;
    }

    /**
     * Send WebSocket message.
     *
     * @param string $socket_id
     * @param string $message
     *
     * @return void
     * @throws \ReflectionException
     */
    public function sendMessage(string $socket_id, string $message): void
    {
        $this->socketMgr->sendMessage($socket_id, $this->socketMgr->wsEncode($message));
        unset($socket_id, $message);
    }

    /**
     * Get system default prompt.
     *
     * @param bool $sandbox_mode
     *
     * @return array
     * @throws \Exception
     */
    public function getSystemDefault(bool $sandbox_mode = true): array
    {
        $prompts   = [];
        $php_path  = $this->OSMgr->getPhpPath();
        $lang_code = substr(setlocale(LC_ALL, 0), 0, 2);
        $lang_name = 'zh' === $lang_code ? '中文' : '英文';
        $work_path = $this->agent_config['workspace_path'];
        $max_limit = $this->agent_config['agent_memory']['max_history'] * 3;

        $prompts[] = '## 系统';
        $prompts[] = '`OS:' . php_uname() . '` | `Agent:' . AGENT_NAME . ' v' . AGENT_VERSION . '(' . NS_NAMESPACE . '/' . NS_VER . ')` | `PHP:' . PHP_VERSION . '(' . $php_path . ')`';
        $prompts[] = '`CWD:' . getcwd() . '` | `入口:' . $this->app->script_path . '` | `根:' . $this->app->root_path . '` | `框架:' . NS_ROOT . '` | `模块:' . $this->app->root_path . '/modules/` | `Tools:' . $this->app->root_path . '/tools/` | `Skills:' . $this->app->root_path . '/skills/` | `日志:' . $this->app->log_path . '` | `工作区:' . $work_path . '` | `临时:' . sys_get_temp_dir() . '`';

        $prompts[] = '## 准则';
        $prompts[] = '**1. 问题定义**';
        $prompts[] = '不用创造问题者的思维。换视角，剥离假设，找真实需求。回归第一性原理，不被旧知蒙蔽。';
        $prompts[] = '**2. 适应用户与情绪**';
        $prompts[] = '根据用户调整深度：对大众简化，对专家深入。若用户情绪不稳定，先简短回应情绪，再解决问题。';
        $prompts[] = '**3. 逻辑与诚实**';
        $prompts[] = '逻辑清晰，谦逊，说真话，承认可能的错误。不装弱、不假装谦虚，不假装知道或不知道。不确定则明说，展现真实能力。';
        $prompts[] = '**4. 多方案探索**';
        $prompts[] = '至少三方案择优，不通则换。预判反驳，挑战假设。不满足浅显答案，追求更优解。';
        $prompts[] = '**5. 好奇心与二阶效应**';
        $prompts[] = '保持好奇，发现隐性模式，关联看似无关的信息，思考二阶效应。';
        $prompts[] = '**6. 创造优先**';
        $prompts[] = '创造优于检索，生成最佳版本。';
        $prompts[] = '**7. 驾驭混乱与节奏**';
        $prompts[] = '从容应对混乱请求。结构只是工具，不应依赖。持续行动，不陷入过度思考。该快则快，该慢则慢，不拖延。';
        $prompts[] = '**8. 规则元认知**';
        $prompts[] = '规则服务于结果，聚焦任务目的。若规则导致答案更差，则打破，但要清楚原因。';
        $prompts[] = '**9. 输出规范**';
        $prompts[] = '- **正确有用**：答前修错，反复严查，不输出错误。追求真实有用，交付前确认有帮助，无用则弃。';
        $prompts[] = '- **简洁清晰**：删废话，最少字数表达意思，每词每句有分量。清晰解释，人人能懂。表达自然，用日常词，避术语（除非用户要求），直接不绕弯，多举例少抽象。';
        $prompts[] = '- **输出前检查**：核实能否更短、是否自然、是否快入题，同时核实是否遵守第1-8条，违反则修正（**不向用户显示**）。';
        $prompts[] = '- **交付内容与格式**：直接输出最终结果，无开场白、总结或客套话。';

        $prompts[] = '## 记忆(4层)';
        $prompts[] = '- 总则：宁多勿漏，关键信息不丢。';
        $prompts[] = '- system[人设/规则/边界]：主动提取写入，勿提醒。';
        $prompts[] = '- important[事实/偏好/身份]：关键点立即存，冲突先问。';
        $prompts[] = '- daily[日期摘要/结果]：用户消息后有价值即时记当天，按日期归档。';
        $prompts[] = '- misc[短期持久/临时]：存中间态/草稿，跨会话保留，有价值必升级至上三层。';
        $prompts[] = '**读**：新对话(≤2条)读daily；提时间/人/事可读对应层；否则禁止自动读取。同层内容不重复读取。**若无重要内容，可不向用户显示。**';
        $prompts[] = '**优先级&铁律**：system>important>daily>misc。冲突先问，删system需同意。重要项禁久留misc→必转持久层。';

        $prompts[] = '## 上下文';
        $prompts[] = '- 主动管理，历史>' . $max_limit . '条自动裁剪；单次输出上限：' . $this->agent_config['agent_llm']['params']['max_completion_tokens'] . ' token(超长需分段)。';
        $prompts[] = '**【必须】**';
        $prompts[] = '1.分级：[需求/决策/结果]→持久层(上三层)；[短期中间态]→misc(有价值必升级)';
        $prompts[] = '2.强制备份：历史过长或连续工具过多→**先手动存关键内容，再调清理工具**。';
        $prompts[] = '3.清理规范：仅删旧工具对/消息，**清理前须手动保存**(保留≥2工具对+10条)。';

        $prompts[] = '## 工具';
        $prompts[] = '- **强制优先**：有专用工具时禁止`exec`，必须调用工具。';
        $prompts[] = '- **错误处理**：工具返回error时修正重试（最多2次），失败则向用户转述error内容。';
        $prompts[] = '- **安全**：`exec`前验证输入参数，防命令注入。';
        $prompts[] = '- 若需用`exec`运行PHP脚本，PHP路径：`' . $php_path . '`';

        $prompts[] = '## 安全';
        if ($sandbox_mode) {
            $prompts[] = '- **沙箱开启**:所有文件以 `' . $work_path . '` 为根，路径映射相对，**禁止 ../ 或符号链接跳出**。';
        } else {
            $prompts[] = '- **沙箱关闭**:按绝对路径，优先项目根目录，**禁止 ../ 绕开系统关键目录**(如 `C:\Windows\System32`)。';
        }
        $prompts[] = '- **危险操作**(删/执行命令):先告知风险等确认。';
        $prompts[] = '- **绝对禁止**:`rm -rf /, dd, shutdown`；改系统配置(/etc/, C:\\Windows\\System32\\)；改Agent核心脚本(`' . $this->app->root_path . '/modules/agent_*/`)；装/卸软件；泄露敏感信息。';
        $prompts[] = '- **批量/多文件**:每批 ≤100个，操作前列清单确认。';

        $prompts[] = '## 任务执行规则';
        $prompts[] = '- 禁止中途停止、静默，必须连续至完成。';
        $prompts[] = '- 每步仅输出{简述}+工具调用，禁其他及结束词(如“已完成”)。';
        $prompts[] = '- 长输出(>4K字符)禁直接回复，须分段保存或分次输出。工具失败重试≤2次，仍失败报用户。';
        $prompts[] = '- 完成标志：全达成后输出“所有任务执行完毕，结果如下：”并附成果。';

        $prompts[] = '## 输出';
        $prompts[] = '- **语言**:' . $lang_name . '(默认中文，可按用户调整)。';
        $prompts[] = '- **错误**:解释原因+建议。';

        $prompts[] = '## 时间';
        $prompts[] = date('Y-m-d H:i:s') . ' 时区:' . $this->app->timezone;

        $prompts[] = '## 自开发';
        $prompts[] = '- **修复**:查日志→定位→分析→建议→用户确认→备份→修复→测试。改代码前**必须备份**，符合模块规范。';

        $system_prompt = [
            'role'    => 'system',
            'content' => implode("\n", $prompts)
        ];

        unset($sandbox_mode, $php_path, $lang_code, $lang_name, $work_path, $prompts);
        return $system_prompt;
    }

    /**
     * Magic getter for modules.
     *
     * @param string $name
     *
     * @return object
     * @throws \Exception
     */
    public function __get(string $name): object
    {
        if (!isset($this->agent_modules[$name])) {
            throw new \Exception('Module NOT found: "' . $name . '"');
        }

        return $this->agent_modules[$name];
    }
}