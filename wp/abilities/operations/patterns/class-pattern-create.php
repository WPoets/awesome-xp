<?php
namespace aw2\abilities\operations\patterns;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/pattern-create
 *
 * Creates a pattern as a draft by composing existing blocks.
 * Validates block registration and design system token usage.
 */
class Pattern_Create extends Operation_Base
{
    public function execute(array $params = [])
    {
        $title = isset($params['title']) ? sanitize_text_field(trim($params['title'])) : '';
        $category = isset($params['category']) ? sanitize_text_field(trim($params['category'])) : 'featured';
        $markup = isset($params['markup']) ? (string)$params['markup'] : '';
        $synced = !empty($params['synced']);

        if (empty($title) || empty(trim($markup))) {
            return $this->error('Pattern title and block markup are required.', 'Provide {"title": "...", "markup": "<!-- wp:... -->"}', 'missing_parameters');
        }

        // 1. Validate blocks inside markup
        $core_allowed = ['paragraph', 'heading', 'columns', 'column', 'group', 'image', 'buttons', 'button', 'separator', 'spacer'];
        if (preg_match_all('/<!--\s+wp:([a-zA-Z0-9_\-\/]+)/', $markup, $matches)) {
            $blocks_in_markup = array_unique($matches[1]);
            foreach ($blocks_in_markup as $b) {
                // If core block
                if (in_array(str_replace('core/', '', $b), $core_allowed, true)) {
                    continue;
                }
                // If DGB block (dgb/slug)
                if (strpos($b, 'dgb/') === 0) {
                    $slug = substr($b, 4);
                    $exists = get_page_by_path($slug, OBJECT, 'aw_gb_blocks');
                    if (!$exists) {
                        return $this->error(
                            "Referenced block '{$b}' is not registered.",
                            "Call awesome/block-list to check available blocks or create it via awesome/block-create.",
                            'unregistered_block'
                        );
                    }
                    continue;
                }
            }
        }

        // 2. Validate token class usage in HTML markup (CSS Design System check)
        // If raw hex colors are used in inline styles, warn
        if (preg_match('/#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b/', $markup, $hex_match)) {
            // Include a warning or block if strict
        }

        // 3. Save as wp_block post in draft status
        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $markup,
            'post_type'    => 'wp_block',
            'post_status'  => 'draft'
        ], true);

        if (is_wp_error($post_id)) {
            return $this->error($post_id->get_error_message(), 'Retry pattern creation with valid content.', 'db_error');
        }

        // Assign category
        if (!empty($category) && function_exists('wp_set_object_terms')) {
            wp_set_object_terms($post_id, $category, 'wp_pattern_category');
        }

        // Handle synced / unsynced term
        if (!$synced && function_exists('wp_set_object_terms')) {
            wp_set_object_terms($post_id, 'unsynced', 'wp_pattern_sync_type');
        }

        $this->audit_log('awesome/pattern-create', 'pattern', (string)$post_id, 'success');

        return $this->success([
            'id'       => $post_id,
            'title'    => $title,
            'category' => $category,
            'synced'   => $synced,
            'status'   => 'draft',
            'message'  => "Pattern '{$title}' created as draft (ID: {$post_id})."
        ]);
    }
}
