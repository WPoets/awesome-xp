<?php
/**
 * AWX Patterns CLI Commands
 *
 * Provides WP-CLI management for importing, exporting, and listing
 * AWX-managed Gutenberg block patterns.
 *
 * @package DynamicGutenbergBlocks
 * @version 1.0.0
 */

namespace aw2\gutenberg_blocks;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class Patterns_CLI_Command extends \WP_CLI_Command
{
    /**
     * Import a pattern pack JSON manifest into WordPress.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the JSON pattern bundle file.
     *
     * ## EXAMPLES
     *
     *     # Import baseline patterns fixture
     *     $ wp awx patterns import wp-content/plugins/awesome-xp/wp/libraries/gutenberg/fixtures/patterns.json
     */
    public function import($args, $assoc_args)
    {
        $file_path = $args[0] ?? '';

        if (empty($file_path) || !file_exists($file_path)) {
            \WP_CLI::error("Pattern file not found at: " . $file_path);
            return;
        }

        $raw_json = file_get_contents($file_path);
        $manifest = json_decode($raw_json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($manifest)) {
            \WP_CLI::error("Invalid JSON format in: " . $file_path);
            return;
        }

        \WP_CLI::line(sprintf("Importing %d pattern(s) from %s...", count($manifest), basename($file_path)));

        if (!class_exists('AWX_Pattern_Bridge')) {
            require_once __DIR__ . '/class-pattern-bridge.php';
        }

        $res = \AWX_Pattern_Bridge::import_bundle($manifest);

        if (!empty($res['errors'])) {
            foreach ($res['errors'] as $err) {
                \WP_CLI::warning($err);
            }
        }

        \WP_CLI::success(sprintf(
            "Pattern import complete: %d created, %d updated, %d error(s).",
            $res['created'],
            $res['updated'],
            count($res['errors'])
        ));
    }

    /**
     * Export all AWX-managed block patterns into a portable JSON manifest.
     *
     * ## OPTIONS
     *
     * [<file>]
     * : Target file path to write exported JSON bundle to. If omitted, outputs to stdout.
     *
     * ## EXAMPLES
     *
     *     # Export to file
     *     $ wp awx patterns export /path/to/backup-patterns.json
     *
     *     # Export to stdout
     *     $ wp awx patterns export
     */
    public function export($args, $assoc_args)
    {
        if (!class_exists('AWX_Pattern_Bridge')) {
            require_once __DIR__ . '/class-pattern-bridge.php';
        }

        $bundle = \AWX_Pattern_Bridge::export_bundle();
        $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $target_file = $args[0] ?? '';

        if (!empty($target_file)) {
            $bytes = file_put_contents($target_file, $json);
            if ($bytes === false) {
                \WP_CLI::error("Failed writing export bundle to: " . $target_file);
                return;
            }
            \WP_CLI::success(sprintf("Exported %d pattern(s) to %s (%d bytes).", count($bundle), $target_file, $bytes));
        } else {
            \WP_CLI::line($json);
        }
    }

    /**
     * List all AWX-managed block patterns.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output format (table, json, csv, count). Default: table.
     *
     * ## EXAMPLES
     *
     *     # List all managed patterns
     *     $ wp awx patterns list
     */
    public function list_($args, $assoc_args)
    {
        $meta_slug_key = class_exists('AWX_Pattern_Bridge') ? \AWX_Pattern_Bridge::META_SLUG : '_awx_pattern_slug';
        $meta_managed  = class_exists('AWX_Pattern_Bridge') ? \AWX_Pattern_Bridge::META_MANAGED : '_awx_pattern_managed';

        $query = new \WP_Query([
            'post_type'      => 'wp_block',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'meta_query'     => [
                ['key' => $meta_managed, 'value' => 1]
            ]
        ]);

        if (empty($query->posts)) {
            \WP_CLI::line("No AWX-managed patterns found in database.");
            return;
        }

        $items = [];
        foreach ($query->posts as $p) {
            $slug = get_post_meta($p->ID, $meta_slug_key, true) ?: $p->post_name;
            $sync = get_post_meta($p->ID, 'wp_pattern_sync_status', true) ?: 'synced';
            $cats = wp_get_object_terms($p->ID, 'wp_pattern_category', ['fields' => 'slugs']);

            $items[] = [
                'ID'         => $p->ID,
                'slug'       => $slug,
                'title'      => $p->post_title,
                'sync'       => $sync,
                'categories' => is_wp_error($cats) ? '' : implode(', ', (array)$cats),
                'status'     => $p->post_status,
            ];
        }

        $format = $assoc_args['format'] ?? 'table';
        \WP_CLI\Utils\format_items($format, $items, ['ID', 'slug', 'title', 'sync', 'categories', 'status']);
    }
}
