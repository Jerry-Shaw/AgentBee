<?php

/**
 * XLSX Handler - Complete Native PHP Implementation (Read + Write + Append)
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

namespace modules\agent_skills\OfficeSuite\lib;

use modules\agent_core\core;
use Nervsys\Core\Factory;

class xlsxHandler extends Factory
{
    public core $core;

    public function __construct()
    {
        $this->core = core::new();
    }

    /**
     * Read all content from an XLSX file.
     */
    public function read(string $path): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return ['error' => 'Failed to open XLSX file as a zip archive.'];
        }

        // shared strings
        $shared_strings = [];
        $sst_content    = $zip->getFromName('xl/sharedStrings.xml');
        if (false !== $sst_content && '' !== $sst_content) {
            $reader = new \XMLReader();
            if (true === $reader->XML($sst_content)) {
                while ($reader->read()) {
                    if (\XMLReader::ELEMENT === $reader->nodeType && 't' === $reader->name) {
                        $shared_strings[] = $reader->readString() ?? '';
                    }
                }
            }
            $reader->close();
            unset($reader);
        }
        unset($sst_content);

        // Get all worksheet files and sort by numeric order
        $sheet_files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $filename, $matches)) {
                $sheet_num               = (int)$matches[1];
                $sheet_files[$sheet_num] = $filename;
            }
        }
        ksort($sheet_files); // ensure 1,2,3 order

        // Parse each worksheet
        $sheets_data = [];
        foreach ($sheet_files as $sheet_num => $filename) {
            $xml_content = $zip->getFromName($filename);
            if (false === $xml_content) {
                continue;
            }

            $cells  = [];
            $reader = new \XMLReader();
            if (true === $reader->XML($xml_content)) {
                while ($reader->read()) {
                    if (\XMLReader::ELEMENT === $reader->nodeType && 'c' === $reader->name) {
                        $ref  = null;
                        $type = null;
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                if ('r' === $reader->name) {
                                    $ref = $reader->value;
                                } elseif ('t' === $reader->name) {
                                    $type = $reader->value;
                                }
                            }
                            $reader->moveToElement();
                        }
                        if (null === $ref) {
                            continue;
                        }

                        $value = null;
                        while ($reader->read()) {
                            if (\XMLReader::ELEMENT === $reader->nodeType && 'v' === $reader->name) {
                                $raw_value = $reader->readString() ?? '';
                                if ('s' === $type) {
                                    $raw_value = $reader->readString() ?? '';
                                    if ('' === $raw_value) {
                                        $value = '';
                                    } else {
                                        $idx   = (int)$raw_value;
                                        $value = $shared_strings[$idx] ?? '';
                                    }
                                } elseif ('inlineStr' === $type) {
                                    $value = '';
                                } else {
                                    $value = is_numeric($raw_value) ? ((strpos($raw_value, '.') !== false) ? (float)$raw_value : (int)$raw_value) : $raw_value;
                                }
                                unset($raw_value);
                            } elseif (\XMLReader::END_ELEMENT === $reader->nodeType && 'c' === $reader->name) {
                                break;
                            }
                        }
                        $cells[] = ['ref' => $ref, 'value' => $value];
                        unset($ref, $type, $value);
                    }
                }
            }
            $reader->close();
            unset($reader, $xml_content);

            // convert to grid
            $grid = [];
            foreach ($cells as $cell) {
                if (false === preg_match('/^([A-Z]+)(\d+)$/', $cell['ref'], $match)) {
                    continue;
                }
                $col = $this->columnToIndex($match[1]);
                $row = (int)$match[2] - 1;
                if ($row < 0 || $col < 0) {
                    continue;
                }
                $grid[$row][$col] = $cell['value'];
                unset($match);
            }
            unset($cells);

            // fill missing cells with null
            if (!empty($grid)) {
                $max_row = max(array_keys($grid));
                $max_col = 0;
                foreach ($grid as $row_cells) {
                    $cols = array_keys($row_cells);
                    if (!empty($cols)) {
                        $max_col = max($max_col, max($cols));
                    }
                }
                for ($r = 0; $r <= $max_row; $r++) {
                    if (!isset($grid[$r])) {
                        $grid[$r] = [];
                    }
                    for ($c = 0; $c <= $max_col; $c++) {
                        if (!array_key_exists($c, $grid[$r])) {
                            $grid[$r][$c] = null;
                        }
                    }
                    ksort($grid[$r]);
                }
                ksort($grid);
            }

            $sheets_data[$sheet_num] = $grid;
            unset($grid);
        }
        $zip->close();
        unset($zip);

        // get sheet names
        $sheet_names   = $this->getSheetNames($path);
        $result_sheets = [];
        foreach ($sheets_data as $idx => $grid) {
            $name                 = $sheet_names[$idx] ?? 'Sheet' . $idx;
            $result_sheets[$name] = $grid;
        }

        $result = [
            'status' => 'success',
            'file'   => basename($path),
            'sheets' => $result_sheets
        ];

        unset($shared_strings, $sheets_data, $sheet_names, $result_sheets);
        return $result;
    }

    /**
     * Write new XLSX file (overwrite).
     */
    public function writeNew(string $path, array $data): array
    {
        $temp_dir = null;
        try {
            $sheets = $this->normalizeSheets($data);
            if (empty($sheets)) {
                return ['error' => 'No data to write.'];
            }

            $temp_dir = $this->core->agent_config['workspace_path'] . '/OfficeTemp/xlsx_' . uniqid('', true);
            if (!mkdir($temp_dir, 0755, true)) {
                return ['error' => 'Failed to create temp dir'];
            }
            mkdir($temp_dir . '/xl/worksheets', 0755, true);
            mkdir($temp_dir . '/xl/_rels', 0755, true);
            mkdir($temp_dir . '/_rels', 0755, true);

            // collect shared strings
            $shared_strings = [];
            $string_index   = [];
            foreach ($sheets as &$sheet) {
                foreach ($sheet['rows'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    foreach ($row as $cell) {
                        if (null === $cell || '' === $cell) {
                            continue;
                        }
                        if (is_numeric($cell)) {
                            continue;
                        }
                        $str = (string)$cell;
                        if (!isset($string_index[$str])) {
                            $string_index[$str] = count($shared_strings);
                            $shared_strings[]   = $str;
                        }
                        unset($str);
                    }
                }
            }
            unset($sheet);

            $this->writeSharedStrings($temp_dir, $shared_strings);
            $sheet_files = [];
            foreach ($sheets as $idx => $sheet) {
                $sheet_idx  = $idx + 1;
                $sheet_file = 'sheet' . $sheet_idx . '.xml';
                $this->writeWorksheet($temp_dir, $sheet_file, $sheet['rows'], $string_index);
                $sheet_files[] = [
                    'id'   => $sheet_idx,
                    'name' => $sheet['name'],
                    'file' => $sheet_file
                ];
                unset($sheet);
            }

            $this->writeWorkbook($temp_dir, $sheet_files);
            $this->writeWorkbookRels($temp_dir, $sheet_files, !empty($shared_strings));
            $this->writeContentTypes($temp_dir, $sheet_files, !empty($shared_strings));
            $this->writeRootRels($temp_dir);

            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            $zip = new \ZipArchive();
            if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
                $this->rrmdir($temp_dir);
                unset($temp_dir, $zip);
                return ['error' => 'Failed to create ZIP archive'];
            }
            $this->addDirToZip($zip, $temp_dir, '');
            $zip->close();
            unset($zip);

            $this->rrmdir($temp_dir);
            $temp_dir = null;

            $result = [
                'status'       => 'success',
                'path'         => $path,
                'sheets_count' => count($sheets),
                'message'      => 'XLSX written successfully.'
            ];

            unset($sheets, $shared_strings, $string_index, $sheet_files);
            return $result;
        } catch (\Exception $e) {
            if (null !== $temp_dir && is_dir($temp_dir)) {
                $this->rrmdir($temp_dir);
            }
            return ['error' => 'Failed to write XLSX: ' . $e->getMessage()];
        }
    }

    /**
     * Append rows to an existing sheet (create sheet if not exists).
     */
    public function appendRows(string $path, string $sheet_name, array $rows): array
    {
        if (false === file_exists($path)) {
            return $this->writeNew($path, [['name' => $sheet_name, 'rows' => $rows]]);
        }

        $existing = $this->read($path);
        if (isset($existing['error'])) {
            return $existing;
        }

        $sheets = [];
        foreach ($existing['sheets'] as $name => $grid) {
            $sheets[] = ['name' => $name, 'rows' => $grid];
        }

        $found = false;
        foreach ($sheets as &$sheet) {
            if ($sheet['name'] === $sheet_name) {
                $sheet['rows'] = array_merge($sheet['rows'], $rows);
                $found         = true;
                break;
            }
        }
        unset($sheet);

        if (false === $found) {
            $sheets[] = ['name' => $sheet_name, 'rows' => $rows];
        }

        $result = $this->writeNew($path, $sheets);
        unset($existing, $sheets, $found);
        return $result;
    }

    // ------------------------------------------------------------------------
    // Private helper methods (unchanged but without error suppression)
    // ------------------------------------------------------------------------

    private function getSheetNames(string $path): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return [];
        }
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        if (false === $workbook) {
            return [];
        }

        $names  = [];
        $reader = new \XMLReader();
        if (true === $reader->XML($workbook)) {
            while ($reader->read()) {
                if (\XMLReader::ELEMENT === $reader->nodeType && 'sheet' === $reader->name) {
                    if ($reader->hasAttributes) {
                        while ($reader->moveToNextAttribute()) {
                            if ('name' === $reader->name) {
                                $names[] = $reader->value;
                                break;
                            }
                        }
                        $reader->moveToElement();
                    }
                }
            }
            $reader->close();
        }
        unset($reader, $workbook);

        $result = [];
        foreach ($names as $idx => $name) {
            $result[$idx + 1] = $name;
        }
        unset($names);
        return $result;
    }

    private function normalizeSheets(array $data): array
    {
        if (isset($data[0]) && is_array($data[0]) && false === isset($data[0]['name']) && false === isset($data[0]['rows'])) {
            return [['name' => 'Sheet1', 'rows' => $this->normalizeRows($data)]];
        }
        $sheets = [];
        foreach ($data as $item) {
            if (is_array($item) && isset($item['rows'])) {
                $name     = $item['name'] ?? ('Sheet' . (count($sheets) + 1));
                $sheets[] = ['name' => $name, 'rows' => $this->normalizeRows($item['rows'])];
            } else {
                $sheets[] = ['name' => 'Sheet' . (count($sheets) + 1), 'rows' => $this->normalizeRows($item)];
            }
        }
        return $sheets;
    }

    private function normalizeRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        $max_cols = 0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $cnt = count($row);
                if ($cnt > $max_cols) {
                    $max_cols = $cnt;
                }
            } else {
                $max_cols = max($max_cols, 1);
            }
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (false === is_array($row)) {
                $row = [$row];
            }
            while (count($row) < $max_cols) {
                $row[] = null;
            }
            $normalized[] = $row;
        }
        return $normalized;
    }

    private function writeSharedStrings(string $temp_dir, array $strings): void
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('sst');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->writeAttribute('count', count($strings));
        $xml->writeAttribute('uniqueCount', count($strings));
        foreach ($strings as $str) {
            $xml->startElement('si');
            $xml->startElement('t');
            $xml->writeRaw(htmlspecialchars($str, ENT_XML1, 'UTF-8'));
            $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();
        file_put_contents($temp_dir . '/xl/sharedStrings.xml', $xml->flush());
        $xml->flush();
        unset($xml);
    }

    private function writeWorksheet(string $temp_dir, string $sheet_file, array $rows, array $string_index): void
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('worksheet');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->startElement('sheetData');

        $row_num = 1;
        foreach ($rows as $row) {
            $xml->startElement('row');
            $xml->writeAttribute('r', $row_num);
            $col_num = 0;
            foreach ($row as $cell) {
                $col_letter = $this->indexToColumn($col_num);
                if (null === $cell || '' === $cell) {
                    $col_num++;
                    continue;
                }
                $xml->startElement('c');
                $xml->writeAttribute('r', $col_letter . $row_num);

                if (is_bool($cell)) {
                    $xml->writeAttribute('t', 'n');
                    $xml->startElement('v');
                    $xml->text($cell ? '1' : '0');
                    $xml->endElement();
                } elseif (is_numeric($cell)) {
                    $xml->writeAttribute('t', 'n');
                    $xml->startElement('v');
                    $xml->text((string)$cell);
                    $xml->endElement();
                } elseif (is_string($cell)) {
                    if (isset($string_index[$cell])) {
                        $xml->writeAttribute('t', 's');
                        $xml->startElement('v');
                        $xml->text((string)$string_index[$cell]);
                        $xml->endElement();
                    } else {
                        $xml->writeAttribute('t', 'inlineStr');
                        $xml->startElement('is');
                        $xml->startElement('t');
                        $xml->writeRaw(htmlspecialchars($cell, ENT_XML1, 'UTF-8'));
                        $xml->endElement();
                        $xml->endElement();
                    }
                }
                $xml->endElement(); // c
                $col_num++;
            }
            $xml->endElement(); // row
            $row_num++;
        }

        $xml->endElement(); // sheetData
        $xml->endElement(); // worksheet
        $xml->endDocument();
        file_put_contents($temp_dir . '/xl/worksheets/' . $sheet_file, $xml->flush());
        $xml->flush();
        unset($xml);
    }

    private function writeWorkbook(string $temp_dir, array $sheets): void
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('workbook');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->writeAttribute('xmlns:r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $xml->startElement('sheets');
        foreach ($sheets as $idx => $sheet) {
            $xml->startElement('sheet');
            $xml->writeAttribute('name', $sheet['name']);
            $xml->writeAttribute('sheetId', $idx + 1);
            $xml->writeAttribute('r:id', 'rId' . ($idx + 1));
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        file_put_contents($temp_dir . '/xl/workbook.xml', $xml->flush());
        $xml->flush();
        unset($xml);
    }

    private function writeWorkbookRels(string $temp_dir, array $sheets, bool $has_shared_strings): void
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('Relationships');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($sheets as $idx => $sheet) {
            $xml->startElement('Relationship');
            $xml->writeAttribute('Id', 'rId' . ($idx + 1));
            $xml->writeAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet');
            $xml->writeAttribute('Target', 'worksheets/' . $sheet['file']);
            $xml->endElement();
        }
        if ($has_shared_strings) {
            $xml->startElement('Relationship');
            $xml->writeAttribute('Id', 'rId' . (count($sheets) + 1));
            $xml->writeAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings');
            $xml->writeAttribute('Target', 'sharedStrings.xml');
            $xml->endElement();
        }
        $xml->endElement();
        file_put_contents($temp_dir . '/xl/_rels/workbook.xml.rels', $xml->flush());
        $xml->flush();
        unset($xml);
    }

    private function writeContentTypes(string $temp_dir, array $sheets, bool $has_shared_strings): void
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('Types');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/package/2006/content-types');
        $xml->startElement('Default');
        $xml->writeAttribute('Extension', 'xml');
        $xml->writeAttribute('ContentType', 'application/xml');
        $xml->endElement();
        $xml->startElement('Default');
        $xml->writeAttribute('Extension', 'rels');
        $xml->writeAttribute('ContentType', 'application/vnd.openxmlformats-package.relationships+xml');
        $xml->endElement();
        foreach ($sheets as $sheet) {
            $xml->startElement('Override');
            $xml->writeAttribute('PartName', '/xl/worksheets/' . $sheet['file']);
            $xml->writeAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');
            $xml->endElement();
        }
        $xml->startElement('Override');
        $xml->writeAttribute('PartName', '/xl/workbook.xml');
        $xml->writeAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml');
        $xml->endElement();
        if ($has_shared_strings) {
            $xml->startElement('Override');
            $xml->writeAttribute('PartName', '/xl/sharedStrings.xml');
            $xml->writeAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml');
            $xml->endElement();
        }
        $xml->endElement();
        file_put_contents($temp_dir . '/[Content_Types].xml', $xml->flush());
        $xml->flush();
        unset($xml);
    }

    private function writeRootRels(string $temp_dir): void
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('Relationships');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $xml->startElement('Relationship');
        $xml->writeAttribute('Id', 'rId1');
        $xml->writeAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument');
        $xml->writeAttribute('Target', 'xl/workbook.xml');
        $xml->endElement();
        $xml->endElement();
        file_put_contents($temp_dir . '/_rels/.rels', $xml->flush());
        $xml->flush();
        unset($xml);
    }

    private function indexToColumn(int $col): string
    {
        $col++;
        $result = '';
        while ($col > 0) {
            $col--;
            $result = chr(65 + ($col % 26)) . $result;
            $col    = intdiv($col, 26);
        }
        return $result;
    }

    private function columnToIndex(string $col): int
    {
        $index = 0;
        for ($i = 0; $i < strlen($col); $i++) {
            $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    private function addDirToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        if (false === is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        foreach ($files as $file) {
            if ('.' === $file || '..' === $file) {
                continue;
            }
            $full     = $dir . '/' . $file;
            $zip_path = $prefix . $file;
            if (is_dir($full)) {
                $this->addDirToZip($zip, $full, $zip_path . '/');
            } else {
                $zip->addFile($full, $zip_path);
            }
            unset($full, $zip_path);
        }
        unset($files);
    }

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