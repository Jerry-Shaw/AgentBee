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

namespace modules\agent_core\lib;

use Nervsys\Core\Factory;
use Nervsys\Core\Lib\App;
use Nervsys\Core\Lib\Error;
use Nervsys\Core\Mgr\OSMgr;
use Nervsys\Core\Mgr\ProcMgr;
use Nervsys\Ext\libFileIO;

class utils extends Factory
{
    public App       $app;
    public OSMgr     $OSMgr;
    public ProcMgr   $procMgr;
    public libFileIO $libFileIO;
    public config    $config;

    public int $main_idx   = 0;
    public int $proc_idx   = 10;
    public int $worker_idx = 1000;

    public string $session_id;
    public array  $agent_config;

    public array $session_history = [];

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->app       = App::new();
        $this->OSMgr     = OSMgr::new();
        $this->procMgr   = ProcMgr::new('socket');
        $this->libFileIO = libFileIO::new();
        $this->config    = config::new();

        $this->session_id   = hash('md5', uniqid('', true));
        $this->agent_config = $this->config->get();
    }

    /**
     * @return int
     */
    public function getMainIDX(): int
    {
        return $this->main_idx;
    }

    /**
     * @return int
     */
    public function getProcIDX(): int
    {
        if ($this->proc_idx >= $this->worker_idx) {
            $this->proc_idx = 100;
        }

        return $this->proc_idx++;
    }

    /**
     * @return int
     */
    public function getWorkerIDX(): int
    {
        return $this->worker_idx++;
    }

    /**
     * @param string $worker_name
     * @param array  $content
     *
     * @return int
     */
    public function addSessionHistory(string $worker_name, array $content): int
    {
        $this->session_history[$worker_name]   ??= [];
        $this->session_history[$worker_name][] = $content;

        $message_count = count($this->session_history[$worker_name]);

        unset($worker_name, $content);
        return $message_count;
    }

    /**
     * @param string $worker_name
     * @param array  $history
     *
     * @return void
     */
    public function setSessionHistory(string $worker_name, array $history): void
    {
        $this->session_history[$worker_name] = $history;
        unset($worker_name, $history);
    }

    /**
     * @param string $worker_name
     *
     * @return array
     */
    public function getSessionHistory(string $worker_name): array
    {
        return $this->session_history[$worker_name] ?? [];
    }

    /**
     * Prune session history for a worker.
     *
     * @param string $worker_name
     * @param int    $keep_normal     Min normal units (user + assistant w/o tool_calls)
     * @param int    $keep_tool_pairs Min tool pairs (assistant with tool_calls + its tools)
     * @param bool   $force_prune     Allow zero limits
     *
     * @return array{removed_normal:int, removed_tools:int, current_count:int}
     */
    public function cleanSessionHistory(string $worker_name, int $keep_normal = 6, int $keep_tool_pairs = 2, bool $force_prune = false): array
    {
        $history = $this->session_history[$worker_name];

        // 1. enforce bounds
        if (!$force_prune) {
            $keep_tool_pairs = max(1, $keep_tool_pairs);
            $keep_normal     = max(2, $keep_normal);
        } else {
            $keep_tool_pairs = max(0, $keep_tool_pairs);
            $keep_normal     = max(0, $keep_normal);
        }

        // 2. extract system message (first one)
        $system = null;
        foreach ($history as $idx => $msg) {
            if ('system' === $msg['role']) {
                $system = $msg;
                unset($history[$idx]);
                break;
            }
        }

        $history = array_values($history);
        $total   = count($history);

        // 3. split into units (normal / tool) and count original totals
        $units              = [];
        $total_normal_units = 0;
        $total_tool_units   = 0;
        $i                  = 0;

        while ($i < $total) {
            $msg = $history[$i];
            if ('assistant' === $msg['role'] && !empty($msg['tool_calls'])) {
                $indices = [$i];
                $j       = $i + 1;

                while ($j < $total && 'tool' === $history[$j]['role']) {
                    $indices[] = $j;
                    $j++;
                }

                $units[] = ['type' => 'tool', 'indices' => $indices];
                $total_tool_units++;
                $i = $j;
            } else {
                $units[] = ['type' => 'normal', 'indices' => [$i], 'role' => $msg['role']];
                $total_normal_units++;
                $i++;
            }
        }

        // 4. handle keep_normal == 0 : only system may survive
        if (0 === $keep_normal || empty($history)) {
            $this->session_history[$worker_name] = !is_null($system) ? [$system] : [];

            return [
                'removed_normal' => $total_normal_units,
                'removed_tools'  => $total_tool_units,
                'current_count'  => !is_null($system) ? 1 : 0
            ];
        }

        // 5. select units from end to front
        $selected_normal = [];
        $selected_tools  = [];

        $need_normal = $keep_normal;
        $need_tools  = $keep_tool_pairs;
        $unit_count  = count($units);

        for ($idx = $unit_count - 1; $idx >= 0; $idx--) {
            $unit = $units[$idx];
            if ('normal' === $unit['type']) {
                if ($need_normal > 0) {
                    array_unshift($selected_normal, $unit);
                    $need_normal--;
                }
            } else {
                if ($need_tools > 0) {
                    array_unshift($selected_tools, $unit);
                    $need_tools--;
                }
            }
            if ($need_normal <= 0 && $need_tools <= 0) {
                break;
            }
        }

        // 6. ensure first normal unit is 'user'
        if (!empty($selected_normal) && 'user' !== $selected_normal[0]['role']) {
            $first_idx = $selected_normal[0]['indices'][0];
            for ($i = $first_idx - 1; $i >= 0; $i--) {
                if ('user' === $history[$i]['role']) {
                    $user_unit = ['type' => 'normal', 'indices' => [$i], 'role' => 'user'];
                    array_unshift($selected_normal, $user_unit);
                    break;
                }
            }
        }

        // 7. ensure each tool pair has a preceding user (add missing users)
        $extra_user_indices = [];
        foreach ($selected_tools as $tool_unit) {
            $tool_start = $tool_unit['indices'][0];
            for ($i = $tool_start - 1; $i >= 0; $i--) {
                if ('user' === $history[$i]['role']) {
                    $extra_user_indices[$i] = true;
                    break;
                }
            }
        }

        $normal_idx_set = [];
        foreach ($selected_normal as $nu) {
            $normal_idx_set[$nu['indices'][0]] = true;
        }

        foreach ($extra_user_indices as $uidx => $_) {
            if (!isset($normal_idx_set[$uidx])) {
                $selected_normal[] = ['type' => 'normal', 'indices' => [$uidx], 'role' => 'user'];
            }
        }

        // 8. merge and sort units by original order
        $all_units = array_merge($selected_normal, $selected_tools);

        usort(
            $all_units,
            function ($a, $b)
            {
                return $a['indices'][0] <=> $b['indices'][0];
            }
        );

        // 9. count kept units and build new history
        $kept_normal_units = 0;
        $kept_tool_units   = 0;
        $keep              = [];

        foreach ($all_units as $unit) {
            foreach ($unit['indices'] as $idx) {
                $keep[$idx] = true;
            }
            if ('normal' === $unit['type']) {
                $kept_normal_units++;
            } else {
                $kept_tool_units++;
            }
        }

        $new_history = [];

        for ($i = 0; $i < $total; $i++) {
            if (isset($keep[$i])) {
                $new_history[] = $history[$i];
            }
        }

        // 10. prepend system if exists
        if (null !== $system) {
            array_unshift($new_history, $system);
        }

        $this->session_history[$worker_name] = $new_history;

        // 11. compute removed counts (non‑negative)
        $removed_normal = max(0, $total_normal_units - $kept_normal_units);
        $removed_tools  = max(0, $total_tool_units - $kept_tool_units);

        // 12. clean temporary variables (ordered by definition, exclude $new_history)
        unset($worker_name, $keep_normal, $keep_tool_pairs, $force_prune, $history, $system, $total, $units, $i, $msg, $indices, $j, $selected_normal, $selected_tools, $need_normal, $need_tools, $unit_count, $idx, $unit, $first_idx, $user_unit, $extra_user_indices, $normal_idx_set, $uidx, $tool_unit, $tool_start, $all_units, $keep, $total_normal_units, $total_tool_units, $kept_normal_units, $kept_tool_units);

        return [
            'removed_normal' => $removed_normal,
            'removed_tools'  => $removed_tools,
            'current_count'  => count($new_history)
        ];
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
     * @param string $sender
     * @param string $worker_name
     * @param string $worker_role
     * @param string $window_name
     * @param int    $is_sub_talk
     *
     * @return array
     */
    public function getMessageMarker(string $sender, string $worker_name, string $worker_role, string $window_name, int $is_sub_talk): array
    {
        $marker = [
            'sender'     => $sender,
            'isSubTalk'  => $is_sub_talk,
            'workerName' => $worker_name,
            'workerRole' => $worker_role,
            'sessionId'  => $this->session_id,
            'messageId'  => hash('md5', uniqid(microtime(), true)),
            'WindowName' => $window_name
        ];

        unset($sender, $worker_name, $worker_role, $window_name, $is_sub_talk);
        return $marker;
    }

    /**
     * @param string $dirname
     * @param string $module
     * @param array  $tool_names
     *
     * @return array
     * @throws \ReflectionException
     */
    public function fetchSkills(string $dirname, string $module = '', array $tool_names = []): array
    {
        $skills   = [];
        $dir_list = [];
        $dirname  = strtr($dirname, ['\\' => DIRECTORY_SEPARATOR, '/' => DIRECTORY_SEPARATOR]);

        if ('' !== $module) {
            $dir_list[] = [
                'name'          => $module,
                'absolute_path' => $this->app->root_path . DIRECTORY_SEPARATOR . $dirname . DIRECTORY_SEPARATOR . $module
            ];
        } else {
            $contents = $this->libFileIO->getDirContents($this->app->root_path . DIRECTORY_SEPARATOR . $dirname);

            foreach ($contents as $item) {
                if ($item['is_file']) {
                    continue;
                }

                $dir_list[] = [
                    'name'          => $item['name'],
                    'absolute_path' => $item['absolute_path']
                ];
            }

            unset($contents);
        }

        foreach ($dir_list as $path) {
            $json_file = $path['absolute_path'] . DIRECTORY_SEPARATOR . 'module.json';
            $meta_file = $path['absolute_path'] . DIRECTORY_SEPARATOR . 'skills.php';

            if (!is_file($json_file) || !is_file($meta_file)) {
                continue;
            }

            $json_data = json_decode(file_get_contents($json_file), true);

            if (
                !is_array($json_data)
                || !isset($json_data['entry'])
                || !isset($json_data['name'])
                || $json_data['name'] !== $path['name']
                || isset($this->agent_tools[$json_data['name']])
            ) {
                continue;
            }

            $namespace = '\\' . $dirname . '\\' . $json_data['name'];

            try {
                $metadata = ($namespace . '\\skills')::META;

                if (!empty($tool_names)) {
                    $metadata = array_filter(
                        $metadata,
                        function (array $item) use ($tool_names): bool
                        {
                            return in_array($item['function']['name'], $tool_names);
                        }
                    );
                }

                foreach ($metadata as $index => $item) {
                    $metadata[$index]['function']['name'] = $json_data['name'] . '/' . $item['function']['name'];
                }

                $skills[] = [
                    'name'  => $json_data['name'],
                    'meta'  => array_values($metadata),
                    'class' => $namespace . '\\' . strstr($json_data['entry'], '.', true)
                ];
            } catch (\Throwable $throwable) {
                Error::new()->exceptionHandler($throwable, false, false);
                unset($throwable);
            }
        }

        unset($dirname, $module, $tool_names, $dir_list, $item, $path, $json_file, $meta_file, $json_data, $namespace, $metadata, $index);
        return $skills;
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getMainPrompt(): array
    {
        $prompts   = [];
        $php_path  = $this->OSMgr->getPhpPath();
        $lang_code = substr(setlocale(LC_ALL, 0), 0, 2);
        $lang_name = 'zh' === $lang_code ? '中文' : '英文';
        $work_path = $this->agent_config['workspace_path'];
        $max_limit = $this->agent_config['max_ctx_len'] * 3;

        $prompts[] = '## 系统';
        $prompts[] = '`OS:' . php_uname() . '` | `Agent:' . AGENT_NAME . ' v' . AGENT_VERSION . '(' . NS_NAMESPACE . '/' . NS_VER . ')` | `PHP:' . PHP_VERSION . '(' . $php_path . ')`';
        $prompts[] = '`CWD:' . getcwd() . '` | `入口:' . $this->app->script_path . '` | `根目录:' . $this->app->root_path . '` | `框架:' . NS_ROOT . '` | `模块:' . $this->app->root_path . '/modules/` | `技能:' . $this->app->root_path . '/skills/` | `日志:' . $this->app->log_path . '` | `工作区:' . $work_path . '`';

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
        $prompts[] = '- 任务完成即止，勿重复调用工具。';

        $prompts[] = '## 安全';
        if ($this->agent_config['sandbox_mode']) {
            $prompts[] = '- **沙箱开启**:所有文件以 `' . $work_path . '` 为根目录，强制路径映射，**禁止 ../ 或符号链接跳出**。';
        } else {
            $prompts[] = '- **沙箱关闭**:按绝对路径，优先工作区，**禁止 ../ 绕开系统关键目录**(如 `C:\Windows\System32`)。';
        }
        $prompts[] = '- **危险操作**(删/执行命令):先告知风险等确认。';
        $prompts[] = '- **绝对禁止**:`rm -rf /, dd, shutdown`；改系统配置(/etc/, C:\\Windows\\System32\\)；改Agent核心脚本(`' . $this->app->root_path . '/modules/*`)；装/卸软件；泄露敏感信息。';
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

        $prompts[] = '## Worker 元数据';
        $prompts[] = '- 名称: ' . WORKER_MAIN;
        $prompts[] = '- 角色: 个人助理';

        $prompt = [
            'role'    => 'system',
            'content' => implode("\n", $prompts)
        ];

        unset($prompts, $php_path, $lang_code, $lang_name, $work_path, $max_limit);
        return $prompt;
    }

    /**
     * @param string $worker_name
     * @param string $worker_role
     * @param string $system_prompt
     *
     * @return array
     * @throws \Exception
     */
    public function getChildPrompt(string $worker_name, string $worker_role, string $system_prompt): array
    {
        $prompts = [];

        $prompts[] = '## 系统';
        $prompts[] = '`OS:' . php_uname() . '` | `PHP:' . PHP_VERSION . ' (' . $this->OSMgr->getPhpPath() . ')` | `CWD:' . getcwd() . '`';
        $prompts[] = '`入口:' . $this->app->script_path . '` | `根目录:' . $this->app->root_path . '` | `工作区:' . $this->agent_config['workspace_path'] . '`';
        $prompts[] = '`框架:' . NS_ROOT . '` | `模块:' . $this->app->root_path . '/modules/` | `技能:' . $this->app->root_path . '/skills/` | `日志:' . $this->app->log_path . '`';

        $prompts[] = '## 工具';
        $prompts[] = '- **强制优先**：有专用工具时优先调用工具。';
        $prompts[] = '- **错误处理**：工具返回error时修正重试（最多2次），失败则输出错误信息。';
        $prompts[] = '- 任务完成即止，勿重复调用工具。';

        $prompts[] = '## 安全';
        if ($this->agent_config['sandbox_mode']) {
            $prompts[] = '- **沙箱开启**:所有文件以 `' . $this->agent_config['workspace_path'] . '` 为根目录，强制路径映射，**禁止 ../ 或符号链接跳出**。';
        } else {
            $prompts[] = '- **沙箱关闭**:按绝对路径，优先工作区，**禁止 ../ 绕开系统关键目录**(如 `C:\Windows\System32`)。';
        }
        $prompts[] = '- **危险操作**(删/执行命令):先告知风险等确认。';
        $prompts[] = '- **绝对禁止**:`rm -rf /, dd, shutdown`；改系统配置(/etc/, C:\\Windows\\System32\\)；改Agent核心脚本(`' . $this->app->root_path . '/modules/*`)；装/卸软件；泄露敏感信息。';
        $prompts[] = '- **批量/多文件**:每批 ≤100个，操作前列清单确认。';

        $prompts[] = '## 时间';
        $prompts[] = date('Y-m-d H:i:s') . ' 时区:' . $this->app->timezone;
        $prompts[] = '';

        $prompts[] = '## Worker 元数据';
        $prompts[] = '- 名称: ' . $worker_name;
        $prompts[] = '- 角色: ' . $worker_role;
        $prompts[] = '';

        $prompts[] = '## 用户指令';
        $prompts[] = $system_prompt;

        $prompt = [
            'role'    => 'system',
            'content' => implode("\n", $prompts)
        ];

        unset($worker_name, $worker_role, $system_prompt, $prompts);
        return $prompt;
    }

    /**
     * @param string $string
     * @param string $level Debug level (none, trace, debug)
     *
     * @return void
     */
    public function debug(string $string, string $level = 'trace'): void
    {
        $debug_level = strtolower($this->agent_config['agent_debug']);

        if ('none' === $debug_level) {
            return;
        }

        if ('trace' === $debug_level && 'debug' === strtolower($level)) {
            return;
        }

        echo '[' . date('Y-m-d H:i:s') . '][' . strtoupper($level) . '] ' . $string . PHP_EOL;
        unset($string, $level, $debug_level);
    }
}