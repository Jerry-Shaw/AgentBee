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
            $this->llm_tools['tools']       = $agent_tools;
            $this->llm_tools['tool_choice'] = 'auto';
        }

        unset($agent_tools, $tool_list, $tool, $tool_class, $tool_meta, $metadata, $index, $meta_item);
    }

    /**
     * Add a message to session history.
     *
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
            $drop_num  = $message_count - $this->session_history_limit;

            foreach ($user_keys as $id) {
                $diff = abs($drop_num - $id);
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

            unset($role_list, $user_keys, $target_id, $min_diff, $drop_num, $id, $diff);
        }

        unset($content, $message_count);
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
            $memory = ["\n", '=== 重要个性设定 ==='];

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

        $prompts[] = '=== 系统环境 ===';
        $prompts[] = '系统: ' . php_uname();
        $prompts[] = 'Agent: ' . $this->agent_name . ' v' . AGENT_VERSION . ' (' . NS_NAMESPACE . ' / ' . NS_VER . ')';
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

        $prompts[] = '=== 记忆系统（四层存储） ===';
        $prompts[] = '';
        $prompts[] = '【存储说明】';
        $prompts[] = '- ram：临时存储，重启即丢。用来存放本轮会话的中间结果和临时内容，避免上下文截断时丢失信息。需要你主动写入。其中有价值的内容后续可以转存到 daily 或 important。';
        $prompts[] = '- daily：长期持久，按日期组织。存放对话摘要、日常要点、任务结果。发现值得记录的内容就立刻存，不用等对话结束。';
        $prompts[] = '- important：长期持久，按主题组织。存放用户身份、长期偏好、核心事实、重要关系。发现重要内容立刻存储，适当总结浓缩，但保留可读性。';
        $prompts[] = '- system：长期持久，固定字段。存放人设、行为规则、边界、指令偏好。只有用户明确要求修改时才写入。';
        $prompts[] = '';
        $prompts[] = '【读取规则】只有上下文缺少答案时才读记忆。上下文很短时：先读 daily（近期日期）→ important → system。ram 只适合在对话中临时存取，启动时不要读。';
        $prompts[] = '';
        $prompts[] = '【写入规则】';
        $prompts[] = '- ram：主动写入临时数据。如果其中有需要长期保留的内容，及时转存到 daily 或 important。';
        $prompts[] = '- daily：对话中遇到有价值的信息（要点、结果、用户临时偏好等），立即存入当天日期。';
        $prompts[] = '- important：发现长期事实时主动提炼存入。如果和已有内容冲突，先问用户。';
        $prompts[] = '- system：只有用户明确要求修改人设或规则时才写入。';
        $prompts[] = '';
        $prompts[] = '【持久化铁律】需要跨会话保留的内容，禁止只存 ram，必须转存到 daily、important 或 system。';
        $prompts[] = '';
        $prompts[] = '【冲突与删除】新信息和已有记忆冲突时，先问用户。可以按需删除 daily、important、ram；删除 system 需要用户明确同意。';
        $prompts[] = '';
        $prompts[] = '【优先级】system > important > daily > ram';

        $prompts[] = '=== 上下文管理 ===';
        $prompts[] = '- 窗口上限' . $this->agent_config['agent_memory']['max_history'] . '条。超出后系统会强制裁剪（保留最后' . $this->agent_config['agent_memory']['max_history'] . '条至user）。';
        $prompts[] = '- 【必须执行】';
        $prompts[] = '  1. 发现重要信息，随时主动保存到持久记忆(daily/important/system)；';
        $prompts[] = '  2. 接近上限时，将关键内容（用户消息、助手回复、工具结果）总结存入持久记忆，临时上下文可存入ram记忆；';
        $prompts[] = '  3. 连续工具调用>20次或历史过长时，调用清理工具：调用前总结所有关键结果，工具会删除历史工具调用对，并将总结存入daily。';
        $prompts[] = '- 自动裁剪按消息条数（不可控制），清理工具按工具调用对（需主动调用）。';
        $prompts[] = '';

        $prompts[] = '=== 系统工具 ===';
        $prompts[] = '- 优先用专用工具，避免直接执行系统命令。';
        $prompts[] = '- 执行PHP：直接用exec工具调用PHP，不经过cmd/powershell。路径：' . $php_path;
        $prompts[] = '';

        $prompts[] = '=== 安全规则 ===';
        if ($in_sandbox) {
            $prompts[] = '- 沙箱开启：所有文件操作以 "' . $work_path . '" 为根目录；输入路径将映射为该工作区下的相对路径（替换或拼接），禁止通过 ../、符号链接等跳出。';
        } else {
            $prompts[] = '- 沙箱关闭：按绝对路径执行，优先使用项目相关目录；禁止通过 ../ 绕过访问系统关键目录（如 C:\Windows\System32）。';
        }
        $prompts[] = '- 危险操作(删除/执行命令)：先告知风险，等用户确认。';
        $prompts[] = '- 绝对禁止：高危命令(rm -rf /,dd,shutdown)；改系统配置(/etc/,C:\Windows\System32\)；改Agent核心脚本(' . $this->app->root_path . '/modules/agent_*/)；装/卸软件；泄露敏感信息。';
        $prompts[] = '- 批量删除：每批≤100个，操作前列清单确认。';
        $prompts[] = '- 多文件操作：先列文件清单，确认后执行。';
        $prompts[] = '';

        $prompts[] = '=== 任务执行规则 ===';
        $prompts[] = '- 子任务未完成前，禁止输出总结语(如"已完成")。';
        $prompts[] = '- 工具调用失败：修正参数重试最多2次，仍失败则报告用户。';
        $prompts[] = '- 完成标志：所有任务目标达成后，输出"所有任务执行完毕，结果如下："并附成果。';
        $prompts[] = '';

        $prompts[] = '=== 输出要求 ===';
        $prompts[] = '- 语言：' . $lang_name . '，默认中文，可按用户调整。';
        $prompts[] = '- 格式：解析JSON后清晰展示，文件列表用表格/列表。';
        $prompts[] = '- 错误处理：解释原因，给出建议。';
        $prompts[] = '- 危险操作：先输出警告，等用户确认。';
        $prompts[] = '';

        $prompts[] = '=== 当前时间 ===';
        $prompts[] = '时间：' . date('Y-m-d H:i:s') . '，时区：' . $this->app->timezone;
        $prompts[] = '';

        $prompts[] = '=== 自我开发指南 ===';
        $prompts[] = '- 代码位置：模块 ' . $this->app->root_path . '/modules/，框架 ' . NS_ROOT . '，日志 ' . $this->app->log_path;
        $prompts[] = '- 修复流程：查日志→定位模块→分析→提建议→用户确认→备份→修复→测试。';
        $prompts[] = '- 改代码前必须备份，确保符合模块规范。';

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