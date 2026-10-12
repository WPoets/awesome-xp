<?php
namespace aw2\abilities\operations\discovery;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/block-get
 *
 * Returns a block's DGB JSON config, shortcode template, version info,
 * and pages using it.
 */
class Block_Get extends Operation_Base
{
    public function execute(array $params = [])
    {
        global $wpdb;

        $slug = isset($params['slug']) ? sanitize_title(trim($params['slug'])) : '';
        if (empty($slug)) {
            return $this->error('Block slug is required.', 'Pass {"slug": "block-name"} in parameters.', 'missing_slug');
        }

        $config = null;
        $template = '';
        $version_info = ['current' => 1, 'revisions_count' => 0];
        $post_id = 0;
        $status = 'publish';

        // 1. Check aw_gb_blocks custom post type
        $post = null;
        if (is_numeric($slug)) {
            $p = get_post((int)$slug);
            if ($p && $p->post_type === 'aw_gb_blocks') {
                $post = $p;
            }
        }
        if (!$post) {
            $posts = get_posts([
                'name'           => $slug,
                'post_type'      => 'aw_gb_blocks',
                'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
                'posts_per_page' => 1
            ]);
            if (!empty($posts)) {
                $post = $posts[0];
            }
        }
        if (!$post && function_exists('get_page_by_path')) {
            $post = get_page_by_path($slug, OBJECT, 'aw_gb_blocks');
        }
        if (!$post) {
            // Fallback: search by post_title
            $posts_by_title = get_posts([
                'title'          => $slug,
                'post_type'      => 'aw_gb_blocks',
                'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
                'posts_per_page' => 1
            ]);
            if (!empty($posts_by_title)) {
                $post = $posts_by_title[0];
            }
        }
        if (!$post && $wpdb) {
            // Fallback: search post_content for [name]slug[/name] or "name": "slug"
            $like_tag = '%[name]' . $wpdb->esc_like($slug) . '[/name]%';
            $like_json = '%"name"%' . $wpdb->esc_like($slug) . '%';
            $p_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'aw_gb_blocks' AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID DESC LIMIT 1",
                $like_tag,
                $like_json
            ));
            if ($p_id) {
                $post = get_post((int)$p_id);
            }
        }

        if ($post) {
            $post_id = $post->ID;
            $status = $post->post_status;
            $decoded = json_decode($post->post_content, true);
            if (is_array($decoded)) {
                $config = $decoded['config'] ?? $decoded;
                $template = $decoded['template'] ?? '';
            } else {
                // Post content is raw shortcode DSL (like real live blocks)
                $template = $post->post_content;
                $parsed_config = [];
                if (preg_match('/\[config\](.*?)\[\/config\]/s', $template, $cfg_match)) {
                    if (preg_match('/\[name\](.*?)\[\/name\]/s', $cfg_match[1], $nm)) $parsed_config['name'] = trim($nm[1]);
                    if (preg_match('/\[title\](.*?)\[\/title\]/s', $cfg_match[1], $ti)) $parsed_config['title'] = trim($ti[1]);
                    if (preg_match('/\[category\](.*?)\[\/category\]/s', $cfg_match[1], $cat)) $parsed_config['category'] = trim($cat[1]);
                    if (preg_match('/\[icon\](.*?)\[\/icon\]/s', $cfg_match[1], $ic)) $parsed_config['icon'] = trim($ic[1]);
                }
                $config = [
                    'name'        => $parsed_config['name'] ?? $post->post_name,
                    'title'       => $parsed_config['title'] ?? $post->post_title,
                    'category'    => $parsed_config['category'] ?? 'common',
                    'description' => !empty($post->post_excerpt) ? $post->post_excerpt : $post->post_title,
                    'icon'        => $parsed_config['icon'] ?? 'admin-post'
                ];
            }

            // Version info
            $revisions = wp_get_post_revisions($post_id);
            $current_v = (int)get_post_meta($post_id, '_aw_version', true) ?: (count($revisions) + 1);
            $version_info = [
                'current_version' => $current_v,
                'revisions_count' => count($revisions),
                'status'          => $status,
                'modified'        => $post->post_modified
            ];
        }

        // 2. Check filesystem fallback if not in DB
        if (!$config) {
            $file_path = dirname(dirname(dirname(__DIR__))) . "/libraries/gutenberg/blocks/{$slug}.json";
            if (file_exists($file_path)) {
                $file_json = json_decode(file_get_contents($file_path), true);
                if ($file_json) {
                    $config = $file_json;
                    $template = $file_json['template'] ?? '';
                }
            }
        }

        if (!$config) {
            return $this->error(
                "Block '{$slug}' was not found.",
                "Call awesome/block-list to inspect existing blocks before creating a new one.",
                'block_not_found'
            );
        }

        // Find pages using this block
        $pages_using = [];
        if ($wpdb) {
            $block_tag = "%wp:dgb/{$slug}%";
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s LIMIT 10",
                $block_tag
            ));
            foreach ($results as $r) {
                $pages_using[] = [
                    'id'    => (int)$r->ID,
                    'title' => $r->post_title,
                    'slug'  => $r->post_name
                ];
            }
        }

        // Check for active open draft fork
        $open_draft = null;
        if ($post_id) {
            $draft_posts = get_posts([
                'post_type'   => 'aw_gb_blocks',
                'meta_key'    => '_aw_forked_from',
                'meta_value'  => $slug,
                'post_status' => 'draft'
            ]);
            if (!empty($draft_posts)) {
                $open_draft = [
                    'draft_id' => $draft_posts[0]->ID,
                    'modified' => $draft_posts[0]->post_modified
                ];
            }
        }

        return $this->success([
            'slug'         => $slug,
            'status'       => $status,
            'config'       => $config,
            'template'     => $template,
            'version_info' => $version_info,
            'open_draft'   => $open_draft,
            'pages_using'  => $pages_using
        ]);
    }
}
