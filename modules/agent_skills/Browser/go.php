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

namespace modules\agent_skills\Browser;

use Nervsys\Core\Factory;

class go extends Factory
{
    /**
     * @param string $worker_name
     * @param bool   $headless
     *
     * @return array
     */
    public function start(string $worker_name, bool $headless = false): array
    {
        $result = [
            'action'      => 'start',
            'worker_name' => $worker_name,
            'headless'    => $headless,
            'handler'     => handler::class,
            'message'     => '启动浏览器操作已提交，稍后会自动推送结果，待就绪后即可操作。'
        ];
        unset($worker_name, $headless);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $url
     *
     * @return array
     */
    public function navigate(string $worker_name, string $url): array
    {
        $result = [
            'action'      => 'navigate',
            'worker_name' => $worker_name,
            'params'      => ['url' => $url],
            'handler'     => handler::class,
            'message'     => '导航操作已提交，稍后会自动推送结果。请勿重复导航相同 URL。'
        ];
        unset($worker_name, $url);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $selector
     *
     * @return array
     */
    public function click(string $worker_name, string $selector): array
    {
        $result = [
            'action'      => 'click',
            'worker_name' => $worker_name,
            'params'      => ['selector' => $selector],
            'handler'     => handler::class,
            'message'     => '点击操作已提交，稍后会自动推送结果。请勿重复点击。'
        ];
        unset($worker_name, $selector);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $selector
     * @param string $text
     *
     * @return array
     */
    public function type(string $worker_name, string $selector, string $text): array
    {
        $result = [
            'action'      => 'type',
            'worker_name' => $worker_name,
            'params'      => ['selector' => $selector, 'text' => $text],
            'handler'     => handler::class,
            'message'     => '输入操作已提交，稍后会自动推送结果。请勿重复输入相同内容。'
        ];
        unset($worker_name, $selector, $text);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $selector
     *
     * @return array
     */
    public function submit(string $worker_name, string $selector = 'form'): array
    {
        $result = [
            'action'      => 'submit',
            'worker_name' => $worker_name,
            'params'      => ['selector' => $selector],
            'handler'     => handler::class,
            'message'     => '提交表单操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $selector);
        return $result;
    }

    /**
     * @param string $worker_name
     *
     * @return array
     */
    public function getUrl(string $worker_name): array
    {
        $result = [
            'action'      => 'getUrl',
            'worker_name' => $worker_name,
            'params'      => [],
            'handler'     => handler::class,
            'message'     => '获取 URL 操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name);
        return $result;
    }

    /**
     * @param string $worker_name
     *
     * @return array
     */
    public function getTitle(string $worker_name): array
    {
        $result = [
            'action'      => 'getTitle',
            'worker_name' => $worker_name,
            'params'      => [],
            'handler'     => handler::class,
            'message'     => '获取标题操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param bool   $html
     *
     * @return array
     */
    public function getContent(string $worker_name, bool $html = false): array
    {
        $result = [
            'action'      => 'getContent',
            'worker_name' => $worker_name,
            'params'      => ['html' => $html],
            'handler'     => handler::class,
            'message'     => '获取内容操作已提交，稍后会自动推送结果。请勿重复获取相同内容。'
        ];
        unset($worker_name, $html);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $selector
     *
     * @return array
     */
    public function getValue(string $worker_name, string $selector): array
    {
        $result = [
            'action'      => 'getValue',
            'worker_name' => $worker_name,
            'params'      => ['selector' => $selector],
            'handler'     => handler::class,
            'message'     => '获取值操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $selector);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $selector
     * @param string $attribute
     *
     * @return array
     */
    public function getAttribute(string $worker_name, string $selector, string $attribute): array
    {
        $result = [
            'action'      => 'getAttribute',
            'worker_name' => $worker_name,
            'params'      => ['selector' => $selector, 'attribute' => $attribute],
            'handler'     => handler::class,
            'message'     => '获取属性操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $selector, $attribute);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $selector
     * @param string $attribute
     * @param string $value
     *
     * @return array
     */
    public function setAttribute(string $worker_name, string $selector, string $attribute, string $value): array
    {
        $result = [
            'action'      => 'setAttribute',
            'worker_name' => $worker_name,
            'params'      => ['selector' => $selector, 'attribute' => $attribute, 'value' => $value],
            'handler'     => handler::class,
            'message'     => '设置属性操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $selector, $attribute, $value);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $selector
     *
     * @return array
     */
    public function scrollIntoView(string $worker_name, string $selector): array
    {
        $result = [
            'action'      => 'scrollIntoView',
            'worker_name' => $worker_name,
            'params'      => ['selector' => $selector],
            'handler'     => handler::class,
            'message'     => '滚动操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $selector);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $selector
     * @param string $value
     *
     * @return array
     */
    public function selectOption(string $worker_name, string $selector, string $value): array
    {
        $result = [
            'action'      => 'selectOption',
            'worker_name' => $worker_name,
            'params'      => ['selector' => $selector, 'value' => $value],
            'handler'     => handler::class,
            'message'     => '选择选项操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $selector, $value);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $script
     * @param bool   $return_by_value
     *
     * @return array
     */
    public function evaluate(string $worker_name, string $script, bool $return_by_value = true): array
    {
        $result = [
            'action'      => 'evaluate',
            'worker_name' => $worker_name,
            'params'      => ['script' => $script, 'return_by_value' => $return_by_value],
            'handler'     => handler::class,
            'message'     => '执行脚本操作已提交，稍后会自动推送结果。请勿重复执行相同代码。'
        ];
        unset($worker_name, $script, $return_by_value);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $save_path
     * @param string $format
     * @param int    $quality
     *
     * @return array|string[]
     */
    public function screenshot(string $worker_name, string $save_path, string $format = 'jpeg', int $quality = 80): array
    {
        if (!in_array($format, ['jpeg', 'png'], true)) {
            return [
                'status' => 'error',
                'error'  => 'Invalid image format. Only jpeg and png are supported.'
            ];
        }

        $result = [
            'action'      => 'screenshot',
            'worker_name' => $worker_name,
            'params'      => ['save_path' => $save_path, 'format' => $format, 'quality' => $quality],
            'handler'     => handler::class,
            'message'     => '截屏操作已提交，稍后会自动推送结果。请勿重复截屏。'
        ];
        unset($worker_name, $save_path, $format, $quality);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $selector
     * @param int    $timeout
     *
     * @return array
     */
    public function waitForSelector(string $worker_name, string $selector, int $timeout = 30): array
    {
        $result = [
            'action'      => 'waitForSelector',
            'worker_name' => $worker_name,
            'params'      => ['selector' => $selector, 'timeout' => $timeout],
            'handler'     => handler::class,
            'message'     => '等待元素操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $selector, $timeout);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param int    $timeout
     *
     * @return array
     */
    public function waitForPageLoad(string $worker_name, int $timeout = 30): array
    {
        $result = [
            'action'      => 'waitForPageLoad',
            'worker_name' => $worker_name,
            'params'      => ['timeout' => $timeout],
            'handler'     => handler::class,
            'message'     => '等待页面加载操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $timeout);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $text
     * @param int    $timeout
     *
     * @return array
     */
    public function waitForText(string $worker_name, string $text, int $timeout = 30): array
    {
        $result = [
            'action'      => 'waitForText',
            'worker_name' => $worker_name,
            'params'      => ['text' => $text, 'timeout' => $timeout],
            'handler'     => handler::class,
            'message'     => '等待文本操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $text, $timeout);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $selector
     * @param int    $timeout
     *
     * @return array
     */
    public function waitForElementVisible(string $worker_name, string $selector, int $timeout = 30): array
    {
        $result = [
            'action'      => 'waitForElementVisible',
            'worker_name' => $worker_name,
            'params'      => ['selector' => $selector, 'timeout' => $timeout],
            'handler'     => handler::class,
            'message'     => '等待元素可见操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $selector, $timeout);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $url_pattern
     * @param int    $timeout
     *
     * @return array
     */
    public function waitForUrl(string $worker_name, string $url_pattern, int $timeout = 30): array
    {
        $result = [
            'action'      => 'waitForUrl',
            'worker_name' => $worker_name,
            'params'      => ['url_pattern' => $url_pattern, 'timeout' => $timeout],
            'handler'     => handler::class,
            'message'     => '等待 URL 操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $url_pattern, $timeout);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $selector
     *
     * @return array
     */
    public function hover(string $worker_name, string $selector): array
    {
        $result = [
            'action'      => 'hover',
            'worker_name' => $worker_name,
            'params'      => ['selector' => $selector],
            'handler'     => handler::class,
            'message'     => '悬停操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $selector);
        return $result;
    }

    /**
     * @param string $worker_name
     * @param string $key
     * @param array  $modifiers
     *
     * @return array
     */
    public function pressKey(string $worker_name, string $key, array $modifiers = []): array
    {
        $result = [
            'action'      => 'pressKey',
            'worker_name' => $worker_name,
            'params'      => ['key' => $key, 'modifiers' => $modifiers],
            'handler'     => handler::class,
            'message'     => '按键操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name, $key, $modifiers);
        return $result;
    }

    /**
     * @return string[]
     */
    public function list(): array
    {
        return [
            'action'  => 'list',
            'handler' => handler::class,
            'message' => '列出实例操作已提交，稍后会自动推送结果。'
        ];
    }

    /**
     * @param string $worker_name
     *
     * @return string[]
     */
    public function close(string $worker_name): array
    {
        $result = [
            'action'      => 'close',
            'worker_name' => $worker_name,
            'handler'     => handler::class,
            'message'     => '关闭实例操作已提交，稍后会自动推送结果。'
        ];
        unset($worker_name);
        return $result;
    }
}