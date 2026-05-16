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

    public config    $config;
    public ProcMgr   $procMgr;
    public SocketMgr $socketMgr;

    public string $name = 'AgentBee';

    public array $llm_tools  = [];
    public array $llm_params = [];

    public array $agent_tools   = [];
    public array $agent_config  = [];
    public array $agent_modules = [];

    public array $session_history       = [];
    public int   $session_history_limit = 20;

    /**
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

        $this->agent_config['agent_tools']['workspace_path'] ??= $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
    }

    /**
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
                continue;
            }
        }

        unset($name, $config, $module);
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function initTools(): void
    {
        if (!isset($this->agent_config['agent_tools']) || !isset($this->agent_config['agent_tools']['enabled'])) {
            return;
        }

        if (true !== $this->agent_config['agent_tools']['enabled']) {
            return;
        }

        $agent_tools = [];

        $this->agent_config['agent_tools']['list'] ??= [];

        foreach ($this->agent_config['agent_tools']['list'] as $tool) {
            $tool_class = '\\modules\\' . $tool['name'] . '\\go';
            $tool_meta  = '\\modules\\' . $tool['name'] . '\\tools';

            try {
                $metadata = $tool_meta::META;

                foreach ($metadata as $key => $meta) {
                    $metadata[$key]['function']['name'] = $tool['name'] . '/' . $meta['function']['name'];
                }

                $this->agent_tools[$tool['name']] = $tool_class::new();

                $agent_tools = array_merge($agent_tools, $metadata);
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, false, false);
                unset($throwable);
                continue;
            }
        }

        if (!empty($agent_tools)) {
            $this->llm_tools['tools']       = $agent_tools;
            $this->llm_tools['tool_choice'] = 'auto';
        }

        unset($agent_tools, $tool, $tool_class, $tool_meta, $metadata, $key, $meta);
    }

    /**
     * @param array $content
     *
     * @return void
     * @throws \Exception
     */
    public function addSessionHistory(array $content): void
    {
        if (empty($this->session_history)) {
            $this->session_history[] = $this->getSystemMemory();
        }

        $this->session_history[] = $content;

        $message_count = count($this->session_history);

        if ($message_count > $this->session_history_limit) {
            $role_list = array_column($this->session_history, 'role');
            $user_keys = array_keys($role_list, 'user');

            $target_id = 0;
            $min_diff  = INF;
            $drop      = $message_count - $this->session_history_limit;

            foreach ($user_keys as $id) {
                $diff = abs($drop - $id);
                if ($diff < $min_diff) {
                    $min_diff  = $diff;
                    $target_id = $id;
                } elseif ($diff === $min_diff && $id < $target_id) {
                    $target_id = $id;
                }
            }

            if ($target_id > 1) {
                array_unshift($this->session_history, $this->getSystemMemory());
                array_splice($this->session_history, 1, $target_id);
            }

            unset($role_list, $user_keys, $target_id, $min_diff, $drop, $id, $diff);
        }

        unset($content, $message_count);
    }

    /**
     * @return array
     */
    public function getSessionHistory(): array
    {
        return $this->session_history;
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getSystemMemory(): array
    {
        $system_default = $this->getSystemDefault($this->agent_config['agent_tools']['in_sandbox'] ?? true);
        $system_memory  = $this->agent_modules['agent_memory']->read('system', 0, 0);

        if (!empty($system_memory['messages'])) {
            $memory = ["\n", '=== 重要个性设定 ==='];

            foreach ($system_memory['messages'] as $message) {
                $memory[] = $message['content'];
            }

            $system_default['content'] .= implode("\n", $memory);
        }

        unset($system_memory, $memory);
        return $system_default;
    }

    /**
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
            $fn_argv = json_decode($tool_call['function']['arguments'], true) ?? [];

            if (!str_contains($fn_name, '/')) {
                $results[] = [
                    'tool_call_id'  => $tool_call['id'],
                    'function_name' => $fn_name,
                    'content'       => 'Invalid tool function name format: ' . $fn_name . '. Expected "module/method" (e.g., "agent_tools/readFile").'
                ];

                continue;
            }

            [$module, $method] = explode('/', $fn_name);

            try {
                $arguments      = Factory::buildArgs(Reflect::getCallable([$this->agent_tools[$module], $method])->getParameters(), (array)$fn_argv);
                $tool_result    = $this->agent_tools[$module]->$method(...$arguments);
                $result_content = json_encode($tool_result, JSON_FORMAT);

                if (false === $result_content) {
                    $result_content = json_last_error_msg();
                }
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, false, false);
                $result_content = $throwable->getMessage();
                unset($throwable);
            }

            $llm_result = [
                'tool_call_id'  => $tool_call['id'],
                'function_name' => $fn_name,
                'content'       => $result_content
            ];

            $results[] = $llm_result;
        }

        unset($tool_calls, $tool_call, $fn_name, $fn_argv, $module, $method, $arguments, $tool_result, $result_content, $llm_result);
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

        if ($in_sandbox) {
            $drive = current($parts);

            if (str_contains($drive, ':')) {
                array_shift($parts);
            }

            array_unshift($parts, rtrim($this->agent_config['agent_tools']['workspace_path'], '\\/'));
        }

        $parts = array_values($parts);
        $path  = implode(DIRECTORY_SEPARATOR, $parts);

        unset($in_sandbox, $parts);
        return $path;
    }

    /**
     * @param string $name
     *
     * @return object|null
     */
    public function getModule(string $name): object|null
    {
        return $this->agent_modules[$name] ?? null;
    }

    /**
     * @return array
     */
    public function getLLMParams(): array
    {
        return $this->llm_params + $this->llm_tools;
    }

    /**
     * @param string $socket_id
     * @param string $message
     *
     * @return void
     * @throws \ReflectionException
     */
    public function sendMessage(string $socket_id, string $message): void
    {
        $this->socketMgr->sendMessage($socket_id, $this->socketMgr->wsEncode($message));
    }

    /**
     * @param bool $in_sandbox
     *
     * @return array
     * @throws \Exception
     */
    public function getSystemDefault(bool $in_sandbox = true): array
    {
        $prompts   = [];
        $lang_code = substr(setlocale(LC_ALL, 0), 0, 2);
        $lang_name = 'zh' === $lang_code ? '中文' : '英文';
        $work_path = $this->agent_config['agent_tools']['workspace_path'];

        $prompts[] = '=== 系统环境 ===';
        $prompts[] = '系统: ' . php_uname();
        $prompts[] = 'Agent: ' . $this->name . ' v' . AGENT_VERSION . ' (' . NS_NAMESPACE . ' / ' . NS_VER . ')';
        $prompts[] = 'PHP: ' . PHP_VERSION . ' (' . php_sapi_name() . ') | 路径: ' . $this->OSMgr->getPhpPath();
        $prompts[] = '';

        $prompts[] = '=== 关键路径 ===';
        $prompts[] = '当前目录: ' . getcwd();
        $prompts[] = '入口脚本: ' . $this->app->script_path;
        $prompts[] = 'Agent根目录: ' . $this->app->root_path;
        $prompts[] = '框架路径: ' . NS_ROOT;
        $prompts[] = '模块目录: ' . $this->app->root_path . '/modules/';
        $prompts[] = '日志目录: ' . $this->app->log_path;
        $prompts[] = '工作区目录: ' . $work_path;
        $prompts[] = '临时目录: ' . sys_get_temp_dir();
        $prompts[] = '';

        $prompts[] = '=== 记忆系统（save/read/search）===';
        $prompts[] = '自主决定存储，用户不干预。';
        $prompts[] = '';
        $prompts[] = '【流程】';
        $prompts[] = '1. system层自动附加到每轮对话头部，无需你读取。';
        $prompts[] = '2. 按需 read important 或 search 历史。';
        $prompts[] = '3. 有价值信息立即 save，尤其是频繁存取的临时信息用 ram 层。';
        $prompts[] = '4. 对话后总结存入 daily 或 important。';
        $prompts[] = '';
        $prompts[] = '【system】永久';
        $prompts[] = '· 角色/风格/规则/能力边界。';
        $prompts[] = '· 示例：“你是技术助手，优先给代码示例”。';
        $prompts[] = '· 写入：用户明确表达对你的期望时。';
        $prompts[] = '· 必须浓缩为核心规则。';
        $prompts[] = '';
        $prompts[] = '【important】永久';
        $prompts[] = '· 用户长期关键信息（身份/配置/偏好）。';
        $prompts[] = '· 写入：识别到值得长期记住的事实时。';
        $prompts[] = '· 将多轮对话总结为简短事实。';
        $prompts[] = '';
        $prompts[] = '【daily】按日期分文件';
        $prompts[] = '· 日常要点 + 工具结果。';
        $prompts[] = '· 写入：每次重要交互后。';
        $prompts[] = '· 必须浓缩：丢弃“你好/谢谢”、报错等无用内容。';
        $prompts[] = '';
        $prompts[] = '【ram】高速临时，不持久化（优先使用）';
        $prompts[] = '· 用途：本轮或近期会话的上下文片段、中间结果、频繁存取的临时信息。';
        $prompts[] = '· 特点：读写极快，进程/重启后完全丢失，仅限当前会话有效。';
        $prompts[] = '· 写入：大胆存，不必担心性能或冗余，尽量保持对话连续。但注意重启后清空。';
        $prompts[] = '· 读取：每轮对话先 read ram 取回最近内容，辅助保持上下文连贯。';
        $prompts[] = '· 去重：ram 层无需 search 去重，直接用 save 追加或覆盖即可。';
        $prompts[] = '';
        $prompts[] = '【原则】';
        $prompts[] = '· 自主写入，无需确认。';
        $prompts[] = '· 写入前用 search 去重（ram 层除外）。';
        $prompts[] = '· 每轮：先 read ram（连贯性），再按需 read important / search daily。';
        $prompts[] = '· 浓缩/丢弃无意义内容，提升效率。';
        $prompts[] = '· 定期整理（合并/去重/重写）更佳。';
        $prompts[] = '';

        $prompts[] = '=== 上下文管理 ===';
        $prompts[] = '· 上下文窗口上限为 ' . $this->agent_config['agent_memory']['max_history'] . ' 条，且必须以 user 消息开始。';
        $prompts[] = '· 截断机制：超出上限时，系统将从后往前保留足够数量的消息（确保数量大于或等于上限数），并进一步向前追溯至首条为 user 为止，其余更早的内容将被永久丢弃。';
        $prompts[] = '· 记忆迁移触发：在接近窗口上限前，必须主动将当前会话的关键状态、中间结论和重要事实总结并迁移至 memory 系统（临时 -> ram, 日常 -> daily, 长期 -> important），以对抗截断导致的信息丢失。';
        $prompts[] = '';

        $prompts[] = '=== 系统工具 ===';
        $prompts[] = '优先使用专用工具，避免直接执行系统命令。';
        $prompts[] = '';

        $prompts[] = '=== 安全规则 ===';
        if ($in_sandbox) {
            $prompts[] = '【沙箱已启用】所有文件操作都会被重定向到工作区目录内：' . $work_path;
            $prompts[] = '禁止：访问工作区外路径、使用 ../ 跳出、使用绝对路径。所有路径都会被截断并重定向到工作区目录内。';
        } else {
            $prompts[] = '【沙箱已关闭】文件操作按传入的绝对路径执行。';
            $prompts[] = '建议：操作限定在工作区或Agent根目录内，禁止使用 ../ 绕过限制。';
        }
        $prompts[] = '【危险操作】删除文件/目录、执行命令前，必须告知风险并等待用户确认。';
        $prompts[] = '【优先原则】能用专用工具就不执行系统命令，exec仅作为最后手段。';
        $prompts[] = '【绝对禁止】高危命令（rm -rf /、dd、shutdown等）；修改系统配置（/etc/、C:\Windows\System32\）；修改Agent核心脚本（' . $this->app->root_path . '/modules/agent_*/）；安装/卸载软件；泄漏敏感信息。';
        $prompts[] = '【网络请求】仅允许安全API端点，必须验证用户提供的URL和文件路径。';
        $prompts[] = '【批量删除】每批不超过100个文件，操作前先列出文件清单并确认。';
        $prompts[] = '【操作确认】涉及多个文件的操作，先列出受影响文件列表，确认后再执行。';
        $prompts[] = '';

        $prompts[] = '=== 任务执行规则 ===';
        $prompts[] = '· 持续执行，直到所有子任务完成：';
        $prompts[] = '  - 若有未完成的子任务（读取文件、编写代码、测试、创建模块等），必须继续调用工具。';
        $prompts[] = '  - 禁止提前输出任何形式的总结性话语，如：“已完成”、“信息已收集”、“任务结束”、“可以开始编写了”等总结语。';
        $prompts[] = '';
        $prompts[] = '· 工具调用失败时：';
        $prompts[] = '  - 根据错误信息修正参数（如补充缺失字段、改正类型）。';
        $prompts[] = '  - 同一工具最多重试 3 次。3 次后仍失败则报告用户。';
        $prompts[] = '';
        $prompts[] = '· 任务完成的标志：';
        $prompts[] = '  - 所有要求的文件都已创建且验证通过后，输出：“所有任务执行完毕，结果如下：”并附上成果。';
        $prompts[] = '  - 未输出此标志前，继续工作。';
        $prompts[] = '';
        $prompts[] = '· 调用工具时，`arguments` 必须是**完整的 JSON 对象字符串**，绝不能为空字符串 `""`。';
        $prompts[] = '  例如：`{"path":"C:/file.txt","content":"data"}`。空参数会导致工具报错并中断任务。';
        $prompts[] = '';

        $prompts[] = '=== 输出要求 ===';
        $prompts[] = '语言：用户系统语言为 ' . $lang_name . '，优先使用中文回答。也可根据用户要求调整。';
        $prompts[] = '格式：工具返回JSON需解析后清晰展示；文件列表使用表格或列表。';
        $prompts[] = '错误处理：解释错误原因，提供解决建议。';
        $prompts[] = '危险操作：先输出警告，等待用户明确确认后再执行。';
        $prompts[] = '';

        $prompts[] = '=== 当前时间 ===';
        $prompts[] = '时间: ' . date('Y-m-d H:i:s') . ' 时区: ' . date_default_timezone_get();
        $prompts[] = '';

        $prompts[] = '=== 自我开发指南 ===';
        $prompts[] = '【代码位置】模块目录：' . $this->app->root_path . '/modules/，框架路径：' . NS_ROOT . '，日志目录：' . $this->app->log_path;
        $prompts[] = '【修复流程】查日志 → 定位模块 → 分析原因 → 提建议 → 用户确认 → 备份原文件 → 修复 → 测试。';
        $prompts[] = '【注意事项】修改代码前必须备份，确保符合模块规范。';

        $system_prompt = [
            'role'    => 'system',
            'content' => implode("\n", $prompts)
        ];

        unset($in_sandbox, $prompts, $lang_code, $lang_name, $work_path);
        return $system_prompt;
    }

    /**
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