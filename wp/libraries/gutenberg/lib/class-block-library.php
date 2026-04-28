<?php
/**
 * Dynamic Gutenberg Block Library
 * 
 * A flexible library for creating Gutenberg blocks from JSON configurations
 * 
 * @package DynamicGutenbergBlocks
 * @version 1.0.0
 */

namespace aw2\gutenberg_blocks;

if (!defined('ABSPATH')) {
    exit;
}

class BlockLibrary {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Registered blocks
     */
    private $blocks = array();
    
    /**
     * Block configurations directory
     */
    private $blocks_dir = '';
    
    /**
     * Assets URL
     */
    private $assets_url = '';
    
    /**
     * Get singleton instance
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize the library
     * 
     * @param string $blocks_directory Path to directory containing block JSON files
     * @param string $assets_url URL to assets directory (optional)
     */
    public function init( $assets_url = '') {
       // $this->blocks_dir = trailingslashit($blocks_directory);
        $this->assets_url = $assets_url ? trailingslashit($assets_url) : plugins_url('assets/', __FILE__);
        
        // Register hooks
        add_action('init', array($this, 'register_blocks'),15);
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
    }
    
    /**
     * Load and register all blocks from JSON files
     */
    public function register_blocks() {
        //if (!is_dir($this->blocks_dir)) {
        //    return;
        //}
        
        // Find all JSON files in the blocks directory
        //$json_files = glob($this->blocks_dir . '*.json');

        $gutenberg_blocks=&\aw2_library::get_array_ref('gutenberg_blocks_v2');
    
        foreach ($gutenberg_blocks as $gtb) {
            $this->register_block($gtb);
        }
        
        // Register the field block (used internally)
        $this->register_field_block();
    }
    
    /**
     * Register a single block from JSON configuration
     * 
     * @param string $json_file Path to JSON file
     * @return bool Success status
     */
    private function register_block($config_data) {
      //  $json_content = file_get_contents($json_file);

        //\util::var_dump($config_data);
        //$config = json_decode($json_content, true);
       
        if (!isset($config_data['config'])) {
            error_log("DGB Error: config not found");
            return false;
        }

        $config = $config_data['config'];
        $config['template']=$config_data['render_service'];

        if (!empty($config_data['controls_service'])) {
          $sections =\aw2_library::service_run($config_data['controls_service'],null,null,'service');
          //$config['tabs'] = $sections['sections'] ?? '';

          //loop through the $sections['sections'], check if fields array exisits, then loop though it and convert the json to array and update it.  
            // Loop through the sections
            if (!empty($sections['sections'])) {
                foreach ($sections['sections'] as &$section) {
                    // Check if fields array exists
                    if (!empty($section['fields'])) {
                        // Loop through each field and convert JSON to array
                        foreach ($section['fields'] as &$field) {
                            $field = json_decode($field, true);
                        }
                        unset($field); // Break the reference
                    }
                }
                unset($section); // Break the reference
                
                $config['tabs'] = $sections['sections'];
            }


        }

        // Validate required fields
        if (!isset($config['name']) || !isset($config['title'])) {
            error_log("DGB Error: Block config missing 'name' or 'title' ");
            return false;
        }
        
        // Set defaults
        $config = $this->set_config_defaults($config);
        
        // Store configuration
        $this->blocks[$config['name']] = $config;
        
        // Register the block type
        $block_name = 'dgb/' . $config['name'];
        
        register_block_type($block_name, array(
            'api_version' => 3,
            'editor_script' => 'dgb-blocks-editor',
            'editor_style' => 'dgb-blocks-editor-style',
            'style' => isset($config['style']) ? $config['style'] : null,
            'render_callback' => array($this, 'render_block'),
            'attributes' => $this->build_attributes($config),
            'supports' => isset($config['supports']) ? $config['supports'] : array(
                'html' => false,
                'align' => true,
                'className' => true
            )
        ));
        
        return true;
    }
    
    /**
     * Set default configuration values
     */
    private function set_config_defaults($config) {
        $defaults = array(
            'icon' => 'admin-generic',
            'category' => 'widgets',
            'tabs' => array(),
            'fields' => array(),
            'template' => '',
            'template_file' => '',
            'enqueue_scripts' => array(),
            'enqueue_styles' => array()
        );
        
        return array_merge($defaults, $config);
    }
    
