<?php

/**
 * XLSX Handler - Complete Native PHP Implementation
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

use Nervsys\Core\Factory;

class xlsxHandler extends Factory
{
    /**
     * Read ALL content from an .xlsx file.
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
        if ($zip->open($path) !== true) {
            return ['error' => "Failed to open XLSX file as a zip archive."];
        }

        // 1. Extract shared strings table
        $sharedStrings = [];
        $sstContent    = $zip->getFromName('xl/sharedStrings.xml');
        if ($sstContent !== false && $sstContent !== '') {
            $reader = new \XMLReader();
            if ($reader->XML($sstContent)) {
                while ($reader->read()) {
                    if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name === 't') {
                        $sharedStrings[] = $reader->readString() ?? '';
                    }
                }
            }
            $reader->close();
            unset($reader);
        }
        unset($sstContent);

        // 2. Find all worksheet files and parse each one
        $sheetsData = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (!preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $filename, $matches)) {
                continue;
            }
            $sheetNum = (int)$matches[1];
            unset($matches);

            $xmlContent = $zip->getFromName($filename);
            if ($xmlContent === false) {
                continue;
            }

            $cells  = [];
            $reader = new \XMLReader();
            if ($reader->XML($xmlContent)) {
                while ($reader->read()) {
                    if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name === 'c') {
                        $ref  = null;
                        $type = null;
                        if ($reader->hasAttributes) {
                            while ($reader->moveToNextAttribute()) {
                                if ($reader->name === 'r') {
                                    $ref = $reader->value;
                                } elseif ($reader->name === 't') {
                                    $type = $reader->value;
                                }
                            }
                            $reader->moveToElement();
                        }
                        if (!$ref) continue;

                        $value = null;
                        while ($reader->read()) {
                            if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name === 'v') {
                                $rawValue = $reader->readString() ?? '';
                                if ($type === 's') {
                                    $idx   = (int)$rawValue;
                                    $value = $sharedStrings[$idx] ?? '';
                                } elseif ($type === 'inlineStr') {
                                    $value = '';
                                } else {
                                    if (is_numeric($rawValue)) {
                                        $value = (strpos($rawValue, '.') !== false) ? (float)$rawValue : (int)$rawValue;
                                    } else {
                                        $value = $rawValue;
                                    }
                                }
                                unset($rawValue);
                            } elseif ($reader->nodeType == \XMLReader::END_ELEMENT && $reader->name === 'c') {
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

            // Convert sparse cells to 2D grid
            $grid = [];
            foreach ($cells as $cell) {
                if (!preg_match('/^([A-Z]+)(\d+)$/', $cell['ref'], $match)) continue;
                $col              = self::columnToIndex($match[1]);
                $row              = (int)$match[2] - 1;
                $grid[$row][$col] = $cell['value'];
                unset($match);
            }
            unset($cells);

            // Fill missing cells with null
            if (!empty($grid)) {
                $maxRow = max(array_keys($grid));
                for ($r = 0; $r <= $maxRow; $r++) {
                    if (!isset($grid[$r])) $grid[$r] = [];
                    if (empty($grid[$r])) continue;
                    $maxCol = max(array_keys($grid[$r]));
                    for ($c = 0; $c <= $maxCol; $c++) {
                        if (!isset($grid[$r][$c])) $grid[$r][$c] = null;
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

        if (empty($sheetsData)) {
            return ['status' => 'success', 'file' => basename($path), 'sheets' => [], 'message' => 'No worksheets found.'];
        }

        // Get sheet names
        $sheetNames   = $this->getSheetNames($path);
        $resultSheets = [];
        foreach ($sheetsData as $idx => $grid) {
            $name                = $sheetNames[$idx] ?? "Sheet$idx";
            $resultSheets[$name] = $grid;
            unset($grid);
        }
        unset($sheetsData, $sheetNames);

        $result = [
            'status' => 'success',
            'file'   => basename($path),
            'sheets' => $resultSheets
        ];
        unset($resultSheets);
        return $result;
    }

    /**
     * Write an .xlsx file from 2D array data.
     *
     * @param string $path
     * @param array  $data
     *
     * @return array
     */
    public function write(string $path, array $data): array
    {
        $tempDir = null;
        try {
            $allSheets = $this->normalizeSheets($data);
            if (empty($allSheets)) {
                return ['error' => 'No data to write.'];
            }

            $tempDir = sys_get_temp_dir() . '/xlsx_' . uniqid();
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
                    if (!is_array($row)) continue;
                    foreach ($row as $cell) {
                        if ($cell === null || $cell === '') continue;
                        if (is_numeric($cell)) continue;
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
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                self::rrmdir($tempDir);
                return ['error' => "Failed to create ZIP archive"];
            }
            self::addDirectoryToZip($zip, $tempDir, '');
            $zip->close();
            unset($zip);

            self::rrmdir($tempDir);
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
            if ($tempDir !== null && is_dir($tempDir)) {
                self::rrmdir($tempDir);
            }
            return ['error' => "Failed to write XLSX: " . $e->getMessage()];
        }
    }

    // ---------- Helper methods (unchanged, but ensure constants are correct) ----------
    private function normalizeSheets(array $data): array
    {
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

    private function normalizeRows(array $rows): array
    {
        if (empty($rows)) return [];
        $maxCols = 0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $cnt = count($row);
                if ($cnt > $maxCols) $maxCols = $cnt;
            } else {
                $maxCols = max($maxCols, 1);
            }
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) $row = [$row];
            while (count($row) < $maxCols) $row[] = null;
            $normalized[] = $row;
        }
        return $normalized;
    }

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
                $colLetter = self::indexToColumn($colNum);
                if ($cell === null) {
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
                } elseif (is_string($cell) && $cell !== '') {
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

    private function getSheetNames(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return [];
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        if (!$workbook) return [];

        $names  = [];
        $reader = new \XMLReader();
        if ($reader->XML($workbook)) {
            while ($reader->read()) {
                if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name === 'sheet') {
                    if ($reader->hasAttributes) {
                        while ($reader->moveToNextAttribute()) {
                            if ($reader->name === 'name') {
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

    private static function indexToColumn(int $col): string
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

    private static function columnToIndex(string $col): int
    {
        $index = 0;
        for ($i = 0; $i < strlen($col); $i++) {
            $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    private static function addDirectoryToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        if (!is_dir($dir)) return;
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $full    = $dir . '/' . $file;
            $zipPath = $prefix . $file;
            if (is_dir($full)) {
                self::addDirectoryToZip($zip, $full, $zipPath . '/');
            } else {
                $zip->addFile($full, $zipPath);
            }
            unset($file, $full, $zipPath);
        }
        unset($files);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $full = $dir . '/' . $file;
            if (is_dir($full)) {
                self::rrmdir($full);
            } else {
                try {
                    unlink($full);
                } catch (\Exception) {
                }
            }
            unset($file, $full);
        }
        try {
            rmdir($dir);
        } catch (\Exception) {
        }
        unset($files);
    }
}