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
use Nervsys\Core\Lib\App;
use Nervsys\Core\Lib\Error;
use Nervsys\Core\Mgr\OSMgr;
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Core\Mgr\SocketMgr;
use Nervsys\Core\Reflect;

trait core
{
    public App       $app;
    public OSMgr     $OSMgr;
    public config    $config;
    public ProcMgr   $procMgr;
    public SocketMgr $socketMgr;

    public string $name = 'AgentBee';

    public array $llm_params = [];

    public array $agent_tools   = [];
    public array $agent_config  = [];
    public array $agent_modules = [];

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function initCore(): void
    {
        $this->app       = App::new();
        $this->OSMgr     = OSMgr::new();
        $this->config    = config::new();
        $this->socketMgr = SocketMgr::new();
        $this->procMgr   = ProcMgr::new('socket');

        $this->agent_config = $this->config->get();
        $this->llm_params   = $this->agent_config['llm']['params'] ?? [];

        $this->agent_config['tools']['workspace_path'] ??= $this->app->root_path . DIRECTORY_SEPARATOR . 'workspace';
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function initModules(): void
    {
        $called_class  = get_class($this);
        $pos_start     = strpos($called_class, '\\') + 1;
        $pos_end       = strpos($called_class, '\\', $pos_start);
        $called_module = substr($called_class, $pos_start, $pos_end - $pos_start);

        foreach ($this->agent_config as $name => $config) {
            if (!isset($config['provider'])) {
                continue;
            }

            if ($called_module !== $config['provider']) {
                $module = '\\modules\\' . $config['provider'] . '\\go';

                try {
                    $this->agent_modules[$name] = $module::new();
                } catch (\Throwable $throwable) {
                    Error::new()->exceptionHandler($throwable, true, false);
                    unset($throwable);
                    continue;
                }
            }
        }

        unset($called_class, $pos_start, $pos_end, $called_module, $name, $config, $module, $object);
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function initTools(): void
    {
        if (!isset($this->agent_config['tools']) || !isset($this->agent_config['tools']['enabled'])) {
            return;
        }

        if (true !== $this->agent_config['tools']['enabled']) {
            return;
        }

        $this->llm_params['tools']           ??= [];
        $this->agent_config['tools']['list'] ??= [];

        foreach ($this->agent_config['tools']['list'] as $tool) {
            $tool_class = '\\modules\\' . $tool['name'] . '\\go';
            $tool_meta  = '\\modules\\' . $tool['name'] . '\\tools';

            try {
                $metadata = $tool_meta::META;

                foreach ($metadata as $key => $meta) {
                    $metadata[$key]['function']['name'] = $tool['name'] . '/' . $meta['function']['name'];
                }

                $this->agent_tools[$tool['name']] = $tool_class::new();

                $this->llm_params['tools'] = array_merge($this->llm_params['tools'], $metadata);
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, true, false);
                unset($throwable);
                continue;
            }
        }

        if (!empty($this->llm_params['tools'])) {
            $this->llm_params['tool_choice'] = 'auto';
        }

        unset($tool, $tool_class, $tool_meta, $metadata, $key, $meta);
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
                continue;
            }

            [$module, $method] = explode('/', $fn_name);

            try {
                $arguments   = Factory::buildArgs(Reflect::getCallable([$this->agent_tools[$module], $method])->getParameters(), $fn_argv);
                $tool_result = $this->agent_tools[$module]->$method(...$arguments);
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, true, false);
                $tool_result = $throwable->getMessage();
                unset($throwable);
            }

            $llm_result = [
                'tool_call_id'  => $tool_call['id'],
                'function_name' => $fn_name,
                'result'        => json_encode($tool_result, JSON_FORMAT)
            ];

            $results[] = $llm_result;
        }

        unset($tool_calls, $tool_call, $fn_name, $fn_argv, $module, $method, $tool_result, $llm_result);
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
        $in_sandbox = $this->agent_config['tools']['in_sandbox'] ?? true;

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

            array_unshift($parts, rtrim($this->agent_config['tools']['workspace_path'], '\\/'));
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
        return $this->llm_params;
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
    public function getSystemPrompt(bool $in_sandbox = true): array
    {
        $prompts   = [];
        $lang_code = substr(setlocale(LC_ALL, 0), 0, 2);
        $lang_name = 'zh' === $lang_code ? '中文' : '英文';
        $work_path = $this->agent_config['tools']['workspace_path'];

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

        $prompts[] = '=== 记忆系统 ===';
        $prompts[] = '你有三个记忆工具（save/read/search），全部由你自主决定存储内容，用户不干预。';
        $prompts[] = '';
        $prompts[] = '【核心流程】';
        $prompts[] = '1. 系统自动将【system层】全部记忆附加到每轮对话头部，无需你读取。';
        $prompts[] = '2. 可按需读取【important层】获取深度信息，根据问题复杂度可调用 search 查找相关历史。';
        $prompts[] = '3. 对话中判断有价值信息，调用 save 写入。';
        $prompts[] = '4. 对话后可总结要点存入 daily 或 important。';
        $prompts[] = '';
        $prompts[] = '【系统记忆 system】永久保存';
        $prompts[] = '- 用途：角色设定、行为风格、能力边界、全局规则。';
        $prompts[] = '- 内容示例：“你是技术助手，优先给代码示例”、“禁止回答政治问题”。';
        $prompts[] = '- 写入时机：用户明确表达对你的期望或要求时。';
        $prompts[] = '- 必须提炼浓缩，只留核心规则，避免冗长。';
        $prompts[] = '';
        $prompts[] = '【重要记忆 important】永久保存';
        $prompts[] = '- 用途：用户相关长期关键信息，避免重复询问。';
        $prompts[] = '- 内容示例：用户身份、项目配置、代码规范、常用命令、技术偏好。';
        $prompts[] = '- 写入时机：识别到值得长期记住的信息时主动存入。';
        $prompts[] = '- 必须提炼浓缩，将多轮对话总结为简短事实。';
        $prompts[] = '';
        $prompts[] = '【实时记忆 daily】按日期分文件';
        $prompts[] = '- 用途：记录日常对话要点和工具调用结果。';
        $prompts[] = '- 内容示例：用户问题浓缩、回复要点、关键工具输入输出。';
        $prompts[] = '- 写入时机：每次重要交互后写入有价值记录。';
        $prompts[] = '- 必须提炼浓缩：不保存“你好/谢谢”等无意义对话，不保存报错等无用结果。';
        $prompts[] = '';
        $prompts[] = '【操作原则】';
        $prompts[] = '- 三类记忆均由你自主判断写入，无需用户确认。';
        $prompts[] = '- 写入前先用 search 检查是否已存在类似内容，避免重复。';
        $prompts[] = '- 每轮对话开始，按需 read important；根据问题 search daily。';
        $prompts[] = '- 浓缩记忆、丢弃无意义内容非常重要，能减少文件大小、提高检索效率。';
        $prompts[] = '- 定期整理记忆（合并/去重/重写）可进一步提升效率。';
        $prompts[] = '';

        $prompts[] = '=== 系统工具 ===';
        $prompts[] = '优先使用专用工具，避免直接执行系统命令。';
        $prompts[] = '';

        $prompts[] = '=== 安全规则 ===';
        if ($in_sandbox) {
            $prompts[] = '【沙箱已启用】所有文件操作限定在工作区目录内：' . $work_path;
            $prompts[] = '禁止：访问工作区外路径、使用 ../ 跳出、使用绝对路径。';
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