    /**
     * Build block attributes from configuration
     */
    private function build_attributes($config) {
        $attributes = array();
        
        // Get fields from both top-level and tabs
        $all_fields = $config['fields'];
        
        if (!empty($config['tabs'])) {
            foreach ($config['tabs'] as $tab) {
                if (isset($tab['fields']) && is_array($tab['fields'])) {
                    $all_fields = array_merge($all_fields, $tab['fields']);
                }
            }
        }
        
        // Build attributes for each field
        foreach ($all_fields as $field) {
            if (!isset($field['attr_name'])) {
                continue;
            }
            
            $attr_config = $this->get_attribute_config_for_field($field);
            $attributes[$field['attr_name']] = $attr_config;
        }
        
        return $attributes;
    }
    
    /**
     * Get attribute configuration for a field type
     */
    private function get_attribute_config_for_field($field) {
        $type = isset($field['type']) ? $field['type'] : 'text';
        $default = isset($field['default']) ? $field['default'] : null;
        
        $type_map = array(
            'text' => 'string',
            'textarea' => 'string',
            'number' => 'number',
            'small-number' => 'number',
            'toggle' => 'boolean',
            'select' => 'string',
            'radio' => 'string',
            'checkbox' => 'array',
            'single-checkbox' => 'boolean',
            'image' => 'object',
            'date' => 'string',
            'attributes-repeater' => 'array',
            'row_repeater' => 'array',
            'innerblocks' => 'string',
            'service' => 'string',
            'awesome_code' => 'string',
            'env_path' => 'string',
            'title' => 'string',
            'purpose' => 'string',
            'query' => 'string'
        );
        
        $attr_type = isset($type_map[$type]) ? $type_map[$type] : 'string';
        
        $config = array('type' => $attr_type);
        
        if ($default !== null) {
            $config['default'] = $default;
        } elseif ($attr_type === 'array') {
            $config['default'] = array();
        } elseif ($attr_type === 'boolean') {
            $config['default'] = false;
        } elseif ($attr_type === 'number') {
            $config['default'] = 0;
        } else {
            $config['default'] = '';
        }
        
        return $config;
    }
    
    /**
     * Register the internal field block
     */
    private function register_field_block() {
        register_block_type('dgb/field', array(
            'api_version' => 3,
            'editor_script' => 'dgb-blocks-editor',
            'attributes' => array(
                'name' => array('type' => 'string', 'default' => ''),
                'type' => array('type' => 'string', 'default' => 'text'),
                'label' => array('type' => 'string', 'default' => ''),
                'value' => array('type' => ['string', 'array', 'object', 'boolean', 'number'], 'default' => ''),
                'tab' => array('type' => 'string', 'default' => ''),
                'attr_name' => array('type' => 'string', 'default' => ''),
                'options' => array('type' => 'array', 'default' => array()),
                'validation' => array('type' => 'object', 'default' => array()),
                'repeater_fields' => array('type' => 'array', 'default' => array())
            ),
            'supports' => array(
                'inserter' => false,
                'html' => false,
                'reusable' => false
            )
        ));
    }
    
    /**
     * Enqueue editor assets
     */
    public function enqueue_editor_assets() {
        // Pass block configurations to JavaScript
        wp_localize_script(
            'dgb-blocks-editor',
            'dgbBlockConfigs',
            array('blocks' => $this->blocks)
        );
    }
    
    /**
     * Render block callback
     */
    public function render_block($attributes, $content, $block) {
        // Get block name without namespace
        $block_name = str_replace('dgb/', '', $block->name);
        
        if (!isset($this->blocks[$block_name])) {
            return '';
        }
        
        $config = $this->blocks[$block_name];
        
        // Collect field values from inner blocks
        $field_values = $this->extract_field_values($block);
        
        // Merge with attributes
        $data = array_merge($attributes, $field_values);
        
        // Enqueue block-specific assets
        $this->enqueue_block_assets($config);
        
        // Render using template
        return $this->render_template($config, $data, $content);
    }
    
    /**
     * Extract field values from inner blocks
     */
    private function extract_field_values($block) {
        $values = array();
        
        if (!isset($block->parsed_block['innerBlocks'])) {
            return $values;
        }
        
        foreach ($block->parsed_block['innerBlocks'] as $inner_block) {
            if ($inner_block['blockName'] === 'dgb/field') {
                $attrs = $inner_block['attrs'];
                
                if (!empty($attrs['attr_name']) && isset($attrs['value'])) {
                    // Handle nested attribute names (e.g., "tab1.title")
                    $this->set_nested_value($values, $attrs['attr_name'], $attrs['value']);
                    
                    // Handle innerblocks for field
                    if (!empty($inner_block['innerBlocks'])) {
                        $inner_content = '';
                        foreach ($inner_block['innerBlocks'] as $nested_block) {
                            $inner_content .= render_block($nested_block);
                        }
                        $this->set_nested_value($values, $attrs['attr_name'] . '_content', $inner_content);
                    }
                }
            }
        }
        
        return $values;
    }
    
