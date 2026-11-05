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

    /**
     * Migrate legacy DGB blocks (dgb/field child blocks, legacy image IDs) to modern native attributes.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview migration changes without modifying the database.
     *
     * [--post-type=<types>]
     * : Comma-separated list of post types to scan and migrate.
     * ---
     * default: post,page,wp_block
     * ---
     *
     * [--post-id=<id>]
     * : Migrate a specific post ID only.
     *
     * ## EXAMPLES
     *
     *     # Preview migration changes across all posts and pages
     *     $ wp awx blocks migrate --dry-run
     *
     *     # Migrate all posts, pages, and patterns
     *     $ wp awx blocks migrate
     *
     *     # Migrate a single post
     *     $ wp awx blocks migrate --post-id=105
     */
    public function migrate($args, $assoc_args)
    {
        global $wpdb;

        $dry_run   = !empty($assoc_args['dry-run']);
        $post_id   = !empty($assoc_args['post-id']) ? (int) $assoc_args['post-id'] : 0;
        $post_type = !empty($assoc_args['post-type']) ? $assoc_args['post-type'] : 'post,page,wp_block';
        $types     = array_map('trim', explode(',', $post_type));

        $dgb = dgb();
        $registry = $dgb->load_registry(true);

        \WP_CLI::line("Scanning for legacy DGB blocks" . ($dry_run ? " [DRY RUN]" : "") . "...");

        // Query posts
        if ($post_id > 0) {
            $posts = get_posts(array(
                'include'        => array($post_id),
                'post_type'      => 'any',
                'post_status'    => 'any',
                'posts_per_page' => 1,
            ));
        } else {
            $posts = get_posts(array(
                'post_type'      => $types,
                'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => -1,
                's'              => 'wp:dgb/',
            ));

            if (empty($posts) && $wpdb) {
                $type_placeholders = implode("','", array_map('esc_sql', $types));
                $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('{$type_placeholders}') AND post_content LIKE '%wp:dgb/%'");
                if (!empty($ids)) {
                    $posts = get_posts(array(
                        'include'        => $ids,
                        'post_type'      => $types,
                        'post_status'    => 'any',
                        'posts_per_page' => -1,
                    ));
                }
            }
        }

        if (empty($posts)) {
            \WP_CLI::success("No posts containing DGB blocks found.");
            return;
        }

        $posts_scanned  = count($posts);
        $posts_migrated = 0;
        $blocks_updated = 0;

        foreach ($posts as $post) {
            if (empty($post->post_content) || strpos($post->post_content, 'wp:dgb/') === false) {
                continue;
            }

            $blocks = parse_blocks($post->post_content);
            if (empty($blocks)) {
                continue;
            }

            $post_blocks_changed = 0;
            $migrated_blocks = $this->migrate_block_tree($blocks, $registry, $post_blocks_changed);

            if ($post_blocks_changed > 0) {
                $new_content = serialize_blocks($migrated_blocks);

                if ($new_content !== $post->post_content) {
                    $posts_migrated++;
                    $blocks_updated += $post_blocks_changed;

                    if ($dry_run) {
                        \WP_CLI::line(sprintf("[DRY RUN] Post #%d '%s' (%s): %d block(s) would be updated.", $post->ID, $post->post_title, $post->post_type, $post_blocks_changed));
                    } else {
                        remove_all_actions('save_post_aw_gb_blocks');
                        wp_update_post(array(
                            'ID'           => $post->ID,
                            'post_content' => $new_content,
                        ));
                        \WP_CLI::line(sprintf("[MIGRATED] Post #%d '%s' (%s): %d block(s) upgraded.", $post->ID, $post->post_title, $post->post_type, $post_blocks_changed));
                    }
                }
            }
        }

        \WP_CLI::success(sprintf(
            "Migration %s: %d posts scanned, %d posts %s, %d blocks migrated.",
            $dry_run ? "simulation complete" : "complete",
            $posts_scanned,
            $posts_migrated,
            $dry_run ? "would be updated" : "updated",
            $blocks_updated
        ));
    }

    /**
     * Recursively migrate a parsed block tree
     */
    private function migrate_block_tree(&$blocks, $registry, &$changed_count)
    {
        $dgb = dgb();

        foreach ($blocks as &$block) {
            if (empty($block['blockName'])) {
                continue;
            }

            // Recurse into nested innerBlocks first
            if (!empty($block['innerBlocks'])) {
                $this->migrate_block_tree($block['innerBlocks'], $registry, $changed_count);
            }

            if (strpos($block['blockName'], 'dgb/') === 0) {
                $slug = str_replace('dgb/', '', $block['blockName']);
                $config = isset($registry[$slug]) ? $registry[$slug] : array();

                // Build field map for slug
                $all_fields = isset($config['fields']) && is_array($config['fields']) ? $config['fields'] : array();
                if (!empty($config['tabs']) && is_array($config['tabs'])) {
                    foreach ($config['tabs'] as $t) {
                        if (!empty($t['fields']) && is_array($t['fields'])) {
                            $all_fields = array_merge($all_fields, $t['fields']);
                        }
                    }
                }

                $name_to_attr = array();
                $field_types  = array();
                foreach ($all_fields as $f) {
                    if (!empty($f['name'])) {
                        $target_attr = !empty($f['attr_name']) ? $f['attr_name'] : $f['name'];
                        $name_to_attr[$f['name']] = $target_attr;
                        $field_types[$target_attr] = isset($f['type']) ? $f['type'] : 'text';
                    }
                    if (!empty($f['attr_name'])) {
                        $name_to_attr[$f['attr_name']] = $f['attr_name'];
                        $field_types[$f['attr_name']] = isset($f['type']) ? $f['type'] : 'text';
                    }
                }

                $block_modified = false;
                $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();

                // 1. Extract values from child dgb/field pseudo-blocks
                if (!empty($block['innerBlocks'])) {
                    $cleaned_inners = array();
                    foreach ($block['innerBlocks'] as $child) {
                        if (isset($child['blockName']) && $child['blockName'] === 'dgb/field') {
                            $child_attrs = isset($child['attrs']) ? $child['attrs'] : array();
                            $raw_key = !empty($child_attrs['attr_name']) ? $child_attrs['attr_name'] : (!empty($child_attrs['name']) ? $child_attrs['name'] : '');
                            $attr_key = isset($name_to_attr[$raw_key]) ? $name_to_attr[$raw_key] : $raw_key;

                            if (!empty($attr_key) && isset($child_attrs['value'])) {
                                $val   = $child_attrs['value'];
                                $ftype = isset($field_types[$attr_key]) ? $field_types[$attr_key] : '';

                                if ($ftype === 'image' || $ftype === 'media' || $ftype === 'file') {
                                    $val = $dgb->normalize_image_value($val);
                                } elseif (is_numeric($val) && (strpos($attr_key, 'video') !== false || strpos($attr_key, 'url') !== false)) {
                                    $val = $dgb->normalize_media_url($val);
                                }

                                if (!isset($attrs[$attr_key]) || $attrs[$attr_key] === '' || $attrs[$attr_key] === null) {
                                    $attrs[$attr_key] = $val;
                                    $block_modified = true;
                                }
                            }
                            if (!empty($config['has_innerblocks'])) {
                                $cleaned_inners[] = $child;
                            } else {
                                $block_modified = true;
                            }
                        } else {
                            $cleaned_inners[] = $child;
                        }
                    }
                    $block['innerBlocks'] = $cleaned_inners;
                }

                // 2. Normalize existing attributes on the block
                foreach ($attrs as $k => $v) {
                    $ftype = isset($field_types[$k]) ? $field_types[$k] : '';
                    if ($ftype === 'image' || $ftype === 'media' || $ftype === 'file' || strpos($k, 'image') !== false) {
                        if (!is_array($v) || empty($v['url'])) {
                            $normalized = $dgb->normalize_image_value($v);
                            if ($normalized !== null && $normalized !== $v) {
                                $attrs[$k] = $normalized;
                                $block_modified = true;
                            }
                        }
                    } elseif (is_numeric($v) && (strpos($k, 'video') !== false || strpos($k, 'url') !== false)) {
                        $normalized_url = $dgb->normalize_media_url($v);
                        if ($normalized_url !== '' && $normalized_url !== $v) {
                            $attrs[$k] = $normalized_url;
                            $block_modified = true;
                        }
                    }
                }

                if ($block_modified) {
                    $block['attrs'] = $attrs;
                    $changed_count++;
                }
            }
        }
        unset($block);

        return $blocks;
    }
}
