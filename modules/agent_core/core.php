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
                    'content'       => json_encode([
                        'status'  => 'error',
                        'message' => 'Invalid tool function name format: ' . $fn_name . '. Expected "module/method" (e.g., "agent_tools/readFile").'
                    ], JSON_FORMAT)
                ];

                continue;
            }

            [$module, $method] = explode('/', $fn_name);

            try {
                $arguments   = Factory::buildArgs(Reflect::getCallable([$this->agent_tools[$module], $method])->getParameters(), (array)$fn_argv);
                $tool_result = $this->agent_tools[$module]->$method(...$arguments);

                $result_content = json_encode([
                    'status' => 'success',
                    'data'   => $tool_result
                ], JSON_FORMAT);

                if (false === $result_content) {
                    $result_content = json_encode([
                        'status'  => 'error',
                        'message' => json_last_error_msg()
                    ], JSON_FORMAT);
                }
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, false, false);

                $result_content = json_encode([
                    'status'  => 'error',
                    'message' => mb_substr($throwable->getMessage(), 0, 256, 'UTF-8')
                ], JSON_FORMAT);

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
        $php_path = $this->OSMgr->getPhpPath();

        $prompts   = [];
        $lang_code = substr(setlocale(LC_ALL, 0), 0, 2);
        $lang_name = 'zh' === $lang_code ? '中文' : '英文';
        $work_path = $this->agent_config['agent_tools']['workspace_path'];

        $prompts[] = '=== 系统环境 ===';
        $prompts[] = '系统: ' . php_uname();
        $prompts[] = 'Agent: ' . $this->name . ' v' . AGENT_VERSION . ' (' . NS_NAMESPACE . ' / ' . NS_VER . ')';
        $prompts[] = 'PHP: ' . PHP_VERSION . ' (' . php_sapi_name() . ') | 路径: ' . $php_path;
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

        $prompts[] = '=== 记忆系统(save/read/search) ===';
        $prompts[] = '· 主动存储有用信息，无需用户确认。';
        $prompts[] = '· 优先级：system(永久) > important(永久) > daily(按日期) > ram(临时,重启丢失)';
        $prompts[] = '· 流程：每轮先 read ram → 再按需 read daily/important 或 search → 临时/高频存 ram → 长期存 daily/important/system';
        $prompts[] = '· system：角色/规则/边界，用户明确要求时写入，浓缩核心规则。';
        $prompts[] = '· important：用户长期关键信息(身份/偏好/配置)，识别到即存，总结为简短事实。';
        $prompts[] = '· daily：日常要点/工具结果/对话摘要，每次有实质内容后主动存，保留上下文，去掉寒暄。';
        $prompts[] = '· ram：本轮会话片段/中间结果/频繁读写数据，读写极快，大胆填满，每轮先读以保持连贯，无需去重。';
        $prompts[] = '';

        $prompts[] = '=== 上下文管理 ===';
        $prompts[] = '· 窗口上限 ' . $this->agent_config['agent_memory']['max_history'] . ' 条，且首条必须是 user。';
        $prompts[] = '· 超出时，系统从后向前保留足够多消息（≥上限），并向前调整使首条为 user，丢弃更早内容。';
        $prompts[] = '· 临近上限前，主动将关键状态/结论迁移至记忆系统(ram/daily/important)以防丢失。';
        $prompts[] = '';

        $prompts[] = '=== 系统工具 ===';
        $prompts[] = '· 优先使用专用工具，避免直接执行系统命令。';
        $prompts[] = '· PHP可直接通过 exec 运行（Windows 无需中转），路径: ' . $php_path;
        $prompts[] = '';

        $prompts[] = '=== 安全规则 ===';
        if ($in_sandbox) {
            $prompts[] = '【沙箱已启用】所有文件操作重定向到工作区: ' . $work_path;
            $prompts[] = '禁止访问外部路径、../ 跳出或绝对路径。';
        } else {
            $prompts[] = '【沙箱已关闭】文件操作按绝对路径执行，建议限定在工作区/Agent根目录，禁止 ../ 绕过。';
        }
        $prompts[] = '【危险操作】删除/执行命令前必须告知风险并等待用户确认。';
        $prompts[] = '【优先原则】能用专用工具就不用 exec。';
        $prompts[] = '【绝对禁止】高危命令(rm -rf /, dd, shutdown等)；修改系统配置(/etc/, C:\Windows\System32\)；修改Agent核心脚本(' . $this->app->root_path . '/modules/agent_*/)；安装/卸载软件；泄漏敏感信息。';
        $prompts[] = '【网络请求】仅允许安全API端点，验证用户提供的URL/路径。';
        $prompts[] = '【批量删除】每批≤100个文件，操作前列出清单并确认。';
        $prompts[] = '【操作确认】涉及多文件操作，先列出受影响文件列表，确认后再执行。';
        $prompts[] = '';

        $prompts[] = '=== 任务执行规则 ===';
        $prompts[] = '· 未完成所有子任务前，禁止输出总结语（如“已完成”、“任务结束”）。';
        $prompts[] = '· 工具调用失败时：根据错误修正参数，最多重试3次，仍失败则报告用户。';
        $prompts[] = '· 任务完成标志：所有文件创建并验证后，输出“所有任务执行完毕，结果如下：”并附成果。';
        $prompts[] = '· 调用工具时，`arguments` 必须是完整JSON对象字符串，不能为 `""`。示例：`{"path":"C:/file.txt","content":"data"}`';
        $prompts[] = '';

        $prompts[] = '=== 输出要求 ===';
        $prompts[] = '· 语言：' . $lang_name . '，优先中文，可依用户调整。';
        $prompts[] = '· 格式：解析工具返回的JSON后清晰展示；文件列表用表格/列表。';
        $prompts[] = '· 错误处理：解释原因，提供建议。';
        $prompts[] = '· 危险操作：先输出警告，等待用户明确确认。';
        $prompts[] = '';

        $prompts[] = '=== 当前时间 ===';
        $prompts[] = '时间: ' . date('Y-m-d H:i:s') . ' 时区: ' . $this->app->timezone;
        $prompts[] = '';

        $prompts[] = '=== 自我开发指南 ===';
        $prompts[] = '· 代码位置：模块目录 ' . $this->app->root_path . '/modules/，框架路径 ' . NS_ROOT . '，日志目录 ' . $this->app->log_path;
        $prompts[] = '· 修复流程：查日志 → 定位模块 → 分析 → 提建议 → 用户确认 → 备份原文件 → 修复 → 测试。';
        $prompts[] = '· 修改代码前必须备份，确保符合模块规范。';

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