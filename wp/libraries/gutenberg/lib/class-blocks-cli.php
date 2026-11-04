<?php
namespace aw2\gutenberg_blocks;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Manage Awesome Gutenberg Blocks registry and compiled assets.
 */
class Blocks_CLI_Command extends \WP_CLI_Command
{
    /**
     * Flush the Awesome Gutenberg Block registry cache.
     *
     * ## OPTIONS
     *
     * [--recompile]
     * : Synchronously rebuild the registry and recompile block CSS/JS assets.
     *
     * [--slug=<slug>]
     * : Recompile assets for a specific block slug only.
     *
     * ## EXAMPLES
     *
     *     # Invalidate registry cache
     *     $ wp awx blocks flush
     *
     *     # Invalidate and rebuild all block assets
     *     $ wp awx blocks flush --recompile
     *
     *     # Recompile assets for a single block
     *     $ wp awx blocks flush --slug=awx-features-showcase
     */
    public function flush($args, $assoc_args)
    {
        $dgb = dgb();
        $dgb->invalidate_registry();
        \WP_CLI::line("Block registry cache invalidated.");

        $slug = isset($assoc_args['slug']) ? sanitize_title(trim($assoc_args['slug'])) : '';
        if (!empty($slug)) {
            $compiled = $dgb->recompile_single_block_assets($slug);
            $has_assets = !empty($compiled['css']) || !empty($compiled['js']);
            if ($has_assets) {
                \WP_CLI::success("Recompiled CSS/JS assets for block '{$slug}'.");
            } else {
                \WP_CLI::warning("Block '{$slug}' had no compilable styles/scripts or was not found.");
            }
            return;
        }

        if (!empty($assoc_args['recompile'])) {
            \WP_CLI::line("Rebuilding block registry and compiling assets...");
            $blocks = $dgb->build_registry();
            $count  = is_array($blocks) ? count($blocks) : 0;
            \WP_CLI::success("Rebuilt registry and compiled assets for {$count} block(s).");
            return;
        }

        \WP_CLI::success("Registry stamp bumped. Cache will rebuild on next request.");
    }

    /**
     * List registered Awesome Gutenberg Blocks.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     *   - count
     * ---
     *
     * [--fields=<fields>]
     * : Comma-separated list of fields to display.
     * ---
     * default: slug,title,category,icon,has_css,has_js,innerblocks
     * ---
     *
     * ## EXAMPLES
     *
     *     # List all blocks
     *     $ wp awx blocks list
     *
     *     # Output as JSON
     *     $ wp awx blocks list --format=json
     */
    public function list_($args, $assoc_args)
    {
        $dgb = dgb();
        $blocks = $dgb->load_registry(true);

        if (empty($blocks)) {
            \WP_CLI::line("No Awesome Gutenberg blocks found.");
            return;
        }

        $items = array();
        foreach ($blocks as $slug => $config) {
            $items[] = array(
                'slug'        => $slug,
                'title'       => isset($config['title']) ? $config['title'] : $slug,
                'category'    => isset($config['category']) ? $config['category'] : 'common',
                'icon'        => isset($config['icon']) ? $config['icon'] : 'admin-generic',
                'has_css'     => !empty($config['css']) ? 'yes' : 'no',
                'has_js'      => !empty($config['js']) ? 'yes' : 'no',
                'innerblocks' => !empty($config['has_innerblocks']) ? 'yes' : 'no',
            );
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        $fields = isset($assoc_args['fields']) ? explode(',', $assoc_args['fields']) : array('slug', 'title', 'category', 'icon', 'has_css', 'has_js', 'innerblocks');

        \WP_CLI\Utils\format_items($format, $items, $fields);
    }

    /**
     * Recompile CSS and JS assets for one or all blocks.
     *
     * ## OPTIONS
     *
     * [<slug>]
     * : Slug of the block to recompile.
     *
     * [--all]
     * : Recompile assets for all registered blocks.
     *
     * ## EXAMPLES
     *
     *     # Recompile all blocks
     *     $ wp awx blocks recompile
     *
     *     # Recompile a single block
     *     $ wp awx blocks recompile awx-features-showcase
     *
     *     # Recompile all blocks explicitly
     *     $ wp awx blocks recompile --all
     */
    public function recompile($args, $assoc_args)
    {
        $dgb = dgb();
        $slug = !empty($args[0]) ? sanitize_title(trim($args[0])) : '';
        $all  = !empty($assoc_args['all']) || empty($slug);

        if (!empty($slug)) {
            $compiled = $dgb->recompile_single_block_assets($slug);
            $has_assets = !empty($compiled['css']) || !empty($compiled['js']);
            if ($has_assets) {
                \WP_CLI::success("Recompiled assets for block '{$slug}'.");
            } else {
                \WP_CLI::warning("Could not recompile block '{$slug}'. Ensure the post exists and is published.");
            }
            return;
        }

        \WP_CLI::line("Rebuilding all block assets...");
        $dgb->invalidate_registry();
        $blocks = $dgb->build_registry();
        $count  = is_array($blocks) ? count($blocks) : 0;
        \WP_CLI::success("Successfully recompiled assets for {$count} block(s).");
    }

    /**
     * Seed the Core 12 AWX Dynamic Gutenberg Blocks into the database.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Overwrite existing block posts with current specifications.
     *
     * ## EXAMPLES
     *
     *     # Seed missing core blocks
     *     $ wp awx blocks seed
     *
     *     # Force update all 12 core blocks
     *     $ wp awx blocks seed --force
     */
    public function seed($args, $assoc_args)
    {
        $force = !empty($assoc_args['force']);
        \WP_CLI::line("Seeding Core 12 AWX blocks (force=" . ($force ? "true" : "false") . ")...");

        if (!class_exists('aw2\gutenberg_blocks\AWX_Core_Blocks_Seeder')) {
            require_once __DIR__ . '/class-core-blocks-seeder.php';
        }

        $res = AWX_Core_Blocks_Seeder::seed($force);

        if (!empty($res['errors'])) {
            foreach ($res['errors'] as $err) {
                \WP_CLI::warning($err);
            }
        }

        \WP_CLI::success(sprintf(
            "Seeding complete: %d seeded, %d updated, %d skipped.",
            $res['seeded'],
            $res['updated'],
            $res['skipped']
        ));
    }
}
