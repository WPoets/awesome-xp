<?php
/**
 * Dynamic Gutenberg Block Library
 * 
 * High-performance, partitioned registry architecture for registering
 * and rendering Gutenberg blocks within the Awesome Enterprise ecosystem.
 * 
 * @package DynamicGutenbergBlocks
 * @version 1.1.0
 */

namespace aw2\gutenberg_blocks;

if (!defined('ABSPATH')) {
    exit;
}

class BlockLibrary
{
    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Registered blocks for current execution context
     */
    private $blocks = array();

    /**
     * Assets URL
     */
    private $assets_url = '';

    /**
     * Get singleton instance
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the library
     * 
     * @param string $assets_url URL to assets directory (optional)
     */
    public function init($assets_url = '')
    {
        $this->assets_url = $assets_url ? trailingslashit($assets_url) : plugins_url('assets/', __FILE__);

        // Register block types on init priority 15
        add_action('init', array($this, 'register_blocks'), 15);
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
    }

    /**
     * Determine whether the current request is an editor/administrative context
     * 
     * @return bool
     */
    public static function is_editor_context()
    {
        if (is_admin()) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            if (strpos($uri, '/wp/v2/block-types') !== false || strpos($uri, '/wp/v2/block-renderer') !== false) {
                return true;
            }
            if (isset($_GET['context']) && $_GET['context'] === 'edit') {
                return true;
            }
        }

