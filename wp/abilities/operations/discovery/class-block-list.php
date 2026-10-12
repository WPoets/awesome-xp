<?php
namespace aw2\abilities\operations\discovery;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/block-list
 *
 * Lists registered blocks with purpose, fields, and usage count.
 * Check this before creating a block: prefer tokens, then modifiers,
 * then patterns, and create a new block only as a last resort.
 */
class Block_List extends Operation_Base
{
    public function execute(array $params = [])
    {
        global $wpdb;

        $search = isset($params['search']) ? sanitize_text_field(trim($params['search'])) : '';
        $category = isset($params['category']) ? sanitize_text_field(trim($params['category'])) : '';

        $blocks = [];

        // 1. Fetch from aw_gb_blocks posts (only live posts, ignore draft forks)
        $block_posts = get_posts([
            'post_type'      => 'aw_gb_blocks',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => 100,
            'meta_query'     => [
                [
                    'key'     => '_aw_is_draft_fork',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);

        foreach ($block_posts as $bp) {
            $slug = $bp->post_name;
            $title = $bp->post_title;
            $decoded = json_decode($bp->post_content, true);

            if (is_array($decoded)) {
                $config = isset($decoded['config']) && is_array($decoded['config']) ? $decoded['config'] : $decoded;
            } else {
                $config = [];
                $template = $bp->post_content;
                if (preg_match('/\[config\](.*?)\[\/config\]/s', $template, $cfg_match)) {
                    if (preg_match('/\[name\](.*?)\[\/name\]/s', $cfg_match[1], $nm)) $config['name'] = trim($nm[1]);
                    if (preg_match('/\[title\](.*?)\[\/title\]/s', $cfg_match[1], $ti)) $config['title'] = trim($ti[1]);
                    if (preg_match('/\[category\](.*?)\[\/category\]/s', $cfg_match[1], $cat)) $config['category'] = trim($cat[1]);
                    if (preg_match('/\[icon\](.*?)\[\/icon\]/s', $cfg_match[1], $ic)) $config['icon'] = trim($ic[1]);
                }
                if (preg_match_all('/"name"\s*:\s*"([^"]+)"/', $template, $f_matches)) {
                    $config['fields'] = array_map(function($fn) { return ['name' => $fn]; }, $f_matches[1]);
                }
            }

            if (empty($slug) && !empty($config['name'])) {
                $slug = $config['name'];
            }
            if (empty($title) && !empty($config['title'])) {
                $title = $config['title'];
            }

            $block_category = $config['category'] ?? 'widgets';
            $purpose = $config['description'] ?? (!empty($bp->post_excerpt) ? $bp->post_excerpt : (!empty($title) ? $title : 'Dynamic Gutenberg Block'));

            if (!empty($category) && strtolower($block_category) !== strtolower($category)) {
                continue;
            }

            if (!empty($search)) {
                if (stripos($slug, $search) === false && stripos($title, $search) === false && stripos($purpose, $search) === false) {
                    continue;
                }
            }

            // Extract field names
            $fields = [];
            if (!empty($config['fields']) && is_array($config['fields'])) {
                foreach ($config['fields'] as $f) {
                    $fields[] = $f['name'] ?? ($f['attr_name'] ?? 'field');
                }
            } elseif (!empty($config['tabs']) && is_array($config['tabs'])) {
                foreach ($config['tabs'] as $tab) {
                    if (!empty($tab['fields']) && is_array($tab['fields'])) {
                        foreach ($tab['fields'] as $f) {
                            $fields[] = $f['name'] ?? ($f['attr_name'] ?? 'field');
                        }
                    }
                }
            }

            // Calculate usage count across posts
            $usage_count = 0;
            if ($wpdb) {
                $block_tag = "%wp:dgb/{$slug}%";
                $count_query = $wpdb->prepare(
                    "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s",
                    $block_tag
                );
                $usage_count = (int)$wpdb->get_var($count_query);
            }

            $blocks[$slug] = [
                'name'        => $slug,
                'title'       => $title,
                'category'    => $block_category,
                'purpose'     => $purpose,
                'fields'      => $fields,
                'status'      => $bp->post_status,
                'usage_count' => $usage_count
            ];
        }

        // 2. Also check file-based blocks in wp/libraries/gutenberg/blocks/
        $blocks_dir = dirname(dirname(dirname(__DIR__))) . '/libraries/gutenberg/blocks/';
        if (is_dir($blocks_dir)) {
            $files = glob($blocks_dir . '*.json');
            if ($files) {
                foreach ($files as $file) {
                    $json = json_decode(file_get_contents($file), true);
                    if ($json && isset($json['name']) && !isset($blocks[$json['name']])) {
                        $slug = $json['name'];
                        $b_cat = $json['category'] ?? 'widgets';
                        if (!empty($category) && strtolower($b_cat) !== strtolower($category)) {
                            continue;
                        }
                        if (!empty($search) && stripos($slug, $search) === false && stripos($json['title'] ?? '', $search) === false) {
                            continue;
                        }
                        $blocks[$slug] = [
                            'name'        => $slug,
                            'title'       => $json['title'] ?? $slug,
                            'category'    => $b_cat,
                            'purpose'     => $json['description'] ?? 'Filesystem DGB Block',
                            'fields'      => array_column($json['fields'] ?? [], 'name'),
                            'status'      => 'file_registered',
                            'usage_count' => 0
                        ];
                    }
                }
            }
        }

        return $this->success(array_values($blocks));
    }
}
