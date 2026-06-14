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

namespace modules\agent_skills\OfficeSuite\lib;

use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;

class docxHandler extends Factory
{
    public utils $utils;

    public function __construct()
    {
        $this->utils = utils::new();
    }

    /**
     * Read content from DOCX file (text + images).
     */
    public function read(string $path): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return ['error' => 'Failed to open DOCX file as a zip archive.'];
        }

        $xml_content = $zip->getFromName('word/document.xml');
        if (false === $xml_content) {
            $zip->close();
            unset($zip, $xml_content);
            return ['error' => 'Could not find document.xml'];
        }

        // Extract plain text
        $text_parts = [];
        $reader     = new \XMLReader();
        if (true === $reader->XML($xml_content)) {
            while ($reader->read()) {
                if (\XMLReader::ELEMENT === $reader->nodeType && 'w:t' === $reader->name) {
                    $text = $reader->readString() ?? '';
                    if ('' !== trim($text)) {
                        $text_parts[] = trim($text);
                    }
                    unset($text);
                }
            }
        }
        $reader->close();
        $plain_text = implode(' ', $text_parts);
        unset($text_parts, $reader);

        // Extract images
        $temp_dir = $this->utils->agent_config['workspace_path'] . '/OfficeTemp/docx_read_' . uniqid('', true);
        if (!mkdir($temp_dir, 0755, true)) {
            $zip->close();
            return ['error' => 'Cannot create temporary directory for images'];
        }

        $images = $this->extractImagesFromDocx($zip, $xml_content, $temp_dir);
        $zip->close();

        $result = [
            'status'          => 'success',
            'file'            => basename($path),
            'content'         => $plain_text,
            'images'          => $images,
            'images_temp_dir' => $temp_dir,
        ];

        unset($zip, $xml_content, $plain_text, $images, $temp_dir, $path);
        return $result;
    }

    /**
     * Write structured DOCX (overwrite).
     */
    public function writeStructured(string $path, array $structured_items): array
    {
        return $this->writeStructuredInternal($path, $structured_items, false);
    }

    /**
     * Append structured content to existing DOCX file (preserves original content and images).
     */
    public function appendStructured(string $path, array $structured_items): array
    {
        if (!file_exists($path)) {
            return $this->writeStructured($path, $structured_items);
        }
        return $this->writeStructuredInternal($path, $structured_items, true);
    }

    /**
     * Internal write method (overwrite or append).
     */
    private function writeStructuredInternal(string $path, array $structured_items, bool $append): array
    {
        $temp_dir        = null;
        $append_temp_dir = null;
        try {
            if (empty($structured_items)) {
                return ['error' => 'No content to write.'];
            }

            $temp_dir = $this->utils->agent_config['workspace_path'] . '/OfficeTemp/docx_' . uniqid('', true);
            if (!mkdir($temp_dir, 0755, true)) {
                return ['error' => 'Failed to create temp dir'];
            }
            mkdir($temp_dir . '/word', 0755, true);
            mkdir($temp_dir . '/word/_rels', 0755, true);
            mkdir($temp_dir . '/_rels', 0755, true);
            mkdir($temp_dir . '/word/media', 0755, true);

            if ($append && file_exists($path) && 0 < filesize($path)) {
                $append_temp_dir = $this->utils->agent_config['workspace_path'] . '/OfficeTemp/docx_append_' . uniqid('', true);
                if (!mkdir($append_temp_dir, 0755, true)) {
                    return ['error' => 'Failed to create temp dir for append'];
                }
                $existing_items = $this->extractStructuredItemsFromDocx($path, $append_temp_dir);
                if (isset($existing_items['error'])) {
                    $this->rrmdir($append_temp_dir);
                    return $existing_items;
                }
                $structured_items = array_merge($existing_items, $structured_items);
            }

            $media_dir     = $temp_dir . '/word/media';
            $rels_entries  = [];
            $image_counter = 1;
            $body_xml      = '';

            foreach ($structured_items as $item) {
                switch ($item['type']) {
                    case 'heading':
                        $body_xml .= $this->generateHeadingXml($item['text'], $item['level']);
                        break;
                    case 'paragraph':
                        $body_xml .= $this->generateParagraphXml(
                            $item['text'],
                            $item['bold'] ?? false,
                            $item['italic'] ?? false,
                            $item['fontSize'] ?? null,
                            $item['align'] ?? 'left',
                            $item['firstLineIndent'] ?? null,
                            $item['lineSpacing'] ?? null,
                            $item['beforeSpacing'] ?? null,
                            $item['afterSpacing'] ?? null,
                            $item['fontFamily'] ?? null,
                            $item['fontFamilyEastAsia'] ?? null,
                            $item['color'] ?? null,
                            $item['underline'] ?? null
                        );
                        break;
                    case 'image':
                        $src = $item['path'];
                        if (!file_exists($src)) {
                            throw new \Exception('Image not found: ' . $src);
                        }
                        $ext     = strtolower(pathinfo($src, PATHINFO_EXTENSION));
                        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'bmp'];
                        if (!in_array($ext, $allowed, true)) {
                            $ext = 'png';
                        }
                        $dest_name = 'image' . $image_counter . '.' . $ext;
                        $dest_path = $media_dir . '/' . $dest_name;
                        copy($src, $dest_path);

                        $width_px = $item['width'] ?? 200;
                        if ($width_px <= 0) {
                            $width_px = 200;
                        }
                        $height_px = $item['height'] ?? null;
                        if (null === $height_px || $height_px <= 0) {
                            $info = getimagesize($dest_path);
                            if (false !== $info) {
                                $height_px = (int)round($width_px * $info[1] / $info[0]);
                            } else {
                                $height_px = $width_px;
                            }
                            unset($info);
                        }
                        if ($height_px <= 0) {
                            $height_px = $width_px;
                        }

                        $align = $item['align'] ?? 'center';

                        $rel_id         = 'rId' . $image_counter;
                        $rels_entries[] = [
                            'Id'     => $rel_id,
                            'Target' => 'media/' . $dest_name,
                            'Type'   => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image'
                        ];
                        $body_xml       .= $this->generateImageXml($dest_path, $width_px, $height_px, $rel_id, $align);
                        $image_counter++;
                        unset($src, $ext, $allowed, $dest_name, $dest_path, $width_px, $height_px, $align, $rel_id);
                        break;
                }
                unset($item);
            }

            $document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $document_xml .= '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">' . "\n";
            $document_xml .= '  <w:body>' . "\n";
            $document_xml .= $body_xml;
            $document_xml .= '    <w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr>' . "\n";
            $document_xml .= '  </w:body>' . "\n";
            $document_xml .= '</w:document>';
            file_put_contents($temp_dir . '/word/document.xml', $document_xml);
            unset($document_xml);

            $this->writeStyles($temp_dir);

            // Write [Content_Types].xml
            $ct         = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $ct         .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
            $ct         .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
            $ct         .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";
            $image_exts = [];
            foreach ($structured_items as $item) {
                if ('image' === $item['type']) {
                    $e = pathinfo($item['path'], PATHINFO_EXTENSION);
                    if ('jpg' === $e) {
                        $image_exts['jpg'] = 'image/jpeg';
                    } elseif ('jpeg' === $e) {
                        $image_exts['jpeg'] = 'image/jpeg';
                    } elseif ('png' === $e) {
                        $image_exts['png'] = 'image/png';
                    } elseif ('gif' === $e) {
                        $image_exts['gif'] = 'image/gif';
                    } elseif ('bmp' === $e) {
                        $image_exts['bmp'] = 'image/bmp';
                    }
                    unset($e);
                }
            }
            foreach ($image_exts as $ext => $mime) {
                $ct .= '  <Default Extension="' . $ext . '" ContentType="' . $mime . '"/>' . "\n";
            }
            $ct .= '  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' . "\n";
            $ct .= '  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>' . "\n";
            $ct .= '</Types>';
            file_put_contents($temp_dir . '/[Content_Types].xml', $ct);
            unset($ct, $image_exts, $ext, $mime);

            // Write _rels/.rels
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            $rels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' . "\n";
            $rels .= '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="word/styles.xml"/>' . "\n";
            $rels .= '</Relationships>';
            file_put_contents($temp_dir . '/_rels/.rels', $rels);
            unset($rels);

            // Write word/_rels/document.xml.rels
            $doc_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $doc_rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
            foreach ($rels_entries as $rel) {
                $doc_rels .= '  <Relationship Id="' . $rel['Id'] . '" Type="' . $rel['Type'] . '" Target="' . $rel['Target'] . '"/>' . "\n";
            }
            $doc_rels .= '</Relationships>';
            file_put_contents($temp_dir . '/word/_rels/document.xml.rels', $doc_rels);
            unset($doc_rels, $rels_entries);

            // Create final ZIP
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            $zip = new \ZipArchive();
            if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
                $this->rrmdir($temp_dir);
                if ($append_temp_dir && is_dir($append_temp_dir)) {
                    $this->rrmdir($append_temp_dir);
                }
                unset($temp_dir, $zip, $structured_items, $media_dir, $image_counter, $body_xml);
                return ['error' => 'Failed to create ZIP archive'];
            }
            $this->addDirToZip($zip, $temp_dir, '');
            $zip->close();
            unset($zip);

            $this->rrmdir($temp_dir);
            if ($append_temp_dir && is_dir($append_temp_dir)) {
                $this->rrmdir($append_temp_dir);
            }
            $result = ['status' => 'success', 'path' => $path, 'message' => 'DOCX written with structured data'];

            unset($temp_dir, $append_temp_dir, $structured_items, $media_dir, $image_counter, $body_xml, $path);
            return $result;
        } catch (\Exception $e) {
            if ($temp_dir && is_dir($temp_dir)) {
                $this->rrmdir($temp_dir);
            }
            if ($append_temp_dir && is_dir($append_temp_dir)) {
                $this->rrmdir($append_temp_dir);
            }
            $error = ['error' => 'Write failed: ' . $e->getMessage()];
            unset($temp_dir, $append_temp_dir, $e);
            return $error;
        }
    }

    /**
     * Extract existing structured items (heading, paragraph, image) from an existing DOCX file.
     * Used for append operation.
     *
     * @param string $path     DOCX file path
     * @param string $temp_dir Temporary directory to store extracted images (will be cleaned by caller)
     *
     * @return array
     */
    private function extractStructuredItemsFromDocx(string $path, string $temp_dir): array
    {
        $items = [];

        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return ['error' => 'Failed to open DOCX file for reading existing content'];
        }

        $xml_content = $zip->getFromName('word/document.xml');
        if (false === $xml_content) {
            $zip->close();
            return ['error' => 'Could not find document.xml in existing file'];
        }

        // Load relationships to map rId to image target
        $rels_content = $zip->getFromName('word/_rels/document.xml.rels');
        $rel_map      = [];
        if (false !== $rels_content) {
            $rel_reader = new \XMLReader();
            if (true === $rel_reader->XML($rels_content)) {
                while ($rel_reader->read()) {
                    if (\XMLReader::ELEMENT === $rel_reader->nodeType && 'Relationship' === $rel_reader->name) {
                        $id     = null;
                        $target = null;
                        if ($rel_reader->hasAttributes) {
                            while ($rel_reader->moveToNextAttribute()) {
                                if ('Id' === $rel_reader->name) {
                                    $id = $rel_reader->value;
                                } elseif ('Target' === $rel_reader->name) {
                                    $target = $rel_reader->value;
                                }
                            }
                            $rel_reader->moveToElement();
                        }
                        if ($id && $target && false !== strpos($target, 'media/')) {
                            $rel_map[$id] = $target;
                        }
                    }
                }
            }
            $rel_reader->close();
            unset($rel_reader);
        }
        unset($rels_content);

        // Parse document.xml
        $dom = new \DOMDocument();
        if (false === $dom->loadXML($xml_content)) {
            $zip->close();
            return ['error' => 'Failed to parse document.xml of existing file'];
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');

        $paragraphs = $xp->query('//w:p');
        if (false !== $paragraphs) {
            foreach ($paragraphs as $para) {
                // Check if paragraph contains a drawing (image)
                $drawings = $xp->query('.//w:drawing', $para);
                if (false !== $drawings && $drawings->length > 0) {
                    // Process image
                    foreach ($drawings as $drawing) {
                        $blip_nodes = $xp->query('.//a:blip/@r:embed', $drawing);
                        if (false !== $blip_nodes && $blip_nodes->length > 0) {
                            $r_id = $blip_nodes->item(0)->value;
                            if (isset($rel_map[$r_id])) {
                                $image_target   = $rel_map[$r_id];
                                $image_zip_path = 'word/' . $image_target;
                                $image_data     = $zip->getFromName($image_zip_path);
                                if (false !== $image_data) {
                                    $ext     = strtolower(pathinfo($image_target, PATHINFO_EXTENSION));
                                    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'bmp'];
                                    if (false === in_array($ext, $allowed, true)) {
                                        $ext = 'png';
                                    }
                                    $temp_image_path = $temp_dir . '/img_' . uniqid() . '.' . $ext;
                                    file_put_contents($temp_image_path, $image_data);
                                    // Get dimensions
                                    $width_px     = 200;
                                    $height_px    = 200;
                                    $extent_nodes = $xp->query('.//wp:extent', $drawing);
                                    if (false !== $extent_nodes && $extent_nodes->length > 0) {
                                        $cx = (int)$extent_nodes->item(0)->getAttribute('cx');
                                        $cy = (int)$extent_nodes->item(0)->getAttribute('cy');
                                        if ($cx > 0 && $cy > 0) {
                                            $width_px  = (int)round($cx / 9525);
                                            $height_px = (int)round($cy / 9525);
                                        }
                                    }
                                    $items[] = [
                                        'type'   => 'image',
                                        'path'   => $temp_image_path,
                                        'width'  => (0 < $width_px) ? $width_px : 200,
                                        'height' => (0 < $height_px) ? $height_px : null,
                                        'align'  => 'center',
                                    ];
                                }
                            }
                        }
                    }
                } else {
                    // Text paragraph
                    $text_nodes = $xp->query('.//w:t', $para);
                    $text       = '';
                    foreach ($text_nodes as $t_node) {
                        $text .= $t_node->nodeValue;
                    }
                    $text = trim($text);
                    if ('' !== $text) {
                        // Check if it's a heading (has pStyle)
                        $p_style_nodes = $xp->query('.//w:pStyle', $para);
                        if (false !== $p_style_nodes && $p_style_nodes->length > 0) {
                            $style_val = $p_style_nodes->item(0)->getAttribute('w:val');
                            if (preg_match('/Heading(\d+)/', $style_val, $matches)) {
                                $level   = (int)$matches[1];
                                $items[] = [
                                    'type'  => 'heading',
                                    'level' => $level,
                                    'text'  => $text,
                                ];
                                continue;
                            }
                        }
                        $items[] = [
                            'type' => 'paragraph',
                            'text' => $text,
                        ];
                    }
                }
            }
        }

        $zip->close();
        unset($zip, $dom, $xp);

        return $items;
    }

    /**
     * Extract images from DOCX document.xml and save to temp directory.
     */
    private function extractImagesFromDocx(\ZipArchive $zip, string $document_xml, string $temp_dir): array
    {
        $images = [];

        $rels_content = $zip->getFromName('word/_rels/document.xml.rels');
        if (false === $rels_content) {
            return $images;
        }

        $rel_map    = [];
        $rel_reader = new \XMLReader();
        if (true === $rel_reader->XML($rels_content)) {
            while ($rel_reader->read()) {
                if (\XMLReader::ELEMENT === $rel_reader->nodeType && 'Relationship' === $rel_reader->name) {
                    $id     = null;
                    $target = null;
                    if ($rel_reader->hasAttributes) {
                        while ($rel_reader->moveToNextAttribute()) {
                            if ('Id' === $rel_reader->name) {
                                $id = $rel_reader->value;
                            } elseif ('Target' === $rel_reader->name) {
                                $target = $rel_reader->value;
                            }
                        }
                        $rel_reader->moveToElement();
                    }
                    if ($id && $target && false !== strpos($target, 'media/')) {
                        $rel_map[$id] = $target;
                    }
                }
            }
        }
        $rel_reader->close();
        unset($rels_content, $rel_reader);

        if (empty($rel_map)) {
            return $images;
        }

        $dom = new \DOMDocument();
        if (false === $dom->loadXML($document_xml)) {
            return $images;
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');

        $drawings = $xp->query('//w:drawing');
        if (false === $drawings || 0 === $drawings->length) {
            return $images;
        }

        $image_counter = 1;
        foreach ($drawings as $drawing) {
            $blip_nodes = $xp->query('.//a:blip/@r:embed', $drawing);
            if (false === $blip_nodes || 0 === $blip_nodes->length) {
                continue;
            }
            $r_id = $blip_nodes->item(0)->value;
            if (!isset($rel_map[$r_id])) {
                continue;
            }
            $image_target   = $rel_map[$r_id];
            $image_zip_path = 'word/' . $image_target;
            $image_data     = $zip->getFromName($image_zip_path);
            if (false === $image_data) {
                continue;
            }

            $ext     = strtolower(pathinfo($image_target, PATHINFO_EXTENSION));
            $allowed = ['png', 'jpg', 'jpeg', 'gif', 'bmp'];
            if (false === in_array($ext, $allowed, true)) {
                $ext = 'png';
            }
            $image_filename  = 'img_' . $image_counter . '.' . $ext;
            $image_full_path = $temp_dir . '/' . $image_filename;
            file_put_contents($image_full_path, $image_data);

            $width_px     = 200;
            $height_px    = 200;
            $extent_nodes = $xp->query('.//wp:extent', $drawing);
            if (false !== $extent_nodes && $extent_nodes->length > 0) {
                $cx = (int)$extent_nodes->item(0)->getAttribute('cx');
                $cy = (int)$extent_nodes->item(0)->getAttribute('cy');
                if ($cx > 0 && $cy > 0) {
                    $width_px  = (int)round($cx / 9525);
                    $height_px = (int)round($cy / 9525);
                }
            } else {
                $info = getimagesize($image_full_path);
                if (false !== $info) {
                    $width_px  = $info[0];
                    $height_px = $info[1];
                }
            }

            $images[] = [
                'path'   => $image_full_path,
                'width'  => $width_px,
                'height' => $height_px,
                'ext'    => $ext,
            ];
            $image_counter++;
            unset($blip_nodes, $r_id, $image_target, $image_zip_path, $image_data, $ext, $image_filename, $image_full_path, $width_px, $height_px, $extent_nodes, $cx, $cy);
        }

        unset($dom, $xp, $drawings, $rel_map);
        return $images;
    }

    /**
     * Generate word/styles.xml with Heading1-6 definitions.
     */
    private function writeStyles(string $temp_dir): void
    {
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $styles .= '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' . "\n";
        $styles .= '  <w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:eastAsia="宋体"/><w:sz w:val="24"/></w:rPr></w:rPrDefault></w:docDefaults>' . "\n";
        for ($i = 1; $i <= 6; $i++) {
            $font_size = 28 - ($i - 1) * 2;
            $font_size = max(16, $font_size);
            $styles    .= '  <w:style w:type="paragraph" w:styleId="Heading' . $i . '">' . "\n";
            $styles    .= '    <w:name w:val="heading ' . $i . '"/>' . "\n";
            $styles    .= '    <w:basedOn w:val="Normal"/>' . "\n";
            $styles    .= '    <w:next w:val="Normal"/>' . "\n";
            $styles    .= '    <w:uiPriority w:val="' . (9 - $i) . '"/>' . "\n";
            $styles    .= '    <w:qFormat/>' . "\n";
            $styles    .= '    <w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="240" w:after="60" w:line="480" w:lineRule="auto"/></w:pPr>' . "\n";
            $styles    .= '    <w:rPr>' . "\n";
            $styles    .= '      <w:b/>' . "\n";
            $styles    .= '      <w:sz w:val="' . ($font_size * 2) . '"/>' . "\n";
            $styles    .= '    </w:rPr>' . "\n";
            $styles    .= '  </w:style>' . "\n";
        }
        $styles .= '</w:styles>';
        file_put_contents($temp_dir . '/word/styles.xml', $styles);
        unset($styles);
    }

    /**
     * Generate heading XML.
     */
    private function generateHeadingXml(string $text, int $level): string
    {
        $esc = htmlspecialchars($text, ENT_XML1, 'UTF-8');
        $xml = '    <w:p>' . "\n";
        $xml .= '      <w:pPr>' . "\n";
        $xml .= '        <w:pStyle w:val="Heading' . $level . '"/>' . "\n";
        $xml .= '        <w:spacing w:line="480" w:lineRule="auto"/>' . "\n";
        $xml .= '      </w:pPr>' . "\n";
        $xml .= '      <w:r>' . "\n";
        $xml .= '        <w:t xml:space="preserve">' . $esc . '</w:t>' . "\n";
        $xml .= '      </w:r>' . "\n";
        $xml .= '    </w:p>' . "\n";
        unset($text, $level, $esc);
        return $xml;
    }

    /**
     * Generate paragraph XML with full style support.
     */
    private function generateParagraphXml(
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
    ): string
    {
        $esc = htmlspecialchars($text, ENT_XML1, 'UTF-8');

        $align_map = [
            'left'    => 'left',
            'center'  => 'center',
            'right'   => 'right',
            'justify' => 'both'
        ];
        $jc        = $align_map[$align] ?? 'left';

        $p_pr = '      <w:pPr>' . "\n";
        $p_pr .= '        <w:jc w:val="' . $jc . '"/>' . "\n";

        if (null !== $line_spacing && 0 < $line_spacing) {
            $line_val = (int)round($line_spacing * 240);
            $p_pr     .= '        <w:spacing w:line="' . $line_val . '" w:lineRule="auto"/>' . "\n";
        } else {
            $p_pr .= '        <w:spacing w:line="240" w:lineRule="auto"/>' . "\n";
        }

        if (null !== $before_spacing && 0 < $before_spacing) {
            $p_pr .= '        <w:spacing w:before="' . $before_spacing . '"/>' . "\n";
        }
        if (null !== $after_spacing && 0 < $after_spacing) {
            $p_pr .= '        <w:spacing w:after="' . $after_spacing . '"/>' . "\n";
        }

        if (null !== $first_line_indent && 0 !== $first_line_indent) {
            $p_pr .= '        <w:ind w:firstLine="' . $first_line_indent . '"/>' . "\n";
        }

        $p_pr .= '      </w:pPr>' . "\n";

        $r_pr = '        <w:rPr>' . "\n";
        if ($bold) {
            $r_pr .= '          <w:b/>' . "\n";
        }
        if ($italic) {
            $r_pr .= '          <w:i/>' . "\n";
        }
        if (null !== $font_size && 0 < $font_size) {
            $r_pr .= '          <w:sz w:val="' . ($font_size * 2) . '"/>' . "\n";
        }
        if (null !== $font_family || null !== $font_family_east_asia) {
            $ascii     = $font_family ?? 'Arial';
            $east_asia = $font_family_east_asia ?? '宋体';
            $r_pr      .= '          <w:rFonts w:ascii="' . $ascii . '" w:eastAsia="' . $east_asia . '"/>' . "\n";
        }
        if (null !== $color && preg_match('/^[0-9A-Fa-f]{6}$/', $color)) {
            $r_pr .= '          <w:color w:val="' . $color . '"/>' . "\n";
        }
        if (null !== $underline) {
            $r_pr .= '          <w:u w:val="' . $underline . '"/>' . "\n";
        }
        $r_pr .= '        </w:rPr>' . "\n";

        $xml = '    <w:p>' . "\n";
        $xml .= $p_pr;
        $xml .= '      <w:r>' . "\n";
        $xml .= $r_pr;
        $xml .= '        <w:t xml:space="preserve">' . $esc . '</w:t>' . "\n";
        $xml .= '      </w:r>' . "\n";
        $xml .= '    </w:p>' . "\n";

        unset($text, $bold, $italic, $font_size, $align, $first_line_indent, $line_spacing, $before_spacing, $after_spacing, $font_family, $font_family_east_asia, $color, $underline, $esc, $align_map, $jc, $p_pr, $r_pr);
        return $xml;
    }

    /**
     * Generate image XML.
     */
    private function generateImageXml(string $image_path, int $width_px, int $height_px, string $rel_id, string $align): string
    {
        $width_emu  = $width_px * 9525;
        $height_emu = $height_px * 9525;
        $align_map  = [
            'left'   => 'left',
            'center' => 'center',
            'right'  => 'right'
        ];
        $align_val  = $align_map[$align] ?? 'center';
        $xml        = '    <w:p>' . "\n";
        $xml        .= '      <w:pPr><w:jc w:val="' . $align_val . '"/></w:pPr>' . "\n";
        $xml        .= '      <w:r>' . "\n";
        $xml        .= '        <w:drawing>' . "\n";
        $xml        .= '          <wp:inline distT="0" distB="0" distL="0" distR="0">' . "\n";
        $xml        .= '            <wp:extent cx="' . $width_emu . '" cy="' . $height_emu . '"/>' . "\n";
        $xml        .= '            <wp:docPr id="1" name="Picture"/>' . "\n";
        $xml        .= '            <a:graphic>' . "\n";
        $xml        .= '              <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">' . "\n";
        $xml        .= '                <pic:pic>' . "\n";
        $xml        .= '                  <pic:nvPicPr>' . "\n";
        $xml        .= '                    <pic:cNvPr id="0" name="Picture"/>' . "\n";
        $xml        .= '                    <pic:cNvPicPr/>' . "\n";
        $xml        .= '                  </pic:nvPicPr>' . "\n";
        $xml        .= '                  <pic:blipFill>' . "\n";
        $xml        .= '                    <a:blip r:embed="' . $rel_id . '"/>' . "\n";
        $xml        .= '                    <a:stretch><a:fillRect/></a:stretch>' . "\n";
        $xml        .= '                  </pic:blipFill>' . "\n";
        $xml        .= '                  <pic:spPr>' . "\n";
        $xml        .= '                    <a:xfrm>' . "\n";
        $xml        .= '                      <a:off x="0" y="0"/>' . "\n";
        $xml        .= '                      <a:ext cx="' . $width_emu . '" cy="' . $height_emu . '"/>' . "\n";
        $xml        .= '                    </a:xfrm>' . "\n";
        $xml        .= '                    <a:prstGeom prst="rect"/>' . "\n";
        $xml        .= '                  </pic:spPr>' . "\n";
        $xml        .= '                </pic:pic>' . "\n";
        $xml        .= '              </a:graphicData>' . "\n";
        $xml        .= '            </a:graphic>' . "\n";
        $xml        .= '          </wp:inline>' . "\n";
        $xml        .= '        </w:drawing>' . "\n";
        $xml        .= '      </w:r>' . "\n";
        $xml        .= '    </w:p>' . "\n";
        unset($image_path, $width_px, $height_px, $rel_id, $align, $width_emu, $height_emu, $align_val);
        return $xml;
    }

    /**
     * Recursively add directory to zip.
     */
    private function addDirToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        $files = scandir($dir);
        foreach ($files as $f) {
            if ('.' === $f || '..' === $f) {
                continue;
            }
            $full     = $dir . '/' . $f;
            $zip_path = $prefix . $f;
            if (is_dir($full)) {
                $this->addDirToZip($zip, $full, $zip_path . '/');
            } else {
                $zip->addFile($full, $zip_path);
            }
            unset($full, $zip_path);
        }
        unset($files, $dir, $prefix);
    }

    /**
     * Recursively remove directory.
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
        unset($files, $dir);
    }
}