<?php

/**
 * DOCX Handler - Native PHP Implementation (Read + Write)
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

namespace modules\agent_doc\app;

use Nervsys\Core\Factory;

class docxHandler extends Factory
{
    /**
     * Read text content from a .docx file.
     *
     * @param string $path
     *
     * @return array ['status'|'error', 'file', 'content']
     */
    public function read(string $path): array
    {
        if (!file_exists($path)) {
            return ['error' => "File not found: $path"];
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return ['error' => 'Failed to open DOCX file as a zip archive.'];
        }

        $targetFile = 'word/document.xml';
        if ($zip->locateName($targetFile) === false) {
            $zip->close();
            return ['error' => 'Could not find document.xml inside the DOCX file.'];
        }

        $xmlContent = $zip->getFromName($targetFile);
        $zip->close();

        if ($xmlContent === false || $xmlContent === '') {
            return ['status' => 'success', 'file' => basename($path), 'content' => ''];
        }

        $textParts = [];
        $reader    = new \XMLReader();
        if ($reader->XML($xmlContent)) {
            while ($reader->read()) {
                // DOCX text nodes are w:t (with namespace prefix)
                if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name === 'w:t') {
                    $text = $reader->readString() ?? '';
                    if (trim($text) !== '') {
                        $textParts[] = trim($text);
                    }
                    unset($text);
                }
            }
        }
        $reader->close();
        unset($reader, $xmlContent, $zip);

        $result = [
            'status'  => 'success',
            'file'    => basename($path),
            'content' => implode(' ', $textParts)
        ];
        unset($textParts);
        return $result;
    }

    /**
     * Write a .docx file from an array of paragraphs.
     *
     * @param string $path
     * @param array  $data List of paragraphs (strings)
     *
     * @return array
     */
    public function write(string $path, array $data): array
    {
        $tempDir = null;
        try {
            $paragraphs = [];
            foreach ($data as $item) {
                if (is_array($item)) {
                    foreach ($item as $subItem) {
                        $paragraphs[] = trim((string)$subItem);
                    }
                } else {
                    $paragraphs[] = trim((string)$item);
                }
            }
            if (empty($paragraphs)) {
                $paragraphs = [''];
            }

            $tempDir = sys_get_temp_dir() . '/docx_' . uniqid();
            if (!mkdir($tempDir, 0755, true)) {
                return ['error' => "Failed to create temp directory: $tempDir"];
            }

            // 1. [Content_Types].xml
            $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $contentTypes .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
            $contentTypes .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
            $contentTypes .= '<Default Extension="xml" ContentType="application/xml"/>' . "\n";
            $contentTypes .= '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' . "\n";
            $contentTypes .= '</Types>';
            file_put_contents($tempDir . '/[Content_Types].xml', $contentTypes);
            unset($contentTypes);

            // 2. _rels/.rels
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' . "\n";
            $rels .= '</Relationships>';
            mkdir($tempDir . '/_rels', 0755, true);
            file_put_contents($tempDir . '/_rels/.rels', $rels);
            unset($rels);

            // 3. word/document.xml
            $docXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $docXml .= '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' . "\n";
            $docXml .= '  <w:body>' . "\n";
            foreach ($paragraphs as $para) {
                if (trim($para) === '') {
                    $docXml .= '    <w:p/>' . "\n";
                } else {
                    $escaped = htmlspecialchars($para, ENT_XML1, 'UTF-8');
                    $docXml  .= '    <w:p>' . "\n";
                    $docXml  .= '      <w:r>' . "\n";
                    $docXml  .= '        <w:t xml:space="preserve">' . $escaped . '</w:t>' . "\n";
                    $docXml  .= '      </w:r>' . "\n";
                    $docXml  .= '    </w:p>' . "\n";
                    unset($escaped);
                }
                unset($para);
            }
            $docXml .= '    <w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr>' . "\n";
            $docXml .= '  </w:body>' . "\n";
            $docXml .= '</w:document>';
            mkdir($tempDir . '/word', 0755, true);
            file_put_contents($tempDir . '/word/document.xml', $docXml);
            unset($docXml);

            // 4. Create minimal word/_rels/document.xml.rels
            $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $docRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';
            mkdir($tempDir . '/word/_rels', 0755, true);
            file_put_contents($tempDir . '/word/_rels/document.xml.rels', $docRels);
            unset($docRels);

            // 5. Create ZIP
            if (!file_exists(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                self::rrmdir($tempDir);
                return ['error' => "Failed to create ZIP archive: $path"];
            }
            self::addDirectoryToZip($zip, $tempDir, '');
            $zip->close();
            unset($zip);

            self::rrmdir($tempDir);
            $tempDir = null;

            $result = [
                'status'           => 'success',
                'path'             => $path,
                'paragraphs_count' => count(array_filter($paragraphs, fn($p) => $p !== '')),
                'message'          => 'DOCX written successfully.'
            ];
            unset($paragraphs);
            return $result;
        } catch (\Exception $e) {
            if ($tempDir !== null && is_dir($tempDir)) {
                self::rrmdir($tempDir);
            }
            return ['error' => "Failed to write DOCX: " . $e->getMessage()];
        }
    }

    private static function addDirectoryToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $file) {
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