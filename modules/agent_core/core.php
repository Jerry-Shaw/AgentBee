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

use modules\agent_core\app\config;
use Nervsys\Core\Factory;
use Nervsys\Core\Lib\Error;
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Core\Reflect;
use Nervsys\Core\System;

final class core extends Factory
{
    use System;

    public const PROC_IDX_OPENAI = 0;
    public const PROC_IDX_EXEC   = 1;

    public config    $config;
    public ProcMgr   $procMgr;
    public SocketMgr $socketMgr;

    public string $agent_name = 'AgentBee/蜂小秘';

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
     * @return void
     * @throws \ReflectionException
     */
    public function initCore(): void
    {
        $this->init();

        $this->config    = config::new();
        $this->socketMgr = SocketMgr::new();
        $this->procMgr   = ProcMgr::new('socket');

        $this->agent_config = $this->config->get();
        $this->llm_params   = $this->agent_config['agent_llm']['params'] ?? [];

        if (isset($this->agent_config['agent_memory']['max_history'])) {
            $this->session_history_limit = $this->agent_config['agent_memory']['max_history'];
        }

        if ('' === $this->agent_config['agent_tools']['workspace_path'] || !is_dir($this->agent_config['agent_tools']['workspace_path'])) {
            $this->agent_config['agent_tools']['workspace_path'] = $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
        }

        $this->agent_config['agent_tools']['workspace_path'] ??= $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
    }

    /**
     * Initialize all modules.
     *
     * @return void
     * @throws \ReflectionException
     */
    public function initModules(): void
    {
        foreach ($this->agent_config as $name => $config) {
            if (!isset($config['provider'])) {
                continue;
            }

            $module = '\\modules\\' . $config['provider'] . '\\go';

            try {
                $this->agent_modules[$name] = $module::new();
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, false, false);
                unset($throwable);
            }
        }

