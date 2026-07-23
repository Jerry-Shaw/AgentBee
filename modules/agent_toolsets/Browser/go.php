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

use Nervsys\Core\Factory;

class go extends Factory
{
    /**
     * @param bool $headless
     *
     * @return array
     */
    public function start(bool $headless = false): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['headless' => $headless],
            'handler' => handler::class,
        ];
    }

    /**
     * @return array
     */
    public function close(): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => [],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $url
     *
     * @return array
     */
    public function createTab(string $url = 'about:blank'): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['url' => $url],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $target_id
     *
     * @return array
     */
    public function switchTab(string $target_id): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['target_id' => $target_id],
            'handler' => handler::class,
        ];
    }

    /**
     * @return array
     */
    public function listTabs(): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => [],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $target_id
     *
     * @return array
     */
    public function closeTab(string $target_id): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['target_id' => $target_id],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $url
     *
     * @return array
     */
    public function navigate(string $url): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['url' => $url],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $selector
     *
     * @return array
     */
    public function click(string $selector): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['selector' => $selector],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $selector
     * @param string $text
     *
     * @return array
     */
    public function type(string $selector, string $text): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['selector' => $selector, 'text' => $text],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $selector
     *
     * @return array
     */
    public function submit(string $selector = 'form'): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['selector' => $selector],
            'handler' => handler::class,
        ];
    }

    /**
     * @return array
     */
    public function getUrl(): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => [],
            'handler' => handler::class,
        ];
    }

    /**
     * @return array
     */
    public function getTitle(): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => [],
            'handler' => handler::class,
        ];
    }

    /**
     * @param bool $html
     *
     * @return array
     */
    public function getContent(bool $html = false): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['html' => $html],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $selector
     *
     * @return array
     */
    public function getValue(string $selector): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['selector' => $selector],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $selector
     * @param string $attribute
     *
     * @return array
     */
    public function getAttribute(string $selector, string $attribute): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['selector' => $selector, 'attribute' => $attribute],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $selector
     * @param string $attribute
     * @param string $value
     *
     * @return array
     */
    public function setAttribute(string $selector, string $attribute, string $value): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['selector' => $selector, 'attribute' => $attribute, 'value' => $value],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $selector
     *
     * @return array
     */
    public function scrollIntoView(string $selector): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['selector' => $selector],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $selector
     * @param string $value
     *
     * @return array
     */
    public function selectOption(string $selector, string $value): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['selector' => $selector, 'value' => $value],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $script
     * @param bool   $return_by_value
     *
     * @return array
     */
    public function evaluate(string $script, bool $return_by_value = true): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['script' => $script, 'return_by_value' => $return_by_value],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $save_path
     * @param string $format
     * @param int    $quality
     *
     * @return array
     */
    public function screenshot(string $save_path, string $format = 'jpeg', int $quality = 80): array
    {
        if (!in_array($format, ['jpeg', 'png'], true)) {
            $format = 'jpeg';
        }

        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['save_path' => $save_path, 'format' => $format, 'quality' => $quality],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $selector
     * @param int    $timeout
     *
     * @return array
     */
    public function waitForSelector(string $selector, int $timeout = 30): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['selector' => $selector, 'timeout' => $timeout],
            'handler' => handler::class,
        ];
    }

    /**
     * @param int $timeout
     *
     * @return array
     */
    public function waitForPageLoad(int $timeout = 30): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['timeout' => $timeout],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $text
     * @param int    $timeout
     *
     * @return array
     */
    public function waitForText(string $text, int $timeout = 30): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['text' => $text, 'timeout' => $timeout],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $selector
     * @param int    $timeout
     *
     * @return array
     */
    public function waitForElementVisible(string $selector, int $timeout = 30): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['selector' => $selector, 'timeout' => $timeout],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $url_pattern
     * @param int    $timeout
     *
     * @return array
     */
    public function waitForUrl(string $url_pattern, int $timeout = 30): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['url_pattern' => $url_pattern, 'timeout' => $timeout],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $selector
     *
     * @return array
     */
    public function hover(string $selector): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['selector' => $selector],
            'handler' => handler::class,
        ];
    }

    /**
     * @param string $key
     * @param array  $modifiers
     *
     * @return array
     */
    public function pressKey(string $key, array $modifiers = []): array
    {
        return [
            'async'   => false,
            'action'  => __FUNCTION__,
            'params'  => ['key' => $key, 'modifiers' => $modifiers],
            'handler' => handler::class,
        ];
    }
}