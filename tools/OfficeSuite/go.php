<?php

/**
 * Agent Doc Module - Unified Office Document Processing Module
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

namespace tools\OfficeSuite;

use modules\agent_core\core;
use Nervsys\Core\Factory;
use tools\OfficeSuite\lib\docxHandler;
use tools\OfficeSuite\lib\pptxHandler;
use tools\OfficeSuite\lib\xlsxHandler;

class go extends Factory
{
    public core $core;

    /**
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->core = core::new();
    }

    /**
     * Get handler instance by document type.
     *
     * @param string $type
     *
     * @return docxHandler|xlsxHandler|pptxHandler|null
     */
    public function getHandler(string $type): docxHandler|xlsxHandler|pptxHandler|null
    {
        $type       = strtolower($type);
        $validTypes = ['docx', 'xlsx', 'pptx'];

        if (false === in_array($type, $validTypes, true)) {
            return null;
        }

        $class  = '\\' . __NAMESPACE__ . '\\lib\\' . $type . 'Handler';
        $result = $class::new();

        unset($type, $validTypes, $class);
        return $result;
    }

    /**
     * Recursively secure all image paths inside data array.
     *
     * @param array $data
     *
     * @return array
     */
    private function secureDataPaths(array $data): array
    {
        foreach ($data as &$item) {
            if (is_array($item)) {
                if (isset($item['type']) && 'image' === $item['type'] && isset($item['content'])) {
                    $item['content'] = $this->core->securePath($item['content']);
                } else {
                    $item = $this->secureDataPaths($item);
                }
            }
        }

        unset($item);
        return $data;
    }

    // ---------- DOCX ----------
    public function readDocx(string $path): array
    {
        $path = $this->core->securePath($path);

        if (!is_file($path)) {
            return ['error' => "File not found: $path"];
        }

        $handler = $this->getHandler('docx');

        if (null === $handler) {
            return ['error' => 'DOCX handler not found.'];
        }

        $result = $handler->read($path);

        unset($path, $handler);
        return $result;
    }

    public function writeDocx(string $path, array $data, bool $append = false): array
    {
        $path = $this->core->securePath($path);
        $data = $this->secureDataPaths($data);

        $handler = $this->getHandler('docx');
        if (null === $handler) {
            return ['error' => 'DOCX handler not found.'];
        }

        $result = $handler->write($path, $data, $append);

        unset($path, $data, $append, $handler);
        return $result;
    }

    // ---------- XLSX ----------
    public function readXlsx(string $path): array
    {
        $path = $this->core->securePath($path);

        if (!is_file($path)) {
            return ['error' => "File not found: $path"];
        }

        $handler = $this->getHandler('xlsx');

        if (null === $handler) {
            return ['error' => 'XLSX handler not found.'];
        }

        $result = $handler->read($path);

        unset($path, $handler);
        return $result;
    }

    public function writeXlsx(string $path, array $data, bool $append = false): array
    {
        $path    = $this->core->securePath($path);
        $handler = $this->getHandler('xlsx');

        if (null === $handler) {
            return ['error' => 'XLSX handler not found.'];
        }

        $result = $handler->write($path, $data, $append);

        unset($path, $data, $append, $handler);
        return $result;
    }

    // ---------- PPTX ----------
    public function readPptx(string $path): array
    {
        $path = $this->core->securePath($path);

        if (!is_file($path)) {
            return ['error' => "File not found: $path"];
        }

        $handler = $this->getHandler('pptx');

        if (null === $handler) {
            return ['error' => 'PPTX handler not found.'];
        }

        $result = $handler->read($path);

        unset($path, $handler);
        return $result;
    }

    public function writePptx(string $path, array $data, bool $append = false): array
    {
        $path = $this->core->securePath($path);
        $data = $this->secureDataPaths($data);

        $handler = $this->getHandler('pptx');
        if (null === $handler) {
            return ['error' => 'PPTX handler not found.'];
        }

        $result = $handler->write($path, $data, $append);

        unset($path, $data, $append, $handler);
        return $result;
    }
}