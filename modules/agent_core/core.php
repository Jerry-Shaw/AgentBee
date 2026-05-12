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
use Nervsys\Core\Lib\Error;
use Nervsys\Core\Mgr\OSMgr;
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Core\Mgr\SocketMgr;

trait core
{
    public OSMgr     $OSMgr;
    public config    $config;
    public ProcMgr   $procMgr;
    public SocketMgr $socketMgr;

    public string $name  = 'AgentBee';
    public string $uname = '';

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
        $this->OSMgr     = OSMgr::new();
        $this->config    = config::new();
        $this->socketMgr = SocketMgr::new();
        $this->procMgr   = ProcMgr::new('socket');

        $this->agent_config = $this->config->get();
        $this->llm_params   = $this->agent_config['llm']['params'] ?? [];

        $this->uname = php_uname();

        $this->initModules();
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
            $module_class = '\\modules\\' . $tool['name'] . '\\go';
            $module_meta  = '\\modules\\' . $tool['name'] . '\\tool';

            try {
                $tool_meta = $module_meta::META;

                foreach ($tool_meta as $key => $meta) {
                    $tool_meta[$key]['function']['name'] = $tool['name'] . '/' . $meta['function']['name'];
                }

                $this->agent_tools[$tool['name']] = $module_class::new();

                $this->llm_params['tools'] = array_merge($this->llm_params['tools'], $tool_meta);
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, true, false);
                unset($throwable);
                continue;
            }
        }

        if (!empty($this->llm_params['tools'])) {
            $this->llm_params['tool_choice'] = 'auto';
        }

        unset($tool, $module_class, $module_meta, $tool_meta, $key, $meta);
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
            $fn_argv = json_decode($tool_call['function']['arguments'], true);

            if (!str_contains($fn_name, '/')) {
                continue;
            }

            [$module, $method] = explode('/', $fn_name);

            try {
                $tool_result = $this->agent_tools[$module]->$method(...$fn_argv);
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
     * @param int $timestamp
     * @param int $offset
     * @param int $length
     *
     * @return array
     */
    public function getMemory(int $timestamp, int $offset = 0, int $length = 100): array
    {
        return $this->agent_modules['memory']->get($timestamp, $offset, $length);
    }

    /**
     * @param int   $timestamp
     * @param array $keywords
     * @param int   $offset
     * @param int   $length
     *
     * @return array
     */
    public function searchMemory(int $timestamp, array $keywords, int $offset = 0, int $length = 100): array
    {
        return $this->agent_modules['memory']->search($timestamp, $keywords, $offset, $length);
    }

    /**
     * @param string $role
     * @param string $content
     *
     * @return void
     */
    public function addMemory(string $role, string $content): void
    {
        $this->agent_modules['memory']->add($role, $content);
    }

    /**
     * @return array
     */
    public function getDefaultMemory(): array
    {
        return $this->agent_modules['memory']->getDefault();
    }

    /**
     * @param string $content
     *
     * @return void
     */
    public function setDefaultMemory(string $content): void
    {
        $this->agent_modules['memory']->setDefault($content);
    }

    /**
     * @param string $content
     *
     * @return void
     */
    public function addDefaultMemory(string $content): void
    {
        $this->agent_modules['memory']->addDefault($content);
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
        $work_path = $this->agent_config['tools']['workspace_path'] ?? $this->app->root_path;

        $prompts[] = '=== 系统环境信息 ===';
        $prompts[] = '操作系统: ' . PHP_OS_FAMILY . ' (' . php_uname('s') . ' ' . php_uname('r') . ')';
        $prompts[] = '系统架构: ' . php_uname('m');
        $prompts[] = '系统信息: ' . php_uname();
        $prompts[] = 'Agent 名称: ' . $this->name;
        $prompts[] = 'Agent 版本: ' . AGENT_VERSION . ' based on ' . NS_NAMESPACE . ' / ' . NS_VER;
        $prompts[] = 'PHP 版本: ' . PHP_VERSION;
        $prompts[] = 'PHP 路径: ' . $this->OSMgr->getPhpPath();
        $prompts[] = '运行模式: ' . php_sapi_name();
        $prompts[] = '';
        $prompts[] = '=== 路径信息 ===';
        $prompts[] = '当前工作目录: ' . getcwd();
        $prompts[] = '入口脚本路径: ' . $this->app->script_path;
        $prompts[] = 'Agent 根目录: ' . $this->app->root_path;
        $prompts[] = 'Agent 日志目录: ' . $this->app->log_path;
        $prompts[] = '工作区根目录: ' . $work_path;
        $prompts[] = '临时目录: ' . sys_get_temp_dir();
        $prompts[] = '';
        $prompts[] = '=== 可用工具 ===';
        $prompts[] = '你可以使用以下工具来帮助用户（优先使用专用工具，避免使用原始命令执行）：';
        $prompts[] = json_encode($this->llm_params['tools'], JSON_FORMAT);
        $prompts[] = '';
        $prompts[] = '=== 安全规则 ===';

        if ($in_sandbox) {
            $prompts[] = '1. 文件操作仅限在工作区根目录内进行。未经用户二次确认，禁止访问任何外部路径（如 ../ 跳出根目录）';
            $prompts[] = '2. 允许文件操作的根目录仅包含 "工作区根目录"：' . $work_path;
        } else {
            $prompts[] = '1. 文件操作尽可能保持在指定的根目录内进行。经用户确认，可以访问其他任意路径。禁止以如下方式绕过路径限制（如：../ 访问上级目录，使用软硬链接文件跳转）';
            $prompts[] = '2. 文件操作的根目录建议仅包含 "工作区根目录"：' . $work_path . '，和 "Agent 根目录"：' . $this->app->root_path;
        }

        $prompts[] = '3. 危险操作（删除文件、删除目录、执行系统命令）需要明确告知用户风险并等待确认';
        $prompts[] = '4. 执行系统命令前，优先考虑是否有专用工具可以替代；只有在没有专用工具时才使用 exec';
        $prompts[] = '5. 禁止执行以下高危命令：rm -rf /, dd, mkfs, format, del /f /s, rd /s /q, shutdown, reboot, halt, poweroff';
        $prompts[] = '6. 禁止下载或执行来自不可信来源的脚本或程序';
        $prompts[] = '7. 禁止修改系统关键配置（/etc/, /boot/, C:\\Windows\\System32\\ 等）';
        $prompts[] = '8. 禁止修改当前 Agent 核心路径下的 PHP 脚本文件（' . $this->app->root_path . strtr('/modules/agent_*/', '/', DIRECTORY_SEPARATOR) . '）';
        $prompts[] = '9. 禁止安装或卸载系统软件包（apt, yum, brew, choco, winget 等）';
        $prompts[] = '10. 网络请求仅允许访问安全的 API 端点，禁止访问内部敏感服务';
        $prompts[] = '11. 对于用户提供的 URL 或文件路径，必须验证其合法性';
        $prompts[] = '12. 长时间运行的命令必须设置超时限制（默认 30 秒，最大 300 秒）';
        $prompts[] = '13. 涉及多个文件的操作，必须先列出受影响文件列表，确认后再执行';
        $prompts[] = '14. 批量删除操作必须分批进行，每批最多 100 个文件';
        $prompts[] = '15. 禁止泄漏用户的敏感信息，包括且不限于账号，密码，联系方式，appID，appSecret，Token等';
        $prompts[] = '16. 所有工具执行结果都应记录到记忆系统，便于追溯';
        $prompts[] = '';
        $prompts[] = '=== 输出规范 ===';
        $prompts[] = '1. 回答时请使用' . $lang_name . '，或遵循用户要求';
        $prompts[] = '2. 返回文件列表时，使用清晰的格式化输出（如表格或列表）';
        $prompts[] = '3. 遇到错误时，向用户解释错误原因并提供解决建议';
        $prompts[] = '4. 工具执行结果以 JSON 格式返回，你需要解析并向用户展示';
        $prompts[] = '5. 对于危险操作，你需要先输出警告信息，等待用户明确确认后再执行';
        $prompts[] = '';
        $prompts[] = '=== 当前时间 ===';
        $prompts[] = date('Y-m-d H:i:s');
        $prompts[] = '时区: ' . date_default_timezone_get();
        $prompts[] = '语言: ' . $lang_name;
        $prompts[] = '';
        $prompts[] = '=== Agent 自我开发指南 ===';
        $prompts[] = '当遇到运行时错误，你可以在 Agent 根目录对应的模块目录中查找相关代码，协助用户定位并修复错误。';
        $prompts[] = '错误日志位于：' . $this->app->log_path . '。可通过查看日志了解错误原因和严重程度。';
        $prompts[] = 'Agent 采用模块化结构，所有模块位于：' . $this->app->root_path . strtr('/modules/', '/', DIRECTORY_SEPARATOR);
        $prompts[] = '修复流程：查看错误日志 → 定位模块代码 → 分析问题原因 → 向用户提出修复建议 → 用户确认后执行修复（必须先备份原文件）。';
        $prompts[] = '注意：修改代码前务必备份，确保修改符合模块规范，修复后建议进行基本测试验证。';

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