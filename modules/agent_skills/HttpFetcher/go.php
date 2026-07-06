<?php

/**
 * AgentBee - Http Fetcher Module
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

namespace modules\agent_skills\HttpFetcher;

use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;
use Nervsys\Ext\libHttp;

class go extends Factory
{
    public utils   $utils;
    public libHttp $http;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->utils = utils::new();
        $this->http  = libHttp::new();
    }

    /**
     * Internal request handler.
     *
     * @throws \ReflectionException
     */
    private function request(
        string $url,
        string $method = 'GET',
        array  $params = [],
        int    $timeout = 30,
        array  $headers = [],
        string $content_type = ''
    ): array
    {
        $this->http->resetOptions();
        $this->http->setTimeout($timeout);
        $this->http->setHttpMethod($method);

        if ('' !== $content_type) {
            $this->http->setContentType($content_type);
        }

        $this->setDefaultHeaders();

        if (!empty($headers)) {
            $this->http->addHeader($headers);
        }

        if (!empty($params)) {
            $this->http->addData($params);
        }

        $res = $this->http->fetch($url);

        $result = [
            'http_code'    => $this->http->getHttpCode(),
            'http_body'    => $res,
            'http_error'   => $this->http->getHttpError(),
            'http_headers' => $this->http->getHttpHeader()
        ];

        unset($url, $method, $params, $timeout, $headers, $content_type, $res);
        return $result;
    }

    /**
     * @return void
     */
    private function setDefaultHeaders(): void
    {
        $this->http->setSslVerifyPeer(false);
        $this->http->setSslVerifyHost(0);

        $this->http->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
        $this->http->setAcceptType('text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8');
        $this->http->addHeader([
            'Accept-Language'           => 'zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control'             => 'max-age=0',
            'Connection'                => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
        ]);
    }

    /**
     * @param string $html
     * @param array  $headers
     *
     * @return string
     */
    private function detectCharset(string $html, array $headers): string
    {
        if (isset($headers['content-type'])) {
            $content_type = $headers['content-type'];
            $charset_pos  = strpos($content_type, 'charset=');

            if (false !== $charset_pos) {
                $charset = substr($content_type, $charset_pos + 8);
                $charset = strtok($charset, '; "');
                $charset = trim($charset);

                if ('' !== $charset) {
                    return strtoupper($charset);
                }
            }

            unset($content_type);
        }

        $search_pos = 0;

        while (false !== ($meta_start = strpos($html, '<meta', $search_pos))) {
            $meta_end = strpos($html, '>', $meta_start);

            if (false === $meta_end) {
                break;
            }

            $meta_tag    = substr($html, $meta_start, $meta_end - $meta_start + 1);
            $search_pos  = $meta_end + 1;
            $charset_pos = strpos($meta_tag, 'charset=');

            if (false !== $charset_pos) {
                $charset = substr($meta_tag, $charset_pos + 8);
                $charset = strtok($charset, '"\' >');
                $charset = trim($charset);

                if ('' !== $charset) {
                    return strtoupper($charset);
                }
            }
        }

        $detected = mb_detect_encoding($html, ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1'], true);

        unset($html, $headers, $charset_pos, $charset, $search_pos, $meta_start, $meta_end, $meta_tag);
        return is_string($detected) ? strtoupper($detected) : 'UTF-8';
    }

    /**
     * @param string $url
     * @param int    $timeout
     * @param array  $headers
     *
     * @return array
     * @throws \ReflectionException
     */
    public function fetchHtml(string $url, int $timeout = 30, array $headers = []): array
    {
        $res = $this->request($url, 'GET', [], $timeout, $headers);

        if ('' !== $res['http_error']) {
            return [
                'status' => 'error',
                'error'  => $res['http_error']
            ];
        }

        $result = [
            'status'    => 'success',
            'http_url'  => $url,
            'http_code' => $res['http_code'],
            'http_html' => $res['http_body'],
        ];

        unset($url, $timeout, $headers, $res);
        return $result;
    }

    /**
     * @param string $url
     * @param int    $timeout
     *
     * @return array
     * @throws \ReflectionException
     */
    public function fetchText(string $url, int $timeout = 30): array
    {
        $res = $this->request($url, 'GET', [], $timeout);

        if ('' !== $res['http_error']) {
            return [
                'status' => 'error',
                'error'  => $res['http_error']
            ];
        }

        $body    = $res['http_body'];
        $charset = $this->detectCharset($body, $res['http_headers']);

        if ('UTF-8' !== $charset) {
            $body = mb_convert_encoding($body, 'UTF-8', $charset);
        }

        $clean_body = preg_replace('/<(script|style|head).*?<\/(\1)>/is', '', $body);
        $clean_body = preg_replace('/<!--.*?-->/s', '', $clean_body);
        $text       = trim(preg_replace('/\s+/', ' ', strip_tags($clean_body)));

        $result = [
            'status'    => 'success',
            'http_url'  => $url,
            'http_code' => $res['http_code'],
            'http_text' => $text
        ];

        unset($url, $timeout, $res, $body, $clean_body, $text);
        return $result;
    }

    /**
     * @param string $url
     * @param int    $timeout
     *
     * @return array
     * @throws \ReflectionException
     */
    public function fetchContent(string $url, int $timeout = 30): array
    {
        $res = $this->request($url, 'GET', [], $timeout);

        if ('' !== $res['http_error']) {
            return [
                'status' => 'error',
                'error'  => $res['http_error']
            ];
        }

        $body    = $res['http_body'];
        $charset = $this->detectCharset($body, $res['http_headers']);

        if ('UTF-8' !== $charset) {
            $body = mb_convert_encoding($body, 'UTF-8', $charset);
        }

        preg_match('/<title>(.*?)<\/title>/is', $body, $title_match);

        $title      = trim($title_match[1] ?? 'No Title');
        $clean_body = preg_replace('/<(script|style|nav|footer|header).*?<\/(\1)>/is', '', $body);
        $clean_body = preg_replace('/<!--.*?-->/s', '', $clean_body);
        $content    = trim(strip_tags($clean_body));

        $result = [
            'status'       => 'success',
            'http_url'     => $url,
            'http_code'    => $res['http_code'],
            'http_title'   => $title,
            'http_content' => $content,
        ];

        unset($url, $timeout, $res, $body, $title_match, $title, $clean_body, $content);
        return $result;
    }

    /**
     * @param string $url
     * @param int    $timeout
     *
     * @return array
     * @throws \ReflectionException
     */
    public function extractLinks(string $url, int $timeout = 30): array
    {
        $res = $this->request($url, 'GET', [], $timeout);

        if ('' !== $res['http_error']) {
            return [
                'status' => 'error',
                'error'  => $res['http_error']
            ];
        }

        $body    = $res['http_body'];
        $charset = $this->detectCharset($body, $res['http_headers']);

        if ('UTF-8' !== $charset) {
            $body = mb_convert_encoding($body, 'UTF-8', $charset);
        }

        preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $body, $matches);

        if (empty($matches[1])) {
            return [];
        }

        $parsed = parse_url($url);
        $domain = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');

        if (isset($parsed['port'])) {
            $domain .= ':' . $parsed['port'];
        }

        $path = $parsed['path'] ?? '/';

        if ('/' === $path || '' === $path) {
            $path = '';
        } else {
            if (str_ends_with($path, '/')) {
                $path = rtrim($path, '/');
            } else {
                $last_pos = strrpos($path, '/');

                if (false !== $last_pos) {
                    $path = substr($path, 0, $last_pos);
                } else {
                    $path = '';
                }
            }
        }

        $urls = [];
        $base = $domain . $path . '/';

        foreach ($matches[1] as $link) {
            if (str_starts_with($link, '//')) {
                $link = ($parsed['scheme'] ?? 'http') . ':' . $link;
            }

            if (filter_var($link, FILTER_VALIDATE_URL)) {
                $urls[] = $link;
            } elseif (str_starts_with($link, '/')) {
                $urls[] = $domain . $link;
            } else {
                $urls[] = $base . $link;
            }
        }

        unset($url, $timeout, $res, $body, $matches, $parsed, $domain, $path, $clean, $dir_pos, $base, $link);
        return array_values(array_unique($urls));
    }

    /**
     * @param string $url
     * @param int    $timeout
     *
     * @return array
     * @throws \ReflectionException
     */
    public function extractAttachments(string $url, int $timeout = 30): array
    {
        $res = $this->request($url, 'GET', [], $timeout);

        if ('' !== $res['http_error']) {
            return [
                'status' => 'error',
                'error'  => $res['http_error']
            ];
        }

        $body    = $res['http_body'];
        $charset = $this->detectCharset($body, $res['http_headers']);

        if ('UTF-8' !== $charset) {
            $body = mb_convert_encoding($body, 'UTF-8', $charset);
        }

        preg_match_all('/<img\s+[^>]*src=["\']([^"\']+)["\']/i', $body, $img_matches);
        preg_match_all('/<a\s+[^>]*href=["\']([^"\']+\.(?:pdf|zip|rar|docx|xlsx|pptx|exe|jpg|png|webp))["\']/i', $body, $file_matches);

        if (empty($img_matches[1]) && empty($file_matches[1])) {
            return [
                'http_url' => $url,
                'images'   => [],
                'files'    => [],
            ];
        }

        $parsed = parse_url($url);
        $domain = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');

        if (isset($parsed['port'])) {
            $domain .= ':' . $parsed['port'];
        }

        $path = $parsed['path'] ?? '/';

        if ('/' === $path || '' === $path) {
            $path = '';
        } else {
            if (str_ends_with($path, '/')) {
                $path = rtrim($path, '/');
            } else {
                $last_pos = strrpos($path, '/');

                if (false !== $last_pos) {
                    $path = substr($path, 0, $last_pos);
                } else {
                    $path = '';
                }
            }
        }

        $base   = $domain . $path . '/';
        $scheme = $parsed['scheme'] ?? 'http';

        $resolve = function (string $link) use ($domain, $base, $scheme): string
        {
            if (str_starts_with($link, '//')) {
                $link = $scheme . ':' . $link;
            }

            if (filter_var($link, FILTER_VALIDATE_URL)) {
                return $link;
            }

            if (str_starts_with($link, '/')) {
                return $domain . $link;
            }

            return $base . $link;
        };

        $images = array_values(array_unique(array_map($resolve, $img_matches[1] ?? [])));
        $files  = array_values(array_unique(array_map($resolve, $file_matches[1] ?? [])));

        return [
            'http_url' => $url,
            'images'   => $images,
            'files'    => $files,
        ];
    }

    /**
     * @param string $url
     * @param array  $params
     * @param int    $timeout
     *
     * @return array
     * @throws \ReflectionException
     */
    public function fetchJson(string $url, array $params = [], int $timeout = 30): array
    {
        $method = empty($params) ? 'GET' : 'POST';
        $res    = $this->request($url, $method, $params, $timeout, [], libHttp::CONTENT_TYPE_JSON);

        if ('' !== $res['http_error']) {
            return [
                'status' => 'error',
                'error'  => $res['http_error']
            ];
        }

        $data = json_decode($res['http_body'], true);

        if (is_null($data)) {
            return [
                'status'   => 'error',
                'error'    => 'Invalid JSON response',
                'response' => $res['http_body'],
            ];
        }

        $result = is_array($data) ? $data : ['response' => $data];

        unset($url, $params, $timeout, $method, $res, $data);
        return $result;
    }

    /**
     * @param string $url
     * @param string $save_to
     * @param int    $timeout
     *
     * @return array
     * @throws \ReflectionException
     */
    public function downloadFile(string $url, string $save_to, int $timeout = 30): array
    {
        $save_to  = $this->utils->securePath($save_to);
        $dir_path = dirname($save_to);

        if (!is_dir($dir_path)) {
            mkdir($dir_path, 0755, true);
        }

        $this->http->resetOptions();
        $this->http->setTimeout($timeout);

        $this->setDefaultHeaders();

        $this->http->fetch($url, $save_to, true);

        $code  = $this->http->getHttpCode();
        $error = $this->http->getHttpError();

        $success = (200 === $code && is_file($save_to));

        if ($success) {
            $result = [
                'status'    => 'success',
                'file_path' => $save_to,
                'file_size' => filesize($save_to),
            ];
        } else {
            $result = [
                'status'    => 'error',
                'error'     => $error,
                'http_code' => $code,
            ];
        }

        unset($url, $save_to, $timeout, $dir_path, $code, $error, $success);
        return $result;
    }
}