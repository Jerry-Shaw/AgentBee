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

namespace modules\agent_toolsets\OfficeSuite;

use modules\agent_core\lib\utils;
use modules\agent_toolsets\OfficeSuite\lib\docxHandler;
use modules\agent_toolsets\OfficeSuite\lib\pptxHandler;
use modules\agent_toolsets\OfficeSuite\lib\xlsxHandler;
use Nervsys\Core\Factory;

class go extends Factory
{
    public utils  $utils;
    private array $docx_buffer    = [];
    private array $pptx_temp_dirs = [];
    private array $docx_temp_dirs = [];

    public function __construct()
    {
        $this->utils = utils::new();
    }

    public function __destruct()
    {
        foreach ($this->pptx_temp_dirs as $dir) {
            if (is_dir($dir)) {
                $this->utils->libFileIO->delDir($dir);
            }
        }
        foreach ($this->docx_temp_dirs as $dir) {
            if (is_dir($dir)) {
                $this->utils->libFileIO->delDir($dir);
            }
        }
    }

    // ---------- DOCX atomic tools ----------

    /**
     * Read DOCX file content (text and images).
     *
     * @throws \ReflectionException
     */
    public function readDocx(string $path): array
    {
        $path = $this->utils->securePath($path);

        if (!is_file($path)) {
            return ['error' => 'File not found: ' . $path];
        }

        $result = docxHandler::new()->read($path);

        if (isset($result['images_temp_dir']) && is_dir($result['images_temp_dir'])) {
            $this->docx_temp_dirs[] = $result['images_temp_dir'];
        }

        unset($path);
        return $result;
    }

    /**
     * Initialize document buffer.
     */
    public function initDocx(): array
    {
        $this->docx_buffer = [];
        return ['status' => 'success', 'message' => 'Document initialized'];
    }

    /**
     * Add a heading.
     */
    public function addDocxHeading(int $level, string $text): array
    {
        $text  = trim($text);
        $level = max(1, min(6, $level));

        if ('' === $text) {
            return ['error' => 'Heading text cannot be empty'];
        }

        $this->docx_buffer[] = [
            'type'  => 'heading',
            'level' => $level,
            'text'  => $text,
        ];

        $result = ['status' => 'success', 'message' => 'Heading added'];

        unset($level, $text);
        return $result;
    }

    /**
     * Add a paragraph with full style support.
     */
    public function addDocxParagraph(
        string  $text,
        bool    $bold = false,
        bool    $italic = false,
        ?int    $font_size = null,
        string  $align = 'left',
        ?int    $first_line_indent = null,
        ?float  $line_spacing = null,
        ?int    $before_spacing = null,
        ?int    $after_spacing = null,
        ?string $font_family = null,
        ?string $font_family_east_asia = null,
        ?string $color = null,
        ?string $underline = null
    ): array
    {
        $text = trim($text);

        if ('' === $text) {
            return ['error' => 'Paragraph text cannot be empty'];
        }
        if (!in_array($align, ['left', 'center', 'right', 'justify'], true)) {
            $align = 'left';
        }
        if (null !== $font_size && (6 > $font_size || 72 < $font_size)) {
            $font_size = 12;
        }
        if (null !== $first_line_indent && 0 > $first_line_indent) {
            $first_line_indent = null;
        }
        if (null !== $line_spacing && 0 >= $line_spacing) {
            $line_spacing = null;
        }
        if (null !== $before_spacing && 0 > $before_spacing) {
            $before_spacing = null;
        }
        if (null !== $after_spacing && 0 > $after_spacing) {
            $after_spacing = null;
        }
        if (null !== $color && false === preg_match('/^[0-9A-Fa-f]{6}$/', $color)) {
            $color = null;
        }
        if (null !== $underline && !in_array($underline, ['single', 'double', 'dash', 'dot', 'wave'], true)) {
            $underline = null;
        }

        $this->docx_buffer[] = [
            'type'               => 'paragraph',
            'text'               => $text,
            'bold'               => $bold,
            'italic'             => $italic,
            'fontSize'           => $font_size,
            'align'              => $align,
            'firstLineIndent'    => $first_line_indent,
            'lineSpacing'        => $line_spacing,
            'beforeSpacing'      => $before_spacing,
            'afterSpacing'       => $after_spacing,
            'fontFamily'         => $font_family,
            'fontFamilyEastAsia' => $font_family_east_asia,
            'color'              => $color,
            'underline'          => $underline,
        ];

        $result = ['status' => 'success', 'message' => 'Paragraph added'];

        unset($text, $bold, $italic, $font_size, $align, $first_line_indent, $line_spacing, $before_spacing, $after_spacing, $font_family, $font_family_east_asia, $color, $underline);
        return $result;
    }

