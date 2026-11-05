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

        // Cascade Layer Hierarchy: Output @layer tokens, base, blocks, sections; before any stylesheet execution
        add_action('enqueue_block_assets', array($this, 'enqueue_cascade_layers'), 1);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_cascade_layers'), 1);

        // Register block types on init priority 15
        add_action('init', array($this, 'register_blocks'), 15);
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
    }

    /**
     * Enqueue foundational cascade layer declaration before any stylesheet execution
     */
    public function enqueue_cascade_layers()
    {
        static $declared = false;
        if ($declared) {
            return;
        }
        $declared = true;

        if (function_exists('wp_register_style') && function_exists('wp_enqueue_style')) {
            wp_register_style('awx-cascade-layers', false);
            wp_enqueue_style('awx-cascade-layers');
            if (function_exists('wp_add_inline_style')) {
                wp_add_inline_style('awx-cascade-layers', '@layer tokens, base, blocks, sections;');
            }
        }
    }

    /**
     * Get base filesystem path for compiled CSS assets
     * 
     * @return string
     */
    public function get_css_dir()
    {
        $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : array();
        $basedir = !empty($upload['basedir']) ? $upload['basedir'] : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/uploads' : dirname(dirname(dirname(__DIR__))) . '/uploads');
        $dir = trailingslashit($basedir) . 'awx-css/';
        if (!file_exists($dir) && function_exists('wp_mkdir_p')) {
            wp_mkdir_p($dir);
        } elseif (!file_exists($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Get base URL for compiled CSS assets
     * 
     * @return string
     */
    public function get_css_url()
    {
        $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : array();
        $baseurl = !empty($upload['baseurl']) ? $upload['baseurl'] : (function_exists('content_url') ? content_url('uploads') : '/wp-content/uploads');
        return trailingslashit($baseurl) . 'awx-css/';
    }

    /**
     * Get base filesystem path for compiled JS assets
     * 
     * @return string
     */
    public function get_js_dir()
    {
        $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : array();
        $basedir = !empty($upload['basedir']) ? $upload['basedir'] : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/uploads' : dirname(dirname(dirname(__DIR__))) . '/uploads');
        $dir = trailingslashit($basedir) . 'awx-js/';
        if (!file_exists($dir) && function_exists('wp_mkdir_p')) {
            wp_mkdir_p($dir);
        } elseif (!file_exists($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Get base URL for compiled JS assets
     * 
     * @return string
     */
    public function get_js_url()
    {
        $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : array();
        $baseurl = !empty($upload['baseurl']) ? $upload['baseurl'] : (function_exists('content_url') ? content_url('uploads') : '/wp-content/uploads');
        return trailingslashit($baseurl) . 'awx-js/';
    }

    /**
     * Compile block styles into content-hashed CSS file scoped inside @layer blocks
     * 
     * @param string $slug Block slug
     * @param string $styles_service Service tag to execute
     * @param array $config Block config
     * @return array|null Metadata array or null if no styles
     */
    public function compile_block_styles($slug, $styles_service = '', $config = array())
    {
        if (empty($slug)) {
            return null;
        }

        $raw_css = '';
        if (!empty($styles_service) && class_exists('\aw2_library') && method_exists('\aw2_library', 'service_run')) {
            $result = \aw2_library::service_run($styles_service, null, null, 'service');
            if (is_string($result) && strpos($result, 'Template not found') === false && strpos($result, 'Module not found') === false) {
                $raw_css = trim($result);
            }
        } elseif (!empty($config['css_content']) && is_string($config['css_content'])) {
            $raw_css = trim($config['css_content']);
        }

        if (empty($raw_css)) {
            return null;
        }

        // Wrap in @layer blocks (or @layer sections) if not already scoped with @layer
        $final_css = $raw_css;
        if (!preg_match('/@layer\s+[a-zA-Z0-9_-]+\s*\{/', $raw_css)) {
            $target_layer = (isset($config['category']) && $config['category'] === 'sections') || strpos($slug, 'section-') === 0 || strpos($raw_css, '.wp-section-') !== false
                ? 'sections'
                : 'blocks';

            $final_css = "@layer {$target_layer} {\n" . $raw_css . "\n}";
        }

        $hash      = md5($final_css);
        $file_name = "{$slug}-{$hash}.css";
        $css_dir   = $this->get_css_dir();
        $css_url   = $this->get_css_url();
        $path      = $css_dir . $file_name;
        $url       = $css_url . $file_name;

        // Write file if missing
        if (!file_exists($path)) {
            $written = @file_put_contents($path, $final_css, LOCK_EX);
            if ($written === false) {
                error_log("DynamicGutenbergBlocks: Failed to write compiled CSS to {$path}");
            }
        }

        // Garbage collection: clean up older superseded hash files for this slug
        $this->cleanup_stale_assets($slug, 'css', $hash);

        $style_deps = array('awx-cascade-layers');
        if (function_exists('wp_style_is') && wp_style_is('awesome-css', 'registered')) {
            $style_deps[] = 'awesome-css';
        }
        if (!empty($config['style_deps']) && is_array($config['style_deps'])) {
            $style_deps = array_unique(array_merge($style_deps, $config['style_deps']));
        }

        return array(
            'handle'       => "awx-block-{$slug}",
            'path'         => $path,
            'url'          => $url,
            'hash'         => $hash,
            'dependencies' => $style_deps,
        );
    }

    /**
     * Compile client-side block scripts into content-hashed JS file
     * 
     * @param string $slug Block slug
     * @param string $scripts_service Service tag to execute
     * @param array $config Block config
     * @return array|null Metadata array or null if no scripts
     */
    public function compile_block_scripts($slug, $scripts_service = '', $config = array())
    {
        if (empty($slug)) {
            return null;
        }

        $raw_js = '';
        if (!empty($scripts_service) && class_exists('\aw2_library') && method_exists('\aw2_library', 'service_run')) {
            $result = \aw2_library::service_run($scripts_service, null, null, 'service');
            if (is_string($result) && strpos($result, 'Template not found') === false && strpos($result, 'Module not found') === false) {
                $raw_js = trim($result);
            }
        } elseif (!empty($config['js_content']) && is_string($config['js_content'])) {
            $raw_js = trim($config['js_content']);
        }

        if (empty($raw_js)) {
            return null;
        }

        $hash      = md5($raw_js);
        $file_name = "{$slug}-{$hash}.js";
        $js_dir    = $this->get_js_dir();
        $js_url    = $this->get_js_url();
        $path      = $js_dir . $file_name;
        $url       = $js_url . $file_name;

        // Write file if missing
        if (!file_exists($path)) {
            $written = @file_put_contents($path, $raw_js, LOCK_EX);
            if ($written === false) {
                error_log("DynamicGutenbergBlocks: Failed to write compiled JS to {$path}");
            }
        }

        // Garbage collection: clean up older superseded hash files for this slug
        $this->cleanup_stale_assets($slug, 'js', $hash);

        $js_deps = array();
        if (!empty($config['script_deps']) && is_array($config['script_deps'])) {
            $js_deps = $config['script_deps'];
        }

        return array(
            'handle'       => "awx-script-{$slug}",
            'path'         => $path,
            'url'          => $url,
            'hash'         => $hash,
            'dependencies' => $js_deps,
        );
    }

    /**
     * Unlink older superseded content-hashed files for a given block
     * 
     * @param string $slug Block slug
     * @param string $type 'css' or 'js'
     * @param string $current_hash Current active hash to preserve
     */
    public function cleanup_stale_assets($slug, $type = 'css', $current_hash = '')
    {
        $dir = $type === 'js' ? $this->get_js_dir() : $this->get_css_dir();
        $ext = $type === 'js' ? 'js' : 'css';

        if (!is_dir($dir)) {
            return;
        }

        $current_file = "{$slug}-{$current_hash}.{$ext}";
        $pattern      = trailingslashit($dir) . "{$slug}-*.{$ext}";
        $files        = glob($pattern);

        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== $current_file) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Permanently delete all compiled assets (.css and .js) for a block
     * 
     * @param string $slug Block slug
     */
    public function delete_block_assets($slug)
    {
        if (empty($slug)) {
            return;
        }

        $css_dir = $this->get_css_dir();
        $js_dir  = $this->get_js_dir();

        if (is_dir($css_dir)) {
            $css_files = glob(trailingslashit($css_dir) . "{$slug}-*.css");
            if (is_array($css_files)) {
                foreach ($css_files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
        }

        if (is_dir($js_dir)) {
            $js_files = glob(trailingslashit($js_dir) . "{$slug}-*.js");
            if (is_array($js_files)) {
                foreach ($js_files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
        }
    }

    /**
     * Synchronously recompile assets for a single block (Self-Healing on ephemeral nodes)
     * 
     * @param string $slug Block slug
     * @return array ['css' => array|null, 'js' => array|null]
     */
    public function recompile_single_block_assets($slug)
    {
        if (empty($slug)) {
            return array('css' => null, 'js' => null);
        }

        $config = isset($this->blocks[$slug]) ? $this->blocks[$slug] : array();

        // If config is empty or missing services, attempt to detect from post definition
        $styles_service  = !empty($config['styles_service']) ? $config['styles_service'] : '';
        $scripts_service = !empty($config['scripts_service']) ? $config['scripts_service'] : '';

        if (empty($styles_service) || empty($scripts_service)) {
            if (function_exists('get_posts')) {
                $posts = get_posts(array(
                    'name'           => $slug,
                    'post_type'      => 'aw_gb_blocks',
                    'post_status'    => array('publish', 'draft'),
                    'posts_per_page' => 1,
                ));
                if (!empty($posts)) {
                    $post = $posts[0];
                    if (strpos($post->post_content, 'templates.add styles') !== false) {
                        $styles_service = "gb_blocks.{$slug}.styles";
                    }
                    if (strpos($post->post_content, 'templates.add scripts') !== false) {
                        $scripts_service = "gb_blocks.{$slug}.scripts";
                    }
                }
            }
        }

        // Fallback to convention if post was not found/inspected
        if (empty($styles_service) && !isset($post)) {
            $styles_service = "gb_blocks.{$slug}.styles";
        }
        if (empty($scripts_service) && !isset($post)) {
            $scripts_service = "gb_blocks.{$slug}.scripts";
        }

        $css_meta = $this->compile_block_styles($slug, $styles_service, $config);
        $js_meta  = $this->compile_block_scripts($slug, $scripts_service, $config);

        if ($css_meta && isset($this->blocks[$slug])) {
            $this->blocks[$slug]['css']   = $css_meta;
            $this->blocks[$slug]['style'] = $css_meta['handle'];
        }

        if ($js_meta && isset($this->blocks[$slug])) {
            $this->blocks[$slug]['js']          = $js_meta;
            $this->blocks[$slug]['view_script'] = $js_meta['handle'];
        }

        return array(
            'css' => $css_meta,
            'js'  => $js_meta,
        );
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

                // Check for Styles Service
                $styles_service = !empty($config_data['styles_service'])
                    ? $config_data['styles_service']
                    : (!empty($config['styles_service']) ? $config['styles_service'] : '');

                $has_styles = !empty($styles_service)
                    || (isset($block_posts[$slug]['post_content']) && strpos($block_posts[$slug]['post_content'], 'templates.add styles') !== false);

                if (!$styles_service && $has_styles) {
                    $styles_service = "gb_blocks.{$slug}.styles";
                }

                // Check for Scripts Service
                $scripts_service = !empty($config_data['scripts_service'])
                    ? $config_data['scripts_service']
                    : (!empty($config['scripts_service']) ? $config['scripts_service'] : '');

                $has_scripts = !empty($scripts_service)
                    || (isset($block_posts[$slug]['post_content']) && strpos($block_posts[$slug]['post_content'], 'templates.add scripts') !== false);

                if (!$scripts_service && $has_scripts) {
                    $scripts_service = "gb_blocks.{$slug}.scripts";
                }

                // Compile Block Styles & Scripts
                $css_meta = $has_styles ? $this->compile_block_styles($slug, $styles_service, $config) : null;
                $js_meta  = $has_scripts ? $this->compile_block_scripts($slug, $scripts_service, $config) : null;

                // 4. Partition Payloads
                // Lightweight frontend definition (no tabs, no field schemas)
                $frontend_blocks[$slug] = array(
                    'name'             => $slug,
                    'render_service'   => $config['template'],
                    'template'         => $config['template'],
                    'template_file'    => isset($config['template_file']) ? $config['template_file'] : '',
                    'styles_service'   => $styles_service,
                    'scripts_service'  => $scripts_service,
                    'css'              => $css_meta,
                    'js'               => $js_meta,
                    'attributes'       => $attributes,
                    'supports'         => $supports,
                    'has_innerblocks'  => $has_innerblocks,
                    'innerblocks_attr' => $innerblocks_attr,
                    'style'            => $css_meta ? $css_meta['handle'] : (isset($config['style']) ? $config['style'] : null),
                    'view_script'      => $js_meta ? $js_meta['handle'] : (isset($config['view_script']) ? $config['view_script'] : null),
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
                    'sidebar_tabs'   => isset($config['sidebar_tabs']) ? $config['sidebar_tabs'] : (isset($config_data['sidebar_tabs']) ? $config_data['sidebar_tabs'] : array()),
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

            // 1. Process Scoped Stylesheet & Self-Healing
            if (!empty($config['css']) && is_array($config['css'])) {
                $css_meta = $config['css'];
                $handle   = !empty($css_meta['handle']) ? $css_meta['handle'] : "awx-block-{$slug}";
                $deps     = !empty($css_meta['dependencies']) ? $css_meta['dependencies'] : array('awx-cascade-layers');

                // Self-Healing: Verify file exists on local disk (critical for ephemeral nodes / containers)
                if (!empty($css_meta['path']) && !file_exists($css_meta['path'])) {
                    $healed = $this->recompile_single_block_assets($slug);
                    if (!empty($healed['css'])) {
                        $css_meta = $healed['css'];
                    }
                }

                if (!empty($css_meta['url']) && function_exists('wp_register_style')) {
                    wp_register_style($handle, $css_meta['url'], $deps, $css_meta['hash']);

                    // Enable WP 5.8+ core auto-inlining for small stylesheets (skip for remote streams like s3://)
                    if (!empty($css_meta['path']) && file_exists($css_meta['path']) && is_readable($css_meta['path'])) {
                        $is_stream = function_exists('wp_is_stream') && wp_is_stream($css_meta['path']);
                        if (!$is_stream && function_exists('wp_style_add_data')) {
                            wp_style_add_data($handle, 'path', $css_meta['path']);
                        }
                    }
                }

                $args['style'] = isset($config['style']) && $config['style'] !== $handle
                    ? array_unique(array_merge((array) $config['style'], array($handle)))
                    : $handle;
            } elseif (!empty($config['style'])) {
                $args['style'] = $config['style'];
            }

            // 2. Process Scoped Frontend Script (Interactivity) & Self-Healing
            if (!empty($config['js']) && is_array($config['js'])) {
                $js_meta   = $config['js'];
                $js_handle = !empty($js_meta['handle']) ? $js_meta['handle'] : "awx-script-{$slug}";
                $js_deps   = !empty($js_meta['dependencies']) ? $js_meta['dependencies'] : array();

                // Self-Healing: Verify file exists on local disk
                if (!empty($js_meta['path']) && !file_exists($js_meta['path'])) {
                    $healed = $this->recompile_single_block_assets($slug);
                    if (!empty($healed['js'])) {
                        $js_meta = $healed['js'];
                    }
                }

                if (!empty($js_meta['url']) && function_exists('wp_register_script')) {
                    wp_register_script($js_handle, $js_meta['url'], $js_deps, $js_meta['hash'], true);
                }

                $args['view_script'] = isset($config['view_script']) && $config['view_script'] !== $js_handle
                    ? array_unique(array_merge((array) $config['view_script'], array($js_handle)))
                    : $js_handle;
            } elseif (!empty($config['view_script'])) {
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

        // Allow null for object types in Gutenberg REST schema validation
        if ($attr_type === 'object') {
            $config = array('type' => array('object', 'null'));
        } else {
            $config = array('type' => $attr_type);
        }

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
            $extracted_inner = $this->extract_field_values($block, $config);
            if (!empty($extracted_inner)) {
                $field_values = array_merge($field_values, $extracted_inner);
            }

            // C. Merge field values with block attributes
            $data = is_array($attributes) ? $attributes : array();
            unset($data['_data']);

            foreach ($field_values as $key => $val) {
                $data[$key] = $val;
            }

            // D. Self-heal and normalize legacy data (images, video URLs, and legacy un-prefixed keys)
            $this->self_heal_data_attributes($data, $config);

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
                    $extra_classes = array();

                    // Generate modifier classes from style.* attributes (Section 1.5 specification)
                    if (!empty($data['style.surface'])) {
                        $extra_classes[] = 'has-surface-' . sanitize_html_class($data['style.surface']);
                        $extra_classes[] = 'is-surface-' . sanitize_html_class($data['style.surface']);
                    }
                    if (!empty($data['style.width'])) {
                        $extra_classes[] = 'has-width-' . sanitize_html_class($data['style.width']);
                    }
                    if (!empty($data['style.padding'])) {
                        $extra_classes[] = 'has-padding-' . sanitize_html_class($data['style.padding']);
                    }
                    if (!empty($data['style.align'])) {
                        $extra_classes[] = sanitize_html_class($data['style.align']);
                    }
                    if (!empty($data['style.columns'])) {
                        $extra_classes[] = 'has-cols-' . sanitize_html_class($data['style.columns']);
                    }
                    if (!empty($data['style.layout'])) {
                        $layout_val = sanitize_html_class($data['style.layout']);
                        if ($layout_val === 'bento') {
                            $extra_classes[] = 'is-bento';
                        } else {
                            $extra_classes[] = $layout_val;
                        }
                    }
                    if (!empty($data['style.order'])) {
                        $order_val = sanitize_html_class($data['style.order']);
                        $extra_classes[] = strpos($order_val, 'is-order-') === 0 ? $order_val : 'is-order-' . $order_val;
                    }

                    $wrapper_args = !empty($extra_classes) ? array('class' => implode(' ', array_unique($extra_classes))) : array();
                    $wrapper_attrs = get_block_wrapper_attributes($wrapper_args);
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
     * Normalize an image value into a standard object dictionary
     * Supports:
     * - Native object/array: ['id' => 12, 'url' => '...', 'alt' => '...', 'title' => '...']
     * - Attachment ID: 12 or "12" -> resolves via wp_get_attachment_url
     * - URL string: "https://..." or "/wp-content/..." -> ['id' => 0, 'url' => '...', ...]
     *
     * @param mixed $value
     * @return array|null
     */
    public function normalize_image_value($value)
    {
        if (empty($value)) {
            return null;
        }

        // Already structured array
        if (is_array($value)) {
            if (!empty($value['url'])) {
                return array(
                    'id'    => !empty($value['id']) ? (int) $value['id'] : 0,
                    'url'   => (string) $value['url'],
                    'alt'   => isset($value['alt']) ? (string) $value['alt'] : '',
                    'title' => isset($value['title']) ? (string) $value['title'] : '',
                );
            }
            if (!empty($value['id']) && is_numeric($value['id'])) {
                $value = (int) $value['id'];
            } else {
                return null;
            }
        } elseif (is_object($value)) {
            if (!empty($value->url)) {
                return array(
                    'id'    => !empty($value->id) ? (int) $value->id : 0,
                    'url'   => (string) $value->url,
                    'alt'   => isset($value->alt) ? (string) $value->alt : '',
                    'title' => isset($value->title) ? (string) $value->title : '',
                );
            }
            if (!empty($value->id) && is_numeric($value->id)) {
                $value = (int) $value->id;
            } else {
                return null;
            }
        }

        // Numeric attachment ID
        if (is_numeric($value) && (int) $value > 0) {
            $id = (int) $value;
            $url = function_exists('wp_get_attachment_url') ? wp_get_attachment_url($id) : '';
            if (!empty($url)) {
                $alt   = function_exists('get_post_meta') ? (string) get_post_meta($id, '_wp_attachment_image_alt', true) : '';
                $title = function_exists('get_the_title') ? (string) get_the_title($id) : '';
                return array(
                    'id'    => $id,
                    'url'   => $url,
                    'alt'   => $alt,
                    'title' => $title,
                );
            }
            return null;
        }

        // String URL
        if (is_string($value) && (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0 || strpos($value, '/') === 0)) {
            return array(
                'id'    => 0,
                'url'   => $value,
                'alt'   => '',
                'title' => '',
            );
        }

        return null;
    }

    /**
     * Normalize media URL if value is an attachment ID or object
     *
     * @param mixed $value
     * @return string
     */
    public function normalize_media_url($value)
    {
        if (empty($value)) {
            return '';
        }
        if (is_numeric($value) && (int) $value > 0) {
            $url = function_exists('wp_get_attachment_url') ? wp_get_attachment_url((int) $value) : '';
            return $url ? $url : (string) $value;
        }
        if (is_array($value) && !empty($value['url'])) {
            return (string) $value['url'];
        }
        if (is_object($value) && !empty($value->url)) {
            return (string) $value->url;
        }
        return is_string($value) ? $value : '';
    }

    /**
     * Self-heal and normalize data attributes against block configuration
     *
     * @param array &$data
     * @param array $config
     */
    public function self_heal_data_attributes(&$data, $config)
    {
        $all_fields = isset($config['fields']) && is_array($config['fields']) ? $config['fields'] : array();
        if (!empty($config['tabs']) && is_array($config['tabs'])) {
            foreach ($config['tabs'] as $t) {
                if (!empty($t['fields']) && is_array($t['fields'])) {
                    $all_fields = array_merge($all_fields, $t['fields']);
                }
            }
        }

        foreach ($all_fields as $f) {
            $attr_name = !empty($f['attr_name']) ? $f['attr_name'] : (!empty($f['name']) ? $f['name'] : '');
            $name      = !empty($f['name']) ? $f['name'] : '';
            $type      = isset($f['type']) ? $f['type'] : 'text';

            if (empty($attr_name)) {
                continue;
            }

            // Check if legacy un-prefixed name has value while attr_name is missing
            if (!empty($name) && $name !== $attr_name) {
                if ((!isset($data[$attr_name]) || $data[$attr_name] === '' || $data[$attr_name] === null) && isset($data[$name]) && $data[$name] !== '') {
                    $data[$attr_name] = $data[$name];
                    $this->set_nested_value($data, $attr_name, $data[$name]);
                }
            }

            // Self-heal image / media values
            if ($type === 'image' || $type === 'media' || $type === 'file') {
                if (isset($data[$attr_name])) {
                    $normalized = $this->normalize_image_value($data[$attr_name]);
                    if ($normalized !== null) {
                        $data[$attr_name] = $normalized;
                        $this->set_nested_value($data, $attr_name, $normalized);
                    }
                }
            } elseif (strpos($attr_name, 'video') !== false || strpos($attr_name, 'url') !== false) {
                if (isset($data[$attr_name]) && is_numeric($data[$attr_name])) {
                    $norm_url = $this->normalize_media_url($data[$attr_name]);
                    if ($norm_url !== '') {
                        $data[$attr_name] = $norm_url;
                        $this->set_nested_value($data, $attr_name, $norm_url);
                    }
                }
            }
        }
    }

    /**
     * Extract field values from inner blocks (legacy support)
     */
    public function extract_field_values($block, $config = array())
    {
        $values = array();

        // Build mapping of field name -> attr_name and field types from config
        $name_to_attr = array();
        $field_types  = array();
        $all_fields   = isset($config['fields']) && is_array($config['fields']) ? $config['fields'] : array();
        if (!empty($config['tabs']) && is_array($config['tabs'])) {
            foreach ($config['tabs'] as $t) {
                if (!empty($t['fields']) && is_array($t['fields'])) {
                    $all_fields = array_merge($all_fields, $t['fields']);
                }
            }
        }
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

                $raw_key  = !empty($attrs['attr_name']) ? $attrs['attr_name'] : (!empty($attrs['name']) ? $attrs['name'] : '');
                $attr_key = isset($name_to_attr[$raw_key]) ? $name_to_attr[$raw_key] : $raw_key;

                if (!empty($attr_key) && isset($attrs['value'])) {
                    $val   = $attrs['value'];
                    $ftype = isset($field_types[$attr_key]) ? $field_types[$attr_key] : (isset($attrs['type']) ? $attrs['type'] : '');

                    if ($ftype === 'image' || $ftype === 'media' || $ftype === 'file') {
                        $normalized = $this->normalize_image_value($val);
                        if ($normalized !== null) {
                            $val = $normalized;
                        }
                    } elseif (is_numeric($val) && (strpos($attr_key, 'video') !== false || strpos($attr_key, 'url') !== false)) {
                        $val = $this->normalize_media_url($val);
                    }

                    // Populate both flat key and nested array path
                    $values[$attr_key] = $val;
                    $this->set_nested_value($values, $attr_key, $val);

                    // If attr_key was mapped from a different raw key, keep raw key as fallback too
                    if ($raw_key !== $attr_key && !empty($raw_key)) {
                        $values[$raw_key] = $val;
                        $this->set_nested_value($values, $raw_key, $val);
                    }
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
