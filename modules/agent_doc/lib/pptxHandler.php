<?php

/**
 * PPTX Handler - Complete Native PHP Implementation (Read + Write)
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

        if (empty($slideFiles)) {
            for ($i = 1; $i <= 100; $i++) {
                $file = "ppt/slides/slide{$i}.xml";
                if ($zip->locateName($file) !== false) {
                    $slideFiles[] = $file;
                }
            }
        }

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

            $shapes = $xpS->query('//p:sp');
            if ($shapes !== false) {
                foreach ($shapes as $shape) {
                    $isTitle  = false;
                    $nvPrList = $xpS->query('./p:nvSpPr/p:nvPr', $shape);
                    if ($nvPrList !== false && $nvPrList->length > 0) {
                        $nvPr    = $nvPrList->item(0);
                        $phNodes = $xpS->query('p:ph', $nvPr);
                        if ($phNodes !== false && $phNodes->length > 0) {
                            $ph     = $phNodes->item(0);
                            $phType = $ph->getAttribute('type');
                            if ($phType === 'title' || $ph->getAttribute('idx') === '0') {
                                $isTitle = true;
                            }
                        }
                    }

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
     * Each slide can have:
     *   - title (string)
     *   - content (string or array of lines)
     *   - image (string, path to image file)
     *   - image_x (int, EMU, default 8000000)
     *   - image_y (int, EMU, default 500000)
     *   - image_width (int, EMU, default 2540000)
     *   - image_height (int, EMU, default 1905000)
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
                    $image = $item['image'] ?? null;
                    // 图片位置和大小（EMU），如果未提供则使用默认值（右上角，200x150pt）
                    $image_x      = isset($item['image_x']) ? (int)$item['image_x'] : 8000000;
                    $image_y      = isset($item['image_y']) ? (int)$item['image_y'] : 500000;
                    $image_width  = isset($item['image_width']) ? (int)$item['image_width'] : 2540000;
                    $image_height = isset($item['image_height']) ? (int)$item['image_height'] : 1905000;
                    $slides[]     = [
                        'title'        => $title,
                        'content'      => $content,
                        'image'        => $image,
                        'image_x'      => $image_x,
                        'image_y'      => $image_y,
                        'image_width'  => $image_width,
                        'image_height' => $image_height,
                    ];
                } else {
                    $slides[] = [
                        'title'        => '',
                        'content'      => trim((string)$item),
                        'image'        => null,
                        'image_x'      => 8000000,
                        'image_y'      => 500000,
                        'image_width'  => 2540000,
                        'image_height' => 1905000,
                    ];
                }
            }
            if (empty($slides)) {
                $slides[] = [
                    'title'        => '',
                    'content'      => '',
                    'image'        => null,
                    'image_x'      => 8000000,
                    'image_y'      => 500000,
                    'image_width'  => 2540000,
                    'image_height' => 1905000,
                ];
            }

            $tempDir = sys_get_temp_dir() . '/pptx_' . uniqid('pptx_', true);
            if (!mkdir($tempDir, 0755, true)) {
                return ['error' => 'Cannot create temp directory'];
            }

            $dirs = [
                '/_rels', '/ppt/_rels', '/ppt/slides', '/ppt/slideMasters',
                '/ppt/slideLayouts', '/ppt/theme', '/ppt/media'
            ];
            foreach ($dirs as $sub) {
                mkdir($tempDir . $sub, 0755, true);
            }
            mkdir($tempDir . '/docProps', 0755, true);

            // ========== [Content_Types].xml ==========
            $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
            $ct .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
            $ct .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";
            // 收集图片扩展名
            $imageTypes = [];
            foreach ($slides as $slide) {
                if ($slide['image'] && file_exists($slide['image'])) {
                    $ext = strtolower(pathinfo($slide['image'], PATHINFO_EXTENSION));
                    if ($ext === 'png') {
                        $imageTypes['png'] = 'image/png';
                    } elseif ($ext === 'jpg' || $ext === 'jpeg') {
                        $imageTypes['jpg'] = 'image/jpeg';
                        if ($ext === 'jpeg') $imageTypes['jpeg'] = 'image/jpeg';
                    } elseif ($ext === 'gif') {
                        $imageTypes['gif'] = 'image/gif';
                    }
                }
            }
            foreach ($imageTypes as $ext => $mime) {
                $ct .= '  <Default Extension="' . $ext . '" ContentType="' . $mime . '"/>' . "\n";
            }
            $ct .= '  <Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>' . "\n";
            foreach ($slides as $i => $s) {
                $num = $i + 1;
                $ct  .= '  <Override PartName="/ppt/slides/slide' . $num . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>' . "\n";
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
            unset($ct);

            // ========== _rels/.rels ==========
            $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $rootRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            $rootRels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>' . "\n";
            $rootRels .= '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' . "\n";
            $rootRels .= '  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' . "\n";
            $rootRels .= '</Relationships>';
            file_put_contents($tempDir . '/_rels/.rels', $rootRels);
            unset($rootRels);

            // ========== ppt/presentation.xml ==========
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
            $pres .= '  <p:sldSz cx="12192000" cy="6858000"/>' . "\n";
            $pres .= '  <p:notesSz cx="6858000" cy="9144000"/>' . "\n";
            $pres .= '</p:presentation>';
            file_put_contents($tempDir . '/ppt/presentation.xml', $pres);
            unset($pres);

            // ========== ppt/_rels/presentation.xml.rels ==========
            $presRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $presRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            $presRels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>' . "\n";
            $presRels .= '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>' . "\n";
            foreach ($slides as $i => $s) {
                $num      = $i + 1;
                $presRels .= '  <Relationship Id="rId' . ($num + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $num . '.xml"/>' . "\n";
            }
            $presRels .= '  <Relationship Id="rId' . (count($slides) + 3) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/>' . "\n";
            $presRels .= '  <Relationship Id="rId' . (count($slides) + 4) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/viewProps" Target="viewProps.xml"/>' . "\n";
            $presRels .= '  <Relationship Id="rId' . (count($slides) + 5) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tableStyles" Target="tableStyles.xml"/>' . "\n";
            $presRels .= '</Relationships>';
            file_put_contents($tempDir . '/ppt/_rels/presentation.xml.rels', $presRels);
            unset($presRels);

            // ========== ppt/theme/theme1.xml ==========
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

            // ========== ppt/tableStyles.xml ==========
            $tableStyles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $tableStyles .= '<a:tblStyleLst xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>';
            file_put_contents($tempDir . '/ppt/tableStyles.xml', $tableStyles);
            unset($tableStyles);

            // ========== ppt/presProps.xml ==========
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

            // ========== ppt/viewProps.xml ==========
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

            // ========== docProps/app.xml ==========
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
            unset($appXml);

            // ========== docProps/core.xml ==========
            $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $coreXml .= '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
            $coreXml .= '  <dc:creator>AgentDocEngine</dc:creator>' . "\n";
            $coreXml .= '  <cp:lastModifiedBy>AgentDocEngine</cp:lastModifiedBy>' . "\n";
            $coreXml .= '  <dcterms:created xsi:type="dcterms:W3CDTF">' . date('Y-m-d\TH:i:s\Z') . '</dcterms:created>' . "\n";
            $coreXml .= '  <dcterms:modified xsi:type="dcterms:W3CDTF">' . date('Y-m-d\TH:i:s\Z') . '</dcterms:modified>' . "\n";
            $coreXml .= '</cp:coreProperties>';
            file_put_contents($tempDir . '/docProps/core.xml', $coreXml);
            unset($coreXml);

            // ========== ppt/slideMasters/slideMaster1.xml ==========
            $master = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $master .= '<p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
            $master .= '  <p:cSld>' . "\n";
            $master .= '    <p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>' . "\n";
            $master .= '    <p:spTree>' . "\n";
            $master .= '      <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' . "\n";
            $master .= '      <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>' . "\n";
            $master .= '      <p:sp>' . "\n";
            $master .= '        <p:nvSpPr><p:cNvPr id="2" name="Title Placeholder"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>' . "\n";
            $master .= '        <p:spPr><a:xfrm><a:off x="838200" y="365125"/><a:ext cx="10515600" cy="1325563"/></a:xfrm><a:prstGeom prst="rect"/></p:spPr>' . "\n";
            $master .= '        <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>单击此处编辑母版标题样式</a:t></a:r></a:p></p:txBody>' . "\n";
            $master .= '      </p:sp>' . "\n";
            $master .= '      <p:sp>' . "\n";
            $master .= '        <p:nvSpPr><p:cNvPr id="3" name="Body Placeholder"/><p:cNvSpPr/><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr>' . "\n";
            $master .= '        <p:spPr><a:xfrm><a:off x="838200" y="1825625"/><a:ext cx="10515600" cy="4351338"/></a:xfrm><a:prstGeom prst="rect"/></p:spPr>' . "\n";
            $master .= '        <p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:pPr lvl="0"/><a:r><a:t>单击此处编辑母版文本样式</a:t></a:r></a:p></p:txBody>' . "\n";
            $master .= '      </p:sp>' . "\n";
            $master .= '      <p:sp><p:nvSpPr><p:cNvPr id="4" name="Date"/><p:cNvSpPr/><p:nvPr><p:ph type="dt" sz="half" idx="2"/></p:nvPr></p:nvSpPr><p:spPr><a:xfrm><a:off x="838200" y="6356350"/><a:ext cx="2743200" cy="365125"/></a:xfrm></p:spPr><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:fld type="datetimeFigureOut"><a:t>2026/5/28</a:t></a:fld></a:p></p:txBody></p:sp>' . "\n";
            $master .= '      <p:sp><p:nvSpPr><p:cNvPr id="5" name="Footer"/><p:cNvSpPr/><p:nvPr><p:ph type="ftr" sz="quarter" idx="3"/></p:nvPr></p:nvSpPr><p:spPr><a:xfrm><a:off x="4038600" y="6356350"/><a:ext cx="4114800" cy="365125"/></a:xfrm></p:spPr><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr/></a:p></p:txBody></p:sp>' . "\n";
            $master .= '      <p:sp><p:nvSpPr><p:cNvPr id="6" name="Slide Number"/><p:cNvSpPr/><p:nvPr><p:ph type="sldNum" sz="quarter" idx="4"/></p:nvPr></p:nvSpPr><p:spPr><a:xfrm><a:off x="8610600" y="6356350"/><a:ext cx="2743200" cy="365125"/></a:xfrm></p:spPr><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:fld type="slidenum"><a:t>‹#›</a:t></a:fld></a:p></p:txBody></p:sp>' . "\n";
            $master .= '    </p:spTree>' . "\n";
            $master .= '  </p:cSld>' . "\n";
            $master .= '  <p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>' . "\n";
            $master .= '</p:sldMaster>';
            file_put_contents($tempDir . '/ppt/slideMasters/slideMaster1.xml', $master);
            unset($master);

            // ========== ppt/slideMasters/_rels/slideMaster1.xml.rels ==========
            $masterRelsDir = $tempDir . '/ppt/slideMasters/_rels';
            if (!is_dir($masterRelsDir)) mkdir($masterRelsDir, 0755, true);
            $masterRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $masterRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            $masterRels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>' . "\n";
            $masterRels .= '</Relationships>';
            file_put_contents($masterRelsDir . '/slideMaster1.xml.rels', $masterRels);
            unset($masterRels);

            // ========== ppt/slideLayouts/slideLayout1.xml ==========
            $layout = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $layout .= '<p:sldLayout type="blank" preserve="1" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
            $layout .= '  <p:cSld name="Blank Layout">' . "\n";
            $layout .= '    <p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree>' . "\n";
            $layout .= '  </p:cSld>' . "\n";
            $layout .= '</p:sldLayout>';
            file_put_contents($tempDir . '/ppt/slideLayouts/slideLayout1.xml', $layout);
            unset($layout);

            // ========== ppt/slideLayouts/_rels/slideLayout1.xml.rels ==========
            $layoutRelsDir = $tempDir . '/ppt/slideLayouts/_rels';
            if (!is_dir($layoutRelsDir)) mkdir($layoutRelsDir, 0755, true);
            $layoutRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $layoutRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            $layoutRels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>' . "\n";
            $layoutRels .= '</Relationships>';
            file_put_contents($layoutRelsDir . '/slideLayout1.xml.rels', $layoutRels);
            unset($layoutRels);

            // ========== Generate each slide (with image support) ==========
            $imageCounter = 1;
            foreach ($slides as $idx => $slide) {
                $num       = $idx + 1;
                $title     = $slide['title'];
                $content   = $slide['content'];
                $imagePath = $slide['image'];

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

                // Add image if provided
                if ($imagePath && file_exists($imagePath)) {
                    $ext           = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
                    $imageFileName = 'image' . $imageCounter . '.' . $ext;
                    $targetImage   = $tempDir . '/ppt/media/' . $imageFileName;
                    copy($imagePath, $targetImage);
                    $imageRid = 'rId' . ($imageCounter + 100); // ensure unique

                    $imgX = $slide['image_x'];
                    $imgY = $slide['image_y'];
                    $imgW = $slide['image_width'];
                    $imgH = $slide['image_height'];

                    $slideXml .= '      <p:pic>' . "\n";
                    $slideXml .= '        <p:nvPicPr>' . "\n";
                    $slideXml .= '          <p:cNvPr id="' . (100 + $idx) . '" name="Picture ' . $imageCounter . '"/>' . "\n";
                    $slideXml .= '          <p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr>' . "\n";
                    $slideXml .= '          <p:nvPr/>' . "\n";
                    $slideXml .= '        </p:nvPicPr>' . "\n";
                    $slideXml .= '        <p:blipFill>' . "\n";
                    $slideXml .= '          <a:blip r:embed="' . $imageRid . '"/>' . "\n";
                    $slideXml .= '          <a:stretch><a:fillRect/></a:stretch>' . "\n";
                    $slideXml .= '        </p:blipFill>' . "\n";
                    $slideXml .= '        <p:spPr>' . "\n";
                    $slideXml .= '          <a:xfrm><a:off x="' . $imgX . '" y="' . $imgY . '"/><a:ext cx="' . $imgW . '" cy="' . $imgH . '"/></a:xfrm>' . "\n";
                    $slideXml .= '          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>' . "\n";
                    $slideXml .= '        </p:spPr>' . "\n";
                    $slideXml .= '      </p:pic>' . "\n";

                    // Add relationship for this image in slide's rels file
                    $relsDir = $tempDir . '/ppt/slides/_rels';
                    if (!is_dir($relsDir)) mkdir($relsDir, 0755, true);
                    $relsFile    = $relsDir . '/slide' . $num . '.xml.rels';
                    $relsContent = '';
                    if (file_exists($relsFile)) {
                        $relsContent = file_get_contents($relsFile);
                        // Remove trailing </Relationships> if present, we'll add it back after appending
                        $relsContent = preg_replace('/<\/Relationships>\s*$/s', '', $relsContent);
                    } else {
                        $relsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
                        $relsContent .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
                    }
                    $relsContent .= '  <Relationship Id="' . $imageRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . $imageFileName . '"/>' . "\n";
                    $relsContent .= '</Relationships>';
                    file_put_contents($relsFile, $relsContent);
                    $imageCounter++;
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

                // Ensure slide has at least a basic relationship to slideLayout (if not already present)
                $relsDir = $tempDir . '/ppt/slides/_rels';
                if (!is_dir($relsDir)) mkdir($relsDir, 0755, true);
                $relsFile = $relsDir . '/slide' . $num . '.xml.rels';
                if (!file_exists($relsFile)) {
                    $slideRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
                    $slideRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
                    $slideRels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>' . "\n";
                    $slideRels .= '</Relationships>';
                    file_put_contents($relsFile, $slideRels);
                    unset($slideRels);
                }
            }

            // Create ZIP
            if (!file_exists(dirname($path))) mkdir(dirname($path), 0755, true);
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
                'message'      => 'PPTX written successfully with image support.',
            ];
        } catch (\Exception $e) {
            if ($tempDir !== null && is_dir($tempDir)) self::rrmdir($tempDir);
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
                } catch (\Exception $e) {
                    // ignore
                }
            }
            unset($file, $full);
        }
        try {
            rmdir($dir);
        } catch (\Exception $e) {
            // ignore
        }
    }
}