<?php
namespace aw2\gutenberg_blocks;

if (!defined('DGB_VERSION')) {
	\define('DGB_VERSION', '1.1.2');
}
if (!defined('DGB_PLUGIN_DIR')) {
	\define('DGB_PLUGIN_DIR', \plugin_dir_path(__FILE__));
}
if (!defined('DGB_PLUGIN_URL')) {
	\define('DGB_PLUGIN_URL', \plugin_dir_url(__FILE__));
}
if (!defined('DGB_ASSETS_URL')) {
	\define('DGB_ASSETS_URL', DGB_PLUGIN_URL . 'assets/');
}

// 1. Post Type & Service Registration
\add_action('init', 'aw2\gutenberg_blocks\register_gt_post_type', 0);

// 2. Library Initialization
\add_action('plugins_loaded', 'aw2\gutenberg_blocks\dgb_init');

// 3. Editor Asset Enqueueing
\add_action('enqueue_block_editor_assets', 'aw2\gutenberg_blocks\enqueue_editor_assets');

// 4. Cache Invalidation Hooks on aw_gb_blocks Mutations
\add_action('save_post_aw_gb_blocks', 'aw2\gutenberg_blocks\invalidate_block_registry', 10, 2);
\add_action('wp_after_insert_post', 'aw2\gutenberg_blocks\on_block_post_saved', 10, 4);
\add_action('trashed_post', 'aw2\gutenberg_blocks\on_block_post_deleted', 10, 1);
\add_action('deleted_post', 'aw2\gutenberg_blocks\on_block_post_deleted', 10, 1);
\add_action('untrashed_post', 'aw2\gutenberg_blocks\invalidate_block_registry', 10, 1);

// 5. Automatic Core Blocks Seeder & Migration on First Enable / Update
if (!defined('AWX_CORE_BLOCKS_VERSION')) {
    \define('AWX_CORE_BLOCKS_VERSION', '1.0.0');
}
\add_action('init', 'aw2\gutenberg_blocks\maybe_auto_seed_core_blocks', 20);

/**
 * Automatically seeds the Core 12 blocks when the plugin is first enabled
 * or when an update bumps AWX_CORE_BLOCKS_VERSION.
 */
function maybe_auto_seed_core_blocks() {
    if (!function_exists('get_option') || !class_exists('aw2\gutenberg_blocks\AWX_Core_Blocks_Seeder')) {
        return;
    }
    $installed_ver = get_option('awx_core_blocks_version', '0.0.0');
    if (version_compare($installed_ver, AWX_CORE_BLOCKS_VERSION, '<')) {
        $force = ($installed_ver !== '0.0.0');
        AWX_Core_Blocks_Seeder::seed($force);
        update_option('awx_core_blocks_version', AWX_CORE_BLOCKS_VERSION);
    }
}

/**
 * Expose database prefix into AWX environment for portable SQL queries ([env.get db.prefix /])
 */
function set_db_prefix() {
    global $wpdb, $table_prefix;
    if (class_exists('\aw2_library') && method_exists('\aw2_library', 'set')) {
        $prefix = isset($wpdb->prefix) ? $wpdb->prefix : (isset($table_prefix) ? $table_prefix : 'wp_');
        \aw2_library::set('db.prefix', $prefix);
    }
}

/**
 * Registers the Custom Post Type for storing Gutenberg block configurations.
 */