    /**
     * Add an image.
     */
    public function addDocxImage(string $path, int $width = 200, ?int $height = null, string $align = 'center'): array
    {
        $path = $this->utils->securePath($path);

        if (!file_exists($path)) {
            return ['error' => 'Image not found: ' . $path];
        }

        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'center';
        }

        $this->docx_buffer[] = [
            'type'   => 'image',
            'path'   => $path,
            'width'  => $width,
            'height' => $height,
            'align'  => $align,
        ];

        $result = ['status' => 'success', 'message' => 'Image added'];

        unset($path, $width, $height, $align);
        return $result;
    }

    /**
     * Append structured content to existing DOCX file.
     *
     * @throws \ReflectionException
     */
    public function appendDocx(string $path, array $items): array
    {
        $path = $this->utils->securePath($path);

        if (empty($items)) {
            return ['error' => 'No content to append.'];
        }

        $result = docxHandler::new()->appendStructured($path, $items);

        unset($path, $items);
        return $result;
    }

    /**
     * Save current buffer to DOCX file.
     *
     * @throws \ReflectionException
     */
    public function saveDocx(string $path): array
    {
        if (empty($this->docx_buffer)) {
            return ['error' => 'No content to save. Call initDocx() first.'];
        }

        $path   = $this->utils->securePath($path);
        $result = docxHandler::new()->writeStructured($path, $this->docx_buffer);

        unset($path);
        return $result;
    }

    // ---------- XLSX atomic tools ----------

    /**
     * Read XLSX file content (all sheets).
     *
     * @throws \ReflectionException
     */
    public function readXlsx(string $path): array
    {
        $path = $this->utils->securePath($path);

        if (!is_file($path)) {
            return ['error' => 'File not found: ' . $path];
        }

        $result = xlsxHandler::new()->read($path);

        unset($path);
        return $result;
    }

    /**
     * Write XLSX file (overwrite). Data can be:
     *   - 2D array (e.g. [["A","B"],["C","D"]]) -> sheet name "Sheet1"
     *   - array of ['name'=>..., 'rows'=>...] for multiple sheets
     *
     * @throws \ReflectionException
     */
    public function writeXlsx(string $path, array $data, ?string $sheet_name = null): array
    {
        $path = $this->utils->securePath($path);

        if (null !== $sheet_name) {
            $is_multi_sheet = false;

            if (!empty($data) && is_array($data[0]) && isset($data[0]['name']) && isset($data[0]['rows'])) {
                $is_multi_sheet = true;
            }

            if (!$is_multi_sheet) {
                $data = [['name' => $sheet_name, 'rows' => $data]];
            }
        }

        $result = xlsxHandler::new()->writeNew($path, $data);

        unset($path, $data, $sheet_name);
        return $result;
    }

    /**
     * Append rows to an existing sheet (create sheet if not exists).
     *
     * @throws \ReflectionException
     */
    public function appendXlsxRows(string $path, string $sheet_name, array $rows): array
    {
        $path   = $this->utils->securePath($path);
        $result = xlsxHandler::new()->appendRows($path, $sheet_name, $rows);

        unset($path, $sheet_name, $rows);
        return $result;
    }

    // ---------- PPTX tools (read-only) ----------

    /**
     * Read PPTX file content (text and images).
     *
     * @throws \ReflectionException
     */
    public function readPptx(string $path): array
    {
        $path = $this->utils->securePath($path);

        if (!is_file($path)) {
            return ['error' => 'File not found: ' . $path];
        }

        $result = pptxHandler::new()->read($path);

        if (isset($result['images_temp_dir']) && is_dir($result['images_temp_dir'])) {
            $this->pptx_temp_dirs[] = $result['images_temp_dir'];
        }

        unset($path);
        return $result;
    }
}