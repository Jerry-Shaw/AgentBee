<?php

/**
 * Agent Claw - Web Crawler Module
 *
 * This module provides high-efficiency web data acquisition tools for Agents,
 * focusing on noise reduction and structural extraction to optimize LLM token usage.
 *
 * Copyright 2026 AgentBee self developed
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

namespace modules\agent_skills\WebCrawler;

use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Ext\libHttp;

class go extends Factory
{
    public utils   $utils;
    public libHttp $http;

    public function __construct()
    {
        $this->utils = utils::new();
        $this->http  = libHttp::new();
    }

    /**
     * Internal request handler.
     */
    private function request(string $url, string $method = 'GET', array $params = [], int $timeout = 30, array $headers = []): array
    {
        $this->http->resetOptions();
        $this->http->setTimeout($timeout);
        $this->http->setHttpMethod($method);

        // Enhanced User-Agent to avoid basic bot detection
        $this->http->addHeader([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Cache-Control: max-age=0',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1'
        ]);

        if (!empty($headers)) {
            $this->http->addHeader($headers);
        }

        // Yoda Condition & Logic Fix
        if ('POST' === $method && !empty($params)) {
            $this->http->addData($params);
        }

        $this->http->fetch($url);

        return [
            'status_code' => $this->http->getHttpCode(),
            'body'        => $this->http->getHttpBody(),
            'error'       => $this->http->getHttpError() ?: null,
        ];
    }

    public function fetchHtml(string $url, int $timeout = 30, array $headers = []): array
    {
        $res = $this->request($url, 'GET', [], $timeout, $headers);
        if (!empty($res['error'])) return ['error' => $res['error']];
        return [
            'html'   => $res['body'],
            'status' => $res['status_code'],
            'url'    => $url
        ];
    }

    public function fetchText(string $url, int $timeout = 30): array
    {
        $res = $this->request($url, 'GET', [], $timeout);
        if (!empty($res['error'])) return ['error' => $res['error']];

        $body = mb_convert_encoding($res['body'], 'UTF-8', 'auto');

        // FIXED: Removed space in closing tag </\1>
        $cleanBody = preg_replace('/<(script|style|head).*?<\/(\1)>/is', '', $body);
        $cleanBody = preg_replace('/<!--.*?-->/s', '', $cleanBody);

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($cleanBody)));

        unset($body, $cleanBody);

        return [
            'text'   => $text,
            'status' => $res['status_code'],
            'url'    => $url
        ];
    }

    public function fetchContent(string $url, int $timeout = 30): array
    {
        $res = $this->request($url, 'GET', [], $timeout);
        if (!empty($res['error'])) return ['error' => $res['error']];

        $body = mb_convert_encoding($res['body'], 'UTF-8', 'auto');

        preg_match('/<title>(.*?)<\/title>/is', $body, $titleMatch);
        $title = trim($titleMatch[1] ?? 'No Title');

        // FIXED: Removed space in closing tag </\1>
        $cleanBody = preg_replace('/<(script|style|nav|footer|header).*?<\/(\1)>/is', '', $body);
        $cleanBody = preg_replace('/<!--.*?-->/s', '', $cleanBody);

        $content = trim(strip_tags($cleanBody));

        unset($body, $cleanBody);

        return [
            'title'   => $title,
            'content' => $content,
            'status'  => $res['status_code'],
            'url'     => $url
        ];
    }

    public function extractLinks(string $url, int $timeout = 30): array
    {
        $res = $this->request($url, 'GET', [], $timeout);
        if (!empty($res['error'])) return ['error' => $res['error']];

        $body = mb_convert_encoding($res['body'], 'UTF-8', 'auto');
        preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $body, $matches);

        $links     = $matches[1] ?? [];
        $parsedUrl = parse_url($url);
        $base      = ($parsedUrl['scheme'] ?? 'http') . '://' . ($parsedUrl['host'] ?? '');

        $resolvedLinks = [];
        foreach ($links as $link) {
            if (filter_var($link, FILTER_VALIDATE_URL)) {
                $resolvedLinks[] = $link;
            } elseif (0 === strpos($link, '/')) { // Yoda Condition
                $resolvedLinks[] = $base . $link;
            } else {
                $path            = ($parsedUrl['path'] ?? '/');
                $resolvedLinks[] = $base . rtrim($path, '/') . '/' . $link;
            }
        }

        unset($body); // Memory optimization
        return array_values(array_unique($resolvedLinks));
    }

    public function extractAssets(string $url, int $timeout = 30): array
    {
        $res = $this->request($url, 'GET', [], $timeout);
        if (!empty($res['error'])) return ['error' => $res['error']];

        $body = mb_convert_encoding($res['body'], 'UTF-8', 'auto');
        preg_match_all('/<img\s+[^>]*src=["\']([^"\']+)["\']/i', $body, $imgMatches);
        preg_match_all('/<a\s+[^>]*href=["\']([^"\']+\.(?:pdf|zip|rar|docx|xlsx|pptx|exe|jpg|png|webp))["\']/i', $body, $fileMatches);

        unset($body); // Memory optimization
        return [
            'images' => array_values(array_unique($imgMatches[1] ?? [])),
            'files'  => array_values(array_unique($fileMatches[1] ?? [])),
            'url'    => $url
        ];
    }

    public function fetchJson(string $url, array $params = [], int $timeout = 30): array
    {
        $method = empty($params) ? 'GET' : 'POST';
        if ('POST' === $method) { // Yoda Condition
            $this->http->resetOptions();
            $this->http->setContentType(libHttp::CONTENT_TYPE_JSON);
        }

        $res = $this->request($url, $method, $params, $timeout);
        if (!empty($res['error'])) return ['error' => $res['error']];

        $data = json_decode($res['body'], true);
        return (null !== $data && 0 === json_last_error()) ? $data : ['error' => 'Invalid JSON response', 'raw_body' => $res['body']];
    }

    public function downloadFile(string $url, string $save_to, int $timeout = 30): array
    {
        // Ensure target directory exists before fetching
        $save_to  = $this->utils->securePath($save_to);
        $dir_path = dirname($save_to);

        if (!is_dir($dir_path)) {
            mkdir($dir_path, 755, true);
        }

        // Clean slate + timeout + browser-like headers to avoid bot detection
        $this->http->resetOptions();
        $this->http->setTimeout($timeout);
        $this->http->addHeader([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Cache-Control: max-age=0',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1'
        ]);

        // Stream directly to file + auto-reset options after request (clean slate for next call)
        $this->http->fetch($url, $save_to, true);

        $code = $this->http->getHttpCode();

        return [
            'status' => 200 === $code && is_file($save_to) ? 'success' : 'error',
            'path'   => $save_to,
            'size'   => filesize($save_to) ?: 0,
            'error'  => $this->http->getHttpError(),
        ];
    }
}