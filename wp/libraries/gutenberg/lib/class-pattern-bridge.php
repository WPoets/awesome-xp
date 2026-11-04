<?php
/**
 * AWX Pattern Bridge
 *
 * Implements native pattern management and synchronization via the wp_block post type.
 *
 * @package DynamicGutenbergBlocks
 * @version 1.0.0
 */

namespace aw2\gutenberg_blocks;

if (!defined('ABSPATH')) {
    exit;
}

final class AWX_Pattern_Bridge
{
    const META_SLUG    = '_awx_pattern_slug';
    const META_MANAGED = '_awx_pattern_managed';

    /**
     * Export all AWX-managed patterns into a portable JSON manifest.
     *
     * @return array Portable pattern manifest entries
     */
    public static function export_bundle(): array
    {
        $query = new \WP_Query([
            'post_type'      => 'wp_block',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                ['key' => self::META_MANAGED, 'value' => 1]
            ],
        ]);

        $bundle = [];
        if (!empty($query->posts)) {
            foreach ($query->posts as $post) {
                $categories = function_exists('wp_get_object_terms')
                    ? wp_get_object_terms($post->ID, 'wp_pattern_category', ['fields' => 'slugs'])
                    : [];
                $slug_meta = get_post_meta($post->ID, self::META_SLUG, true);

                $bundle[] = [
                    'slug'        => !empty($slug_meta) ? $slug_meta : $post->post_name,
                    'title'       => $post->post_title,
                    'categories'  => is_wp_error($categories) ? [] : (array)$categories,
                    'content'     => $post->post_content,
                ];
            }
        }

        return $bundle;
    }

    /**
     * Import a pattern bundle idempotently, safely handling slashes and meta keys.
     *
     * @param array $manifest List of pattern entries with slug, title, content, categories
     * @return array Summary of import results [created => int, updated => int, errors => array]
     */
    public static function import_bundle(array $manifest): array
    {
        $results = ['created' => 0, 'updated' => 0, 'errors' => []];

        foreach ($manifest as $entry) {
            if (empty($entry['slug']) || empty($entry['content'])) {
                $results['errors'][] = "Missing slug or content for pattern: " . ($entry['title'] ?? 'Unknown');
                continue;
            }

            $slug  = preg_replace('/[^a-z0-9_\.\-]/i', '', strtolower(trim($entry['slug'])));
            $title = sanitize_text_field($entry['title'] ?? $slug);

            // Locate existing post by explicit metadata rather than post_name
            $existing = new \WP_Query([
                'post_type'      => 'wp_block',
                'post_status'    => ['publish', 'draft', 'trash'],
                'posts_per_page' => 1,
                'meta_query'     => [
                    ['key' => self::META_SLUG, 'value' => $slug]
                ],
            ]);

            $post_content = function_exists('wp_slash') ? wp_slash($entry['content']) : $entry['content'];

            $post_data = [
                'post_type'    => 'wp_block',
                'post_status'  => 'publish',
                'post_title'   => $title,
                'post_content' => $post_content,
                'meta_input'   => [
                    'wp_pattern_sync_status' => 'unsynced',
                    self::META_SLUG          => $slug,
                    self::META_MANAGED       => 1,
                ],
            ];

            if ($existing->have_posts()) {
                $post_data['ID'] = $existing->posts[0]->ID;
                $res = wp_update_post($post_data, true);
                if (is_wp_error($res)) {
                    $results['errors'][] = $res->get_error_message();
                    continue;
                }
                $pattern_id = $res;
                $results['updated']++;
            } else {
                $res = wp_insert_post($post_data, true);
                if (is_wp_error($res)) {
                    $results['errors'][] = $res->get_error_message();
                    continue;
                }
                $pattern_id = $res;
                $results['created']++;
            }

            if (!empty($entry['categories']) && function_exists('wp_set_object_terms')) {
                wp_set_object_terms($pattern_id, (array)$entry['categories'], 'wp_pattern_category');
            }
        }

        return $results;
    }
}

// Global class alias for root-namespace access
if (!class_exists('AWX_Pattern_Bridge', false)) {
    class_alias('aw2\gutenberg_blocks\AWX_Pattern_Bridge', 'AWX_Pattern_Bridge');
}