function register_gt_post_type() {
    set_db_prefix();

    $labels = [
        'name'                  => _x( 'Awesome Gutenberg Blocks', 'Post Type General Name', 'text_domain' ),
        'singular_name'         => _x( 'Awesome Gutenberg Block', 'Post Type Singular Name', 'text_domain' ),
        'menu_name'             => __( 'Awesome Gutenberg Blocks', 'text_domain' ),
        'name_admin_bar'        => __( 'Awesome Gutenberg Block', 'text_domain' ),
        'archives'              => __( 'Awesome Gutenberg Block Archives', 'text_domain' ),
        'attributes'            => __( 'Awesome Gutenberg Block Attributes', 'text_domain' ),
        'parent_item_colon'     => __( 'Parent Awesome Gutenberg Block:', 'text_domain' ),
        'all_items'             => __( 'All Awesome Gutenberg Block', 'text_domain' ),
        'add_new_item'          => __( 'Add New Awesome Gutenberg Block', 'text_domain' ),
        'add_new'               => __( 'Add New', 'text_domain' ),
        'new_item'              => __( 'New Awesome Gutenberg Block', 'text_domain' ),
        'edit_item'             => __( 'Edit Awesome Gutenberg Block', 'text_domain' ),
        'update_item'           => __( 'Update Awesome Gutenberg Block', 'text_domain' ),
        'view_item'             => __( 'View Awesome Gutenberg Block', 'text_domain' ),
        'view_items'            => __( 'View Awesome Gutenberg Blocks', 'text_domain' ),
        'search_items'          => __( 'Search Awesome Gutenberg Block', 'text_domain' ),
        'not_found'             => __( 'Not found', 'text_domain' ),
        'not_found_in_trash'    => __( 'Not found in Trash', 'text_domain' ),
        'featured_image'        => __( 'Featured Image', 'text_domain' ),
        'set_featured_image'    => __( 'Set featured image', 'text_domain' ),
        'remove_featured_image' => __( 'Remove featured image', 'text_domain' ),
        'use_featured_image'    => __( 'Use as featured image', 'text_domain' ),
        'insert_into_item'      => __( 'Insert into element', 'text_domain' ),
        'uploaded_to_this_item' => __( 'Uploaded to this element', 'text_domain' ),
        'items_list'            => __( 'Awesome Gutenberg Blocks list', 'text_domain' ),
        'items_list_navigation' => __( 'Awesome Gutenberg Blocks list navigation', 'text_domain' ),
        'filter_items_list'     => __( 'Filter Awesome Gutenberg Blocks list', 'text_domain' ),
    ];

    $capabilities = array(
        "edit_post"          => "develop_for_awesomeui",
        "read_post"          => "develop_for_awesomeui",
        "delete_post"        => "develop_for_awesomeui",
        "edit_posts"         => "develop_for_awesomeui",
        "edit_others_posts"  => "develop_for_awesomeui",
        "publish_posts"      => "develop_for_awesomeui",
        "read_private_posts" => "develop_for_awesomeui",
        "delete_posts"       => "develop_for_awesomeui"
    );

    $args = [
        'label'               => __( 'Awesome Gutenberg Blocks', 'text_domain' ),
        'description'         => __( 'Post type for storing Gutenberg block configurations.', 'text_domain' ),
        'labels'              => $labels,
        'supports'            => [ 'title', 'editor', 'revisions'],
        'hierarchical'        => false,
        'public'              => false,  // Not publicly accessible as URL endpoints
        'show_ui'             => true,   // Accessible in admin dashboard
        'show_in_menu'        => true,
        'menu_position'       => 20,
        'menu_icon'           => 'dashicons-slides',
        'show_in_admin_bar'   => true,
        'show_in_nav_menus'   => false,
        'can_export'          => true,
        'has_archive'         => false,
        'exclude_from_search' => true,
        'publicly_queryable'  => false,
        'capability_type'     => 'post',
        'capabilities'        => $capabilities,
        'rewrite'             => false,
        'delete_with_user'    => false,
        'show_in_rest'        => false,
    ];

    register_post_type( 'aw_gb_blocks', $args );
  
    \aw2_library::add_service('gb_blocks','gb_blocks service refers to aw_gb_blocks posts for rendering etc.',['post_type'=>'aw_gb_blocks']);
}

/**
 * Initialize the block library
 */
function dgb_init() {
    set_db_prefix();
    require_once __DIR__ . '/lib/class-block-library.php';
    require_once __DIR__ . '/lib/class-pattern-bridge.php';
    require_once __DIR__ . '/lib/class-wal-engine.php';
    require_once __DIR__ . '/lib/class-core-blocks-seeder.php';
    dgb()->init(DGB_ASSETS_URL);
}

