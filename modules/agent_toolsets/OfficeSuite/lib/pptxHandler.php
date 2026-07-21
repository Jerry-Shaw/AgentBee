<?php

/**
 * PPTX Handler - Read-only Native PHP Implementation
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

namespace modules\agent_toolsets\OfficeSuite\lib;

use modules\agent_core\lib\utils;
use Nervsys\Core\Factory;

class pptxHandler extends Factory
{
    public utils $utils;

    public function __construct()
    {
        $this->utils = utils::new();
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
        $temp_dir = $this->utils->agent_config['workspace_path'] . '/OfficeTemp/pptx_read_' . uniqid('', true);
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
                        if (!in_array($ext, $allowed, true)) {
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
     * Normalize a relative zip path against a base directory (handles '..').
     */
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

        unset($base_dir, $relative, $rel_parts, $part);
        return implode('/', $base_parts);
    }
}