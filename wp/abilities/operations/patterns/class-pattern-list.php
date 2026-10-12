<?php
namespace aw2\abilities\operations\patterns;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/pattern-list
 *
 * Lists block patterns built from Awesome blocks, with title,
 * category, and whether each is synced.
 */
class Pattern_List extends Operation_Base
{
    public function execute(array $params = [])
    {
        $search = isset($params['search']) ? sanitize_text_field(trim($params['search'])) : '';
        $category = isset($params['category']) ? sanitize_text_field(trim($params['category'])) : '';

        // Query wp_block custom post type (standard WordPress pattern storage)
        $args = [
            'post_type'      => 'wp_block',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => 100
        ];
        if (!empty($search)) {
            $args['s'] = $search;
        }

        $pattern_posts = get_posts($args);
        $patterns = [];

        foreach ($pattern_posts as $post) {
            $markup = $post->post_content;

            // Check if pattern uses Awesome DGB blocks
            $uses_awesome = strpos($markup, 'wp:dgb/') !== false || strpos($markup, 'awx-') !== false;

            // Determine categories
            $terms = function_exists('wp_get_post_terms') ? wp_get_post_terms($post->ID, 'wp_pattern_category', ['fields' => 'names']) : [];
            $cat_name = (!empty($terms) && !is_wp_error($terms)) ? $terms[0] : 'uncategorized';

            if (!empty($category) && strtolower($cat_name) !== strtolower($category)) {
                continue;
            }

            // Synced flag
            $is_synced = function_exists('has_term') ? !has_term('wp_pattern_sync_type_unsynced', 'wp_pattern_sync_type', $post->ID) : false;

            $patterns[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title,
                'slug'         => $post->post_name,
                'category'     => $cat_name,
                'synced'       => $is_synced,
                'status'       => $post->post_status,
                'uses_awesome' => $uses_awesome,
                'date'         => $post->post_date
            ];
        }

        return $this->success($patterns);
    }
}
