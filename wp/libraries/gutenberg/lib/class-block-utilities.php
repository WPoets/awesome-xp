<?php
/**
 * Block Utilities Class
 *
 * Helper functions for working with Dynamic Gutenberg Blocks
 *
 * @package DynamicGutenbergBlocks
 * @version 1.0.0
 */

namespace DynamicGutenbergBlocks;

if (!defined('ABSPATH')) {
    exit;
}

class BlockUtilities
{

    /**
     * Convert JSON file to PHP array
     *
     * @param string $json_file Path to JSON file
     * @return array|false Block configuration or false on error
     */
    public static function json_to_array($json_file)
    {
        if (!file_exists($json_file)) {
            return false;
        }

        $json_content = file_get_contents($json_file);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("DGB Error: Invalid JSON in {$json_file}: " . json_last_error_msg());
            return false;
        }

        return $data;
    }

    /**
     * Validate block configuration
     *
     * @param array $config Block configuration
     * @return array Array of validation errors (empty if valid)
     */
    public static function validate_config($config)
    {
        $errors = array();

        if (!is_array($config)) {
            return array('Configuration must be an array');
        }

        // Check required fields
        if (empty($config['name'])) {
            $errors[] = 'Block name is required';
        }

        if (empty($config['title'])) {
            $errors[] = 'Block title is required';
        }

        // Validate name format (lowercase, alphanumeric, hyphens only)
        if (!empty($config['name']) && !preg_match('/^[a-z0-9-]+$/', $config['name'])) {
            $errors[] = 'Block name must contain only lowercase letters, numbers, and hyphens';
        }

        // Validate top-level fields
        if (isset($config['fields']) && is_array($config['fields'])) {
            foreach ($config['fields'] as $index => $field) {
                $field_errors = self::validate_field($field, (string) $index);
                $errors = array_merge($errors, $field_errors);
            }
        }

        // Validate tabs and their fields
        if (isset($config['tabs']) && is_array($config['tabs'])) {
            foreach ($config['tabs'] as $tab_index => $tab) {
                $tab_name = isset($tab['name']) ? $tab['name'] : '';

                if (empty($tab_name)) {
                    $errors[] = "Tab {$tab_index} is missing a name";
                }

                if (isset($tab['fields']) && is_array($tab['fields'])) {
                    foreach ($tab['fields'] as $field_index => $field) {
                        $context = ($tab_name !== '' ? $tab_name : $tab_index) . ".{$field_index}";
                        $field_errors = self::validate_field($field, $context);
                        $errors = array_merge($errors, $field_errors);
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Validate individual field configuration
     *
     * @param array $field Field configuration
     * @param string $context Context for error messages
     * @return array Array of validation errors
     */
    private static function validate_field($field, $context)
    {
        $errors = array();

        if (!is_array($field)) {
            $errors[] = "Field {$context} is not a valid configuration";
            return $errors;
        }

        $type = isset($field['type']) ? $field['type'] : '';

        if (empty($type)) {
            $errors[] = "Field {$context} is missing a type";
        }

        if (empty($field['name'])) {
            $errors[] = "Field {$context} is missing a name";
        }

        if (empty($field['attr_name'])) {
            $errors[] = "Field {$context} is missing an attr_name";
        }

        // Option-based fields require options
        $needs_options = array('select', 'radio', 'checkbox');
        if (in_array($type, $needs_options, true) && empty($field['options'])) {
            $errors[] = "Field {$context} of type '{$type}' is missing options";
        }

        // Row repeater requires repeater_fields
        if ($type === 'row_repeater' && empty($field['repeater_fields'])) {
            $errors[] = "Row repeater field {$context} is missing repeater_fields";
        }

        return $errors;
    }

    /**
     * Generate a block JSON template
     *
     * @param string $name Block name
     * @param string $title Block title
     * @param array $options Additional options
     * @return array Block configuration template
     */
    public static function generate_template($name, $title, $options = array())
    {
        $defaults = array(
            'icon' => 'admin-generic',
            'category' => 'widgets',
            'description' => '',
            'with_tabs' => false,
            'with_image' => false,
            'with_repeater' => false
        );

        $options = array_merge($defaults, $options);

        $template = array(
            'name' => $name,
            'title' => $title,
            'description' => $options['description'],
            'icon' => $options['icon'],
            'category' => $options['category'],
            'keywords' => array()
        );

        $fields = array(
            array(
                'type' => 'title',
                'name' => 'block-title',
                'label' => 'Title',
                'attr_name' => 'title'
            ),
            array(
                'type' => 'textarea',
                'name' => 'description',
                'label' => 'Description',
                'attr_name' => 'description'
            )
        );

        if ($options['with_image']) {
            $fields[] = array(
                'type' => 'image',
                'name' => 'featured-image',
                'label' => 'Featured Image',
                'attr_name' => 'image'
            );
        }

        if ($options['with_repeater']) {
            $fields[] = array(
                'type' => 'attributes-repeater',
                'name' => 'attributes',
                'label' => 'Attributes',
                'attr_name' => 'attributes'
            );
        }

        if ($options['with_tabs']) {
            $template['tabs'] = array(
                array(
                    'name' => 'content',
                    'title' => 'Content',
                    'icon' => 'edit',
                    'fields' => $fields
                ),
                array(
                    'name' => 'settings',
                    'title' => 'Settings',
                    'icon' => 'admin-settings',
                    'fields' => array(
                        array(
                            'type' => 'select',
                            'name' => 'layout',
                            'label' => 'Layout',
                            'attr_name' => 'settings.layout',
                            'default' => 'standard',
                            'options' => array(
                                array('label' => 'Standard', 'value' => 'standard'),
                                array('label' => 'Card', 'value' => 'card')
                            )
                        )
                    )
                )
            );
        } else {
            $template['fields'] = $fields;
        }

        $template['template'] = self::generate_simple_template($name, $options['with_tabs']);

        return $template;
    }

    /**
     * Generate a simple HTML template
     *
     * @param string $name Block name
     * @param bool $with_tabs Whether block uses tabs
     * @return string Template HTML
     */
    private static function generate_simple_template($name, $with_tabs = false)
    {
        $title_var = $with_tabs ? 'content.title' : 'title';
        $desc_var = $with_tabs ? 'content.description' : 'description';
        $image_var = $with_tabs ? 'content.image' : 'image';

        $template = "<div class=\"{$name}\">\n";
        $template .= "  <h3>{{{$title_var}}}</h3>\n";
        $template .= "  <p>{{{$desc_var}}}</p>\n";
        $template .= "  {{#if {$image_var}}}\n";
        $template .= "    <img src=\"{{{$image_var}.url}}\" alt=\"{{{$image_var}.alt}}\" />\n";
        $template .= "  {{/if}}\n";
        $template .= "</div>";

        return $template;
    }

    /**
     * Sanitize block name
     *
     * @param string $name Block name
     * @return string Sanitized name
     */
    public static function sanitize_block_name($name)
    {
        $name = strtolower($name);
        $name = str_replace(array(' ', '_'), '-', $name);
        $name = preg_replace('/[^a-z0-9-]/', '', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        return $name;
    }

    /**
     * Get all available field types
     *
     * @return array Array of field type information
     */
    public static function get_field_types()
    {
        return array(
            'text' => array(
                'label' => 'Text',
                'description' => 'Single line text input',
                'has_options' => false
            ),
            'textarea' => array(
                'label' => 'Textarea',
                'description' => 'Multi-line text input',
                'has_options' => false
            ),
            'number' => array(
                'label' => 'Number',
                'description' => 'Numeric input',
                'has_options' => false
            ),
            'small-number' => array(
                'label' => 'Small Number',
                'description' => 'Compact numeric input with min/max',
                'has_options' => false
            ),
            'select' => array(
                'label' => 'Select',
                'description' => 'Dropdown selection',
                'has_options' => true
            ),
            'radio' => array(
                'label' => 'Radio Buttons',
                'description' => 'Single selection from multiple options',
                'has_options' => true
            ),
            'checkbox' => array(
                'label' => 'Checkboxes',
                'description' => 'Multiple selection from options',
                'has_options' => true
            ),
            'single-checkbox' => array(
                'label' => 'Single Checkbox',
                'description' => 'Single on/off checkbox',
                'has_options' => false
            ),
            'toggle' => array(
                'label' => 'Toggle',
                'description' => 'Boolean on/off switch',
                'has_options' => false
            ),
            'image' => array(
                'label' => 'Image',
                'description' => 'Media library image picker',
                'has_options' => false
            ),
            'date' => array(
                'label' => 'Date',
                'description' => 'Date picker',
                'has_options' => false
            ),
            'title' => array(
                'label' => 'Title',
                'description' => 'Pre-configured title field',
                'has_options' => false
            ),
            'purpose' => array(
                'label' => 'Purpose',
                'description' => 'Pre-configured purpose/description',
                'has_options' => false
            ),
            'query' => array(
                'label' => 'Query',
                'description' => 'SQL query textarea',
                'has_options' => false
            ),
            'service' => array(
                'label' => 'Service',
                'description' => 'Service name input',
                'has_options' => false
            ),
            'awesome_code' => array(
                'label' => 'Code',
                'description' => 'Code editor textarea',
                'has_options' => false
            ),
            'env_path' => array(
                'label' => 'Environment Path',
                'description' => 'Environment path input',
                'has_options' => false
            ),
            'attributes-repeater' => array(
                'label' => 'Attributes Repeater',
                'description' => 'Dynamic key-value pairs',
                'has_options' => false
            ),
            'row_repeater' => array(
                'label' => 'Row Repeater',
                'description' => 'Custom repeatable rows',
                'has_options' => false
            ),
            'innerblocks' => array(
                'label' => 'Inner Blocks',
                'description' => 'Nested Gutenberg blocks',
                'has_options' => false
            )
        );
    }

    /**
     * Export block configuration to JSON file
     *
     * @param array $config Block configuration
     * @param string $output_path Output file path
     * @return bool Success status
     */
    public static function export_to_json($config, $output_path)
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            error_log('DGB Error: Failed to encode block configuration to JSON');
            return false;
        }

        $result = file_put_contents($output_path, $json);

        return $result !== false;
    }

    /**
     * Get nested value from array using dot notation
     *
     * @param array $array Source array
     * @param string $path Dot-separated path (e.g., 'content.title')
     * @param mixed $default Default value if path not found
     * @return mixed Value at path or default
     */
    public static function get_nested_value($array, $path, $default = null)
    {
        $keys = explode('.', $path);
        $current = $array;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * Set nested value in array using dot notation
     *
     * @param array &$array Target array (passed by reference)
     * @param string $path Dot-separated path
     * @param mixed $value Value to set
     * @return void
     */
    public static function set_nested_value(&$array, $path, $value)
    {
        $keys = explode('.', $path);
        $current = &$array;

        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = array();
            }
            $current = &$current[$key];
        }

        $current = $value;
    }

    /**
     * Convert attributes array to data attributes string
     *
     * @param array $attributes Attributes array
     * @return string HTML data attributes
     */
    public static function attributes_to_data_string($attributes)
    {
        if (empty($attributes) || !is_array($attributes)) {
            return '';
        }

        $data_attrs = array();
        foreach ($attributes as $attr) {
            if (!empty($attr['name']) && isset($attr['value'])) {
                $name = sanitize_key($attr['name']);
                $value = esc_attr($attr['value']);
                $data_attrs[] = "data-{$name}=\"{$value}\"";
            }
        }

        return implode(' ', $data_attrs);
    }

    /**
     * Render a simple template with data
     *
     * @param string $template Template string
     * @param array $data Data array
     * @return string Rendered template
     */
    public static function render_template($template, $data)
    {
        // Process conditionals {{#if variable}}...{{/if}}
        $template = preg_replace_callback(
            '/\{\{#if\s+([a-zA-Z0-9_.]+)\}\}(.*?)\{\{\/if\}\}/s',
            function ($matches) use ($data) {
                $value = self::get_nested_value($data, $matches[1]);
                return $value ? $matches[2] : '';
            },
            $template
        );

        // Process loops {{#each array}}...{{/each}}
        $template = preg_replace_callback(
            '/\{\{#each\s+([a-zA-Z0-9_.]+)\}\}(.*?)\{\{\/each\}\}/s',
            function ($matches) use ($data) {
                $array = self::get_nested_value($data, $matches[1], array());
                if (!is_array($array)) {
                    return '';
                }

                $output = '';
                foreach ($array as $item) {
                    $output .= self::render_template($matches[2], is_array($item) ? $item : array());
                }
                return $output;
            },
            $template
        );

        // Process variables {{variable}}
        $template = preg_replace_callback(
            '/\{\{([a-zA-Z0-9_.]+)\}\}/',
            function ($matches) use ($data) {
                $value = self::get_nested_value($data, $matches[1], '');
                if (is_array($value)) {
                    $value = '';
                }
                return esc_html($value);
            },
            $template
        );

        return $template;
    }

    /**
     * Import blocks from directory
     *
     * @param string $directory Directory containing JSON files
     * @return array Array of imported block names and status
     */
    public static function import_blocks_from_directory($directory)
    {
        $results = array(
            'success' => array(),
            'failed' => array()
        );

        if (!is_dir($directory)) {
            return $results;
        }

        $json_files = glob(trailingslashit($directory) . '*.json');

        foreach ($json_files as $json_file) {
            $config = self::json_to_array($json_file);

            if ($config === false) {
                $results['failed'][] = basename($json_file);
                continue;
            }

            $errors = self::validate_config($config);

            if (!empty($errors)) {
                $results['failed'][] = array(
                    'file' => basename($json_file),
                    'errors' => $errors
                );
                continue;
            }

            $results['success'][] = $config['name'];
        }

        return $results;
    }

    /**
     * Create a CLI-style table for displaying data
     *
     * @param array $headers Table headers
     * @param array $rows Table rows
     * @return string Formatted table
     */
    public static function create_table($headers, $rows)
    {
        $output = '';

        // Calculate column widths
        $widths = array();
        foreach ($headers as $index => $header) {
            $widths[$index] = strlen((string) $header);
        }

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $len = strlen((string) $cell);
                $widths[$index] = isset($widths[$index]) ? max($widths[$index], $len) : $len;
            }
        }

        // Create header row
        $output .= '| ';
        foreach ($headers as $index => $header) {
            $output .= str_pad((string) $header, $widths[$index]) . ' | ';
        }
        $output .= "\n";

        // Create separator
        $output .= '|';
        foreach ($widths as $width) {
            $output .= str_repeat('-', $width + 2) . '|';
        }
        $output .= "\n";

        // Create data rows
        foreach ($rows as $row) {
            $output .= '| ';
            foreach ($row as $index => $cell) {
                $width = isset($widths[$index]) ? $widths[$index] : 0;
                $output .= str_pad((string) $cell, $width) . ' | ';
            }
            $output .= "\n";
        }

        return $output;
    }

    /**
     * Generate documentation for a block
     *
     * @param array $config Block configuration
     * @return string Markdown documentation
     */
    public static function generate_documentation($config)
    {
        $title = isset($config['title']) ? $config['title'] : 'Untitled Block';
        $doc = "# {$title}\n\n";

        if (!empty($config['description'])) {
            $doc .= "{$config['description']}\n\n";
        }

        $doc .= "## Block Information\n\n";
        $doc .= "- **Name**: `" . (isset($config['name']) ? $config['name'] : '') . "`\n";
        $doc .= "- **Category**: " . (isset($config['category']) ? $config['category'] : '') . "\n";
        $doc .= "- **Icon**: " . (isset($config['icon']) ? $config['icon'] : '') . "\n\n";

        if (!empty($config['keywords']) && is_array($config['keywords'])) {
            $doc .= "**Keywords**: " . implode(', ', $config['keywords']) . "\n\n";
        }

        $doc .= "## Fields\n\n";

        // Collect all fields
        $all_fields = array();

        if (isset($config['tabs']) && is_array($config['tabs'])) {
            foreach ($config['tabs'] as $tab) {
                if (isset($tab['fields']) && is_array($tab['fields'])) {
                    foreach ($tab['fields'] as $field) {
                        if (is_array($field)) {
                            $field['_tab'] = isset($tab['title']) ? $tab['title'] : '';
                            $all_fields[] = $field;
                        }
                    }
                }
            }
        } elseif (isset($config['fields']) && is_array($config['fields'])) {
            $all_fields = $config['fields'];
        }

        foreach ($all_fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = isset($field['label']) ? $field['label'] : (isset($field['name']) ? $field['name'] : 'Field');
            $doc .= "### {$label}\n\n";

            if (!empty($field['_tab'])) {
                $doc .= "**Tab**: {$field['_tab']}\n\n";
            }

            $type = isset($field['type']) ? $field['type'] : 'unknown';
            $attr_name = isset($field['attr_name']) ? $field['attr_name'] : '';

            $doc .= "- **Type**: `{$type}`\n";
            $doc .= "- **Attribute**: `{$attr_name}`\n";

            if (isset($field['default'])) {
                $default = $field['default'];
                if (is_array($default)) {
                    $default = json_encode($default);
                } elseif (is_bool($default)) {
                    $default = $default ? 'true' : 'false';
                }
                $doc .= "- **Default**: `{$default}`\n";
            }

            if (isset($field['validation']) && is_array($field['validation'])) {
                $doc .= "- **Validation**:\n";
                foreach ($field['validation'] as $rule => $value) {
                    if (is_bool($value)) {
                        $value = $value ? 'true' : 'false';
                    } elseif (is_array($value)) {
                        $value = json_encode($value);
                    }
                    $doc .= "  - {$rule}: {$value}\n";
                }
            }

            if (in_array($type, array('select', 'radio', 'checkbox'), true) && isset($field['options']) && is_array($field['options'])) {
                $doc .= "- **Options**:\n";
                foreach ($field['options'] as $option) {
                    $opt_label = isset($option['label']) ? $option['label'] : '';
                    $opt_value = isset($option['value']) ? $option['value'] : '';
                    $doc .= "  - {$opt_label} (`{$opt_value}`)\n";
                }
            }

            $doc .= "\n";
        }

        if (!empty($config['template'])) {
            $doc .= "## Template\n\n";
            $doc .= "```html\n";
            $doc .= $config['template'];
            $doc .= "\n```\n\n";
        }

        return $doc;
    }

    /**
     * Get block usage statistics
     *
     * @param string $block_name Block name (without namespace)
     * @return array Usage statistics
     */
    public static function get_block_usage_stats($block_name)
    {
        global $wpdb;

        $full_block_name = 'dgb/' . $block_name;

        // Count posts using this block
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_content LIKE %s
            AND post_status = 'publish'",
            '%<!-- wp:' . $wpdb->esc_like($full_block_name) . '%'
        ));

        // Get posts using this block
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_type
            FROM {$wpdb->posts}
            WHERE post_content LIKE %s
            AND post_status = 'publish'
            LIMIT 10",
            '%<!-- wp:' . $wpdb->esc_like($full_block_name) . '%'
        ));

        return array(
            'usage_count' => intval($count),
            'used_in_posts' => $posts
        );
    }

    /**
     * Clone a block configuration with a new name
     *
     * @param array $config Source block configuration
     * @param string $new_name New block name
     * @param string $new_title New block title
     * @return array Cloned configuration
     */
    public static function clone_block($config, $new_name, $new_title)
    {
        $cloned = $config;
        $cloned['name'] = $new_name;
        $cloned['title'] = $new_title;

        // Update template class names
        if (isset($cloned['template']) && isset($config['name'])) {
            $cloned['template'] = str_replace($config['name'], $new_name, $cloned['template']);
        }

        return $cloned;
    }
}

/**
 * Global helper functions
 *
 * NOTE: Because this file declares `namespace DynamicGutenbergBlocks;`, these
 * functions live in that namespace. Call them as
 * \DynamicGutenbergBlocks\dgb_validate_json(...) etc., or import them with
 * `use function`. (The truly global dgb_create_block()/dgb_get_block() helpers
 * are defined in dgb-init.php, which has no namespace.)
 */

/**
 * Get a block utilities instance
 */
function dgb_utils()
{
    return new BlockUtilities();
}

/**
 * Validate a block JSON file
 */
function dgb_validate_json($json_file)
{
    $config = BlockUtilities::json_to_array($json_file);

    if ($config === false) {
        return array('valid' => false, 'errors' => array('Invalid JSON file'));
    }

    $errors = BlockUtilities::validate_config($config);

    return array(
        'valid' => empty($errors),
        'errors' => $errors,
        'config' => $config
    );
}

/**
 * Generate a new block from template
 */
function dgb_generate_block($name, $title, $options = array())
{
    return BlockUtilities::generate_template($name, $title, $options);
}