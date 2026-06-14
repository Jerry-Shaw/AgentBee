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

namespace modules\agent_skills\OfficeSuite\lib;

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
     * Read all slides from a .pptx file (text + images).
     */
    public function read(string $path): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return ['error' => 'Failed to open PPTX file as a zip archive.'];
        }

        $pres_content = $zip->getFromName('ppt/presentation.xml');
        if (false === $pres_content) {
            $zip->close();
            return ['error' => 'Could not find presentation.xml'];
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        if (false === $dom->loadXML($pres_content)) {
            $zip->close();
            return ['error' => 'Failed to parse presentation.xml'];
        }

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        // Build relationship map (slide id -> slide file path)
        $rel_map      = [];
        $rels_content = $zip->getFromName('ppt/_rels/presentation.xml.rels');
        if (false !== $rels_content) {
            $rel_dom = new \DOMDocument();
            if (true === $rel_dom->loadXML($rels_content)) {
                $rel_xp = new \DOMXPath($rel_dom);
                $rel_xp->registerNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
                $rels = $rel_xp->query('//pr:Relationship');
                if (false !== $rels) {
                    foreach ($rels as $rel) {
                        $id     = $rel->getAttribute('Id');
                        $target = $rel->getAttribute('Target');
                        if ($id && $target && false !== strpos($target, 'slides/')) {
                            $rel_map[$id] = ltrim($target, '/');
                        }
                    }
                }
            }
        }

        $slide_rels = [];
        $sld_nodes  = $xp->query('//p:sldIdLst/p:sldId');
        if (false !== $sld_nodes) {
            foreach ($sld_nodes as $sld_id) {
                $r_id = '';
                foreach ($sld_id->attributes as $attr) {
                    if ('r:id' === $attr->name) {
                        $r_id = $attr->value;
                        break;
                    }
                }
                if ($r_id && isset($rel_map[$r_id])) {
                    $slide_rels[] = 'ppt/' . $rel_map[$r_id];
                }
            }
        }

        // Fallback: scan all slide*.xml files
        if (empty($slide_rels)) {
            for ($i = 1; $i <= 100; $i++) {
                $file = "ppt/slides/slide{$i}.xml";
                if (false !== $zip->locateName($file)) {
                    $slide_rels[] = $file;
                }
            }
        }

        // Create temp directory for extracted images
        $temp_dir = $this->core->agent_config['workspace_path'] . '/OfficeTemp/pptx_read_' . uniqid('', true);
        if (!mkdir($temp_dir, 0755, true)) {
            $zip->close();
            return ['error' => 'Cannot create temporary directory for images'];
        }

        $slides = [];
        foreach ($slide_rels as $idx => $sf) {
            $xml_content = $zip->getFromName($sf);
            if (false === $xml_content) {
                continue;
            }

            $dom_slide = new \DOMDocument('1.0', 'UTF-8');
            if (false === $dom_slide->loadXML($xml_content)) {
                continue;
            }
            $xp_s = new \DOMXPath($dom_slide);
            $xp_s->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $xp_s->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $xp_s->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

            // Extract text
            $title_texts   = [];
            $content_texts = [];
            $shapes        = $xp_s->query('//p:sp');
            if (false !== $shapes) {
                foreach ($shapes as $shape) {
                    $is_title   = false;
                    $nv_pr_list = $xp_s->query('./p:nvSpPr/p:nvPr', $shape);
                    if (false !== $nv_pr_list && $nv_pr_list->length > 0) {
                        $ph_nodes = $xp_s->query('p:ph', $nv_pr_list->item(0));
                        if (false !== $ph_nodes && $ph_nodes->length > 0) {
                            $ph_type = $ph_nodes->item(0)->getAttribute('type');
                            if ('title' === $ph_type || '0' === $ph_nodes->item(0)->getAttribute('idx')) {
                                $is_title = true;
                            }
                        }
                    }
                    $text_nodes = $xp_s->query('.//a:t', $shape);
                    if (false !== $text_nodes) {
                        foreach ($text_nodes as $t_node) {
                            $text = trim($t_node->nodeValue);
                            if ('' !== $text) {
                                if ($is_title) {
                                    $title_texts[] = $text;
                                } else {
                                    $content_texts[] = $text;
                                }
                            }
                        }
                    }
                }
            }

            $title   = implode(' ', array_unique($title_texts));
            $content = implode("\n", array_filter(array_map('trim', $content_texts)));

            if (strlen($title) > 500) {
                $title = substr($title, 0, 500);
            }

            // Extract images
            $images    = [];
            $pic_nodes = $xp_s->query('//p:pic');
            if (false !== $pic_nodes && $pic_nodes->length > 0) {
                $slide_rels_path    = dirname($sf) . '/_rels/' . basename($sf) . '.rels';
                $slide_rels_content = $zip->getFromName($slide_rels_path);
                $image_target_map   = [];
                if (false !== $slide_rels_content) {
                    $rel_dom = new \DOMDocument();
                    if ($rel_dom->loadXML($slide_rels_content)) {
                        $rel_xp = new \DOMXPath($rel_dom);
                        $rel_xp->registerNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
                        $rels = $rel_xp->query('//pr:Relationship');
                        if (false !== $rels) {
                            $slide_base_dir = dirname($sf);
                            foreach ($rels as $rel) {
                                $rel_id = $rel->getAttribute('Id');
                                $target = $rel->getAttribute('Target');
                                if ($rel_id && $target && false !== strpos($target, 'media/')) {
                                    $abs_path                  = $this->normalizeZipPath($slide_base_dir, $target);
                                    $image_target_map[$rel_id] = $abs_path;
                                }
                            }
                        }
                    }
                }

                if (!empty($image_target_map)) {
                    foreach ($pic_nodes as $pic) {
                        $blip_nodes = $xp_s->query('.//a:blip/@r:embed', $pic);
                        if (false === $blip_nodes || 0 === $blip_nodes->length) {
                            continue;
                        }
                        $r_id = $blip_nodes->item(0)->value;
                        if (!isset($image_target_map[$r_id])) {
                            continue;
                        }
                        $image_zip_path = $image_target_map[$r_id];
                        $image_data     = $zip->getFromName($image_zip_path);
                        if (false === $image_data) {
                            continue;
                        }

                        $ext     = strtolower(pathinfo($image_zip_path, PATHINFO_EXTENSION));
                        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'bmp'];
                        if (false === in_array($ext, $allowed, true)) {
                            $ext = 'png';
                        }
                        $image_file_name = 'img_' . uniqid() . '.' . $ext;
                        $image_full_path = $temp_dir . '/' . $image_file_name;
                        file_put_contents($image_full_path, $image_data);

                        // Get dimensions
                        $xfrm_nodes = $xp_s->query('.//a:xfrm', $pic);
                        $width_emu  = 2540000;
                        $height_emu = 1905000;
                        if (false !== $xfrm_nodes && $xfrm_nodes->length > 0) {
                            $cx = $xfrm_nodes->item(0)->getAttribute('cx');
                            $cy = $xfrm_nodes->item(0)->getAttribute('cy');
                            if ($cx && $cy) {
                                $width_emu  = (int)$cx;
                                $height_emu = (int)$cy;
                            }
                        }
                        $width_px  = (int)round($width_emu / 9525);
                        $height_px = (int)round($height_emu / 9525);

                        // Position
                        $off_x = 0;
                        $off_y = 0;
                        if (false !== $xfrm_nodes && $xfrm_nodes->length > 0) {
                            $off_nodes = $xfrm_nodes->item(0)->getElementsByTagName('off');
                            if ($off_nodes->length > 0) {
                                $off_x = (int)$off_nodes->item(0)->getAttribute('x');
                                $off_y = (int)$off_nodes->item(0)->getAttribute('y');
                            }
                        }

                        $images[] = [
                            'path'   => $image_full_path,
                            'width'  => $width_px,
                            'height' => $height_px,
                            'x'      => $off_x,
                            'y'      => $off_y,
                            'ext'    => $ext,
                        ];
                    }
                }
            }

            $slides[] = [
                'number'  => $idx + 1,
                'title'   => $title,
                'content' => $content,
                'images'  => $images,
            ];
            unset($xml_content, $dom_slide, $xp_s, $title_texts, $content_texts, $shapes, $title, $content, $images);
        }

        $zip->close();

        $result = [
            'status'          => 'success',
            'file'            => basename($path),
            'slides_count'    => count($slides),
            'slides'          => $slides,
            'images_temp_dir' => $temp_dir,
        ];

        unset($zip, $pres_content, $dom, $xp, $rel_map, $rels_content, $slide_rels, $sld_nodes, $temp_dir);
        return $result;
    }

    /**
     * Write structured PPTX (overwrite).
     *
     * @param string $path
     * @param array  $slides Array of slides, each slide: ['title'=>string, 'paragraphs'=>array, 'image_path'=>string|null, 'image_width'=>int, 'image_height'=>int, 'image_x'=>int, 'image_y'=>int]
     *
     * @return array
     */
    public function writeStructured(string $path, array $slides): array
    {
        $temp_dir = null;
        try {
            if (empty($slides)) {
                $slides = [['title' => '', 'paragraphs' => []]];
            }

            $temp_dir = $this->core->agent_config['workspace_path'] . '/OfficeTemp/pptx_' . uniqid('', true);
            if (false === mkdir($temp_dir, 0755, true)) {
                return ['error' => 'Cannot create temp directory'];
            }

            $dirs = [
                '/_rels', '/ppt/_rels', '/ppt/slides', '/ppt/slideMasters',
                '/ppt/slideLayouts', '/ppt/theme', '/ppt/media',
                '/ppt/slideMasters/_rels', '/ppt/slideLayouts/_rels'
            ];
            foreach ($dirs as $sub) {
                mkdir($temp_dir . $sub, 0755, true);
            }
            mkdir($temp_dir . '/docProps', 0755, true);

            $this->writeContentTypes($temp_dir, $slides);
            $this->writeRootRels($temp_dir);
            $this->writePresentationXml($temp_dir, $slides);
            $this->writePresentationRels($temp_dir, $slides);
            $this->writeTheme($temp_dir);
            $this->writeTableStyles($temp_dir);
            $this->writePresProps($temp_dir);
            $this->writeViewProps($temp_dir);
            $this->writeDocProps($temp_dir, $slides);
            $this->writeSlideMaster($temp_dir);
            $this->writeSlideLayout($temp_dir);

            $image_counter    = 1;
            $target_media_dir = $temp_dir . '/ppt/media';
            foreach ($slides as $idx => $slide) {
                $slide_num      = $idx + 1;
                $image_path     = $slide['image_path'] ?? null;
                $image_rid      = null;
                $image_filename = null;
                $image_props    = [];

                if (null !== $image_path && file_exists($image_path)) {
                    $ext     = strtolower(pathinfo($image_path, PATHINFO_EXTENSION));
                    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'bmp'];
                    if (false === in_array($ext, $allowed, true)) {
                        $ext = 'png';
                    }
                    $image_filename = 'image' . $image_counter . '.' . $ext;
                    $media_dir      = $temp_dir . '/ppt/media';
                    if (!is_dir($media_dir)) {
                        mkdir($media_dir, 0755, true);
                    }
                    $dest = $media_dir . '/' . $image_filename;
                    copy($image_path, $dest);
                    $image_rid   = 'rId' . (100 + $image_counter);
                    $image_props = [
                        'x'      => $slide['image_x'] ?? 8000000,
                        'y'      => $slide['image_y'] ?? 500000,
                        'width'  => $slide['image_width'] ?? 2540000,
                        'height' => $slide['image_height'] ?? 1905000,
                    ];
                    $image_counter++;
                }

                $this->writeSlide($temp_dir, $slide_num, $slide, $image_rid, $image_filename, $image_props);
                unset($slide_num);
            }

            if (false === is_dir(dirname($path))) {
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
                'slides_count' => count($slides),
                'message'      => 'PPTX written successfully.',
            ];

            unset($slides, $target_media_dir, $image_counter);
            return $result;
        } catch (\Exception $e) {
            if (null !== $temp_dir && is_dir($temp_dir)) {
                $this->rrmdir($temp_dir);
            }
            return ['error' => 'Failed to write PPTX: ' . $e->getMessage()];
        }
    }

    /**
     * Append slides to an existing PPTX file (preserves original content and images).
     *
     * @param string $path   Target file path
     * @param array  $slides Array of new slides (same format as writeStructured)
     *
     * @return array
     */
    public function append(string $path, array $slides): array
    {
        if (false === file_exists($path)) {
            return $this->writeStructured($path, $slides);
        }

        if (empty($slides)) {
            return ['error' => 'No slides to append.'];
        }

        $temp_dir = null;
        try {
            // 1. Extract original file to temp directory
            $temp_dir = $this->core->agent_config['workspace_path'] . '/OfficeTemp/pptx_append_' . uniqid('', true);
            if (false === mkdir($temp_dir, 0755, true)) {
                return ['error' => 'Cannot create temp directory'];
            }

            $zip = new \ZipArchive();
            if (true !== $zip->open($path)) {
                $this->rrmdir($temp_dir);
                return ['error' => 'Failed to open original PPTX file'];
            }
            $zip->extractTo($temp_dir);
            $zip->close();
            unset($zip);

            // 2. Find maximum existing slide number
            $slide_files   = glob($temp_dir . '/ppt/slides/slide*.xml');
            $max_slide_num = 0;
            foreach ($slide_files as $file) {
                if (preg_match('/slide(\d+)\.xml$/', $file, $matches)) {
                    $num = (int)$matches[1];
                    if ($num > $max_slide_num) {
                        $max_slide_num = $num;
                    }
                }
                unset($file, $matches);
            }
            $next_slide_num = $max_slide_num + 1;

            // 3. Find maximum existing image number
            $media_files   = glob($temp_dir . '/ppt/media/image*.{jpg,jpeg,png,gif,bmp}', GLOB_BRACE);
            $max_image_num = 0;
            foreach ($media_files as $file) {
                if (preg_match('/image(\d+)\./', $file, $matches)) {
                    $num = (int)$matches[1];
                    if ($num > $max_image_num) {
                        $max_image_num = $num;
                    }
                }
                unset($file, $matches);
            }
            $next_image_num = $max_image_num + 1;

            // 4. Process new slides (generate XML, copy images, collect relationships)
            $new_slide_ids = []; // ['num'=>..., 'rId'=>...]
            $new_rels      = []; // for presentation.xml.rels
            $image_counter = $next_image_num;

            foreach ($slides as $idx => $slide) {
                $real_num       = $next_slide_num + $idx;
                $slide_file     = "slide{$real_num}.xml";
                $image_path     = $slide['image_path'] ?? null;
                $image_rid      = null;
                $image_filename = null;
                $image_props    = [];

                if (null !== $image_path && file_exists($image_path)) {
                    $ext     = strtolower(pathinfo($image_path, PATHINFO_EXTENSION));
                    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'bmp'];
                    if (false === in_array($ext, $allowed, true)) {
                        $ext = 'png';
                    }
                    $image_filename = 'image' . $image_counter . '.' . $ext;
                    $media_dir      = $temp_dir . '/ppt/media';
                    if (!is_dir($media_dir)) {
                        mkdir($media_dir, 0755, true);
                    }
                    $dest = $media_dir . '/' . $image_filename;
                    copy($image_path, $dest);
                    $image_rid   = 'rId' . (100 + $image_counter);
                    $image_props = [
                        'x'      => $slide['image_x'] ?? 8000000,
                        'y'      => $slide['image_y'] ?? 500000,
                        'width'  => $slide['image_width'] ?? 2540000,
                        'height' => $slide['image_height'] ?? 1905000,
                    ];
                    $image_counter++;
                }

                // Generate slide XML
                $this->writeSlide($temp_dir, $real_num, $slide, $image_rid, $image_filename, $image_props);

                // Prepare relationship for presentation.xml.rels
                $rel_id          = 'rId' . (count($new_rels) + 1000 + $image_counter);
                $new_rels[]      = [
                    'id'     => $rel_id,
                    'target' => 'slides/' . $slide_file
                ];
                $new_slide_ids[] = ['num' => $real_num, 'rel_id' => $rel_id];
            }

            // 5. Update presentation.xml: add new <p:sldId> elements
            $pres_xml_file = $temp_dir . '/ppt/presentation.xml';
            $pres_xml      = file_get_contents($pres_xml_file);
            if (false === $pres_xml) {
                $this->rrmdir($temp_dir);
                return ['error' => 'Failed to read presentation.xml'];
            }
            $dom = new \DOMDocument();
            $dom->loadXML($pres_xml);
            $xp = new \DOMXPath($dom);
            $xp->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

            $sld_id_lst = $xp->query('//p:sldIdLst')->item(0);
            if (!$sld_id_lst) {
                $sld_id_lst = $dom->createElementNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'p:sldIdLst');
                $dom->documentElement->insertBefore($sld_id_lst, $dom->documentElement->firstChild);
            }

            // Find maximum existing sldId
            $max_sld_id       = 0;
            $existing_sld_ids = $xp->query('//p:sldId');
            if (false !== $existing_sld_ids) {
                foreach ($existing_sld_ids as $node) {
                    $id = (int)$node->getAttribute('id');
                    if ($id > $max_sld_id) {
                        $max_sld_id = $id;
                    }
                }
            }
            $next_sld_id = $max_sld_id + 1;

            foreach ($new_slide_ids as $i => $info) {
                $sld_id = $dom->createElement('p:sldId');
                $sld_id->setAttribute('id', $next_sld_id + $i);
                $sld_id->setAttribute('r:id', $info['rel_id']);
                $sld_id_lst->appendChild($sld_id);
            }
            file_put_contents($pres_xml_file, $dom->saveXML());
            unset($dom, $xp, $sld_id_lst, $existing_sld_ids, $max_sld_id, $next_sld_id);

            // 6. Update ppt/_rels/presentation.xml.rels: add relationships for new slides
            $rels_file    = $temp_dir . '/ppt/_rels/presentation.xml.rels';
            $rels_content = file_get_contents($rels_file);
            if (false === $rels_content) {
                $this->rrmdir($temp_dir);
                return ['error' => 'Failed to read presentation.xml.rels'];
            }
            $rels_dom = new \DOMDocument();
            $rels_dom->loadXML($rels_content);
            $root = $rels_dom->documentElement;

            foreach ($new_rels as $rel) {
                $rel_elem = $rels_dom->createElement('Relationship');
                $rel_elem->setAttribute('Id', $rel['id']);
                $rel_elem->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide');
                $rel_elem->setAttribute('Target', $rel['target']);
                $root->appendChild($rel_elem);
            }
            file_put_contents($rels_file, $rels_dom->saveXML());
            unset($rels_dom, $root);

            // 7. Update [Content_Types].xml: add Override for each new slide
            $ct_file    = $temp_dir . '/[Content_Types].xml';
            $ct_content = file_get_contents($ct_file);
            if (false === $ct_content) {
                $this->rrmdir($temp_dir);
                return ['error' => 'Failed to read [Content_Types].xml'];
            }
            $ct_dom = new \DOMDocument();
            $ct_dom->loadXML($ct_content);
            $ct_root = $ct_dom->documentElement;

            foreach ($new_slide_ids as $info) {
                $override = $ct_dom->createElement('Override');
                $override->setAttribute('PartName', '/ppt/slides/slide' . $info['num'] . '.xml');
                $override->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml');
                $ct_root->appendChild($override);
            }
            file_put_contents($ct_file, $ct_dom->saveXML());
            unset($ct_dom, $ct_root);

            // 8. Re-pack ZIP (overwrite original)
            if (false === is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            $new_zip = new \ZipArchive();
            if (true !== $new_zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
                $this->rrmdir($temp_dir);
                return ['error' => 'Failed to create updated PPTX archive'];
            }
            $this->addDirToZip($new_zip, $temp_dir, '');
            $new_zip->close();
            unset($new_zip);

            $this->rrmdir($temp_dir);
            $temp_dir = null;

            $result = [
                'status'       => 'success',
                'path'         => $path,
                'appended'     => count($slides),
                'total_slides' => $max_slide_num + count($slides),
                'message'      => 'Slides appended successfully'
            ];
            unset($slides, $new_slide_ids, $new_rels, $image_counter);
            return $result;
        } catch (\Exception $e) {
            if (null !== $temp_dir && is_dir($temp_dir)) {
                $this->rrmdir($temp_dir);
            }
            return ['error' => 'Failed to append slides: ' . $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------------
    // Private XML generation methods (with full image support)
    // ------------------------------------------------------------------------

    private function writeContentTypes(string $temp_dir, array $slides): void
    {
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
        $ct .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
        $ct .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";

        $image_types = [];
        foreach ($slides as $slide) {
            if (!empty($slide['image_path']) && file_exists($slide['image_path'])) {
                $ext = strtolower(pathinfo($slide['image_path'], PATHINFO_EXTENSION));
                if ('png' === $ext) {
                    $image_types['png'] = 'image/png';
                } elseif ('jpg' === $ext || 'jpeg' === $ext) {
                    $image_types['jpg'] = 'image/jpeg';
                } elseif ('gif' === $ext) {
                    $image_types['gif'] = 'image/gif';
                } elseif ('bmp' === $ext) {
                    $image_types['bmp'] = 'image/bmp';
                }
            }
        }
        foreach ($image_types as $ext => $mime) {
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
        file_put_contents($temp_dir . '/[Content_Types].xml', $ct);
        unset($ct, $image_types);
    }

    private function writeRootRels(string $temp_dir): void
    {
        $root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $root_rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $root_rels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>' . "\n";
        $root_rels .= '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' . "\n";
        $root_rels .= '  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' . "\n";
        $root_rels .= '</Relationships>';
        file_put_contents($temp_dir . '/_rels/.rels', $root_rels);
        unset($root_rels);
    }

    private function writePresentationXml(string $temp_dir, array $slides): void
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
        file_put_contents($temp_dir . '/ppt/presentation.xml', $pres);
        unset($pres);
    }

    private function writePresentationRels(string $temp_dir, array $slides): void
    {
        $pres_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $pres_rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $pres_rels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>' . "\n";
        $pres_rels .= '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>' . "\n";
        foreach ($slides as $i => $s) {
            $pres_rels .= '  <Relationship Id="rId' . ($i + 3) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . ($i + 1) . '.xml"/>' . "\n";
        }
        $pres_rels .= '  <Relationship Id="rId' . (count($slides) + 3) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/>' . "\n";
        $pres_rels .= '  <Relationship Id="rId' . (count($slides) + 4) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/viewProps" Target="viewProps.xml"/>' . "\n";
        $pres_rels .= '  <Relationship Id="rId' . (count($slides) + 5) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tableStyles" Target="tableStyles.xml"/>' . "\n";
        $pres_rels .= '</Relationships>';
        file_put_contents($temp_dir . '/ppt/_rels/presentation.xml.rels', $pres_rels);
        unset($pres_rels);
    }

    private function writeTheme(string $temp_dir): void
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
        file_put_contents($temp_dir . '/ppt/theme/theme1.xml', $theme);
        unset($theme);
    }

    private function writeTableStyles(string $temp_dir): void
    {
        $table_styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $table_styles .= '<a:tblStyleLst xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>';
        file_put_contents($temp_dir . '/ppt/tableStyles.xml', $table_styles);
        unset($table_styles);
    }

    private function writePresProps(string $temp_dir): void
    {
        $pres_props = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $pres_props .= '<p:presentationPr xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $pres_props .= '  <p:extLst>' . "\n";
        $pres_props .= '    <p:ext uri="{E76CE94A-603C-4142-B9EB-6D1370010A27}"><p14:discardImageEditData xmlns:p14="http://schemas.microsoft.com/office/powerpoint/2010/main" val="0"/></p:ext>' . "\n";
        $pres_props .= '    <p:ext uri="{D31A062A-798A-4329-ABDD-BBA856620510}"><p14:defaultImageDpi xmlns:p14="http://schemas.microsoft.com/office/powerpoint/2010/main" val="32767"/></p:ext>' . "\n";
        $pres_props .= '    <p:ext uri="{FD5EFAAD-0ECE-453E-9831-46B23BE46B34}"><p15:chartTrackingRefBased xmlns:p15="http://schemas.microsoft.com/office/powerpoint/2012/main" val="1"/></p:ext>' . "\n";
        $pres_props .= '  </p:extLst>' . "\n";
        $pres_props .= '</p:presentationPr>';
        file_put_contents($temp_dir . '/ppt/presProps.xml', $pres_props);
        unset($pres_props);
    }

    private function writeViewProps(string $temp_dir): void
    {
        $view_props = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $view_props .= '<p:viewPr xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $view_props .= '  <p:normalViewPr><p:restoredLeft sz="15620"/><p:restoredTop sz="94660"/></p:normalViewPr>' . "\n";
        $view_props .= '  <p:slideViewPr>' . "\n";
        $view_props .= '    <p:cSldViewPr snapToGrid="0"><p:cViewPr varScale="1"><p:scale><a:sx n="104" d="100"/><a:sy n="104" d="100"/></p:scale><p:origin x="284" y="76"/></p:cViewPr><p:guideLst/></p:cSldViewPr>' . "\n";
        $view_props .= '  </p:slideViewPr>' . "\n";
        $view_props .= '  <p:notesTextViewPr><p:cViewPr><p:scale><a:sx n="1" d="1"/><a:sy n="1" d="1"/></p:scale><p:origin x="0" y="0"/></p:cViewPr></p:notesTextViewPr>' . "\n";
        $view_props .= '  <p:gridSpacing cx="72008" cy="72008"/>' . "\n";
        $view_props .= '</p:viewPr>';
        file_put_contents($temp_dir . '/ppt/viewProps.xml', $view_props);
        unset($view_props);
    }

    private function writeDocProps(string $temp_dir, array $slides): void
    {
        $app_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $app_xml .= '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">' . "\n";
        $app_xml .= '  <Application>AgentDocEngine</Application>' . "\n";
        $app_xml .= '  <Slides>' . count($slides) . '</Slides>' . "\n";
        $app_xml .= '  <TotalTime>0</TotalTime>' . "\n";
        $app_xml .= '  <ScaleCrop>false</ScaleCrop>' . "\n";
        $app_xml .= '  <LinksUpToDate>false</LinksUpToDate>' . "\n";
        $app_xml .= '  <SharedDoc>false</SharedDoc>' . "\n";
        $app_xml .= '  <HyperlinksChanged>false</HyperlinksChanged>' . "\n";
        $app_xml .= '  <AppVersion>16.0000</AppVersion>' . "\n";
        $app_xml .= '</Properties>';
        file_put_contents($temp_dir . '/docProps/app.xml', $app_xml);

        $core_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $core_xml .= '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        $core_xml .= '  <dc:creator>AgentDocEngine</dc:creator>' . "\n";
        $core_xml .= '  <cp:lastModifiedBy>AgentDocEngine</cp:lastModifiedBy>' . "\n";
        $core_xml .= '  <dcterms:created xsi:type="dcterms:W3CDTF">' . date('Y-m-d\TH:i:s\Z') . '</dcterms:created>' . "\n";
        $core_xml .= '  <dcterms:modified xsi:type="dcterms:W3CDTF">' . date('Y-m-d\TH:i:s\Z') . '</dcterms:modified>' . "\n";
        $core_xml .= '</cp:coreProperties>';
        file_put_contents($temp_dir . '/docProps/core.xml', $core_xml);
        unset($app_xml, $core_xml);
    }

    private function writeSlideMaster(string $temp_dir): void
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
        file_put_contents($temp_dir . '/ppt/slideMasters/slideMaster1.xml', $master);

        $rels_dir = $temp_dir . '/ppt/slideMasters/_rels';
        if (false === is_dir($rels_dir)) {
            mkdir($rels_dir, 0755, true);
        }
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $rels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>' . "\n";
        $rels .= '</Relationships>';
        file_put_contents($rels_dir . '/slideMaster1.xml.rels', $rels);
        unset($master, $rels_dir, $rels);
    }

    private function writeSlideLayout(string $temp_dir): void
    {
        $layout = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $layout .= '<p:sldLayout type="blank" preserve="1" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $layout .= '  <p:cSld name="Blank Layout">' . "\n";
        $layout .= '    <p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree>' . "\n";
        $layout .= '  </p:cSld>' . "\n";
        $layout .= '</p:sldLayout>';
        file_put_contents($temp_dir . '/ppt/slideLayouts/slideLayout1.xml', $layout);

        $rels_dir = $temp_dir . '/ppt/slideLayouts/_rels';
        if (false === is_dir($rels_dir)) {
            mkdir($rels_dir, 0755, true);
        }
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $rels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>' . "\n";
        $rels .= '</Relationships>';
        file_put_contents($rels_dir . '/slideLayout1.xml.rels', $rels);
        unset($layout, $rels_dir, $rels);
    }

    /**
     * Write a single slide XML file and its relationships (with image support).
     */
    private function writeSlide(
        string  $temp_dir,
        int     $slide_num,
        array   $slide,
        ?string $image_rid,
        ?string $image_filename,
        array   $image_props
    ): void
    {
        $title      = $slide['title'] ?? '';
        $paragraphs = $slide['paragraphs'] ?? [];
        if (is_string($paragraphs)) {
            $paragraphs = [$paragraphs];
        }

        $slide_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $slide_xml .= '<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $slide_xml .= '  <p:cSld>' . "\n";
        $slide_xml .= '    <p:spTree>' . "\n";
        $slide_xml .= '      <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' . "\n";
        $slide_xml .= '      <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></a:xfrm></p:grpSpPr>' . "\n";

        // Title shape
        if ('' !== $title) {
            $esc_title = htmlspecialchars($title, ENT_XML1, 'UTF-8');
            $slide_xml .= '      <p:sp>' . "\n";
            $slide_xml .= '        <p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>' . "\n";
            $slide_xml .= '        <p:spPr><a:xfrm><a:off x="508000" y="508000"/><a:ext cx="7924860" cy="300000"/></a:xfrm></p:spPr>' . "\n";
            $slide_xml .= '        <p:txBody><a:bodyPr/><a:p><a:r><a:t>' . $esc_title . '</a:t></a:r></a:p></p:txBody>' . "\n";
            $slide_xml .= '      </p:sp>' . "\n";
            unset($esc_title);
        }

        // Image shape (if provided)
        if (null !== $image_rid && null !== $image_filename && !empty($image_props)) {
            $img_x     = $image_props['x'];
            $img_y     = $image_props['y'];
            $img_w     = $image_props['width'];
            $img_h     = $image_props['height'];
            $slide_xml .= '      <p:pic>' . "\n";
            $slide_xml .= '        <p:nvPicPr>' . "\n";
            $slide_xml .= '          <p:cNvPr id="' . (100 + $slide_num) . '" name="Picture"/>' . "\n";
            $slide_xml .= '          <p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr>' . "\n";
            $slide_xml .= '          <p:nvPr/>' . "\n";
            $slide_xml .= '        </p:nvPicPr>' . "\n";
            $slide_xml .= '        <p:blipFill>' . "\n";
            $slide_xml .= '          <a:blip r:embed="' . $image_rid . '"/>' . "\n";
            $slide_xml .= '          <a:stretch><a:fillRect/></a:stretch>' . "\n";
            $slide_xml .= '        </p:blipFill>' . "\n";
            $slide_xml .= '        <p:spPr>' . "\n";
            $slide_xml .= '          <a:xfrm><a:off x="' . $img_x . '" y="' . $img_y . '"/><a:ext cx="' . $img_w . '" cy="' . $img_h . '"/></a:xfrm>' . "\n";
            $slide_xml .= '          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>' . "\n";
            $slide_xml .= '        </p:spPr>' . "\n";
            $slide_xml .= '      </p:pic>' . "\n";
        }

        // Paragraphs (content lines)
        $y_offset    = 1000000;
        $line_height = 300000;
        foreach ($paragraphs as $idx => $para) {
            $para_text = trim($para);
            if ('' === $para_text) {
                continue;
            }
            $esc_para  = htmlspecialchars($para_text, ENT_XML1, 'UTF-8');
            $slide_xml .= '      <p:sp>' . "\n";
            $slide_xml .= '        <p:nvSpPr><p:cNvPr id="' . (10 + $idx) . '" name="Content ' . ($idx + 1) . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>' . "\n";
            $slide_xml .= '        <p:spPr><a:xfrm><a:off x="508000" y="' . ($y_offset + $idx * $line_height) . '"/><a:ext cx="7924860" cy="200000"/></a:xfrm></p:spPr>' . "\n";
            $slide_xml .= '        <p:txBody><a:bodyPr/><a:p><a:r><a:t>' . $esc_para . '</a:t></a:r></a:p></p:txBody>' . "\n";
            $slide_xml .= '      </p:sp>' . "\n";
            unset($esc_para);
        }

        $slide_xml .= '    </p:spTree>' . "\n";
        $slide_xml .= '  </p:cSld>' . "\n";
        $slide_xml .= '  <p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>' . "\n";
        $slide_xml .= '</p:sld>';
        file_put_contents($temp_dir . '/ppt/slides/slide' . $slide_num . '.xml', $slide_xml);
        unset($slide_xml);

        // Slide relationships file (.rels)
        $rels_dir = $temp_dir . '/ppt/slides/_rels';
        if (false === is_dir($rels_dir)) {
            mkdir($rels_dir, 0755, true);
        }
        $rels_file   = $rels_dir . '/slide' . $slide_num . '.xml.rels';
        $rel_content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rel_content .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $rel_content .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>' . "\n";
        if (null !== $image_rid && null !== $image_filename) {
            $rel_content .= '  <Relationship Id="' . $image_rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . $image_filename . '"/>' . "\n";
        }
        $rel_content .= '</Relationships>';
        file_put_contents($rels_file, $rel_content);
        unset($rels_dir, $rels_file, $rel_content);
    }

    // ------------------------------------------------------------------------
    // Helper methods
    // ------------------------------------------------------------------------

    private function normalizeZipPath(string $base_dir, string $relative): string
    {
        $base_parts = explode('/', trim($base_dir, '/'));
        $rel_parts  = explode('/', trim($relative, '/'));
        foreach ($rel_parts as $part) {
            if ('..' === $part) {
                array_pop($base_parts);
            } elseif ('.' !== $part && '' !== $part) {
                $base_parts[] = $part;
            }
        }
        return implode('/', $base_parts);
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