    /**
     * Set nested value in array using dot notation
     */
    private function set_nested_value(&$array, $path, $value) {
        $keys = explode('.', $path);
        $current = &$array;
        
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $current[$key] = array();
            }
            $current = &$current[$key];
        }
        
        $current = $value;
    }
    
    /**
     * Get nested value from array using dot notation
     */
    private function get_nested_value($array, $path, $default = '') {
        $keys = explode('.', $path);
        $current = $array;
        
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                return $default;
            }
            $current = $current[$key];
        }
        
        return $current;
    }
    
    /**
     * Enqueue block-specific assets
     */
    private function enqueue_block_assets($config) {
        // Enqueue scripts
        if (!empty($config['enqueue_scripts'])) {
            foreach ($config['enqueue_scripts'] as $script) {
                $handle = isset($script['handle']) ? $script['handle'] : '';
                $src = isset($script['src']) ? $script['src'] : '';
                $deps = isset($script['deps']) ? $script['deps'] : array();
                $ver = isset($script['version']) ? $script['version'] : '1.0.0';
                
                if ($handle && $src) {
                    wp_enqueue_script($handle, $src, $deps, $ver, true);
                }
            }
        }
        
        // Enqueue styles
        if (!empty($config['enqueue_styles'])) {
            foreach ($config['enqueue_styles'] as $style) {
                $handle = isset($style['handle']) ? $style['handle'] : '';
                $src = isset($style['src']) ? $style['src'] : '';
                $deps = isset($style['deps']) ? $style['deps'] : array();
                $ver = isset($style['version']) ? $style['version'] : '1.0.0';
                
                if ($handle && $src) {
                    wp_enqueue_style($handle, $src, $deps, $ver);
                }
            }
        }
    }
    
    /**
     * Render template with data
     */
    private function render_template($config, $data, $content) {

        // Use template file if specified
        if (!empty($config['template_file']) && file_exists($config['template_file'])) {
            ob_start();
            include $config['template_file'];
            return ob_get_clean();
        }
        if (!empty($config['template'])) {
           return \aw2_library::service_run($config['template'],$data,null,'service');
        }
        
        // Use inline template
        //if (!empty($config['template'])) {
        //    return $this->process_template($config['template'], $data, $content);
       // }
        
        // Default output
        return $content;
    }
    
    /**
     * Process template string with placeholders
     * Supports: {{variable}}, {{nested.variable}}, {{#if variable}}...{{/if}}, {{#each array}}...{{/each}}
     */
    private function process_template($template, $data, $content = '') {
        // Add content to data
        $data['_content'] = $content;
        
        // Process conditionals {{#if variable}}...{{/if}}
        $template = preg_replace_callback(
            '/\{\{#if\s+([a-zA-Z0-9_.]+)\}\}(.*?)\{\{\/if\}\}/s',
            function($matches) use ($data) {
                $value = $this->get_nested_value($data, $matches[1]);
                return $value ? $matches[2] : '';
            },
            $template
        );
        
        // Process loops {{#each array}}...{{/each}}
        $template = preg_replace_callback(
            '/\{\{#each\s+([a-zA-Z0-9_.]+)\}\}(.*?)\{\{\/each\}\}/s',
            function($matches) use ($data) {
                $array = $this->get_nested_value($data, $matches[1], array());
                if (!is_array($array)) return '';
                
                $output = '';
                foreach ($array as $item) {
                    $output .= $this->process_template($matches[2], $item);
                }
                return $output;
            },
            $template
        );
        
        // Process variables {{variable}}
        $template = preg_replace_callback(
            '/\{\{([a-zA-Z0-9_.]+)\}\}/',
            function($matches) use ($data) {
                return esc_html($this->get_nested_value($data, $matches[1]));
            },
            $template
        );
        
        return $template;
    }
    
    /**
     * Get all registered blocks
     */
    public function get_blocks() {
        return $this->blocks;
    }
    
    /**
     * Get a specific block configuration
     */
    public function get_block($name) {
        return isset($this->blocks[$name]) ? $this->blocks[$name] : null;
    }
}

// Global function to access the library
function dgb() {
    return BlockLibrary::instance();
}