        return false;
    }

    /**
     * Stampede-protected block registry loader with payload segregation
     * 
     * @param bool $for_editor Whether to return full editor metadata or slim frontend metadata
     * @return array
     */
    public function load_registry($for_editor = false)
    {
        $stamp_option = 'awx_gb_registry_stamp';
        $cache_option = 'awx_gb_registry';

        $version       = defined('DGB_VERSION') ? DGB_VERSION : '1.1.0';
        $current_stamp = get_option($stamp_option, '') . '|' . $version;
        $cached        = get_option($cache_option);

        // Cache Hit: Serve partitioned slice immediately
        if (is_array($cached) && isset($cached['stamp']) && $cached['stamp'] === $current_stamp) {
            $slice = $for_editor
                ? (isset($cached['editor_blocks']) ? $cached['editor_blocks'] : array())
                : (isset($cached['frontend_blocks']) ? $cached['frontend_blocks'] : array());
            unset($cached);
            return $slice;
        }

        // Guard Against Cache Stampedes under high traffic (60 second lock)
        $lock_key = 'awx_gb_registry_lock';
        if (!set_transient($lock_key, true, 60)) {
            // Return stale slice if available while another thread rebuilds
            if (is_array($cached) && !empty($cached['frontend_blocks'])) {
                $slice = $for_editor
                    ? (isset($cached['editor_blocks']) ? $cached['editor_blocks'] : array())
                    : $cached['frontend_blocks'];
                unset($cached);
                return $slice;
            }
        }

        try {
            $built = $this->build_registry();
            update_option($cache_option, array(
                'stamp'           => $current_stamp,
                'frontend_blocks' => $built['frontend_blocks'],
                'editor_blocks'   => $built['editor_blocks'],
            ), false); // MUST NOT be autoloaded

            $slice = $for_editor ? $built['editor_blocks'] : $built['frontend_blocks'];
            unset($built);
            return $slice;
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Invalidate cached block registry
     */
    public function invalidate_registry()
    {
        delete_option('awx_gb_registry');
        update_option('awx_gb_registry_stamp', (string) microtime(true), false);
        delete_transient('awx_gb_registry_lock');
        $this->blocks = array();
    }

    /**
     * Build the partitioned block registry (Compile / Cache Miss Only)
     * 
     * @return array ['frontend_blocks' => array, 'editor_blocks' => array]
     */
    public function build_registry()
    {
        $frontend_blocks = array();
        $editor_blocks   = array();

        // Reset runtime collection array
        $gutenberg_blocks =& \aw2_library::get_array_ref('gutenberg_blocks_v2');
        $gutenberg_blocks = array();

        // 1. Database Query: Query all published aw_gb_blocks records
        $block_posts = array();
        if (class_exists('\aw2_library') && method_exists('\aw2_library', 'get_collection')) {
            $block_posts = \aw2_library::get_collection(array('post_type' => 'aw_gb_blocks'));
        }

        // Fallback to get_posts if collection returned empty
        if (empty($block_posts) && function_exists('get_posts')) {
            $posts = get_posts(array(
                'post_type'      => 'aw_gb_blocks',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            ));
            if (!empty($posts)) {
                $block_posts = array();
                foreach ($posts as $p) {
                    $block_posts[$p->post_name] = array(
                        'module'       => $p->post_name,
                        'post_title'   => $p->post_title,
                        'post_content' => $p->post_content,
                    );
                }
            }
        }

        // 2. Base Config Resolution: Execute gb_blocks.{name}.register
        if (!empty($block_posts) && is_array($block_posts)) {
            foreach ($block_posts as $block_post) {
                $module = isset($block_post['module']) ? $block_post['module'] : '';
                if (!empty($module)) {
                    \aw2_library::service_run('gb_blocks.' . $module . '.register', null, null, 'service');
                }
            }
        }

        // 3. Process all registered configurations
        if (!empty($gutenberg_blocks) && is_array($gutenberg_blocks)) {
            foreach ($gutenberg_blocks as $config_data) {
                if (!isset($config_data['config'])) {
                    continue;
                }

                $config = $config_data['config'];
                $slug   = isset($config['name']) ? $config['name'] : '';

                if (empty($slug)) {
                    continue;
                }

                $config['template'] = isset($config_data['render_service'])
                    ? $config_data['render_service']
                    : (isset($config['template']) ? $config['template'] : '');

                $tabs = array();

                // Controls parsing
                if (!empty($config_data['controls_service'])) {
                    $sections = \aw2_library::service_run($config_data['controls_service'], null, null, 'service');

                    if (!empty($sections['sections']) && is_array($sections['sections'])) {
                        foreach ($sections['sections'] as $section) {
                            if (!empty($section['fields']) && is_array($section['fields'])) {
                                foreach ($section['fields'] as &$field) {
                                    if (is_string($field)) {
                                        $decoded = json_decode($field, true);
                                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                            $field = $decoded;
                                        }
                                    }
                                }
                                unset($field);
                            }
                            $tabs[] = $section;
                        }
                    }
                }

                $config['tabs'] = $tabs;
                $config = $this->set_config_defaults($config);

                // Synthesize Gutenberg attributes schema
                $attributes = $this->build_attributes($config, $tabs);

                $supports = isset($config['supports']) ? $config['supports'] : array(
                    'html'      => false,
                    'align'     => true,
                    'className' => true,
                );

                // Check if any field is declared as innerblocks
                $has_innerblocks  = false;
                $innerblocks_attr = '';
                $all_fields = isset($config['fields']) && is_array($config['fields']) ? $config['fields'] : array();
                if (!empty($tabs)) {
                    foreach ($tabs as $tab) {
                        if (isset($tab['fields']) && is_array($tab['fields'])) {
                            $all_fields = array_merge($all_fields, $tab['fields']);
                        }
                    }
                }
                foreach ($all_fields as $f) {
                    if (isset($f['type']) && $f['type'] === 'innerblocks') {
                        $has_innerblocks  = true;
                        $innerblocks_attr = !empty($f['attr_name']) ? $f['attr_name'] : 'content.innerblocks';
                        break;
                    }
                }

                // 4. Partition Payloads
                // Lightweight frontend definition (no tabs, no field schemas)
                $frontend_blocks[$slug] = array(
                    'name'             => $slug,
                    'render_service'   => $config['template'],
                    'template'         => $config['template'],
                    'template_file'    => isset($config['template_file']) ? $config['template_file'] : '',
                    'attributes'       => $attributes,
                    'supports'         => $supports,
                    'has_innerblocks'  => $has_innerblocks,
                    'innerblocks_attr' => $innerblocks_attr,
                    'style'            => isset($config['style']) ? $config['style'] : null,
                    'view_script'      => isset($config['view_script']) ? $config['view_script'] : null,
                    'enqueue_scripts'  => isset($config['enqueue_scripts']) ? $config['enqueue_scripts'] : array(),
                    'enqueue_styles'   => isset($config['enqueue_styles']) ? $config['enqueue_styles'] : array(),
                );

                // Rich editor definition (full controls, tabs, and inspector schemas)
                $editor_blocks[$slug] = array_merge($frontend_blocks[$slug], array(
                    'title'          => isset($config['title']) ? $config['title'] : ucwords(str_replace('-', ' ', $slug)),
                    'description'    => isset($config['description']) ? $config['description'] : '',
                    'icon'           => isset($config['icon']) ? $config['icon'] : 'admin-generic',
                    'category'       => isset($config['category']) ? $config['category'] : 'widgets',
                    'keywords'       => isset($config['keywords']) ? $config['keywords'] : array(),
                    'tabs'           => $tabs,
                    'fields'         => isset($config['fields']) ? $config['fields'] : array(),
                    'editor_script'  => 'dgb-blocks-editor',
                    'editor_style'   => 'dgb-blocks-editor-style',
                ));
            }
        }

        // Free runtime registration memory
        $gutenberg_blocks = array();

        return array(
            'frontend_blocks' => $frontend_blocks,
            'editor_blocks'   => $editor_blocks,
        );
    }

    /**
     * Load and register blocks from the partitioned registry
     */
    public function register_blocks()
    {
        $is_editor = self::is_editor_context();
        $blocks    = $this->load_registry($is_editor);

        $this->register_from_registry($blocks);
    }

    /**
     * Register block types into the WordPress block registry from compiled array
     * 
     * @param array $blocks Array of pre-compiled block configurations
     */
    public function register_from_registry($blocks)
    {
        if (empty($blocks) || !is_array($blocks)) {
            $this->register_field_block();
            return;
        }

        foreach ($blocks as $slug => $config) {
            $this->blocks[$slug] = $config;
            $block_name = 'dgb/' . $slug;

            $args = array(
                'api_version'     => 3,
                'render_callback' => array($this, 'render_block'),
                'attributes'      => isset($config['attributes']) ? $config['attributes'] : array(),
                'supports'        => isset($config['supports']) ? $config['supports'] : array(
                    'html'      => false,
                    'align'     => true,
                    'className' => true,
                ),
            );

            if (!empty($config['editor_script'])) {
                $args['editor_script'] = $config['editor_script'];
            }
            if (!empty($config['editor_style'])) {
                $args['editor_style'] = $config['editor_style'];
            }
            if (!empty($config['style'])) {
                $args['style'] = $config['style'];
            }
            if (!empty($config['view_script'])) {
                $args['view_script'] = $config['view_script'];
            }

            register_block_type($block_name, $args);
        }

        // Register internal field block for editor support
        $this->register_field_block();
    }

    /**
     * Set default configuration values
     */
    private function set_config_defaults($config)
    {
        $defaults = array(
            'icon'            => 'admin-generic',
            'category'        => 'widgets',
            'tabs'            => array(),
            'fields'          => array(),
            'template'        => '',
            'template_file'   => '',
            'enqueue_scripts' => array(),
            'enqueue_styles'  => array()
        );

        return array_merge($defaults, $config);
    }

    /**
     * Build and synthesize block attributes from configuration and control tabs
     * 
     * @param array $config Top-level block configuration
     * @param array $tabs Decoded control tabs
     * @return array Gutenberg attributes dictionary
     */
    private function build_attributes($config, $tabs = array())
    {
        $attributes = array();

        // 1. Gather all fields across top-level and tabs
        $all_fields = isset($config['fields']) && is_array($config['fields']) ? $config['fields'] : array();

        if (!empty($tabs)) {
            foreach ($tabs as $tab) {
                if (isset($tab['fields']) && is_array($tab['fields'])) {
                    $all_fields = array_merge($all_fields, $tab['fields']);
                }
            }
        } elseif (!empty($config['tabs'])) {
            foreach ($config['tabs'] as $tab) {
                if (isset($tab['fields']) && is_array($tab['fields'])) {
                    $all_fields = array_merge($all_fields, $tab['fields']);
                }
            }
        }

        // 2. Synthesize attributes per field
        foreach ($all_fields as $field) {
            if (!isset($field['attr_name']) || empty($field['attr_name'])) {
                continue;
            }

            $type = isset($field['type']) ? $field['type'] : 'text';

            // innerblocks is delivered as $content parameter, not a persisted attribute
            if ($type === 'innerblocks') {
                continue;
            }

            $attr_config = $this->get_attribute_config_for_field($field);
            $attr_name   = $field['attr_name'];

            // Register specific attribute name directly
            $attributes[$attr_name] = $attr_config;
        }

        // Backward compatibility: keep flattened snapshot for legacy editor preview
        $attributes['_data'] = array(
            'type'    => 'object',
            'default' => array(),
        );

        return $attributes;
    }

    /**
     * Get attribute configuration for a given field type
     */
    private function get_attribute_config_for_field($field)
    {
        $type    = isset($field['type']) ? $field['type'] : 'text';
        $default = isset($field['default']) ? $field['default'] : null;

        $type_map = array(
            'text'                 => 'string',
            'textarea'             => 'string',
            'title'                => 'string',
            'select'               => 'string',
            'radio'                => 'string',
            'color'                => 'string',
            'date'                 => 'string',
            'service'              => 'string',
            'awesome_code'         => 'string',
            'env_path'             => 'string',
            'purpose'              => 'string',
            'query'                => 'string',
            'url'                  => 'string',
            'email'                => 'string',
            'dropdown'             => 'string',
            'hidden'               => 'string',
            'number'               => 'number',
            'small-number'         => 'number',
            'range'                => 'number',
            'toggle'               => 'boolean',
            'single-checkbox'      => 'boolean',
            'image'                => 'object',
            'media'                => 'object',
            'file'                 => 'object',
            'post-select'          => 'object',
            'filtered-post-select' => 'object',
            'checkbox'             => 'array',
            'multiselect'          => 'array',
            'taxonomy-select'      => 'array',
            'attributes-repeater'  => 'array',
            'row_repeater'         => 'array',
            'repeater'             => 'array',
            'custom-repeater'      => 'array',
        );

        $attr_type = isset($type_map[$type]) ? $type_map[$type] : 'string';
        $config    = array('type' => $attr_type);

        if ($default !== null) {
            $config['default'] = $default;
        } elseif ($attr_type === 'array') {
            $config['default'] = array();
        } elseif ($attr_type === 'object') {
            $config['default'] = null;
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
    private function register_field_block()
    {
        register_block_type('dgb/field', array(
            'api_version'   => 3,
            'editor_script' => 'dgb-blocks-editor',
            'attributes'    => array(
                'name'            => array('type' => 'string', 'default' => ''),
                'type'            => array('type' => 'string', 'default' => 'text'),
                'label'           => array('type' => 'string', 'default' => ''),
                'value'           => array('type' => array('string', 'array', 'object', 'boolean', 'number'), 'default' => ''),
                'tab'             => array('type' => 'string', 'default' => ''),
                'attr_name'       => array('type' => 'string', 'default' => ''),
                'options'         => array('type' => 'array', 'default' => array()),
                'validation'      => array('type' => 'object', 'default' => array()),
                'repeater_fields' => array('type' => 'array', 'default' => array())
            ),
            'supports'      => array(
                'inserter' => false,
                'html'     => false,
                'reusable' => false
            )
        ));
    }

    /**
     * Enqueue editor assets and localize full block configurations for JS
     */
    public function enqueue_editor_assets()
    {
        // Pass complete editor block configurations to JavaScript
        $editor_blocks = $this->load_registry(true);

        wp_localize_script(
            'dgb-blocks-editor',
            'dgbBlockConfigs',
            array('blocks' => $editor_blocks)
        );
    }

    /**
     * Render block callback
     */
    public function render_block($attributes, $content, $block)
    {
        // Recursion guard to prevent infinite block rendering loops
        static $rendering_blocks = array();
        $block_id = is_object($block) ? spl_object_hash($block) : (isset($block['name']) ? $block['name'] : 'unknown');
        if (isset($rendering_blocks[$block_id])) {
            return '';
        }
        $rendering_blocks[$block_id] = true;

        try {
            // Get block name without namespace
            $block_name = is_object($block) ? str_replace('dgb/', '', $block->name) : '';

            if (!isset($this->blocks[$block_name])) {
                $this->blocks = $this->load_registry(self::is_editor_context());
            }

            if (!isset($this->blocks[$block_name])) {
                return '';
            }

            $config = $this->blocks[$block_name];

            // 1. Resolve field values across legacy and native sources
            $field_values = array();

            // A. Check legacy _data snapshot
            if (!empty($attributes['_data']) && is_array($attributes['_data'])) {
                $field_values = $attributes['_data'];
            }

            // B. Extract from legacy child pseudo-blocks (dgb/field)
            $extracted_inner = $this->extract_field_values($block);
            if (!empty($extracted_inner)) {
                $field_values = array_merge($field_values, $extracted_inner);
            }

            // C. Merge field values with block attributes
            $data = is_array($attributes) ? $attributes : array();
            unset($data['_data']);

            foreach ($field_values as $key => $val) {
                $data[$key] = $val;
            }

            // 2. Bidirectional Attribute Bridge (Flattening & Nesting)
            // Ensure both dot-notated ($data['content.title']) and nested ($data['content']['title']) exist
            // so templates can use [template.get content.title /] or [template.get style.layout /] reliably.
            foreach ($data as $key => $value) {
                if (strpos($key, '.') !== false) {
                    $this->set_nested_value($data, $key, $value);
                } elseif (is_array($value) && in_array($key, array('content', 'style'), true)) {
                    // Flatten nested object keys to dot-notation (e.g. $data['content']['author'] -> $data['content.author'])
                    foreach ($value as $sub_k => $sub_v) {
                        $flat_k = $key . '.' . $sub_k;
                        if (!isset($data[$flat_k]) || $data[$flat_k] === '') {
                            $data[$flat_k] = $sub_v;
                        }
                    }
                }
            }

            // 3. InnerBlocks Ingestion Contract
            // Ingest native InnerBlocks only when declared by the block and non-empty content exists
            if (!empty($config['has_innerblocks']) && !empty($content) && is_string($content)) {
                $clean_content = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($clean_content !== '' || strpos($content, '<') !== false) {
                    $target_attr = !empty($config['innerblocks_attr']) ? $config['innerblocks_attr'] : 'content.innerblocks';
                    $this->set_nested_value($data, $target_attr, $content);
                    $data[$target_attr] = $content;
                }
            }

            // 4. Wrapper Attributes Injection Contract
            if (function_exists('get_block_wrapper_attributes')) {
                if (!isset($data['wp']) || !is_array($data['wp'])) {
                    $data['wp'] = array();
                }
                if (empty($data['wp']['wrapper_attributes'])) {
                    $wrapper_attrs = get_block_wrapper_attributes();
                    $data['wp']['wrapper_attributes'] = $wrapper_attrs;
                    $data['wp.wrapper_attributes']     = $wrapper_attrs;
                }
            }

            // 5. Walk legacy inner blocks for dgb/field innerblocks field content (backward compatibility)
            if (!empty($block->parsed_block['innerBlocks'])) {
                foreach ($block->parsed_block['innerBlocks'] as $inner_block) {
                    if ($inner_block['blockName'] !== 'dgb/field') {
                        continue;
                    }

                    $attrs = $inner_block['attrs'];
                    if (empty($attrs['type']) || $attrs['type'] !== 'innerblocks') {
                        continue;
                    }
                    if (empty($attrs['attr_name'])) {
                        continue;
                    }

                    // Render nested blocks inside this field block
                    $html = '';
                    if (!empty($inner_block['innerBlocks'])) {
                        foreach ($inner_block['innerBlocks'] as $nested) {
                            $html .= render_block($nested);
                        }
                    }

                    // Store rendered HTML at both dot-notation and nested paths
                    $this->set_nested_value($data, $attrs['attr_name'], $html);
                    $this->set_nested_value($data, $attrs['attr_name'] . '_content', $html);
                    $data[$attrs['attr_name']] = $html;
                    $data[$attrs['attr_name'] . '_content'] = $html;
                }
            }

            // Enqueue block-specific assets
            $this->enqueue_block_assets($config);

            // Render using template
            return $this->render_template($config, $data, $content);
        } finally {
            unset($rendering_blocks[$block_id]);
        }
    }

    /**
     * Extract field values from inner blocks (legacy support)
     */
    private function extract_field_values($block)
    {
        $values = array();

        $inner_blocks = array();
        if (is_object($block) && isset($block->parsed_block['innerBlocks'])) {
            $inner_blocks = $block->parsed_block['innerBlocks'];
        } elseif (is_array($block) && isset($block['innerBlocks'])) {
            $inner_blocks = $block['innerBlocks'];
        } elseif (is_object($block) && isset($block->inner_blocks) && is_iterable($block->inner_blocks)) {
            foreach ($block->inner_blocks as $ib) {
                if (is_object($ib) && isset($ib->parsed_block)) {
                    $inner_blocks[] = $ib->parsed_block;
                } elseif (is_array($ib)) {
                    $inner_blocks[] = $ib;
                }
            }
        }

        if (empty($inner_blocks)) {
            return $values;
        }

        foreach ($inner_blocks as $inner_block) {
            if (isset($inner_block['blockName']) && $inner_block['blockName'] === 'dgb/field') {
                $attrs = isset($inner_block['attrs']) ? $inner_block['attrs'] : array();

                if (!empty($attrs['attr_name']) && isset($attrs['value'])) {
                    // Populate both flat key and nested array path
                    $values[$attrs['attr_name']] = $attrs['value'];
                    $this->set_nested_value($values, $attrs['attr_name'], $attrs['value']);
                }
            }
        }

        return $values;
    }

    /**
     * Set nested value in array using dot notation
     */
    private function set_nested_value(&$array, $path, $value)
    {
        $keys    = explode('.', $path);
        $current = &$array;

        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = array();
            }
            $current = &$current[$key];
        }

        $current = $value;
        unset($current);
    }

    /**
     * Get nested value from array using dot notation
     */
    private function get_nested_value($array, $path, $default = '')
    {
        $keys    = explode('.', $path);
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
    private function enqueue_block_assets($config)
    {
        if (!empty($config['enqueue_scripts'])) {
            foreach ($config['enqueue_scripts'] as $script) {
                $handle = isset($script['handle']) ? $script['handle'] : '';
                $src    = isset($script['src']) ? $script['src'] : '';
                $deps   = isset($script['deps']) ? $script['deps'] : array();
                $ver    = isset($script['version']) ? $script['version'] : '1.0.0';

                if ($handle && $src) {
                    wp_enqueue_script($handle, $src, $deps, $ver, true);
                }
            }
        }

        if (!empty($config['enqueue_styles'])) {
            foreach ($config['enqueue_styles'] as $style) {
                $handle = isset($style['handle']) ? $style['handle'] : '';
                $src    = isset($style['src']) ? $style['src'] : '';
                $deps   = isset($style['deps']) ? $style['deps'] : array();
                $ver    = isset($style['version']) ? $style['version'] : '1.0.0';

                if ($handle && $src) {
                    wp_enqueue_style($handle, $src, $deps, $ver);
                }
            }
        }
    }

    /**
     * Render template with data
     */
    private function render_template($config, $data, $content)
    {
        if (!empty($config['template_file']) && file_exists($config['template_file'])) {
            ob_start();
            include $config['template_file'];
            return ob_get_clean();
        }

        if (!empty($config['template'])) {
            return \aw2_library::service_run($config['template'], $data, null, 'service');
        }

        return $content;
    }

    /**
     * Get all registered blocks
     */
    public function get_blocks()
    {
        if (empty($this->blocks)) {
            $this->blocks = $this->load_registry(self::is_editor_context());
        }
        return $this->blocks;
    }

    /**
     * Get a specific block configuration
     */
    public function get_block($name)
    {
        $blocks = $this->get_blocks();
        return isset($blocks[$name]) ? $blocks[$name] : null;
    }
}

// Namespace accessor function
function dgb()
{
    return BlockLibrary::instance();
}
