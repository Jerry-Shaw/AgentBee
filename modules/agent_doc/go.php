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

namespace modules\agent_doc;

use Nervsys\Core\Factory;

class go extends Factory
{
    /**
     * Get handler instance by document type.
     *
     * @param string $type
     *
     * @return object|null
     */
    public function getHandler(string $type): ?object
    {
        $type       = strtolower($type);
        $validTypes = ['docx', 'xlsx', 'pptx'];
        if (!in_array($type, $validTypes)) {
            return null;
        }
        // Build class name with sub‑namespace
        $class = "\\modules\\agent_doc\\app\\{$type}Handler";
        return $class::new();
    }

    // ---------- DOCX ----------

    /**
     * Read DOCX document content.
     *
     * @param string $path
     *
     * @return array
     */
    public function readDocx(string $path): array
    {
        $handler = $this->getHandler('docx');
        if (!$handler) {
            return ['error' => 'DOCX handler not found.'];
        }
        return $handler->read($path);
    }

    /**
     * Write DOCX document from paragraphs.
     *
     * @param string $path
     * @param array  $data
     *
     * @return array
     */
    public function writeDocx(string $path, array $data): array
    {
        $handler = $this->getHandler('docx');
        if (!$handler) {
            return ['error' => 'DOCX handler not found.'];
        }
        return $handler->write($path, $data);
    }

    // ---------- XLSX ----------

    /**
     * Read XLSX spreadsheet content.
     *
     * @param string $path
     *
     * @return array
     */
    public function readXlsx(string $path): array
    {
        $handler = $this->getHandler('xlsx');
        if (!$handler) {
            return ['error' => 'XLSX handler not found.'];
        }
        return $handler->read($path);
    }

    /**
     * Write XLSX spreadsheet from data.
     *
     * @param string $path
     * @param array  $data
     *
     * @return array
     */
    public function writeXlsx(string $path, array $data): array
    {
        $handler = $this->getHandler('xlsx');
        if (!$handler) {
            return ['error' => 'XLSX handler not found.'];
        }
        return $handler->write($path, $data);
    }

    // ---------- PPTX ----------

    /**
     * Read PPTX presentation content.
     *
     * @param string $path
     *
     * @return array
     */
    public function readPptx(string $path): array
    {
        $handler = $this->getHandler('pptx');
        if (!$handler) {
            return ['error' => 'PPTX handler not found.'];
        }
        return $handler->read($path);
    }

    /**
     * Write PPTX presentation from slides.
     *
     * @param string $path
     * @param array  $data
     *
     * @return array
     */
    public function writePptx(string $path, array $data): array
    {
        $handler = $this->getHandler('pptx');
        if (!$handler) {
            return ['error' => 'PPTX handler not found.'];
        }
        return $handler->write($path, $data);
    }
}