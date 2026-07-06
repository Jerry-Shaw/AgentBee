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
    private array $pptx_buffer    = [];
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
                $this->rrmdir($dir);
            }
        }
        foreach ($this->docx_temp_dirs as $dir) {
            if (is_dir($dir)) {
                $this->rrmdir($dir);
            }
        }
    }

    // ---------- DOCX atomic tools ----------

    /**
     * Read DOCX file content (text and images).
     */
    public function readDocx(string $path): array
    {
        $path = $this->utils->securePath($path);
        if (!is_file($path)) {
            return ['error' => 'File not found: ' . $path];
        }
        $handler = docxHandler::new();
        if (null === $handler) {
            return ['error' => 'DOCX handler not found.'];
        }
        $result = $handler->read($path);
        if (isset($result['images_temp_dir']) && is_dir($result['images_temp_dir'])) {
            $this->docx_temp_dirs[] = $result['images_temp_dir'];
        }
        unset($path, $handler);
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
        $level = max(1, min(6, $level));
        $text  = trim($text);
        if ('' === $text) {
            return ['error' => 'Heading text cannot be empty'];
        }
        $this->docx_buffer[] = [
            'type'  => 'heading',
            'level' => $level,
            'text'  => $text,
        ];
        $result              = ['status' => 'success', 'message' => 'Heading added'];
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
        $allowed_align = ['left', 'center', 'right', 'justify'];
        if (!in_array($align, $allowed_align, true)) {
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
        $allowed_underline = ['single', 'double', 'dash', 'dot', 'wave'];
        if (null !== $underline && !in_array($underline, $allowed_underline, true)) {
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
        $result              = ['status' => 'success', 'message' => 'Paragraph added'];
        unset($text, $bold, $italic, $font_size, $align, $first_line_indent, $line_spacing, $before_spacing, $after_spacing, $font_family, $font_family_east_asia, $color, $underline, $allowed_align, $allowed_underline);
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
        $allowed_align = ['left', 'center', 'right'];
        if (!in_array($align, $allowed_align, true)) {
            $align = 'center';
        }
        $this->docx_buffer[] = [
            'type'   => 'image',
            'path'   => $path,
            'width'  => $width,
            'height' => $height,
            'align'  => $align,
        ];
        $result              = ['status' => 'success', 'message' => 'Image added'];
        unset($path, $width, $height, $align, $allowed_align);
        return $result;
    }

    /**
     * Append structured content to existing DOCX file.
     */
    public function appendDocx(string $path, array $items): array
    {
        $path = $this->utils->securePath($path);
        if (empty($items)) {
            return ['error' => 'No content to append.'];
        }
        $handler = docxHandler::new();
        if (null === $handler) {
            return ['error' => 'DOCX handler not found.'];
        }
        $result = $handler->appendStructured($path, $items);
        unset($path, $items, $handler);
        return $result;
    }

    /**
     * Save current buffer to DOCX file.
     */
    public function saveDocx(string $path): array
    {
        if (empty($this->docx_buffer)) {
            return ['error' => 'No content to save. Call initDocx() first.'];
        }
        $path    = $this->utils->securePath($path);
        $handler = docxHandler::new();
        if (null === $handler) {
            return ['error' => 'DOCX handler not found.'];
        }
        $result = $handler->writeStructured($path, $this->docx_buffer);
        unset($path, $handler);
        return $result;
    }

    // ---------- XLSX atomic tools ----------

    /**
     * Read XLSX file content (all sheets).
     */
    public function readXlsx(string $path): array
    {
        $path = $this->utils->securePath($path);
        if (!is_file($path)) {
            return ['error' => 'File not found: ' . $path];
        }
        $handler = xlsxHandler::new();
        $result  = $handler->read($path);
        unset($path, $handler);
        return $result;
    }

    /**
     * Write XLSX file (overwrite). Data can be:
     *   - 2D array (e.g. [["A","B"],["C","D"]]) -> sheet name "Sheet1"
     *   - array of ['name'=>..., 'rows'=>...] for multiple sheets
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
        $handler = xlsxHandler::new();
        $result  = $handler->writeNew($path, $data);
        unset($path, $data, $sheet_name, $handler);
        return $result;
    }

    /**
     * Append rows to an existing sheet (create sheet if not exists).
     */
    public function appendXlsxRows(string $path, string $sheet_name, array $rows): array
    {
        $path    = $this->utils->securePath($path);
        $handler = xlsxHandler::new();
        $result  = $handler->appendRows($path, $sheet_name, $rows);
        unset($path, $sheet_name, $rows, $handler);
        return $result;
    }

    // ---------- PPTX atomic tools ----------

    /**
     * Read PPTX file content (text and images).
     */
    public function readPptx(string $path): array
    {
        $path = $this->utils->securePath($path);
        if (false === is_file($path)) {
            return ['error' => 'File not found: ' . $path];
        }
        $handler = pptxHandler::new();
        $result  = $handler->read($path);
        if (isset($result['images_temp_dir']) && is_dir($result['images_temp_dir'])) {
            $this->pptx_temp_dirs[] = $result['images_temp_dir'];
        }
        unset($path, $handler);
        return $result;
    }

    /**
     * Initialize presentation buffer.
     */
    public function initPptx(): array
    {
        $this->pptx_buffer = [];
        return ['status' => 'success', 'message' => 'Presentation initialized'];
    }

    /**
     * Add a slide.
     *
     * @param string      $title        Slide title (optional)
     * @param array       $paragraphs   Array of paragraph strings
     * @param string|null $image_path   Absolute path to image file (optional)
     * @param int         $image_width  Width in EMU (default 2540000 ≈ 2.67 inches)
     * @param int         $image_height Height in EMU (default 1905000 ≈ 2 inches)
     * @param int         $image_x      X position in EMU (default 8000000)
     * @param int         $image_y      Y position in EMU (default 500000)
     *
     * @return array
     */
    public function addPptxSlide(
        string  $title = '',
        array   $paragraphs = [],
        ?string $image_path = null,
        int     $image_width = 2540000,
        int     $image_height = 1905000,
        int     $image_x = 8000000,
        int     $image_y = 500000
    ): array
    {
        $title = trim($title);
        if ('' === $title && empty($paragraphs) && null === $image_path) {
            return ['error' => 'Slide must have at least title, paragraphs, or image'];
        }
        if (null !== $image_path) {
            $image_path = $this->utils->securePath($image_path);
            if (false === file_exists($image_path)) {
                return ['error' => 'Image not found: ' . $image_path];
            }
        }
        $this->pptx_buffer[] = [
            'title'        => $title,
            'paragraphs'   => $paragraphs,
            'image_path'   => $image_path,
            'image_width'  => $image_width,
            'image_height' => $image_height,
            'image_x'      => $image_x,
            'image_y'      => $image_y,
        ];
        $result              = ['status' => 'success', 'message' => 'Slide added'];
        unset($title, $paragraphs, $image_path, $image_width, $image_height, $image_x, $image_y);
        return $result;
    }

    /**
     * Append slides to an existing PPTX file (preserves original content and images).
     *
     * @param string $path   Target file path
     * @param array  $slides Array of new slides (same format as addPptxSlide)
     *
     * @return array
     * @throws \ReflectionException
     */
    public function appendPptx(string $path, array $slides): array
    {
        $path = $this->utils->securePath($path);
        if (empty($slides)) {
            return ['error' => 'No slides to append.'];
        }
        $handler = pptxHandler::new();
        if (null === $handler) {
            return ['error' => 'PPTX handler not found.'];
        }
        $result = $handler->append($path, $slides);
        unset($path, $slides, $handler);
        return $result;
    }

    /**
     * Save current buffer to PPTX file.
     */
    public function savePptx(string $path): array
    {
        if (empty($this->pptx_buffer)) {
            return ['error' => 'No slides to save. Call initPptx() first.'];
        }
        $path    = $this->utils->securePath($path);
        $handler = pptxHandler::new();
        if (null === $handler) {
            return ['error' => 'PPTX handler not found.'];
        }
        $result = $handler->writeStructured($path, $this->pptx_buffer);
        unset($path, $handler);
        return $result;
    }

    /**
     * Recursively remove directory.
     *
     * @param string $dir
     *
     * @return void
     */
    private function rrmdir(string $dir): void
    {
        if (false === is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        foreach ($files as $file) {
            if ('.' === $file || '..' === $file) {
                continue;
            }
            $full = $dir . '/' . $file;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                unlink($full);
            }
            unset($full);
        }
        rmdir($dir);
        unset($files);
    }
}