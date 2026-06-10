<?php

/**
 * DOCX Handler - Native PHP Implementation (Read + Write + Append)
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

namespace tools\OfficeSuite\lib;

use modules\agent_core\core;
use Nervsys\Core\Factory;

class docxHandler extends Factory
{
    public core $core;

    public function __construct()
    {
        $this->core = core::new();
    }

    /**
     * Read simple text content from .docx.
     *
     * @param string $path
     *
     * @return array
     */
    public function read(string $path): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return ['error' => 'Failed to open DOCX file as a zip archive.'];
        }

        $xmlContent = $zip->getFromName('word/document.xml');
        if (false === $xmlContent) {
            $zip->close();
            return ['error' => 'Could not find document.xml'];
        }

        $textParts = [];
        $reader    = new \XMLReader();
        if (true === $reader->XML($xmlContent)) {
            while ($reader->read()) {
                if ($reader->nodeType == \XMLReader::ELEMENT && 'w:t' === $reader->name) {
                    $text = $reader->readString() ?? '';
                    if ('' !== trim($text)) {
                        $textParts[] = trim($text);
                    }
                }
            }
        }
        $reader->close();
        $zip->close();

        $result = [
            'status'  => 'success',
            'file'    => basename($path),
            'content' => implode(' ', $textParts)
        ];

        unset($zip, $xmlContent, $reader, $textParts);
        return $result;
    }

    /**
     * Write DOCX with append option (preserves images in append mode).
     *
     * @param string $path
     * @param array  $data
     * @param bool   $append
     *
     * @return array
     */
    public function write(string $path, array $data, bool $append = false): array
    {
        $tempDir = null;
        try {
            // Flatten incoming data
            $data = $this->flattenData($data);

            // Normalize new items
            $newItems = [];
            foreach ($data as $item) {
                if (is_array($item) && isset($item['type']) && 'image' === $item['type']) {
                    $imgPath = $item['content'] ?? '';
                    if (!file_exists($imgPath)) {
                        return ['error' => "Image not found: $imgPath"];
                    }
                    $newItems[] = [
                        'type'   => 'image',
                        'path'   => $imgPath,
                        'width'  => $item['width'] ?? 200,
                        'height' => $item['height'] ?? null,
                    ];
                } else {
                    $newItems[] = ['type' => 'text', 'content' => trim((string)$item)];
                }
            }

            $tempDir = $this->core->agent_config['workspace_path'] . '/temp/docx_' . uniqid();
            if (!mkdir($tempDir, 0755, true)) {
                return ['error' => 'Failed to create temp dir'];
            }
            mkdir($tempDir . '/word', 0755, true);
            mkdir($tempDir . '/word/_rels', 0755, true);
            mkdir($tempDir . '/_rels', 0755, true);
            mkdir($tempDir . '/word/media', 0755, true);

            $mediaDir = $tempDir . '/word/media';
            $items    = [];

            if ($append && file_exists($path)) {
                $existing = $this->parseExistingItems($path, $mediaDir);
                $items    = array_merge($existing, $newItems);
                unset($existing);
            } else {
                $items = $newItems;
            }

            if (empty($items)) {
                return ['error' => 'No content to write.'];
            }

            // Copy all images to final media folder and assign unique ids
            $finalItems   = [];
            $imageCounter = 1;

            foreach ($items as $item) {
                if ('text' === $item['type']) {
                    $finalItems[] = $item;
                } else {
                    $ext     = strtolower(pathinfo($item['path'], PATHINFO_EXTENSION));
                    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'bmp'];
                    if (!in_array($ext, $allowed, true)) {
                        $ext = 'png';
                    }
                    $newName = 'image' . $imageCounter . '.' . $ext;
                    $dest    = $mediaDir . '/' . $newName;

                    if (0 !== strpos($item['path'], $tempDir)) {
                        copy($item['path'], $dest);
                    } else {
                        rename($item['path'], $dest);
                    }

                    $finalItems[] = [
                        'type'   => 'image',
                        'path'   => $dest,
                        'width'  => $item['width'],
                        'height' => $item['height'],
                        'rel_id' => 'rId' . $imageCounter
                    ];
                    $imageCounter++;
                    unset($ext, $allowed, $newName, $dest);
                }
            }

            // Build [Content_Types].xml
            $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
            $ct .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
            $ct .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";
            $ct .= '  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' . "\n";

            $imageExts = [];
            foreach ($finalItems as $it) {
                if ('image' === $it['type']) {
                    $e = pathinfo($it['path'], PATHINFO_EXTENSION);
                    if ('jpg' === $e) {
                        $imageExts['jpg'] = 'image/jpeg';
                    } elseif ('jpeg' === $e) {
                        $imageExts['jpeg'] = 'image/jpeg';
                    } elseif ('png' === $e) {
                        $imageExts['png'] = 'image/png';
                    } elseif ('gif' === $e) {
                        $imageExts['gif'] = 'image/gif';
                    } elseif ('bmp' === $e) {
                        $imageExts['bmp'] = 'image/bmp';
                    }
                }
            }
            foreach ($imageExts as $ext => $mime) {
                $ct .= '  <Default Extension="' . $ext . '" ContentType="' . $mime . '"/>' . "\n";
            }
            $ct .= '</Types>';
            file_put_contents($tempDir . '/[Content_Types].xml', $ct);
            unset($ct, $imageExts, $e);

            // _rels/.rels
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            $rels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' . "\n";
            $rels .= '</Relationships>';
            file_put_contents($tempDir . '/_rels/.rels', $rels);
            unset($rels);

            // word/document.xml
            $docXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $docXml .= '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">' . "\n";
            $docXml .= '  <w:body>' . "\n";

            $relsEntries = [];
            $counter     = 1;

            foreach ($finalItems as $it) {
                if ('text' === $it['type']) {
                    $text = $it['content'];
                    if ('' === $text) {
                        $docXml .= '    <w:p/>' . "\n";
                    } else {
                        $esc    = htmlspecialchars($text, ENT_XML1, 'UTF-8');
                        $docXml .= '    <w:p><w:r><w:t xml:space="preserve">' . $esc . '</w:t></w:r></w:p>' . "\n";
                        unset($esc);
                    }
                    unset($text);
                } else {
                    $imgPath  = $it['path'];
                    $widthPx  = $it['width'];
                    $heightPx = $it['height'] ?? $widthPx;
                    if (null === $heightPx) {
                        $info = getimagesize($imgPath);
                        if (false !== $info) {
                            $origW = $info[0];
                            $origH = $info[1];
                            if ($origW > 0) {
                                $heightPx = (int)round($widthPx * $origH / $origW);
                            } else {
                                $heightPx = $widthPx;
                            }
                        } else {
                            $heightPx = $widthPx;
                        }
                        unset($info, $origW, $origH);
                    }
                    $widthEmu  = $widthPx * 9525;
                    $heightEmu = $heightPx * 9525;

                    $relId         = 'rId' . $counter;
                    $relsEntries[] = [
                        'Id'     => $relId,
                        'Target' => 'media/' . basename($imgPath),
                        'Type'   => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image'
                    ];

                    $docXml .= '    <w:p>';
                    $docXml .= '      <w:r>';
                    $docXml .= '        <w:drawing>';
                    $docXml .= '          <wp:inline distT="0" distB="0" distL="0" distR="0">';
                    $docXml .= '            <wp:extent cx="' . $widthEmu . '" cy="' . $heightEmu . '"/>';
                    $docXml .= '            <wp:docPr id="' . $counter . '" name="Picture ' . $counter . '"/>';
                    $docXml .= '            <a:graphic>';
                    $docXml .= '              <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">';
                    $docXml .= '                <pic:pic>';
                    $docXml .= '                  <pic:nvPicPr>';
                    $docXml .= '                    <pic:cNvPr id="0" name="Picture ' . $counter . '"/>';
                    $docXml .= '                    <pic:cNvPicPr/>';
                    $docXml .= '                  </pic:nvPicPr>';
                    $docXml .= '                  <pic:blipFill>';
                    $docXml .= '                    <a:blip r:embed="' . $relId . '"/>';
                    $docXml .= '                    <a:stretch><a:fillRect/></a:stretch>';
                    $docXml .= '                  </pic:blipFill>';
                    $docXml .= '                  <pic:spPr>';
                    $docXml .= '                    <a:xfrm>';
                    $docXml .= '                      <a:off x="0" y="0"/>';
                    $docXml .= '                      <a:ext cx="' . $widthEmu . '" cy="' . $heightEmu . '"/>';
                    $docXml .= '                    </a:xfrm>';
                    $docXml .= '                    <a:prstGeom prst="rect"/>';
                    $docXml .= '                  </pic:spPr>';
                    $docXml .= '                </pic:pic>';
                    $docXml .= '              </a:graphicData>';
                    $docXml .= '            </a:graphic>';
                    $docXml .= '          </wp:inline>';
                    $docXml .= '        </w:drawing>';
                    $docXml .= '      </w:r>';
                    $docXml .= '    </w:p>' . "\n";

                    $counter++;
                    unset($imgPath, $widthPx, $heightPx, $widthEmu, $heightEmu, $relId);
                }
            }

            $docXml .= '    <w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr>' . "\n";
            $docXml .= '  </w:body>' . "\n";
            $docXml .= '</w:document>';
            file_put_contents($tempDir . '/word/document.xml', $docXml);
            unset($docXml);

            // word/_rels/document.xml.rels
            $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $docRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            foreach ($relsEntries as $rel) {
                $docRels .= '  <Relationship Id="' . $rel['Id'] . '" Type="' . $rel['Type'] . '" Target="' . $rel['Target'] . '"/>' . "\n";
            }
            $docRels .= '</Relationships>';
            file_put_contents($tempDir . '/word/_rels/document.xml.rels', $docRels);
            unset($docRels, $relsEntries);

            // Create final zip
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            $zip = new \ZipArchive();
            if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
                $this->rrmdir($tempDir);
                return ['error' => 'Failed to create ZIP archive'];
            }
            $this->addDirToZip($zip, $tempDir, '');
            $zip->close();
            unset($zip);

            $this->rrmdir($tempDir);
            $result = ['status' => 'success', 'path' => $path, 'message' => 'DOCX written with ' . count($finalItems) . ' items'];

            unset($tempDir, $mediaDir, $items, $newItems, $finalItems, $imageCounter, $counter);
            return $result;
        } catch (\Exception $e) {
            if (null !== $tempDir && is_dir($tempDir)) {
                $this->rrmdir($tempDir);
            }
            return ['error' => 'Write failed: ' . $e->getMessage()];
        }
    }

    /**
     * Parse existing document into items (text + images) and extract images to temp folder.
     *
     * @param string $path
     * @param string $tempMediaDir
     *
     * @return array
     */
    private function parseExistingItems(string $path, string $tempMediaDir): array
    {
        $items = [];
        if (!file_exists($path)) {
            return $items;
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return $items;
        }

        // Load relationship map
        $relMap      = [];
        $relsContent = $zip->getFromName('word/_rels/document.xml.rels');
        if (false !== $relsContent) {
            $relReader = new \XMLReader();
            if (true === $relReader->XML($relsContent)) {
                while ($relReader->read()) {
                    if ($relReader->nodeType == \XMLReader::ELEMENT && 'Relationship' === $relReader->name) {
                        $id     = null;
                        $target = null;
                        if ($relReader->hasAttributes) {
                            while ($relReader->moveToNextAttribute()) {
                                if ('Id' === $relReader->name) {
                                    $id = $relReader->value;
                                }
                                if ('Target' === $relReader->name) {
                                    $target = $relReader->value;
                                }
                            }
                            $relReader->moveToElement();
                        }
                        if ($id && $target) {
                            $relMap[$id] = $target;
                        }
                    }
                }
            }
            $relReader->close();
            unset($relReader);
        }
        unset($relsContent);

        // Parse document.xml
        $xmlContent = $zip->getFromName('word/document.xml');
        if (false === $xmlContent) {
            $zip->close();
            return $items;
        }

        $reader = new \XMLReader();
        if (false === $reader->XML($xmlContent)) {
            $reader->close();
            $zip->close();
            return $items;
        }

        $currentText  = '';
        $imageCounter = 0;

        while ($reader->read()) {
            if ($reader->nodeType == \XMLReader::ELEMENT) {
                if ('w:t' === $reader->name) {
                    $currentText .= $reader->readString() ?? '';
                } elseif ('w:drawing' === $reader->name) {
                    if ('' !== $currentText) {
                        $items[]     = ['type' => 'text', 'content' => $currentText];
                        $currentText = '';
                    }
                    $drawingXml = $reader->readOuterXml();
                    $imageInfo  = $this->extractImageFromDrawing($drawingXml, $relMap, $zip, $tempMediaDir, ++$imageCounter);
                    if (null !== $imageInfo) {
                        $items[] = $imageInfo;
                    }
                    unset($drawingXml, $imageInfo);
                }
            } elseif ($reader->nodeType == \XMLReader::END_ELEMENT && 'w:p' === $reader->name) {
                if ('' !== $currentText) {
                    $items[]     = ['type' => 'text', 'content' => $currentText];
                    $currentText = '';
                }
            }
        }
        $reader->close();
        if ('' !== $currentText) {
            $items[] = ['type' => 'text', 'content' => $currentText];
        }

        $zip->close();
        unset($zip, $xmlContent, $reader, $relMap, $currentText, $imageCounter);
        return $items;
    }

    /**
     * Extract image from drawing XML and save to temp directory.
     *
     * @param string      $drawingXml
     * @param array       $relMap
     * @param \ZipArchive $zip
     * @param string      $tempMediaDir
     * @param int         $imgIdx
     *
     * @return array|null
     */
    private function extractImageFromDrawing(string $drawingXml, array $relMap, \ZipArchive $zip, string $tempMediaDir, int $imgIdx): ?array
    {
        $dom = new \DOMDocument();
        if (false === $dom->loadXML($drawingXml)) {
            return null;
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');

        $blipNodes = $xp->query('//a:blip/@r:embed');
        if (false === $blipNodes || 0 === $blipNodes->length) {
            return null;
        }
        $relId = $blipNodes->item(0)->value;
        if (!isset($relMap[$relId])) {
            return null;
        }

        $imageTarget    = $relMap[$relId];
        $imagePathInZip = 'word/' . $imageTarget;
        $imageData      = $zip->getFromName($imagePathInZip);
        if (false === $imageData) {
            return null;
        }

        $ext     = strtolower(pathinfo($imageTarget, PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'bmp'];
        if (!in_array($ext, $allowed, true)) {
            $ext = 'png';
        }
        $tempPath = $tempMediaDir . '/orig_img_' . $imgIdx . '.' . $ext;
        file_put_contents($tempPath, $imageData);

        // Get dimensions from wp:extent
        $widthEmu    = 200 * 9525;
        $heightEmu   = $widthEmu;
        $extentNodes = $xp->query('//wp:extent');
        if (false !== $extentNodes && $extentNodes->length > 0) {
            $widthEmu  = (int)$extentNodes->item(0)->getAttribute('cx');
            $heightEmu = (int)$extentNodes->item(0)->getAttribute('cy');
        }
        $widthPx  = (int)round($widthEmu / 9525);
        $heightPx = (int)round($heightEmu / 9525);

        $result = [
            'type'   => 'image',
            'path'   => $tempPath,
            'width'  => $widthPx,
            'height' => $heightPx,
        ];

        unset($dom, $xp, $blipNodes, $relId, $imageTarget, $imagePathInZip, $imageData);
        unset($ext, $allowed, $tempPath, $widthEmu, $heightEmu, $extentNodes, $widthPx, $heightPx);
        return $result;
    }

    /**
     * Recursively flatten nested array.
     *
     * @param array $data
     *
     * @return array
     */
    private function flattenData(array $data): array
    {
        $result = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                if (isset($item['type']) && 'image' === $item['type']) {
                    $result[] = $item;
                } else {
                    $result = array_merge($result, $this->flattenData($item));
                }
            } else {
                $result[] = $item;
            }
        }
        return $result;
    }

    /**
     * Helper to add directory recursively to zip.
     *
     * @param \ZipArchive $zip
     * @param string      $dir
     * @param string      $prefix
     *
     * @return void
     */
    private function addDirToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        $files = scandir($dir);
        foreach ($files as $f) {
            if ('.' === $f || '..' === $f) {
                continue;
            }
            $full    = $dir . '/' . $f;
            $zipPath = $prefix . $f;
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
     * Recursive remove directory.
     *
     * @param string $dir
     *
     * @return void
     */
    private function rrmdir(string $dir): void
    {
        $files = scandir($dir);
        foreach ($files as $f) {
            if ('.' === $f || '..' === $f) {
                continue;
            }
            $full = $dir . '/' . $f;
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