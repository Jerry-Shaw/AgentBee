<?php

/**
 * PPTX Handler - Complete Native PHP Implementation
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

class pptxHandler extends Factory
{
    /**
     * Read all text content from a .pptx file.
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
            return ['error' => 'Failed to open PPTX file as a zip archive.'];
        }

        // Get presentation.xml
        $presContent = $zip->getFromName('ppt/presentation.xml');
        if ($presContent === false) {
            $zip->close();
            return ['error' => 'Could not find presentation.xml'];
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        if (!$dom->loadXML($presContent)) {
            $zip->close();
            return ['error' => 'Failed to parse presentation.xml'];
        }

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        // Get slide relationships
        $slideRels = [];
        $sldNodes  = $xp->query('//p:sldIdLst/p:sldId');
        if ($sldNodes === false) {
            $zip->close();
            return ['error' => 'Invalid XPath in presentation.xml'];
        }
        foreach ($sldNodes as $sldId) {
            $relId = '';
            foreach ($sldId->attributes as $attr) {
                if ($attr->name === 'r:id') {
                    $relId = trim($attr->value);
                    break;
                }
            }
            if ($relId) {
                $slideRels[] = ['rel_id' => $relId];
            }
        }

        // Parse presentation.xml.rels
        $relsContent = $zip->getFromName('ppt/_rels/presentation.xml.rels');
        $relMap      = [];
        if ($relsContent !== false) {
            $domRels = new \DOMDocument('1.0', 'UTF-8');
            if ($domRels->loadXML($relsContent)) {
                $xpR = new \DOMXPath($domRels);
                $xpR->registerNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
                $relNodes = $xpR->query('//pr:Relationship');
                if ($relNodes !== false) {
                    foreach ($relNodes as $rel) {
                        $id     = '';
                        $target = '';
                        foreach ($rel->attributes as $attr) {
                            if ($attr->name === 'Id') $id = $attr->value;
                            if ($attr->name === 'Target') $target = $attr->value;
                        }
                        if ($id && $target && str_contains($target, 'slides/')) {
                            $relMap[$id] = ltrim($target, '/');
                        }
                    }
                }
            }
        }

        // Build slide file list
        $slideFiles = [];
        foreach ($slideRels as $sr) {
            if (isset($relMap[$sr['rel_id']])) {
                $path = 'ppt/' . $relMap[$sr['rel_id']];
                if ($zip->locateName($path) !== false) {
                    $slideFiles[] = $path;
                } else {
                    $alt = $relMap[$sr['rel_id']];
                    if ($zip->locateName($alt) !== false) {
                        $slideFiles[] = $alt;
                    }
                }
            } else {
                $num   = count($slideFiles) + 1;
                $guess = "ppt/slides/slide{$num}.xml";
                if ($zip->locateName($guess) !== false) {
                    $slideFiles[] = $guess;
                }
            }
        }

        // Fallback brute-force
        if (empty($slideFiles)) {
            for ($i = 1; $i <= 100; $i++) {
                $file = "ppt/slides/slide{$i}.xml";
                if ($zip->locateName($file) !== false) {
                    $slideFiles[] = $file;
                }
            }
        }

        // Read each slide
        $slides = [];
        foreach ($slideFiles as $idx => $sf) {
            $xmlContent = $zip->getFromName($sf);
            if ($xmlContent === false) continue;

            $domSlide = new \DOMDocument('1.0', 'UTF-8');
            libxml_clear_errors();
            if (!$domSlide->loadXML($xmlContent)) {
                continue;
            }
            $xpS = new \DOMXPath($domSlide);
            $xpS->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $xpS->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

            $titleTexts   = [];
            $contentTexts = [];

            // Find all shapes
            $shapes = $xpS->query('//p:sp');
            if ($shapes !== false) {
                foreach ($shapes as $shape) {
                    // Detect if shape is a title placeholder
                    $isTitle  = false;
                    $nvPrList = $xpS->query('./p:nvSpPr/p:nvPr', $shape);
                    if ($nvPrList !== false && $nvPrList->length > 0) {
                        $nvPr    = $nvPrList->item(0);
                        $phNodes = $xpS->query('p:ph', $nvPr);
                        if ($phNodes !== false && $phNodes->length > 0) {
                            $ph     = $phNodes->item(0);
                            $phType = $ph->getAttribute('type');
                            if ($phType === 'title') {
                                $isTitle = true;
                            }
                        }
                    }

                    // Extract text nodes
                    $textNodes = $xpS->query('.//a:t', $shape);
                    if ($textNodes !== false) {
                        foreach ($textNodes as $tNode) {
                            $text = trim($tNode->nodeValue);
                            if ($text !== '') {
                                if ($isTitle) {
                                    $titleTexts[] = $text;
                                } else {
                                    $contentTexts[] = $text;
                                }
                            }
                        }
                    }
                }
            }

            $title   = implode(' ', array_unique($titleTexts));
            $content = implode("\n", array_filter(array_map('trim', $contentTexts)));

            // Fallback: if no title detected but content has multiple lines, assume first line is title
            if ($title === '' && !empty($content)) {
                $lines = explode("\n", $content);
                if (count($lines) > 1 && strlen($lines[0]) < 200) {
                    $title   = array_shift($lines);
                    $content = implode("\n", $lines);
                }
            }

            if (strlen($title) > 500) $title = substr($title, 0, 500);

            $slides[] = [
                'number'  => $idx + 1,
                'title'   => $title,
                'content' => $content,
            ];
        }

        $zip->close();
        libxml_use_internal_errors(false);

        return [
            'status'       => 'success',
            'file'         => basename($path),
            'slides_count' => count($slides),
            'slides'       => $slides,
        ];
    }

    /**
     * Write a .pptx file from an array of slides.
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
            // Normalize slide data
            $slides = [];
            foreach ($data as $item) {
                if (is_array($item)) {
                    $title   = isset($item['title']) ? trim((string)$item['title']) : '';
                    $content = $item['content'] ?? '';
                    if (is_array($content)) {
                        $content = implode("\n", array_map('trim', array_filter($content)));
                    } else {
                        $content = trim((string)$content);
                    }
                    if ($title !== '' && $content === '') {
                        $content = $title;
                    }
                    $slides[] = ['title' => $title, 'content' => $content];
                } else {
                    $slides[] = ['title' => '', 'content' => trim((string)$item)];
                }
            }
            if (empty($slides)) {
                $slides[] = ['title' => '', 'content' => ''];
            }

            $tempDir = sys_get_temp_dir() . '/pptx_' . uniqid('pptx_', true);
            if (!mkdir($tempDir, 0755, true)) {
                return ['error' => 'Cannot create temp directory'];
            }

            $dirs = [
                '/_rels', '/ppt/_rels', '/ppt/slides', '/ppt/slideMasters',
                '/ppt/slideLayouts', '/ppt/theme',
            ];
            foreach ($dirs as $sub) {
                mkdir($tempDir . $sub, 0755, true);
            }

            // [Content_Types].xml
            $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
            $ct .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
            $ct .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";
            $ct .= '  <Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>' . "\n";
            foreach ($slides as $i => $s) {
                $num = $i + 1;
                $ct  .= '  <Override PartName="/ppt/slides/slide' . $num . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>' . "\n";
            }
            $ct .= '  <Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>' . "\n";
            $ct .= '  <Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>' . "\n";
            $ct .= '  <Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>' . "\n";
            $ct .= '</Types>';
            file_put_contents($tempDir . '/[Content_Types].xml', $ct);
            unset($ct);

            // _rels/.rels
            $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $rootRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            $rootRels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>' . "\n";
            $rootRels .= '</Relationships>';
            file_put_contents($tempDir . '/_rels/.rels', $rootRels);
            unset($rootRels);

            // ppt/presentation.xml
            $pres = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $pres .= '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
            $pres .= '  <p:sldMasterIdLst>' . "\n";
            $pres .= '    <p:sldMasterId id="2147483648" r:id="rId2"/>' . "\n";
            $pres .= '  </p:sldMasterIdLst>' . "\n";
            $pres .= '  <p:sldIdLst>' . "\n";
            foreach ($slides as $i => $s) {
                $num  = $i + 1;
                $pres .= '    <p:sldId id="' . (256 + $num) . '" r:id="rId' . ($num + 2) . '"/>' . "\n";
            }
            $pres .= '  </p:sldIdLst>' . "\n";
            $pres .= '  <p:sldSz cx="9144000" cy="6858000"/>' . "\n";
            $pres .= '  <p:notesSz cx="6858000" cy="9144000"/>' . "\n";
            $pres .= '</p:presentation>';
            file_put_contents($tempDir . '/ppt/presentation.xml', $pres);
            unset($pres);

            // ppt/_rels/presentation.xml.rels
            $presRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $presRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            $presRels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>' . "\n";
            $presRels .= '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>' . "\n";
            foreach ($slides as $i => $s) {
                $num      = $i + 1;
                $presRels .= '  <Relationship Id="rId' . ($num + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $num . '.xml"/>' . "\n";
            }
            $presRels .= '</Relationships>';
            file_put_contents($tempDir . '/ppt/_rels/presentation.xml.rels', $presRels);
            unset($presRels);

            // ppt/theme/theme1.xml
            $theme = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $theme .= '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office Theme">' . "\n";
            $theme .= '  <a:themeElements>' . "\n";
            $theme .= '    <a:clrScheme name="Office">' . "\n";
            $theme .= '      <a:dk1><a:srgbClr val="000000"/></a:dk1>' . "\n";
            $theme .= '      <a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>' . "\n";
            $theme .= '      <a:dk2><a:srgbClr val="44546A"/></a:dk2>' . "\n";
            $theme .= '      <a:lt2><a:srgbClr val="E7E6E6"/></a:lt2>' . "\n";
            $theme .= '      <a:accent1><a:srgbClr val="5B9BD5"/></a:accent1>' . "\n";
            $theme .= '      <a:accent2><a:srgbClr val="ED7D31"/></a:accent2>' . "\n";
            $theme .= '      <a:accent3><a:srgbClr val="A5A5A5"/></a:accent3>' . "\n";
            $theme .= '      <a:accent4><a:srgbClr val="FFC000"/></a:accent4>' . "\n";
            $theme .= '      <a:accent5><a:srgbClr val="70AD47"/></a:accent5>' . "\n";
            $theme .= '      <a:accent6><a:srgbClr val="2859A3"/></a:accent6>' . "\n";
            $theme .= '    </a:clrScheme>' . "\n";
            $theme .= '    <a:fontScheme name="Office">' . "\n";
            $theme .= '      <a:majorFont><a:latin typeface="Arial"/><a:ea typeface="Arial"/></a:majorFont>' . "\n";
            $theme .= '      <a:minorFont><a:latin typeface="Calibri"/><a:ea typeface="Calibri"/></a:minorFont>' . "\n";
            $theme .= '    </a:fontScheme>' . "\n";
            $theme .= '    <a:fmtScheme name="Office"/>' . "\n";
            $theme .= '  </a:themeElements>' . "\n";
            $theme .= '</a:theme>';
            file_put_contents($tempDir . '/ppt/theme/theme1.xml', $theme);
            unset($theme);

            // ppt/slideMasters/slideMaster1.xml
            $master = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $master .= '<p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
            $master .= '  <p:cSld name="Master">' . "\n";
            $master .= '    <p:bg><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></p:bg>' . "\n";
            $master .= '    <p:spTree>' . "\n";
            $master .= '      <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' . "\n";
            $master .= '      <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="9144000" cy="6858000"/></a:xfrm></p:grpSpPr>' . "\n";
            $master .= '    </p:spTree>' . "\n";
            $master .= '  </p:cSld>' . "\n";
            $master .= '  <p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2"/>' . "\n";
            $master .= '</p:sldMaster>';
            file_put_contents($tempDir . '/ppt/slideMasters/slideMaster1.xml', $master);
            unset($master);

            // ppt/slideLayouts/slideLayout1.xml
            $layout = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $layout .= '<p:sldLayout type="blank" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
            $layout .= '  <p:cSld name="Blank Layout">' . "\n";
            $layout .= '    <p:bg><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></p:bg>' . "\n";
            $layout .= '    <p:spTree>' . "\n";
            $layout .= '      <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' . "\n";
            $layout .= '      <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="9144000" cy="6858000"/></a:xfrm></p:grpSpPr>' . "\n";
            $layout .= '    </p:spTree>' . "\n";
            $layout .= '  </p:cSld>' . "\n";
            $layout .= '</p:sldLayout>';
            file_put_contents($tempDir . '/ppt/slideLayouts/slideLayout1.xml', $layout);
            unset($layout);

            // Generate each slide
            foreach ($slides as $idx => $slide) {
                $num     = $idx + 1;
                $title   = $slide['title'];
                $content = $slide['content'];

                $slideXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
                $slideXml .= '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
                $slideXml .= '  <p:cSld>' . "\n";
                $slideXml .= '    <p:spTree>' . "\n";
                $slideXml .= '      <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' . "\n";
                $slideXml .= '      <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></a:xfrm></p:grpSpPr>' . "\n";

                // Title shape
                if ($title !== '') {
                    $escTitle = htmlspecialchars($title, ENT_XML1, 'UTF-8');
                    $slideXml .= '      <p:sp>' . "\n";
                    $slideXml .= '        <p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>' . "\n";
                    $slideXml .= '        <p:spPr><a:xfrm><a:off x="508000" y="508000"/><a:ext cx="7924860" cy="300000"/></a:xfrm></p:spPr>' . "\n";
                    $slideXml .= '        <p:txBody><a:bodyPr/><a:p><a:r><a:t>' . $escTitle . '</a:t></a:r></a:p></p:txBody>' . "\n";
                    $slideXml .= '      </p:sp>' . "\n";
                }

                // Content paragraphs
                $lines   = preg_split('/\r?\n/', $content);
                $yOffset = 1000000;
                foreach ($lines as $lineIdx => $line) {
                    if (trim($line) === '') continue;
                    $escLine  = htmlspecialchars(trim($line), ENT_XML1, 'UTF-8');
                    $spId     = 10 + $lineIdx;
                    $slideXml .= '      <p:sp>' . "\n";
                    $slideXml .= '        <p:nvSpPr><p:cNvPr id="' . $spId . '" name="Content ' . ($lineIdx + 1) . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>' . "\n";
                    $slideXml .= '        <p:spPr><a:xfrm><a:off x="508000" y="' . ($yOffset + $lineIdx * 300000) . '"/><a:ext cx="7924860" cy="200000"/></a:xfrm></p:spPr>' . "\n";
                    $slideXml .= '        <p:txBody><a:bodyPr/><a:p><a:r><a:t>' . $escLine . '</a:t></a:r></a:p></p:txBody>' . "\n";
                    $slideXml .= '      </p:sp>' . "\n";
                }

                $slideXml .= '    </p:spTree>' . "\n";
                $slideXml .= '  </p:cSld>' . "\n";
                $slideXml .= '  <p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>' . "\n";
                $slideXml .= '</p:sld>';
                file_put_contents($tempDir . '/ppt/slides/slide' . $num . '.xml', $slideXml);
                unset($slideXml);
            }

            // Create ZIP
            if (!file_exists(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                self::rrmdir($tempDir);
                return ['error' => 'Failed to create ZIP archive'];
            }
            self::addDirectoryToZip($zip, $tempDir, '');
            $zip->close();
            unset($zip);

            self::rrmdir($tempDir);
            $tempDir = null;

            return [
                'status'       => 'success',
                'path'         => $path,
                'slides_count' => count($slides),
                'message'      => 'PPTX written successfully.',
            ];
        } catch (\Exception $e) {
            if ($tempDir !== null && is_dir($tempDir)) {
                self::rrmdir($tempDir);
            }
            return ['error' => 'Failed to write PPTX: ' . $e->getMessage()];
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