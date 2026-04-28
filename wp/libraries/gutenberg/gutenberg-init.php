<?php
namespace aw2\gutenberg_blocks;
\define('DGB_VERSION', '1.0.0');
\define('DGB_PLUGIN_DIR', \plugin_dir_path(__FILE__));
\define('DGB_PLUGIN_URL', \plugin_dir_url(__FILE__));
\define('DGB_ASSETS_URL', DGB_PLUGIN_URL . 'assets/');


\add_action( 'init', 'aw2\gutenberg_blocks\register_gt_post_type', 0 );
\add_action('init','aw2\gutenberg_blocks\setup_blocks',1);
\add_action('plugins_loaded', 'aw2\gutenberg_blocks\dgb_init');
\add_action('enqueue_block_editor_assets', 'aw2\gutenberg_blocks\enqueue_editor_assets');

/**
 * Registers the Custom Post Type for storing Elementor widget JSON.
 */
function register_gt_post_type() {

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
        "edit_post"=>"develop_for_awesomeui",
        "read_post"=>"develop_for_awesomeui",
        "delete_post"=>"develop_for_awesomeui",
        "edit_posts"=>"develop_for_awesomeui",
        "edit_others_posts"=>"develop_for_awesomeui",
        "publish_posts"=>"develop_for_awesomeui",
        "read_private_posts"=>"develop_for_awesomeui",
        "delete_posts"=>"develop_for_awesomeui"
        
    );

    $args = [
        'label'               => __( 'Awesome Gutenberg Blocks', 'text_domain' ),
        'description'         => __( 'Post type for storing Gutenberg block configurations.', 'text_domain' ),
        'labels'              => $labels,
        'supports'            => [ 'title', 'editor', 'revisions'],
        'hierarchical'        => false,
        'public'              => false,  // This is key: not publicly accessible
        'show_ui'             => true,   // But we want to see it in the admin dashboard
        'show_in_menu'        => true,
        'menu_position'       => 20,     // Position below "Pages"
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
        'show_in_rest'        => false, // Important for Gutenberg or REST API access if needed later
    ];

    register_post_type( 'aw_gb_blocks', $args );
  
    \aw2_library::add_service('gb_blocks','gb_blocks service refers to aw_gb_blocks posts for rendering etc.',['post_type'=>'aw_gb_blocks']);

}

/**
 * Initialize the block library
 */
function dgb_init() {
    require_once DGB_PLUGIN_DIR . 'lib/class-block-library.php';
    // Initialize the library with blocks directory
    \aw2\gutenberg_blocks\dgb()->init(
        DGB_ASSETS_URL
    );
}

/**
 * Enqueue editor assets
 */
function enqueue_editor_assets() {
    // Enqueue the main editor script
   \wp_enqueue_script(
        'dgb-blocks-editor',
        DGB_PLUGIN_URL . 'assets/js/blocks.js',
        array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data'),
        DGB_VERSION,
        false
    );
    
    // Enqueue editor styles
    \wp_enqueue_style(
        'dgb-blocks-editor-style',
        DGB_PLUGIN_URL . 'assets/css/editor.css',
        array('wp-edit-blocks'),
        DGB_VERSION
    );
}

/**
 * Collect all the blocks
 */
function setup_blocks(){
	//loop through the module and run the main module.
    $block_posts= \aw2_library::get_collection(["post_type"=>'aw_gb_blocks']);
	foreach($block_posts as $block_post){
        // run the post main service?
        \aw2_library::service_run('gb_blocks.'.$block_post['module'].'.register',null,null,'service');
    }
   
}