<?php

/**
 * PPTX Handler - Complete Native PHP Implementation (Read + Write + Append)
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

namespace skills\OfficeSuite\lib;

use modules\agent_core\core;
use Nervsys\Core\Factory;

class pptxHandler extends Factory
{
    public core $core;

    public function __construct()
    {
        $this->core = core::new();
    }

    /**
     * Read all slides from a .pptx file (text only).
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
            return ['error' => 'Failed to open PPTX file as a zip archive.'];
        }

        $presContent = $zip->getFromName('ppt/presentation.xml');
        if (false === $presContent) {
            $zip->close();
            return ['error' => 'Could not find presentation.xml'];
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        if (false === $dom->loadXML($presContent)) {
            $zip->close();
            return ['error' => 'Failed to parse presentation.xml'];
        }

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        // Build relationship map
        $relMap      = [];
        $relsContent = $zip->getFromName('ppt/_rels/presentation.xml.rels');
        if (false !== $relsContent) {
            $relDom = new \DOMDocument();
            if (true === $relDom->loadXML($relsContent)) {
                $relXp = new \DOMXPath($relDom);
                $relXp->registerNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
                $rels = $relXp->query('//pr:Relationship');
                if (false !== $rels) {
                    foreach ($rels as $rel) {
                        $id     = $rel->getAttribute('Id');
                        $target = $rel->getAttribute('Target');
                        if ($id && $target && str_contains($target, 'slides/')) {
                            $relMap[$id] = ltrim($target, '/');
                        }
                    }
                }
            }
        }

        $slideRels = [];
        $sldNodes  = $xp->query('//p:sldIdLst/p:sldId');
        if (false !== $sldNodes) {
            foreach ($sldNodes as $sldId) {
                $rId = '';
                foreach ($sldId->attributes as $attr) {
                    if ('r:id' === $attr->name) {
                        $rId = $attr->value;
                        break;
                    }
                }
                if ($rId && isset($relMap[$rId])) {
                    $slideRels[] = 'ppt/' . $relMap[$rId];
                }
            }
        }

        // Fallback: scan all slide*.xml files
        if (empty($slideRels)) {
            for ($i = 1; $i <= 100; $i++) {
                $file = "ppt/slides/slide{$i}.xml";
                if (false !== $zip->locateName($file)) {
                    $slideRels[] = $file;
                }
            }
        }

        $slides = [];
        foreach ($slideRels as $idx => $sf) {
            $xmlContent = $zip->getFromName($sf);
            if (false === $xmlContent) {
                continue;
            }

            $domSlide = new \DOMDocument('1.0', 'UTF-8');
            libxml_clear_errors();
            if (false === $domSlide->loadXML($xmlContent)) {
                continue;
            }
            $xpS = new \DOMXPath($domSlide);
            $xpS->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $xpS->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

            $titleTexts   = [];
            $contentTexts = [];
            $shapes       = $xpS->query('//p:sp');
            if (false !== $shapes) {
                foreach ($shapes as $shape) {
                    $isTitle  = false;
                    $nvPrList = $xpS->query('./p:nvSpPr/p:nvPr', $shape);
                    if (false !== $nvPrList && $nvPrList->length > 0) {
                        $phNodes = $xpS->query('p:ph', $nvPrList->item(0));
                        if (false !== $phNodes && $phNodes->length > 0) {
                            $phType = $phNodes->item(0)->getAttribute('type');
                            if ('title' === $phType || '0' === $phNodes->item(0)->getAttribute('idx')) {
                                $isTitle = true;
                            }
                        }
                    }
                    $textNodes = $xpS->query('.//a:t', $shape);
                    if (false !== $textNodes) {
                        foreach ($textNodes as $tNode) {
                            $text = trim($tNode->nodeValue);
                            if ('' !== $text) {
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

            if ('' === $title && !empty($content)) {
                $lines = explode("\n", $content);
                if (count($lines) > 1 && strlen($lines[0]) < 200) {
                    $title   = array_shift($lines);
                    $content = implode("\n", $lines);
                }
            }

            if (strlen($title) > 500) {
                $title = substr($title, 0, 500);
            }

            $slides[] = [
                'number'  => $idx + 1,
                'title'   => $title,
                'content' => $content,
            ];

            unset($xmlContent, $domSlide, $xpS, $titleTexts, $contentTexts, $shapes, $title, $content);
        }

        $zip->close();
        libxml_use_internal_errors(false);

        $result = [
            'status'       => 'success',
            'file'         => basename($path),
            'slides_count' => count($slides),
            'slides'       => $slides,
        ];

        unset($zip, $presContent, $dom, $xp, $relMap, $relsContent, $slideRels, $sldNodes, $slideRels);
        unset($slideFiles, $slides, $idx, $sf);
        return $result;
    }

    /**
     * Parse existing PPTX into full slide data (including images) for append.
     * Uses direct scanning of slide files to be robust.
     *
     * @param string $path
     * @param string $tempMediaDir
     *
     * @return array
     */
    private function parseExistingSlides(string $path, string $tempMediaDir): array
    {
        $slides = [];
        if (!file_exists($path)) {
            return $slides;
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return $slides;
        }

        // Collect all slide file names from ppt/slides/
        $slideFiles = [];
        for ($i = 1; $i <= 100; $i++) {
            $file = "ppt/slides/slide{$i}.xml";
            if (false !== $zip->locateName($file)) {
                $slideFiles[] = $file;
            }
        }

        if (empty($slideFiles)) {
            $zip->close();
            return $slides;
        }

        foreach ($slideFiles as $slideFile) {
            $slideXml = $zip->getFromName($slideFile);
            if (false === $slideXml) {
                continue;
            }

            $slideDom = new \DOMDocument('1.0', 'UTF-8');
            if (false === $slideDom->loadXML($slideXml)) {
                continue;
            }
            $xpSlide = new \DOMXPath($slideDom);
            $xpSlide->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $xpSlide->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $xpSlide->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $xpSlide->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');

            // Extract text
            $titleTexts   = [];
            $contentTexts = [];
            $shapes       = $xpSlide->query('//p:sp');
            if (false !== $shapes) {
                foreach ($shapes as $shape) {
                    $isTitle  = false;
                    $nvPrList = $xpSlide->query('./p:nvSpPr/p:nvPr', $shape);
                    if (false !== $nvPrList && $nvPrList->length > 0) {
                        $phNodes = $xpSlide->query('p:ph', $nvPrList->item(0));
                        if (false !== $phNodes && $phNodes->length > 0) {
                            $phType = $phNodes->item(0)->getAttribute('type');
                            if ('title' === $phType || '0' === $phNodes->item(0)->getAttribute('idx')) {
                                $isTitle = true;
                            }
                        }
                    }
                    $textNodes = $xpSlide->query('.//a:t', $shape);
                    if (false !== $textNodes) {
                        foreach ($textNodes as $tNode) {
                            $text = trim($tNode->nodeValue);
                            if ('' !== $text) {
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
            $content = implode("\n", array_map('trim', $contentTexts));

            // Extract images from drawings (p:pic)
            $drawings   = $xpSlide->query('//p:pic');
            $imageItems = [];
            if (false !== $drawings) {
                foreach ($drawings as $drawing) {
                    $blipNodes = $xpSlide->query('.//a:blip/@r:embed', $drawing);
                    if (false === $blipNodes || 0 === $blipNodes->length) {
                        continue;
                    }
                    $rId = $blipNodes->item(0)->value;

                    // Get image target from slide's relationship file
                    $slideRelsPath    = dirname($slideFile) . '/_rels/' . basename($slideFile) . '.rels';
                    $slideRelsContent = $zip->getFromName($slideRelsPath);
                    $imageTarget      = null;
                    if (false !== $slideRelsContent) {
                        $relReader = new \XMLReader();
                        if (true === $relReader->XML($slideRelsContent)) {
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
                                    if ($id === $rId && $target) {
                                        $imageTarget = $target;
                                        break;
                                    }
                                }
                            }
                        }
                        $relReader->close();
                    }
                    if (!$imageTarget) {
                        continue;
                    }
                    $imageZipPath = 'ppt/' . $imageTarget;
                    $imageData    = $zip->getFromName($imageZipPath);
                    if (false === $imageData) {
                        continue;
                    }

                    $ext = strtolower(pathinfo($imageTarget, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'bmp'])) {
                        $ext = 'png';
                    }
                    $tempName = 'orig_img_' . uniqid() . '.' . $ext;
                    $tempPath = $tempMediaDir . '/' . $tempName;
                    file_put_contents($tempPath, $imageData);

                    // Get dimensions
                    $xfrmNodes = $xpSlide->query('.//a:xfrm', $drawing);
                    $widthEmu  = 2540000;
                    $heightEmu = 1905000;
                    if (false !== $xfrmNodes && $xfrmNodes->length > 0) {
                        $cx = $xfrmNodes->item(0)->getAttribute('cx');
                        $cy = $xfrmNodes->item(0)->getAttribute('cy');
                        if ($cx && $cy) {
                            $widthEmu  = (int)$cx;
                            $heightEmu = (int)$cy;
                        }
                    }
                    $widthPx  = (int)round($widthEmu / 9525);
                    $heightPx = (int)round($heightEmu / 9525);

                    $imageItems[] = [
                        'type'   => 'image',
                        'path'   => $tempPath,
                        'width'  => $widthPx,
                        'height' => $heightPx,
                        'orig_x' => 8000000,
                        'orig_y' => 500000,
                    ];

                    unset($blipNodes, $rId, $slideRelsContent, $imageTarget, $imageData, $ext, $tempPath, $xfrmNodes, $widthEmu, $heightEmu, $widthPx, $heightPx);
                }
            }

            $slideData = [
                'title'   => $title,
                'content' => $content,
            ];
            if (!empty($imageItems)) {
                $img                       = $imageItems[0];
                $slideData['image']        = $img['path'];
                $slideData['image_width']  = $img['width'];
                $slideData['image_height'] = $img['height'];
                $slideData['image_x']      = $img['orig_x'];
                $slideData['image_y']      = $img['orig_y'];
            }
            $slides[] = $slideData;

            unset($slideXml, $slideDom, $xpSlide, $titleTexts, $contentTexts, $shapes, $title, $content, $drawings, $imageItems, $slideData);
        }

        $zip->close();
        unset($zip, $slideFiles, $slideFile);
        return $slides;
    }

    /**
     * Generate XML for a single slide (text only, images are added via relationships).
     *
     * @param int   $slideNum
     * @param array $slide
     *
     * @return string
     */
    private function generateSlideXml(int $slideNum, array $slide): string
    {
        $title   = $slide['title'] ?? '';
        $content = $slide['content'] ?? '';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $xml .= '  <p:cSld>' . "\n";
        $xml .= '    <p:spTree>' . "\n";
        $xml .= '      <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' . "\n";
        $xml .= '      <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></a:xfrm></p:grpSpPr>' . "\n";

        if ('' !== $title) {
            $escTitle = htmlspecialchars($title, ENT_XML1, 'UTF-8');
            $xml      .= '      <p:sp>' . "\n";
            $xml      .= '        <p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>' . "\n";
            $xml      .= '        <p:spPr><a:xfrm><a:off x="508000" y="508000"/><a:ext cx="7924860" cy="300000"/></a:xfrm></p:spPr>' . "\n";
            $xml      .= '        <p:txBody><a:bodyPr/><a:p><a:r><a:t>' . $escTitle . '</a:t></a:r></a:p></p:txBody>' . "\n";
            $xml      .= '      </p:sp>' . "\n";
            unset($escTitle);
        }

        $lines   = preg_split('/\r?\n/', $content);
        $yOffset = 1000000;
        foreach ($lines as $idx => $line) {
            if ('' === trim($line)) {
                continue;
            }
            $escLine = htmlspecialchars(trim($line), ENT_XML1, 'UTF-8');
            $xml     .= '      <p:sp>' . "\n";
            $xml     .= '        <p:nvSpPr><p:cNvPr id="' . (10 + $idx) . '" name="Content ' . ($idx + 1) . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>' . "\n";
            $xml     .= '        <p:spPr><a:xfrm><a:off x="508000" y="' . ($yOffset + $idx * 300000) . '"/><a:ext cx="7924860" cy="200000"/></a:xfrm></p:spPr>' . "\n";
            $xml     .= '        <p:txBody><a:bodyPr/><a:p><a:r><a:t>' . $escLine . '</a:t></a:r></a:p></p:txBody>' . "\n";
            $xml     .= '      </p:sp>' . "\n";
            unset($escLine);
        }

        $xml .= '    </p:spTree>' . "\n";
        $xml .= '  </p:cSld>' . "\n";
        $xml .= '  <p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>' . "\n";
        $xml .= '</p:sld>';

        unset($title, $content, $lines, $yOffset);
        return $xml;
    }

    /**
     * Write PPTX with append support (preserves images from original file).
     *
     * @param string $path
     * @param array  $data
     * @param bool   $append
     *
     * @return array
     */
    public function write(string $path, array $data, bool $append = false): array
    {
        if (!$append || !file_exists($path)) {
            return $this->writeNew($path, $this->normalizeSlides($data), null);
        }

        // Append mode
        $tempDir = $this->core->agent_config['workspace_path'] . '/temp/pptx_append_' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            return ['error' => 'Cannot create temp directory'];
        }

        // 1. Extract original file
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            $this->rrmdir($tempDir);
            return ['error' => 'Failed to open original PPTX file'];
        }
        $zip->extractTo($tempDir);
        $zip->close();
        unset($zip);

        // 2. Find maximum existing slide number
        $slideFiles  = glob($tempDir . '/ppt/slides/slide*.xml');
        $maxSlideNum = 0;
        foreach ($slideFiles as $file) {
            if (preg_match('/slide(\d+)\.xml$/', $file, $matches)) {
                $num = (int)$matches[1];
                if ($num > $maxSlideNum) {
                    $maxSlideNum = $num;
                }
            }
            unset($file, $matches);
        }
        $nextSlideNum = $maxSlideNum + 1;

        // 3. Find maximum existing image number
        $mediaFiles  = glob($tempDir . '/ppt/media/image*.{jpg,jpeg,png,gif,bmp}', GLOB_BRACE);
        $maxImageNum = 0;
        foreach ($mediaFiles as $file) {
            if (preg_match('/image(\d+)\./', $file, $matches)) {
                $num = (int)$matches[1];
                if ($num > $maxImageNum) {
                    $maxImageNum = $num;
                }
            }
            unset($file, $matches);
        }
        $nextImageNum = $maxImageNum + 1;

        // 4. Process new slides
        $newSlides   = $this->normalizeSlides($data);
        $newSlideIds = []; // ['num' => real file number]

        foreach ($newSlides as $idx => $slide) {
            $realNum   = $nextSlideNum + $idx;
            $slideFile = "slide{$realNum}.xml";
            $relFile   = "slide{$realNum}.xml.rels";

            // Generate slide XML
            $slideXml = $this->generateSlideXml($realNum, $slide);
            file_put_contents($tempDir . "/ppt/slides/{$slideFile}", $slideXml);
            unset($slideXml);

            // Handle image if present
            $relsEntries = [];
            if (!empty($slide['image']) && file_exists($slide['image'])) {
                $ext          = strtolower(pathinfo($slide['image'], PATHINFO_EXTENSION));
                $newImageName = "image{$nextImageNum}.{$ext}";
                copy($slide['image'], $tempDir . "/ppt/media/{$newImageName}");
                $relId         = 'rId' . (100 + $nextImageNum);
                $relsEntries[] = [
                    'Id'     => $relId,
                    'Type'   => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image',
                    'Target' => "media/{$newImageName}"
                ];
                $nextImageNum++;
                unset($ext, $newImageName, $relId);
            }

            // Must include slideLayout relationship
            $relsEntries[] = [
                'Id'     => 'rId1',
                'Type'   => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout',
                'Target' => '../slideLayouts/slideLayout1.xml'
            ];

            // Write relationships file
            $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $relsXml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            foreach ($relsEntries as $rel) {
                $relsXml .= '  <Relationship Id="' . $rel['Id'] . '" Type="' . $rel['Type'] . '" Target="' . $rel['Target'] . '"/>' . "\n";
            }
            $relsXml .= '</Relationships>';
            $relsDir = $tempDir . '/ppt/slides/_rels';
            if (!is_dir($relsDir)) {
                mkdir($relsDir, 0755, true);
            }
            file_put_contents($relsDir . '/' . $relFile, $relsXml);
            unset($relsEntries, $relsXml, $relsDir, $relFile);

            $newSlideIds[] = ['num' => $realNum];
            unset($realNum, $slideFile);
        }
        unset($newSlides, $idx, $slide, $nextSlideNum, $nextImageNum);

        // 5. Update presentation.xml: add <p:sldId>
        $presXmlFile = $tempDir . '/ppt/presentation.xml';
        $presXml     = file_get_contents($presXmlFile);
        if (false === $presXml) {
            $this->rrmdir($tempDir);
            return ['error' => 'Failed to read presentation.xml'];
        }
        $dom = new \DOMDocument();
        $dom->loadXML($presXml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $sldIdLst = $xp->query('//p:sldIdLst')->item(0);
        if (!$sldIdLst) {
            $sldIdLst = $dom->createElementNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'p:sldIdLst');
            $dom->documentElement->insertBefore($sldIdLst, $dom->documentElement->firstChild);
        }

        // Find maximum existing sldId
        $maxSldId       = 0;
        $existingSldIds = $xp->query('//p:sldId');
        if (false !== $existingSldIds) {
            foreach ($existingSldIds as $node) {
                $id = (int)$node->getAttribute('id');
                if ($id > $maxSldId) {
                    $maxSldId = $id;
                }
            }
        }
        $nextSldId = $maxSldId + 1;

        foreach ($newSlideIds as $idx => $info) {
            $sldId = $dom->createElement('p:sldId');
            $sldId->setAttribute('id', $nextSldId + $idx);
            $sldId->setAttribute('r:id', 'rId_temp_' . $idx); // temporary placeholder
            $sldIdLst->appendChild($sldId);
        }
        $newPresXml = $dom->saveXML();
        file_put_contents($presXmlFile, $newPresXml);
        unset($presXml, $dom, $xp, $sldIdLst, $existingSldIds, $maxSldId, $nextSldId, $newPresXml);

        // 6. Update ppt/_rels/presentation.xml.rels
        $relsFile    = $tempDir . '/ppt/_rels/presentation.xml.rels';
        $relsContent = file_get_contents($relsFile);
        if (false === $relsContent) {
            $this->rrmdir($tempDir);
            return ['error' => 'Failed to read presentation.xml.rels'];
        }
        $relsDom = new \DOMDocument();
        $relsDom->loadXML($relsContent);
        $root = $relsDom->documentElement;

        // Find maximum rId
        $maxRelId     = 0;
        $existingRels = $root->getElementsByTagName('Relationship');
        foreach ($existingRels as $rel) {
            $id = $rel->getAttribute('Id');
            if (preg_match('/rId(\d+)/', $id, $m)) {
                $num = (int)$m[1];
                if ($num > $maxRelId) {
                    $maxRelId = $num;
                }
            }
            unset($rel, $id, $m);
        }
        $nextRelId = $maxRelId + 1;

        // Re-open presentation.xml to replace placeholders
        $dom = new \DOMDocument();
        $dom->load($presXmlFile);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $sldIdNodes  = $xp->query('//p:sldId');
        $newSlideIdx = 0;
        foreach ($sldIdNodes as $node) {
            $rAttr = $node->getAttributeNode('r:id');
            if ($rAttr && 0 === strpos($rAttr->value, 'rId_temp_')) {
                $realRelId    = 'rId' . ($nextRelId + $newSlideIdx);
                $rAttr->value = $realRelId;

                // Add Relationship
                $rel = $relsDom->createElement('Relationship');
                $rel->setAttribute('Id', $realRelId);
                $rel->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide');
                $rel->setAttribute('Target', 'slides/slide' . $newSlideIds[$newSlideIdx]['num'] . '.xml');
                $root->appendChild($rel);

                $newSlideIdx++;
                unset($realRelId, $rel);
            }
            unset($rAttr);
        }
        file_put_contents($presXmlFile, $dom->saveXML());
        file_put_contents($relsFile, $relsDom->saveXML());
        unset($relsContent, $relsDom, $root, $existingRels, $maxRelId, $nextRelId, $dom, $xp, $sldIdNodes, $newSlideIdx);

        // 7. Update [Content_Types].xml
        $ctFile    = $tempDir . '/[Content_Types].xml';
        $ctContent = file_get_contents($ctFile);
        if (false === $ctContent) {
            $this->rrmdir($tempDir);
            return ['error' => 'Failed to read [Content_Types].xml'];
        }
        $ctDom = new \DOMDocument();
        $ctDom->loadXML($ctContent);
        $ctRoot = $ctDom->documentElement;
        foreach ($newSlideIds as $info) {
            $override = $ctDom->createElement('Override');
            $override->setAttribute('PartName', '/ppt/slides/slide' . $info['num'] . '.xml');
            $override->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml');
            $ctRoot->appendChild($override);
            unset($override);
        }
        file_put_contents($ctFile, $ctDom->saveXML());
        unset($ctContent, $ctDom, $ctRoot);

        // 8. Re-pack zip
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $newZip = new \ZipArchive();
        if (true !== $newZip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            $this->rrmdir($tempDir);
            return ['error' => 'Failed to create updated PPTX archive'];
        }
        $this->addDirToZip($newZip, $tempDir, '');
        $newZip->close();
        unset($newZip);

        $this->rrmdir($tempDir);

        $result = [
            'status'       => 'success',
            'path'         => $path,
            'slides_count' => count($newSlideIds) + $maxSlideNum,
            'message'      => 'Appended successfully'
        ];

        unset($tempDir, $slideFiles, $maxSlideNum, $mediaFiles, $maxImageNum, $newSlideIds);
        return $result;
    }

    /**
     * Write new PPTX file (overwrite). Optionally use existing media dir for images.
     *
     * @param string      $path
     * @param array       $slides
     * @param string|null $existingMediaDir
     *
     * @return array
     */
    private function writeNew(string $path, array $slides, ?string $existingMediaDir = null): array
    {
        $tempDir = null;
        try {
            if (empty($slides)) {
                $slides = [['title' => '', 'content' => '', 'image' => null]];
            }

            $tempDir = $this->core->agent_config['workspace_path'] . '/temp/pptx_' . uniqid('pptx_', true);
            if (!mkdir($tempDir, 0755, true)) {
                return ['error' => 'Cannot create temp directory'];
            }

            $dirs = [
                '/_rels', '/ppt/_rels', '/ppt/slides', '/ppt/slideMasters',
                '/ppt/slideLayouts', '/ppt/theme', '/ppt/media',
                '/ppt/slideMasters/_rels', '/ppt/slideLayouts/_rels'
            ];
            foreach ($dirs as $sub) {
                mkdir($tempDir . $sub, 0755, true);
            }
            mkdir($tempDir . '/docProps', 0755, true);

            $this->writeContentTypes($tempDir, $slides);
            $this->writeRootRels($tempDir);
            $this->writePresentationXml($tempDir, $slides);
            $this->writePresentationRels($tempDir, $slides);
            $this->writeTheme($tempDir);
            $this->writeTableStyles($tempDir);
            $this->writePresProps($tempDir);
            $this->writeViewProps($tempDir);
            $this->writeDocProps($tempDir, $slides);
            $this->writeSlideMaster($tempDir);
            $this->writeSlideLayout($tempDir);

            $imageCounter   = 1;
            $targetMediaDir = $tempDir . '/ppt/media';
            foreach ($slides as $idx => $slide) {
                $slideNum = $idx + 1;
                if (!empty($slide['image'])) {
                    $imgPath = $slide['image'];
                    if (null !== $existingMediaDir && 0 === strpos($imgPath, $existingMediaDir)) {
                        $ext     = pathinfo($imgPath, PATHINFO_EXTENSION);
                        $newName = 'image' . $imageCounter . '.' . $ext;
                        $dest    = $targetMediaDir . '/' . $newName;
                        copy($imgPath, $dest);
                        $slide['image'] = $dest;
                        unset($ext, $newName, $dest);
                    } elseif (file_exists($imgPath)) {
                        $ext     = pathinfo($imgPath, PATHINFO_EXTENSION);
                        $newName = 'image' . $imageCounter . '.' . $ext;
                        $dest    = $targetMediaDir . '/' . $newName;
                        copy($imgPath, $dest);
                        $slide['image'] = $dest;
                        unset($ext, $newName, $dest);
                    }
                    $imageCounter++;
                }
                $this->writeSlide($tempDir, $slideNum, $slide, $imageCounter);
                unset($slideNum, $slide);
            }

            if (!file_exists(dirname($path))) {
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
            $tempDir = null;

            $result = [
                'status'       => 'success',
                'path'         => $path,
                'slides_count' => count($slides),
                'message'      => 'PPTX written successfully.',
            ];

            unset($slides, $targetMediaDir, $imageCounter);
            return $result;
        } catch (\Exception $e) {
            if (null !== $tempDir && is_dir($tempDir)) {
                $this->rrmdir($tempDir);
            }
            return ['error' => 'Failed to write PPTX: ' . $e->getMessage()];
        }
    }

    /**
     * Normalize slide data into internal format.
     *
     * @param array $data
     *
     * @return array
     */
    private function normalizeSlides(array $data): array
    {
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
                $slide    = [
                    'title'        => $title,
                    'content'      => $content,
                    'image'        => $item['image'] ?? null,
                    'image_x'      => isset($item['image_x']) ? (int)$item['image_x'] : 8000000,
                    'image_y'      => isset($item['image_y']) ? (int)$item['image_y'] : 500000,
                    'image_width'  => isset($item['image_width']) ? (int)$item['image_width'] : 2540000,
                    'image_height' => isset($item['image_height']) ? (int)$item['image_height'] : 1905000,
                ];
                $slides[] = $slide;
            } else {
                $slides[] = [
                    'title'   => '',
                    'content' => trim((string)$item),
                    'image'   => null,
                ];
            }
        }
        return $slides;
    }

    // -------------------------------------------------------------------------
    // XML generation methods (all required)
    // -------------------------------------------------------------------------

    private function writeContentTypes(string $tempDir, array $slides): void
    {
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
        $ct .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
        $ct .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";

        $imageTypes = [];
        foreach ($slides as $slide) {
            if ($slide['image'] && file_exists($slide['image'])) {
                $ext = strtolower(pathinfo($slide['image'], PATHINFO_EXTENSION));
                if ('png' === $ext) {
                    $imageTypes['png'] = 'image/png';
                } elseif ('jpg' === $ext || 'jpeg' === $ext) {
                    $imageTypes['jpg'] = 'image/jpeg';
                } elseif ('gif' === $ext) {
                    $imageTypes['gif'] = 'image/gif';
                } elseif ('bmp' === $ext) {
                    $imageTypes['bmp'] = 'image/bmp';
                }
            }
        }
        foreach ($imageTypes as $ext => $mime) {
            $ct .= '  <Default Extension="' . $ext . '" ContentType="' . $mime . '"/>' . "\n";
        }

        $ct .= '  <Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>' . "\n";
        foreach ($slides as $i => $s) {
            $ct .= '  <Override PartName="/ppt/slides/slide' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>' . "\n";
        }
        $ct .= '  <Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>' . "\n";
        $ct .= '  <Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>' . "\n";
        $ct .= '  <Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>' . "\n";
        $ct .= '  <Override PartName="/ppt/tableStyles.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.tableStyles+xml"/>' . "\n";
        $ct .= '  <Override PartName="/ppt/presProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presProps+xml"/>' . "\n";
        $ct .= '  <Override PartName="/ppt/viewProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.viewProps+xml"/>' . "\n";
        $ct .= '  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' . "\n";
        $ct .= '  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' . "\n";
        $ct .= '</Types>';
        file_put_contents($tempDir . '/[Content_Types].xml', $ct);
        unset($ct, $imageTypes);
    }

    private function writeRootRels(string $tempDir): void
    {
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rootRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $rootRels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>' . "\n";
        $rootRels .= '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' . "\n";
        $rootRels .= '  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' . "\n";
        $rootRels .= '</Relationships>';
        file_put_contents($tempDir . '/_rels/.rels', $rootRels);
        unset($rootRels);
    }

    private function writePresentationXml(string $tempDir, array $slides): void
    {
        $pres = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $pres .= '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $pres .= '  <p:sldMasterIdLst>' . "\n";
        $pres .= '    <p:sldMasterId id="2147483648" r:id="rId2"/>' . "\n";
        $pres .= '  </p:sldMasterIdLst>' . "\n";
        $pres .= '  <p:sldIdLst>' . "\n";
        foreach ($slides as $i => $s) {
            $pres .= '    <p:sldId id="' . (256 + $i + 1) . '" r:id="rId' . ($i + 3) . '"/>' . "\n";
        }
        $pres .= '  </p:sldIdLst>' . "\n";
        $pres .= '  <p:sldSz cx="12192000" cy="6858000"/>' . "\n";
        $pres .= '  <p:notesSz cx="6858000" cy="9144000"/>' . "\n";
        $pres .= '</p:presentation>';
        file_put_contents($tempDir . '/ppt/presentation.xml', $pres);
        unset($pres);
    }

    private function writePresentationRels(string $tempDir, array $slides): void
    {
        $presRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $presRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $presRels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>' . "\n";
        $presRels .= '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>' . "\n";
        foreach ($slides as $i => $s) {
            $presRels .= '  <Relationship Id="rId' . ($i + 3) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . ($i + 1) . '.xml"/>' . "\n";
        }
        $presRels .= '  <Relationship Id="rId' . (count($slides) + 3) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/>' . "\n";
        $presRels .= '  <Relationship Id="rId' . (count($slides) + 4) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/viewProps" Target="viewProps.xml"/>' . "\n";
        $presRels .= '  <Relationship Id="rId' . (count($slides) + 5) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tableStyles" Target="tableStyles.xml"/>' . "\n";
        $presRels .= '</Relationships>';
        file_put_contents($tempDir . '/ppt/_rels/presentation.xml.rels', $presRels);
        unset($presRels);
    }

    private function writeTheme(string $tempDir): void
    {
        $theme = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $theme .= '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office Theme">' . "\n";
        $theme .= '  <a:themeElements>' . "\n";
        $theme .= '    <a:clrScheme name="Office">' . "\n";
        $theme .= '      <a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1>' . "\n";
        $theme .= '      <a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>' . "\n";
        $theme .= '      <a:dk2><a:srgbClr val="0E2841"/></a:dk2>' . "\n";
        $theme .= '      <a:lt2><a:srgbClr val="E8E8E8"/></a:lt2>' . "\n";
        $theme .= '      <a:accent1><a:srgbClr val="156082"/></a:accent1>' . "\n";
        $theme .= '      <a:accent2><a:srgbClr val="E97132"/></a:accent2>' . "\n";
        $theme .= '      <a:accent3><a:srgbClr val="196B24"/></a:accent3>' . "\n";
        $theme .= '      <a:accent4><a:srgbClr val="0F9ED5"/></a:accent4>' . "\n";
        $theme .= '      <a:accent5><a:srgbClr val="A02B93"/></a:accent5>' . "\n";
        $theme .= '      <a:accent6><a:srgbClr val="4EA72E"/></a:accent6>' . "\n";
        $theme .= '      <a:hlink><a:srgbClr val="467886"/></a:hlink>' . "\n";
        $theme .= '      <a:folHlink><a:srgbClr val="96607D"/></a:folHlink>' . "\n";
        $theme .= '    </a:clrScheme>' . "\n";
        $theme .= '    <a:fontScheme name="Office">' . "\n";
        $theme .= '      <a:majorFont><a:latin typeface="等线 Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>' . "\n";
        $theme .= '      <a:minorFont><a:latin typeface="等线"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>' . "\n";
        $theme .= '    </a:fontScheme>' . "\n";
        $theme .= '    <a:fmtScheme name="Office"/>' . "\n";
        $theme .= '  </a:themeElements>' . "\n";
        $theme .= '</a:theme>';
        file_put_contents($tempDir . '/ppt/theme/theme1.xml', $theme);
        unset($theme);
    }

    private function writeTableStyles(string $tempDir): void
    {
        $tableStyles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $tableStyles .= '<a:tblStyleLst xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>';
        file_put_contents($tempDir . '/ppt/tableStyles.xml', $tableStyles);
        unset($tableStyles);
    }

    private function writePresProps(string $tempDir): void
    {
        $presProps = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $presProps .= '<p:presentationPr xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $presProps .= '  <p:extLst>' . "\n";
        $presProps .= '    <p:ext uri="{E76CE94A-603C-4142-B9EB-6D1370010A27}"><p14:discardImageEditData xmlns:p14="http://schemas.microsoft.com/office/powerpoint/2010/main" val="0"/></p:ext>' . "\n";
        $presProps .= '    <p:ext uri="{D31A062A-798A-4329-ABDD-BBA856620510}"><p14:defaultImageDpi xmlns:p14="http://schemas.microsoft.com/office/powerpoint/2010/main" val="32767"/></p:ext>' . "\n";
        $presProps .= '    <p:ext uri="{FD5EFAAD-0ECE-453E-9831-46B23BE46B34}"><p15:chartTrackingRefBased xmlns:p15="http://schemas.microsoft.com/office/powerpoint/2012/main" val="1"/></p:ext>' . "\n";
        $presProps .= '  </p:extLst>' . "\n";
        $presProps .= '</p:presentationPr>';
        file_put_contents($tempDir . '/ppt/presProps.xml', $presProps);
        unset($presProps);
    }

    private function writeViewProps(string $tempDir): void
    {
        $viewProps = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $viewProps .= '<p:viewPr xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $viewProps .= '  <p:normalViewPr><p:restoredLeft sz="15620"/><p:restoredTop sz="94660"/></p:normalViewPr>' . "\n";
        $viewProps .= '  <p:slideViewPr>' . "\n";
        $viewProps .= '    <p:cSldViewPr snapToGrid="0"><p:cViewPr varScale="1"><p:scale><a:sx n="104" d="100"/><a:sy n="104" d="100"/></p:scale><p:origin x="284" y="76"/></p:cViewPr><p:guideLst/></p:cSldViewPr>' . "\n";
        $viewProps .= '  </p:slideViewPr>' . "\n";
        $viewProps .= '  <p:notesTextViewPr><p:cViewPr><p:scale><a:sx n="1" d="1"/><a:sy n="1" d="1"/></p:scale><p:origin x="0" y="0"/></p:cViewPr></p:notesTextViewPr>' . "\n";
        $viewProps .= '  <p:gridSpacing cx="72008" cy="72008"/>' . "\n";
        $viewProps .= '</p:viewPr>';
        file_put_contents($tempDir . '/ppt/viewProps.xml', $viewProps);
        unset($viewProps);
    }

    private function writeDocProps(string $tempDir, array $slides): void
    {
        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $appXml .= '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">' . "\n";
        $appXml .= '  <Application>AgentDocEngine</Application>' . "\n";
        $appXml .= '  <Slides>' . count($slides) . '</Slides>' . "\n";
        $appXml .= '  <TotalTime>0</TotalTime>' . "\n";
        $appXml .= '  <ScaleCrop>false</ScaleCrop>' . "\n";
        $appXml .= '  <LinksUpToDate>false</LinksUpToDate>' . "\n";
        $appXml .= '  <SharedDoc>false</SharedDoc>' . "\n";
        $appXml .= '  <HyperlinksChanged>false</HyperlinksChanged>' . "\n";
        $appXml .= '  <AppVersion>16.0000</AppVersion>' . "\n";
        $appXml .= '</Properties>';
        file_put_contents($tempDir . '/docProps/app.xml', $appXml);

        $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $coreXml .= '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        $coreXml .= '  <dc:creator>AgentDocEngine</dc:creator>' . "\n";
        $coreXml .= '  <cp:lastModifiedBy>AgentDocEngine</cp:lastModifiedBy>' . "\n";
        $coreXml .= '  <dcterms:created xsi:type="dcterms:W3CDTF">' . date('Y-m-d\TH:i:s\Z') . '</dcterms:created>' . "\n";
        $coreXml .= '  <dcterms:modified xsi:type="dcterms:W3CDTF">' . date('Y-m-d\TH:i:s\Z') . '</dcterms:modified>' . "\n";
        $coreXml .= '</cp:coreProperties>';
        file_put_contents($tempDir . '/docProps/core.xml', $coreXml);
        unset($appXml, $coreXml);
    }

    private function writeSlideMaster(string $tempDir): void
    {
        $master = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $master .= '<p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $master .= '  <p:cSld>' . "\n";
        $master .= '    <p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>' . "\n";
        $master .= '    <p:spTree>' . "\n";
        $master .= '      <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' . "\n";
        $master .= '      <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>' . "\n";
        $master .= '      <p:sp><p:nvSpPr><p:cNvPr id="2" name="Title Placeholder"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:spPr><a:xfrm><a:off x="838200" y="365125"/><a:ext cx="10515600" cy="1325563"/></a:xfrm><a:prstGeom prst="rect"/></p:spPr><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>单击此处编辑母版标题样式</a:t></a:r></a:p></p:txBody></p:sp>' . "\n";
        $master .= '      <p:sp><p:nvSpPr><p:cNvPr id="3" name="Body Placeholder"/><p:cNvSpPr/><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:spPr><a:xfrm><a:off x="838200" y="1825625"/><a:ext cx="10515600" cy="4351338"/></a:xfrm><a:prstGeom prst="rect"/></p:spPr><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:pPr lvl="0"/><a:r><a:t>单击此处编辑母版文本样式</a:t></a:r></a:p></p:txBody></p:sp>' . "\n";
        $master .= '    </p:spTree>' . "\n";
        $master .= '  </p:cSld>' . "\n";
        $master .= '  <p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>' . "\n";
        $master .= '</p:sldMaster>';
        file_put_contents($tempDir . '/ppt/slideMasters/slideMaster1.xml', $master);

        $relsDir = $tempDir . '/ppt/slideMasters/_rels';
        if (!is_dir($relsDir)) {
            mkdir($relsDir, 0755, true);
        }
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $rels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>' . "\n";
        $rels .= '</Relationships>';
        file_put_contents($relsDir . '/slideMaster1.xml.rels', $rels);
        unset($master, $relsDir, $rels);
    }

    private function writeSlideLayout(string $tempDir): void
    {
        $layout = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $layout .= '<p:sldLayout type="blank" preserve="1" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $layout .= '  <p:cSld name="Blank Layout">' . "\n";
        $layout .= '    <p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree>' . "\n";
        $layout .= '  </p:cSld>' . "\n";
        $layout .= '</p:sldLayout>';
        file_put_contents($tempDir . '/ppt/slideLayouts/slideLayout1.xml', $layout);

        $relsDir = $tempDir . '/ppt/slideLayouts/_rels';
        if (!is_dir($relsDir)) {
            mkdir($relsDir, 0755, true);
        }
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $rels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>' . "\n";
        $rels .= '</Relationships>';
        file_put_contents($relsDir . '/slideLayout1.xml.rels', $rels);
        unset($layout, $relsDir, $rels);
    }

    /**
     * Write a single slide XML file and its relationships.
     *
     * @param string $tempDir
     * @param int    $slideNum
     * @param array  $slide
     * @param int    $imageCounter
     *
     * @return void
     */
    private function writeSlide(string $tempDir, int $slideNum, array $slide, int &$imageCounter): void
    {
        $title   = $slide['title'];
        $content = $slide['content'];
        $image   = $slide['image'] ?? null;

        $slideXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $slideXml .= '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $slideXml .= '  <p:cSld>' . "\n";
        $slideXml .= '    <p:spTree>' . "\n";
        $slideXml .= '      <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' . "\n";
        $slideXml .= '      <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></a:xfrm></p:grpSpPr>' . "\n";

        if ('' !== $title) {
            $escTitle = htmlspecialchars($title, ENT_XML1, 'UTF-8');
            $slideXml .= '      <p:sp>' . "\n";
            $slideXml .= '        <p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>' . "\n";
            $slideXml .= '        <p:spPr><a:xfrm><a:off x="508000" y="508000"/><a:ext cx="7924860" cy="300000"/></a:xfrm></p:spPr>' . "\n";
            $slideXml .= '        <p:txBody><a:bodyPr/><a:p><a:r><a:t>' . $escTitle . '</a:t></a:r></a:p></p:txBody>' . "\n";
            $slideXml .= '      </p:sp>' . "\n";
            unset($escTitle);
        }

        if ($image && file_exists($image)) {
            $ext           = strtolower(pathinfo($image, PATHINFO_EXTENSION));
            $imageFileName = 'image' . $imageCounter . '.' . $ext;
            $targetImage   = $tempDir . '/ppt/media/' . $imageFileName;
            copy($image, $targetImage);

            $imageX = $slide['image_x'] ?? 8000000;
            $imageY = $slide['image_y'] ?? 500000;
            $imageW = $slide['image_width'] ?? 2540000;
            $imageH = $slide['image_height'] ?? 1905000;

            $rid      = 'rId' . ($imageCounter + 100);
            $slideXml .= '      <p:pic>' . "\n";
            $slideXml .= '        <p:nvPicPr>' . "\n";
            $slideXml .= '          <p:cNvPr id="' . (100 + $slideNum) . '" name="Picture ' . $imageCounter . '"/>' . "\n";
            $slideXml .= '          <p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr>' . "\n";
            $slideXml .= '          <p:nvPr/>' . "\n";
            $slideXml .= '        </p:nvPicPr>' . "\n";
            $slideXml .= '        <p:blipFill>' . "\n";
            $slideXml .= '          <a:blip r:embed="' . $rid . '"/>' . "\n";
            $slideXml .= '          <a:stretch><a:fillRect/></a:stretch>' . "\n";
            $slideXml .= '        </p:blipFill>' . "\n";
            $slideXml .= '        <p:spPr>' . "\n";
            $slideXml .= '          <a:xfrm><a:off x="' . $imageX . '" y="' . $imageY . '"/><a:ext cx="' . $imageW . '" cy="' . $imageH . '"/></a:xfrm>' . "\n";
            $slideXml .= '          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>' . "\n";
            $slideXml .= '        </p:spPr>' . "\n";
            $slideXml .= '      </p:pic>' . "\n";

            $relsDir = $tempDir . '/ppt/slides/_rels';
            if (!is_dir($relsDir)) {
                mkdir($relsDir, 0755, true);
            }
            $relsFile    = $relsDir . '/slide' . $slideNum . '.xml.rels';
            $relsContent = '';
            if (file_exists($relsFile)) {
                $relsContent = file_get_contents($relsFile);
                $relsContent = preg_replace('/<\/Relationships>\s*$/s', '', $relsContent);
            } else {
                $relsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
                $relsContent .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            }
            $relsContent .= '  <Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . $imageFileName . '"/>' . "\n";
            $relsContent .= '</Relationships>';
            file_put_contents($relsFile, $relsContent);
            unset($relsDir, $relsFile, $relsContent);
        }

        $lines   = preg_split('/\r?\n/', $content);
        $yOffset = 1000000;
        foreach ($lines as $lineIdx => $line) {
            if ('' === trim($line)) {
                continue;
            }
            $escLine  = htmlspecialchars(trim($line), ENT_XML1, 'UTF-8');
            $slideXml .= '      <p:sp>' . "\n";
            $slideXml .= '        <p:nvSpPr><p:cNvPr id="' . (10 + $lineIdx) . '" name="Content ' . ($lineIdx + 1) . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>' . "\n";
            $slideXml .= '        <p:spPr><a:xfrm><a:off x="508000" y="' . ($yOffset + $lineIdx * 300000) . '"/><a:ext cx="7924860" cy="200000"/></a:xfrm></p:spPr>' . "\n";
            $slideXml .= '        <p:txBody><a:bodyPr/><a:p><a:r><a:t>' . $escLine . '</a:t></a:r></a:p></p:txBody>' . "\n";
            $slideXml .= '      </p:sp>' . "\n";
            unset($escLine);
        }

        $slideXml .= '    </p:spTree>' . "\n";
        $slideXml .= '  </p:cSld>' . "\n";
        $slideXml .= '  <p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>' . "\n";
        $slideXml .= '</p:sld>';
        file_put_contents($tempDir . '/ppt/slides/slide' . $slideNum . '.xml', $slideXml);
        unset($slideXml);

        $relsDir = $tempDir . '/ppt/slides/_rels';
        if (!is_dir($relsDir)) {
            mkdir($relsDir, 0755, true);
        }
        $relsFile = $relsDir . '/slide' . $slideNum . '.xml.rels';
        if (!file_exists($relsFile)) {
            $slideRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $slideRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            $slideRels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>' . "\n";
            $slideRels .= '</Relationships>';
            file_put_contents($relsFile, $slideRels);
            unset($slideRels);
        }
        unset($relsDir, $relsFile);
    }

    // -------------------------------------------------------------------------
    // Helper methods for zip and directory recursion
    // -------------------------------------------------------------------------

    /**
     * Recursively add directory contents to zip archive.
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