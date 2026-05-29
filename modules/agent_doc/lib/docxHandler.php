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

namespace modules\agent_doc\lib;

use Nervsys\Core\Factory;

class docxHandler extends Factory
{
    /**
     * Read text content from a .docx file.
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

        return [
            'status'  => 'success',
            'file'    => basename($path),
            'content' => implode(' ', $textParts)
        ];
    }

    /**
     * Write a .docx file from an array of elements.
     * Each element can be:
     *   - string: text paragraph
     *   - array: with keys:
     *        'type' => 'text' (default) or 'image'
     *        'content' => text string (for text) or image file path (for image)
     *        'width' => int (optional, image width in pixels, default 200)
     *        'height' => int (optional, image height in pixels, auto if not set)
     *
     * Supported image formats: jpg, jpeg, png, gif.
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
            // Normalize items
            $items = [];
            foreach ($data as $item) {
                if (is_array($item)) {
                    $type = isset($item['type']) ? $item['type'] : 'text';
                    if ($type === 'image') {
                        $imagePath = $item['content'];
                        if (!file_exists($imagePath)) {
                            return ['error' => "Image file not found: $imagePath"];
                        }
                        $items[] = [
                            'type'   => 'image',
                            'path'   => $imagePath,
                            'width'  => isset($item['width']) ? (int)$item['width'] : 200,
                            'height' => isset($item['height']) ? (int)$item['height'] : null,
                        ];
                    } else {
                        $items[] = ['type' => 'text', 'content' => trim((string)$item['content'])];
                    }
                } else {
                    $items[] = ['type' => 'text', 'content' => trim((string)$item)];
                }
            }

            $tempDir = sys_get_temp_dir() . '/docx_' . uniqid();
            if (!mkdir($tempDir, 0755, true)) {
                return ['error' => "Failed to create temp directory: $tempDir"];
            }

            // Create required directories
            mkdir($tempDir . '/word', 0755, true);
            mkdir($tempDir . '/word/_rels', 0755, true);
            mkdir($tempDir . '/_rels', 0755, true);
            mkdir($tempDir . '/word/media', 0755, true);

            // 1. [Content_Types].xml
            $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $contentTypes .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
            $contentTypes .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
            $contentTypes .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";
            $contentTypes .= '  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' . "\n";

            // Collect image extensions and MIME types
            $imageExts = [];
            foreach ($items as $item) {
                if ($item['type'] === 'image') {
                    $ext = strtolower(pathinfo($item['path'], PATHINFO_EXTENSION));
                    switch ($ext) {
                        case 'jpg':
                        case 'jpeg':
                            $imageExts['jpg'] = 'image/jpeg';
                            if ($ext === 'jpeg') {
                                $imageExts['jpeg'] = 'image/jpeg';
                            }
                            break;
                        case 'png':
                            $imageExts['png'] = 'image/png';
                            break;
                        case 'gif':
                            $imageExts['gif'] = 'image/gif';
                            break;
                        // 可选：支持 bmp
                        case 'bmp':
                            $imageExts['bmp'] = 'image/bmp';
                            break;
                        // 默认不处理未知格式
                    }
                }
            }
            foreach ($imageExts as $ext => $mime) {
                $contentTypes .= '  <Default Extension="' . $ext . '" ContentType="' . $mime . '"/>' . "\n";
            }
            $contentTypes .= '</Types>';
            file_put_contents($tempDir . '/[Content_Types].xml', $contentTypes);
            unset($contentTypes);

            // 2. _rels/.rels
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            $rels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' . "\n";
            $rels .= '</Relationships>';
            file_put_contents($tempDir . '/_rels/.rels', $rels);
            unset($rels);

            // Prepare document.xml content
            $docXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $docXml .= '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">' . "\n";
            $docXml .= '  <w:body>' . "\n";

            $relsEntries  = [];
            $imageCounter = 1;

            foreach ($items as $item) {
                if ($item['type'] === 'text') {
                    $text = $item['content'];
                    if ($text === '') {
                        $docXml .= '    <w:p/>' . "\n";
                    } else {
                        $escaped = htmlspecialchars($text, ENT_XML1, 'UTF-8');
                        $docXml  .= '    <w:p>' . "\n";
                        $docXml  .= '      <w:r>' . "\n";
                        $docXml  .= '        <w:t xml:space="preserve">' . $escaped . '</w:t>' . "\n";
                        $docXml  .= '      </w:r>' . "\n";
                        $docXml  .= '    </w:p>' . "\n";
                    }
                } elseif ($item['type'] === 'image') {
                    $imagePath     = $item['path'];
                    $ext           = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
                    $imageFileName = 'image' . $imageCounter . '.' . $ext;
                    $targetImage   = $tempDir . '/word/media/' . $imageFileName;
                    if (!copy($imagePath, $targetImage)) {
                        throw new \Exception("Failed to copy image: $imagePath");
                    }

                    // Get original image dimensions for aspect ratio calculation
                    $widthPx = $item['width'];
                    if ($item['height'] !== null) {
                        $heightPx = $item['height'];
                    } else {
                        $imageInfo = getimagesize($imagePath);
                        if ($imageInfo !== false) {
                            $origWidth  = $imageInfo[0];
                            $origHeight = $imageInfo[1];
                            if ($origWidth > 0) {
                                $heightPx = round($widthPx * $origHeight / $origWidth);
                            } else {
                                $heightPx = $widthPx;
                            }
                        } else {
                            // Cannot determine dimensions, fallback to square
                            $heightPx = $widthPx;
                        }
                    }

                    // Convert pixels to EMU (1 pixel = 9525 EMU at 96 DPI)
                    $widthEmu  = $widthPx * 9525;
                    $heightEmu = $heightPx * 9525;

                    // Relationship ID for image
                    $relId         = 'rId' . $imageCounter;
                    $relsEntries[] = [
                        'Id'     => $relId,
                        'Target' => 'media/' . $imageFileName,
                        'Type'   => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image'
                    ];

                    // Add image paragraph
                    $docXml .= '    <w:p>' . "\n";
                    $docXml .= '      <w:r>' . "\n";
                    $docXml .= '        <w:drawing>' . "\n";
                    $docXml .= '          <wp:inline distT="0" distB="0" distL="0" distR="0">' . "\n";
                    $docXml .= '            <wp:extent cx="' . $widthEmu . '" cy="' . $heightEmu . '"/>' . "\n";
                    $docXml .= '            <wp:effectExtent l="0" t="0" r="0" b="0"/>' . "\n";
                    $docXml .= '            <wp:docPr id="' . $imageCounter . '" name="Picture ' . $imageCounter . '"/>' . "\n";
                    $docXml .= '            <wp:cNvGraphicFramePr>' . "\n";
                    $docXml .= '              <a:graphicFrameLocks noChangeAspect="1"/>' . "\n";
                    $docXml .= '            </wp:cNvGraphicFramePr>' . "\n";
                    $docXml .= '            <a:graphic>' . "\n";
                    $docXml .= '              <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">' . "\n";
                    $docXml .= '                <pic:pic>' . "\n";
                    $docXml .= '                  <pic:nvPicPr>' . "\n";
                    $docXml .= '                    <pic:cNvPr id="' . $imageCounter . '" name="Picture ' . $imageCounter . '"/>' . "\n";
                    $docXml .= '                    <pic:cNvPicPr/>' . "\n";
                    $docXml .= '                  </pic:nvPicPr>' . "\n";
                    $docXml .= '                  <pic:blipFill>' . "\n";
                    $docXml .= '                    <a:blip r:embed="' . $relId . '"/>' . "\n";
                    $docXml .= '                    <a:stretch><a:fillRect/></a:stretch>' . "\n";
                    $docXml .= '                  </pic:blipFill>' . "\n";
                    $docXml .= '                  <pic:spPr>' . "\n";
                    $docXml .= '                    <a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $widthEmu . '" cy="' . $heightEmu . '"/></a:xfrm>' . "\n";
                    $docXml .= '                    <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>' . "\n";
                    $docXml .= '                  </pic:spPr>' . "\n";
                    $docXml .= '                </pic:pic>' . "\n";
                    $docXml .= '              </a:graphicData>' . "\n";
                    $docXml .= '            </a:graphic>' . "\n";
                    $docXml .= '          </wp:inline>' . "\n";
                    $docXml .= '        </w:drawing>' . "\n";
                    $docXml .= '      </w:r>' . "\n";
                    $docXml .= '    </w:p>' . "\n";

                    $imageCounter++;
                }
            }

            $docXml .= '    <w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr>' . "\n";
            $docXml .= '  </w:body>' . "\n";
            $docXml .= '</w:document>';
            file_put_contents($tempDir . '/word/document.xml', $docXml);
            unset($docXml);

            // 3. word/_rels/document.xml.rels
            $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $docRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            foreach ($relsEntries as $rel) {
                $docRels .= '  <Relationship Id="' . $rel['Id'] . '" Type="' . $rel['Type'] . '" Target="' . $rel['Target'] . '"/>' . "\n";
            }
            $docRels .= '</Relationships>';
            file_put_contents($tempDir . '/word/_rels/document.xml.rels', $docRels);
            unset($docRels);

            // 4. Create ZIP
            if (!file_exists(dirname($path))) {
                if (!mkdir(dirname($path), 0755, true)) {
                    throw new \Exception("Failed to create output directory: " . dirname($path));
                }
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

            return [
                'status'  => 'success',
                'path'    => $path,
                'message' => 'DOCX written successfully with image support.'
            ];
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
        foreach (scandir($dir) as $file) {
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
    }
}