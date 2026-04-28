<?php

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('DGB_VERSION', '1.0.0');
define('DGB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DGB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DGB_ASSETS_URL', DGB_PLUGIN_URL . 'assets/');
define('DGB_BLOCKS_DIR', DGB_PLUGIN_DIR . 'blocks/');


// Load the library
require_once DGB_PLUGIN_DIR . 'lib/class-block-library.php';

/**
 * Initialize the block library
 */
function dgb_init() {
    // Initialize the library with blocks directory
    \DynamicGutenbergBlocks\dgb()->init(
        DGB_BLOCKS_DIR,
        DGB_ASSETS_URL
    );
}
add_action('plugins_loaded', 'dgb_init');

/**
 * Enqueue editor assets
 */
function dgb_enqueue_editor_assets() {
    // Enqueue the main editor script
    wp_enqueue_script(
        'dgb-blocks-editor',
        DGB_PLUGIN_URL . 'assets/js/blocks.js',
        array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data'),
        DGB_VERSION,
        false
    );
    
    // Enqueue editor styles
    wp_enqueue_style(
        'dgb-blocks-editor-style',
        DGB_PLUGIN_URL . 'assets/css/editor.css',
        array('wp-edit-blocks'),
        DGB_VERSION
    );
}
add_action('enqueue_block_editor_assets', 'dgb_enqueue_editor_assets');

/**
 * Enqueue frontend assets
 *
 * function dgb_enqueue_frontend_assets() {
 *   // Enqueue frontend styles
 *   wp_enqueue_style(
 *       'dgb-blocks-style',
 *       DGB_PLUGIN_URL . 'assets/css/style.css',
 *       array(),
 *       DGB_VERSION
 *   );
 * }
 *  add_action('wp_enqueue_scripts', 'dgb_enqueue_frontend_assets');
*/
/**
 * Register block categories
 */
function dgb_block_categories($categories) {
    return array_merge(
        $categories,
        array(
            array(
                'slug' => 'awesome-blocks',
                'title' => __('Awesome Blocks', 'dgb'),
                'icon' => 'admin-generic'
            )
        )
    );
}
add_filter('block_categories_all', 'dgb_block_categories', 10, 1);

/**
 * Helper function to create a new block JSON file
 * 
 * @param string $name Block name (slug)
 * @param array $config Block configuration
 * @return bool Success status

function dgb_create_block($name, $config = array()) {
    $config['name'] = $name;
    
    if (!isset($config['title'])) {
        $config['title'] = ucwords(str_replace('-', ' ', $name));
    }
    
    $json_file = DGB_BLOCKS_DIR . $name . '.json';
    
    $json_content = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    return file_put_contents($json_file, $json_content) !== false;
}
 */

/**
 * Helper function to get block configuration
 * 
 * @param string $name Block name
 * @return array|null Block configuration or null if not found
 */
function dgb_get_block($name) {
    return \DynamicGutenbergBlocks\dgb()->get_block($name);
}

/**
 * Helper function to get all block configurations
 * 
 * @return array All block configurations
 */
function dgb_get_all_blocks() {
    return \DynamicGutenbergBlocks\dgb()->get_blocks();
}