        unset($name, $config, $module);
    }

    /**
     * Initialize tools.
     *
     * @return void
     * @throws \ReflectionException
     */
    public function initTools(): void
    {
        if (!isset($this->agent_config['agent_tools']['enabled']) || true !== $this->agent_config['agent_tools']['enabled']) {
            return;
        }

        $agent_tools = [];
        $tool_list   = $this->agent_config['agent_tools']['list'] ?? [];

        foreach ($tool_list as $tool) {
            $tool_class = '\\modules\\' . $tool['name'] . '\\go';
            $tool_meta  = '\\modules\\' . $tool['name'] . '\\tools';

            try {
                $metadata = $tool_meta::META;

                foreach ($metadata as $index => $meta_item) {
                    $metadata[$index]['function']['name'] = $tool['name'] . '/' . $meta_item['function']['name'];
                }

                $this->agent_tools[$tool['name']] = $tool_class::new();

                $agent_tools = array_merge($agent_tools, $metadata);
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, false, false);
                unset($throwable);
            }
        }

        if (!empty($agent_tools)) {
            $this->llm_tools['tools']               = $agent_tools;
            $this->llm_tools['tool_choice']         = 'auto';
            $this->llm_tools['parallel_tool_calls'] = true;
        }

        unset($agent_tools, $tool_list, $tool, $tool_class, $tool_meta, $metadata, $index, $meta_item);
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
     * @return void
     * @throws \Exception
     */
    public function cleanSessionHistory(): void
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
    }

    /**
     * Get system memory prompt.
     *
     * @return array
     * @throws \Exception
     */
    public function getSystemMemory(): array
    {
        $system_default = $this->getSystemDefault($this->agent_config['agent_tools']['in_sandbox'] ?? true);
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
                Error::new()->exceptionHandler($throwable, false, false);
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
     * @param string $path
     *
     * @return string
     */
    public function securePath(string $path): string
    {
        $in_sandbox = $this->agent_config['agent_tools']['in_sandbox'] ?? true;

        $path  = strtr($path, '\\/', DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
        $parts = explode(DIRECTORY_SEPARATOR, $path);

        $parts = array_filter(
            $parts,
            function (string $segment) use ($in_sandbox): bool
            {
                $segment = trim($segment, '.');

                if ('' === $segment) {
                    return false;
                }

                if ($in_sandbox && str_contains($segment, ':')) {
                    return false;
                }

                unset($segment);
                return true;
            }
        );

        $parts = array_values($parts);
        $path  = implode(DIRECTORY_SEPARATOR, $parts);

        if ($in_sandbox && !str_starts_with($path, $this->agent_config['agent_tools']['workspace_path'])) {
            $path = rtrim($this->agent_config['agent_tools']['workspace_path'], '\\/') . DIRECTORY_SEPARATOR . $path;
        }

        unset($in_sandbox, $parts);
        return $path;
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
     * @param bool $in_sandbox
     *
     * @return array
     * @throws \Exception
     */
    public function getSystemDefault(bool $in_sandbox = true): array
    {
        $prompts   = [];
        $php_path  = $this->OSMgr->getPhpPath();
        $lang_code = substr(setlocale(LC_ALL, 0), 0, 2);
        $lang_name = 'zh' === $lang_code ? '中文' : '英文';
        $work_path = $this->agent_config['agent_tools']['workspace_path'];
        $max_limit = $this->agent_config['agent_memory']['max_history'] * 3;

        $prompts[] = '## 系统';
        $prompts[] = '`OS:' . php_uname() . '` | `Agent:' . $this->agent_name . ' v' . AGENT_VERSION . '(' . NS_NAMESPACE . '/' . NS_VER . ')` | `PHP:' . PHP_VERSION . '(' . $php_path . ')`';
        $prompts[] = '`CWD:' . getcwd() . '` | `入口:' . $this->app->script_path . '` | `根:' . $this->app->root_path . '` | `框架:' . NS_ROOT . '` | `模块:' . $this->app->root_path . '/modules/` | `日志:' . $this->app->log_path . '` | `工作区:' . $work_path . '` | `临时:' . sys_get_temp_dir() . '`';

        $prompts[] = '## 记忆(4层)';
        $prompts[] = '- 重要内容主动高频存：每次交互后评估重要性，勿等提醒，宁多勿漏';
        $prompts[] = '- ram:临时存，重启丢，存中间结果，每次工具调用后写，有价值转存 daily/important/system';
        $prompts[] = '- daily:按日期存摘要/要点/结果，每次用户消息后立即存当天(仅存有价值)';
        $prompts[] = '- important:按主题存身份/偏好/事实，重要立刻提炼，冲突先问';
        $prompts[] = '- system:固定人设/规则/边界/偏好，主动从对话提取写入(勿提醒)';
        $prompts[] = '**读**:仅新对话(≤2条)读daily；用户提时间/人/事时可读对应层；其他情况禁自动读。同层不重复。';
        $prompts[] = '**铁律&冲突&优先级**:跨会话禁只存ram，必转存。新旧冲突先问；可删 daily/important/ram，删system需用户同意。优先级 system>important>daily>ram';

        $prompts[] = '## 上下文';
        $prompts[] = '- 历史 >' . $max_limit . '条自动裁剪，**主动管理**。';
        $prompts[] = '**【必须】** ①随时存重要信息(需求/回复/工具结果/决策)到持久记忆(daily/important/system)，临时存ram。②历史过多/连续工具>20次/感觉过长 → **先用记忆工具保存将被删的关键内容(旧结果/早期要点)**，再调清理工具。③清理工具只删旧工具对和普通消息，**不自动存**。建议保留 ≥2工具对+10条消息。';

        $prompts[] = '## 工具';
        $prompts[] = '- 优先专用工具，避免直接系统命令。执行PHP用exec，路径:`' . $php_path . '`';

        $prompts[] = '## 安全';
        if ($in_sandbox) {
            $prompts[] = '- **沙箱开**:所有文件以 `' . $work_path . '` 为根，路径映射相对，**禁止 ../ 或符号链接跳出**。';
        } else {
            $prompts[] = '- **沙箱关**:按绝对路径，优先项目目录，**禁止 ../ 绕开系统关键目录**(如 `C:\Windows\System32`)。';
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

        unset($in_sandbox, $php_path, $lang_code, $lang_name, $work_path, $prompts);
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