/**
 * Enqueue editor assets
 */
function enqueue_editor_assets() {
    \wp_enqueue_script(
        'dgb-blocks-editor',
        DGB_PLUGIN_URL . 'assets/js/blocks.js',
        array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data', 'wp-server-side-render'),
        DGB_VERSION,
        false
    );
    
    \wp_enqueue_style(
        'dgb-blocks-editor-style',
        DGB_PLUGIN_URL . 'assets/css/editor.css',
        array('wp-edit-blocks', 'dashicons'),
        DGB_VERSION
    );
}

/**
 * Invalidate the block registry cache on post save/update
 * 
 * @param int $post_id Post ID
 * @param \WP_Post|null $post Post object
 */
function invalidate_block_registry($post_id = 0, $post = null) {
    static $handled = array();

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if ($post_id && function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
        return;
    }
    if ($post_id && isset($handled[$post_id])) {
        return;
    }
    if ($post_id) {
        $handled[$post_id] = true;
    }

    if (function_exists('aw2\gutenberg_blocks\dgb')) {
        dgb()->invalidate_registry();

        // Synchronously recompile assets for the saved block if slug is resolved
        if ($post_id && function_exists('get_post')) {
            $p = get_post($post_id);
            if ($p && !empty($p->post_name)) {
                dgb()->recompile_single_block_assets($p->post_name);
            }
        }
    }
}

/**
 * Handle post insert/update including REST API submissions
 *
 * @param int $post_id Post ID
 * @param \WP_Post|null $post Post object
 * @param bool $update Whether this is an existing post being updated
 * @param \WP_Post|null $post_before Post object before update
 */
function on_block_post_saved($post_id, $post, $update, $post_before = null) {
    if (!$post || $post->post_type !== 'aw_gb_blocks') {
        return;
    }
    invalidate_block_registry($post_id, $post);
}

/**
 * Invalidate the block registry cache on post deletion or trash and purge assets
 * 
 * @param int $post_id Post ID
 */
function on_block_post_deleted($post_id) {
    if (function_exists('get_post_type') && get_post_type($post_id) === 'aw_gb_blocks') {
        $post = function_exists('get_post') ? get_post($post_id) : null;
        $slug = $post ? $post->post_name : '';

        if (!empty($slug) && function_exists('aw2\gutenberg_blocks\dgb')) {
            dgb()->delete_block_assets($slug);
        }

        invalidate_block_registry();
    }
}

/**
 * Backward compatibility helper for manually executing register services.
 * Note: Dynamic block registration is now handled on-demand by build_registry().
 */
function setup_blocks() {
    if (function_exists('aw2\gutenberg_blocks\dgb')) {
        dgb()->invalidate_registry();
        dgb()->build_registry();
    }
}

/**
 * Load the cached block registry
 * 
 * @param bool $for_editor Whether to return full editor metadata or slim frontend metadata
 * @return array
 */
function awx_load_block_registry($for_editor = false) {
    return dgb()->load_registry($for_editor);
}

/**
 * Invalidate the cached block registry
 */
function awx_invalidate_block_registry() {
    dgb()->invalidate_registry();
}

// 5. WP-CLI Command Registration
if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/lib/class-blocks-cli.php';
    require_once __DIR__ . '/lib/class-patterns-cli.php';
    \WP_CLI::add_command('awx blocks', 'aw2\gutenberg_blocks\Blocks_CLI_Command');
    \WP_CLI::add_command('aw2 blocks', 'aw2\gutenberg_blocks\Blocks_CLI_Command');
    \WP_CLI::add_command('awx patterns', 'aw2\gutenberg_blocks\Patterns_CLI_Command');
    \WP_CLI::add_command('aw2 patterns', 'aw2\gutenberg_blocks\Patterns_CLI_Command');
}

// 6. Pattern Bridge, WAL Engine, and Core Blocks Seeder Subsystems
require_once __DIR__ . '/lib/class-pattern-bridge.php';
require_once __DIR__ . '/lib/class-wal-engine.php';
require_once __DIR__ . '/lib/class-core-blocks-seeder.php';

