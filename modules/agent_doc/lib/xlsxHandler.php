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

namespace modules\agent_doc\lib;

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
     * Read all content from an .xlsx file.
     *
     * @param string $path
     *
     * @return array
     */
    public function read(string $path): array
    {
        if (!file_exists($path)) {
            return ['error' => "File not found: $path"];
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return ['error' => "Failed to open XLSX file as a zip archive."];
        }

        // Extract shared strings
        $sharedStrings = [];
        $sstContent    = $zip->getFromName('xl/sharedStrings.xml');
        if (false !== $sstContent && '' !== $sstContent) {
            $reader = new \XMLReader();
            if (true === $reader->XML($sstContent)) {
                while ($reader->read()) {
                    if ($reader->nodeType == \XMLReader::ELEMENT && 't' === $reader->name) {
                        $sharedStrings[] = $reader->readString() ?? '';
                    }
                }
            }
            $reader->close();
            unset($reader);
        }
        unset($sstContent);

        // Parse worksheets
        $sheetsData = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (!preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $filename, $matches)) {
                continue;
            }
            $sheetNum = (int)$matches[1];
            unset($matches);

            $xmlContent = $zip->getFromName($filename);
            if (false === $xmlContent) {
                continue;
            }

            $cells  = [];
            $reader = new \XMLReader();
            if (true === $reader->XML($xmlContent)) {
                while ($reader->read()) {
                    if ($reader->nodeType == \XMLReader::ELEMENT && 'c' === $reader->name) {
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
                        if (!$ref) {
                            continue;
                        }

                        $value = null;
                        while ($reader->read()) {
                            if ($reader->nodeType == \XMLReader::ELEMENT && 'v' === $reader->name) {
                                $rawValue = $reader->readString() ?? '';
                                if ('s' === $type) {
                                    $idx = (int)$rawValue;
                                    if (isset($sharedStrings[$idx])) {
                                        $value = $sharedStrings[$idx];
                                    } else {
                                        $value = '';
                                    }
                                } elseif ('inlineStr' === $type) {
                                    $value = '';
                                } else {
                                    if (is_numeric($rawValue)) {
                                        $value = (strpos($rawValue, '.') !== false) ? (float)$rawValue : (int)$rawValue;
                                    } else {
                                        $value = $rawValue;
                                    }
                                }
                                unset($rawValue);
                            } elseif ($reader->nodeType == \XMLReader::END_ELEMENT && 'c' === $reader->name) {
                                break;
                            }
                        }
                        $cells[] = ['ref' => $ref, 'value' => $value];
                        unset($ref, $type, $value);
                    }
                }
            }
            $reader->close();
            unset($reader, $xmlContent);

            // Convert sparse cells to 2D grid with robust bounds
            $grid = [];
            foreach ($cells as $cell) {
                if (!preg_match('/^([A-Z]+)(\d+)$/', $cell['ref'], $match)) {
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

            // Fill missing cells with null, using global max column across all rows
            if (!empty($grid)) {
                $maxRow = max(array_keys($grid));
                // Determine the maximum column index that appears in any row
                $maxCol = 0;
                foreach ($grid as $rowCells) {
                    $cols = array_keys($rowCells);
                    if (!empty($cols)) {
                        $maxCol = max($maxCol, max($cols));
                    }
                }
                for ($r = 0; $r <= $maxRow; $r++) {
                    if (!isset($grid[$r])) {
                        $grid[$r] = [];
                    }
                    for ($c = 0; $c <= $maxCol; $c++) {
                        if (!array_key_exists($c, $grid[$r])) {
                            $grid[$r][$c] = null;
                        }
                    }
                    ksort($grid[$r]);
                }
                ksort($grid);
            }

            $sheetsData[$sheetNum] = $grid;
            unset($grid);
        }
        $zip->close();
        unset($zip);

        // Get sheet names
        $sheetNames   = $this->getSheetNames($path);
        $resultSheets = [];
        foreach ($sheetsData as $idx => $grid) {
            $name                = $sheetNames[$idx] ?? "Sheet$idx";
            $resultSheets[$name] = $grid;
        }

        $result = [
            'status' => 'success',
            'file'   => basename($path),
            'sheets' => $resultSheets
        ];

        unset($sharedStrings, $sheetsData, $sheetNames, $resultSheets);
        return $result;
    }

    /**
     * Write an .xlsx file from data.
     *
     * @param string $path
     * @param array  $data
     * @param bool   $append
     *
     * @return array
     */
    public function write(string $path, array $data, bool $append = false): array
    {
        if ($append && file_exists($path)) {
            return $this->appendToExisting($path, $data);
        }
        return $this->writeNew($path, $data);
    }

    /**
     * Write new file (overwrite).
     *
     * @param string $path
     * @param array  $data
     *
     * @return array
     */
    private function writeNew(string $path, array $data): array
    {
        $tempDir = null;
        try {
            $allSheets = $this->normalizeSheets($data);
            if (empty($allSheets)) {
                return ['error' => 'No data to write.'];
            }

            $tempDir = $this->core->agent_config['agent_tools']['workspace_path'] . '/temp/xlsx_' . uniqid();
            if (!mkdir($tempDir, 0755, true)) {
                return ['error' => "Cannot create temp dir"];
            }
            mkdir($tempDir . '/xl/worksheets', 0755, true);
            mkdir($tempDir . '/xl/_rels', 0755, true);
            mkdir($tempDir . '/_rels', 0755, true);

            // Collect shared strings
            $sharedStrings = [];
            $stringIndex   = [];
            foreach ($allSheets as &$sheet) {
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
                        if (!isset($stringIndex[$str])) {
                            $stringIndex[$str] = count($sharedStrings);
                            $sharedStrings[]   = $str;
                        }
                        unset($str);
                    }
                }
            }
            unset($sheet);

            $this->writeSharedStrings($tempDir, $sharedStrings);
            $sheetFiles = [];
            foreach ($allSheets as $idx => $sheet) {
                $sheetIdx  = $idx + 1;
                $sheetFile = "sheet$sheetIdx.xml";
                $this->writeWorksheet($tempDir, $sheetFile, $sheet['rows'], $stringIndex);
                $sheetFiles[] = ['id' => $sheetIdx, 'name' => $sheet['name'], 'file' => $sheetFile];
                unset($sheet);
            }

            $this->writeWorkbook($tempDir, $sheetFiles);
            $this->writeWorkbookRels($tempDir, $sheetFiles, !empty($sharedStrings));
            $this->writeContentTypes($tempDir, $sheetFiles, !empty($sharedStrings));
            $this->writeRootRels($tempDir);

            if (!file_exists(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            $zip = new \ZipArchive();
            if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
                $this->rrmdir($tempDir);
                return ['error' => "Failed to create ZIP archive"];
            }
            $this->addDirToZip($zip, $tempDir, '');
            $zip->close();
            unset($zip);

            $this->rrmdir($tempDir);
            $tempDir = null;

            $result = [
                'status'       => 'success',
                'path'         => $path,
                'sheets_count' => count($allSheets),
                'message'      => "XLSX written successfully."
            ];

            unset($allSheets, $sharedStrings, $stringIndex, $sheetFiles);
            return $result;
        } catch (\Exception $e) {
            if (null !== $tempDir && is_dir($tempDir)) {
                $this->rrmdir($tempDir);
            }
            return ['error' => "Failed to write XLSX: " . $e->getMessage()];
        }
    }

    /**
     * Append data to existing file.
     *
     * @param string $path
     * @param array  $data
     *
     * @return array
     */
    private function appendToExisting(string $path, array $data): array
    {
        $existing = $this->read($path);
        if (isset($existing['error'])) {
            return $existing;
        }

        $newSheets    = $this->normalizeSheets($data);
        $mergedSheets = $existing['sheets'];

        foreach ($newSheets as $newSheet) {
            $name = $newSheet['name'];
            if (isset($mergedSheets[$name])) {
                // Append rows
                $mergedSheets[$name] = array_merge($mergedSheets[$name], $newSheet['rows']);
            } else {
                // Add new sheet
                $mergedSheets[$name] = $newSheet['rows'];
            }
        }

        // Convert to normalized format
        $normalized = [];
        foreach ($mergedSheets as $name => $rows) {
            $normalized[] = ['name' => $name, 'rows' => $rows];
        }

        $result = $this->writeNew($path, $normalized);

        unset($existing, $newSheets, $mergedSheets, $normalized);
        return $result;
    }

    /**
     * Normalize sheet data to internal format.
     *
     * @param array $data
     *
     * @return array
     */
    private function normalizeSheets(array $data): array
    {
        // Single sheet (2D array)
        if (isset($data[0]) && is_array($data[0]) && !isset($data[0]['name']) && !isset($data[0]['rows'])) {
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

    /**
     * Ensure rows are rectangular.
     *
     * @param array $rows
     *
     * @return array
     */
    private function normalizeRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        $maxCols = 0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $cnt = count($row);
                if ($cnt > $maxCols) {
                    $maxCols = $cnt;
                }
            } else {
                $maxCols = max($maxCols, 1);
            }
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $row = [$row];
            }
            while (count($row) < $maxCols) {
                $row[] = null;
            }
            $normalized[] = $row;
        }
        return $normalized;
    }

    /**
     * Write shared strings XML.
     *
     * @param string $tempDir
     * @param array  $strings
     *
     * @return void
     */
    private function writeSharedStrings(string $tempDir, array $strings): void
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
        file_put_contents($tempDir . '/xl/sharedStrings.xml', $xml->flush());
        $xml->flush();
        unset($xml);
    }

    /**
     * Write worksheet XML.
     *
     * @param string $tempDir
     * @param string $sheetFile
     * @param array  $rows
     * @param array  $stringIndex
     *
     * @return void
     */
    private function writeWorksheet(string $tempDir, string $sheetFile, array $rows, array $stringIndex): void
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('worksheet');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->startElement('sheetData');
        $rowNum = 1;
        foreach ($rows as $row) {
            $xml->startElement('row');
            $xml->writeAttribute('r', $rowNum);
            $colNum = 0;
            foreach ($row as $cell) {
                $colLetter = $this->indexToColumn($colNum);
                if (null === $cell) {
                    $colNum++;
                    continue;
                }
                $xml->startElement('c');
                $xml->writeAttribute('r', $colLetter . $rowNum);
                if (is_numeric($cell)) {
                    $xml->writeAttribute('t', 'n');
                    $xml->startElement('v');
                    $xml->text((string)$cell);
                    $xml->endElement();
                } elseif (is_string($cell) && '' !== $cell) {
                    if (isset($stringIndex[$cell])) {
                        $xml->writeAttribute('t', 's');
                        $xml->startElement('v');
                        $xml->text((string)$stringIndex[$cell]);
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
                $xml->endElement();
                $colNum++;
                unset($colLetter);
            }
            $xml->endElement();
            $rowNum++;
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        file_put_contents($tempDir . '/xl/worksheets/' . $sheetFile, $xml->flush());
        $xml->flush();
        unset($xml);
    }

    /**
     * Write workbook.xml.
     *
     * @param string $tempDir
     * @param array  $sheets
     *
     * @return void
     */
    private function writeWorkbook(string $tempDir, array $sheets): void
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
        file_put_contents($tempDir . '/xl/workbook.xml', $xml->flush());
        $xml->flush();
        unset($xml);
    }

    /**
     * Write workbook relationships.
     *
     * @param string $tempDir
     * @param array  $sheets
     * @param bool   $hasSharedStrings
     *
     * @return void
     */
    private function writeWorkbookRels(string $tempDir, array $sheets, bool $hasSharedStrings): void
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
        if ($hasSharedStrings) {
            $xml->startElement('Relationship');
            $xml->writeAttribute('Id', 'rId' . (count($sheets) + 1));
            $xml->writeAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings');
            $xml->writeAttribute('Target', 'sharedStrings.xml');
            $xml->endElement();
        }
        $xml->endElement();
        file_put_contents($tempDir . '/xl/_rels/workbook.xml.rels', $xml->flush());
        $xml->flush();
        unset($xml);
    }

    /**
     * Write [Content_Types].xml.
     *
     * @param string $tempDir
     * @param array  $sheets
     * @param bool   $hasSharedStrings
     *
     * @return void
     */
    private function writeContentTypes(string $tempDir, array $sheets, bool $hasSharedStrings): void
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
        if ($hasSharedStrings) {
            $xml->startElement('Override');
            $xml->writeAttribute('PartName', '/xl/sharedStrings.xml');
            $xml->writeAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml');
            $xml->endElement();
        }
        $xml->endElement();
        file_put_contents($tempDir . '/[Content_Types].xml', $xml->flush());
        $xml->flush();
        unset($xml);
    }

    /**
     * Write root relationships.
     *
     * @param string $tempDir
     *
     * @return void
     */
    private function writeRootRels(string $tempDir): void
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
        file_put_contents($tempDir . '/_rels/.rels', $xml->flush());
        $xml->flush();
        unset($xml);
    }

    /**
     * Get sheet names from workbook.
     *
     * @param string $path
     *
     * @return array
     */
    private function getSheetNames(string $path): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return [];
        }
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        if (!$workbook) {
            return [];
        }

        $names  = [];
        $reader = new \XMLReader();
        if (true === $reader->XML($workbook)) {
            while ($reader->read()) {
                if ($reader->nodeType == \XMLReader::ELEMENT && 'sheet' === $reader->name) {
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

    /**
     * Convert column index (0-based) to Excel letter.
     *
     * @param int $col
     *
     * @return string
     */
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

    /**
     * Convert Excel column letter to zero-based index.
     *
     * @param string $col
     *
     * @return int
     */
    private function columnToIndex(string $col): int
    {
        $index = 0;
        for ($i = 0; $i < strlen($col); $i++) {
            $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    /**
     * Recursively add directory to zip.
     *
     * @param \ZipArchive $zip
     * @param string      $dir
     * @param string      $prefix
     *
     * @return void
     */
    private function addDirToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        foreach ($files as $file) {
            if ('.' === $file || '..' === $file) {
                continue;
            }
            $full    = $dir . '/' . $file;
            $zipPath = $prefix . $file;
            if (is_dir($full)) {
                $this->addDirToZip($zip, $full, $zipPath . '/');
            } else {
                $zip->addFile($full, $zipPath);
            }
            unset($full, $zipPath);
        }
        unset($files);
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
        if (!is_dir($dir)) {
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