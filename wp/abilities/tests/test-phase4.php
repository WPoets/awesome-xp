<?php
/**
 * Test Suite for Phase 4: Abilities Registry & Agent MCP Integration
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Minimal WordPress function stubs for testing
$GLOBALS['mock_posts_store'] = [];
$GLOBALS['mock_meta_store'] = [];

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key));
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        $title = strtolower(preg_replace('/[^a-z0-9_\-]/', '-', $title));
        return trim(preg_replace('/-+/', '-', $title), '-');
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}
if (!function_exists('wp_slash')) {
    function wp_slash($value) {
        return addslashes($value);
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return ($thing instanceof \WP_Error);
    }
}
if (!function_exists('current_time')) {
    function current_time($type) {
        return date('Y-m-d H:i:s');
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 1;
    }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['mock_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $val, $autoload = null) {
        $GLOBALS['mock_options'][$name] = $val;
        return true;
    }
}
if (!function_exists('get_permalink')) {
    function get_permalink($id) {
        return 'https://example.com/?p=' . $id;
    }
}
if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link($id, $context = 'display') {
        return 'https://example.com/wp-admin/post.php?post=' . $id . '&action=edit';
    }
}
if (!function_exists('add_action')) {
    function add_action($tag, $cb, $p = 10) {}
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $val) { return $val; }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return true; }
}
if (!function_exists('set_transient')) {
    function set_transient($k, $v, $ttl = 0) { return true; }
}

class WP_Error {
    private $message;
    public function __construct($message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

class WP_Query {
    public $posts = [];
    public function __construct($args = []) {
        $meta_query = $args['meta_query'] ?? [];
        $target_key = $meta_query[0]['key'] ?? '';
        $target_val = $meta_query[0]['value'] ?? '';

        foreach ($GLOBALS['mock_posts_store'] as $id => $post) {
            if ($post->post_type !== ($args['post_type'] ?? 'post')) {
                continue;
            }
            if ($target_key) {
                $actual_val = $GLOBALS['mock_meta_store'][$id][$target_key] ?? null;
                if ($actual_val != $target_val) {
                    continue;
                }
            }
            $this->posts[] = $post;
            if (!empty($args['posts_per_page']) && $args['posts_per_page'] === 1) {
                break;
            }
        }
    }
    public function have_posts() { return !empty($this->posts); }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post($post_data, $wp_error = false) {
        static $auto_id = 1000;
        $id = ++$auto_id;
        $post = (object)$post_data;
        $post->ID = $id;
        $post->post_status = $post_data['post_status'] ?? 'publish';
        $post->post_title = $post_data['post_title'] ?? '';
        $post->post_name = $post_data['post_name'] ?? ('page-' . $id);
        $post->post_content = $post_data['post_content'] ?? '';
        $post->post_type = $post_data['post_type'] ?? 'page';

        $GLOBALS['mock_posts_store'][$id] = $post;

        if (!empty($post_data['meta_input'])) {
            foreach ($post_data['meta_input'] as $mk => $mv) {
                $GLOBALS['mock_meta_store'][$id][$mk] = $mv;
            }
        }
        return $id;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key, $single = false) {
        return $GLOBALS['mock_meta_store'][$id][$key] ?? '';
    }
}
if (!function_exists('get_post')) {
    function get_post($id) {
        return $GLOBALS['mock_posts_store'][$id] ?? null;
    }
}

if (!function_exists('serialize_blocks')) {
    function serialize_blocks($blocks) {
        $output = '';
        foreach ($blocks as $b) {
            $name = $b['blockName'];
            $attrs = json_encode($b['attrs'] ?? []);
            if (!empty($b['innerBlocks'])) {
                $inner = serialize_blocks($b['innerBlocks']);
                $output .= "<!-- wp:{$name} {$attrs} -->\n{$inner}\n<!-- /wp:{$name} -->\n";
            } else {
                $output .= "<!-- wp:{$name} {$attrs} /-->\n";
            }
        }
        return trim($output);
    }
}

if (!function_exists('parse_blocks')) {
    function parse_blocks($content) {
        $pattern = '/<!--\s+(\/?wp:[a-z0-9_\-\/]+)(?:\s+(\{.*?\}))?\s*(\/?)-->/s';
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $root = [];
        $stack = [];

        foreach ($matches as $match) {
            $tag = $match[1];
            $attrs_raw = isset($match[2]) ? $match[2] : '';
            $is_self_closing = !empty($match[3]);

            if (strpos($tag, '/wp:') === 0) {
                if (!empty($stack)) {
                    $closed_block = array_pop($stack);
                    if (empty($stack)) {
                        $root[] = $closed_block;
                    } else {
                        $stack[count($stack) - 1]['innerBlocks'][] = $closed_block;
                    }
                }
            } else {
                $block_name = substr($tag, 3);
                $attrs = !empty($attrs_raw) ? (json_decode(stripslashes($attrs_raw), true) ?: []) : [];
                $block = [
                    'blockName'    => $block_name,
                    'attrs'        => $attrs,
                    'innerBlocks'  => [],
                    'innerHTML'    => '',
                    'innerContent' => []
                ];

                if ($is_self_closing) {
                    if (empty($stack)) {
                        $root[] = $block;
                    } else {
                        $stack[count($stack) - 1]['innerBlocks'][] = $block;
                    }
                } else {
                    $stack[] = $block;
                }
            }
        }

        while (!empty($stack)) {
            $closed = array_pop($stack);
            if (empty($stack)) {
                $root[] = $closed;
            } else {
                $stack[count($stack) - 1]['innerBlocks'][] = $closed;
            }
        }

        return $root;
    }
}

// Load Abilities Subsystem
require_once dirname(__DIR__) . '/abilities-init.php';

function assert_true($condition, $message) {
    if (!$condition) {
        echo "❌ FAIL: $message\n";
        exit(1);
    }
    echo "✅ PASS: $message\n";
}

echo "=== Running Phase 4 Verification Test Suite ===\n\n";

// 1. Registry Inspection
echo "1. Checking Categories & Abilities in Registry...\n";
$registry = \aw2\abilities\Abilities_Registry::instance();
$reflection = new ReflectionClass($registry);
$abilities_prop = $reflection->getProperty('abilities');
$abilities = $abilities_prop->getValue($registry);

$categories_prop = $reflection->getProperty('categories');
$categories = $categories_prop->getValue($registry);

// Check category
assert_true(isset($categories['awesome-wal']), "Category 'awesome-wal' registered");
assert_true(isset($categories['awesome-patterns']), "Category 'awesome-patterns' registered");

// Check ability registrations
assert_true(isset($abilities['awesome/pattern-export']), "'awesome/pattern-export' registered");
assert_true(isset($abilities['awesome/pattern-import']), "'awesome/pattern-import' registered");
assert_true(isset($abilities['awesome/wal-compile']), "'awesome/wal-compile' registered");
assert_true(isset($abilities['awesome/wal-decompile']), "'awesome/wal-decompile' registered");

// Verify Schema & Operations
assert_true($abilities['awesome/pattern-export']['operation'] === \aw2\abilities\operations\patterns\Pattern_Export::class, "pattern-export mapped to Pattern_Export");
assert_true($abilities['awesome/pattern-import']['operation'] === \aw2\abilities\operations\patterns\Pattern_Import::class, "pattern-import mapped to Pattern_Import");
assert_true($abilities['awesome/wal-compile']['operation'] === \aw2\abilities\operations\wal\WAL_Compile::class, "wal-compile mapped to WAL_Compile");
assert_true($abilities['awesome/wal-decompile']['operation'] === \aw2\abilities\operations\wal\WAL_Decompile::class, "wal-decompile mapped to WAL_Decompile");

// 2. Test Pattern Import Ability
echo "\n2. Testing 'awesome/pattern-import' Execution...\n";
$import_op = new \aw2\abilities\operations\patterns\Pattern_Import();

// Validation: missing manifest
$err_res = $import_op->execute([]);
assert_true(!$err_res['success'] && $err_res['error']['code'] === 'missing_manifest', "Rejects missing manifest");

// Execute import via manifest array
$manifest = [
    [
        'slug'       => 'test.hero_banner',
        'title'      => 'Test Hero Banner',
        'categories' => ['featured'],
        'content'    => '<!-- wp:dgb/awx-hero {"content.title":"Hero"} /-->'
    ]
];
$res_import = $import_op->execute(['manifest' => $manifest]);
assert_true($res_import['success'] === true, "Pattern_Import succeeded");
assert_true($res_import['data']['created'] === 1, "Reported 1 pattern created");

// 3. Test Pattern Export Ability
echo "\n3. Testing 'awesome/pattern-export' Execution...\n";
$export_op = new \aw2\abilities\operations\patterns\Pattern_Export();
$res_export = $export_op->execute([]);
assert_true($res_export['success'] === true, "Pattern_Export succeeded");
assert_true($res_export['data']['count'] >= 1, "Exported at least 1 pattern");

// 4. Test WAL Compile Ability
echo "\n4. Testing 'awesome/wal-compile' Execution...\n";
$compile_op = new \aw2\abilities\operations\wal\WAL_Compile();

// Validation: missing WAL payload
$err_compile = $compile_op->execute([]);
assert_true(!$err_compile['success'] && $err_compile['error']['code'] === 'missing_wal', "Rejects missing WAL parameter");

// Valid WAL payload compilation
$wal_doc = [
    'page' => [
        'title'    => 'Modern Enterprise Compute',
        'slug'     => 'enterprise-compute',
        'status'   => 'publish',
        'sections' => [
            [
                'block' => 'awx-section',
                'attrs' => ['style.surface' => 'surface'],
                'inner' => [
                    [
                        'block' => 'awx-hero',
                        'attrs' => [
                            'content.badge' => 'Next-Gen Cloud',
                            'content.title' => 'Deterministic Compute for AI Teams',
                            'style.align'   => 'is-aligned-left'
                        ]
                    ]
                ]
            ],
            [
                'block' => 'awx-section',
                'attrs' => ['style.surface' => 'surface-sunken'],
                'inner' => [
                    [
                        'block' => 'awx-feature-grid',
                        'attrs' => ['style.columns' => '3']
                    ]
                ]
            ]
        ]
    ]
];

$res_compile = $compile_op->execute(['wal' => $wal_doc]);
assert_true($res_compile['success'] === true, "WAL_Compile succeeded");
assert_true(!empty($res_compile['data']['post_id']), "Returned post_id");
assert_true($res_compile['data']['slug'] === 'enterprise-compute', "Returned slug");
assert_true(!empty($res_compile['data']['url']), "Returned page url");

$created_post_id = $res_compile['data']['post_id'];

// 5. Test WAL Decompile Ability
echo "\n5. Testing 'awesome/wal-decompile' Execution...\n";
$decompile_op = new \aw2\abilities\operations\wal\WAL_Decompile();

// Validation: invalid post_id
$err_decompile = $decompile_op->execute(['post_id' => 0]);
assert_true(!$err_decompile['success'] && $err_decompile['error']['code'] === 'invalid_post_id', "Rejects post_id <= 0");

// Decompile compiled page
$res_decompile = $decompile_op->execute(['post_id' => $created_post_id]);
assert_true($res_decompile['success'] === true, "WAL_Decompile succeeded");
assert_true(isset($res_decompile['data']['page']['sections']), "Decompiled WAL contains sections");

$decompiled_sections = $res_decompile['data']['page']['sections'];
assert_true(count($decompiled_sections) === 2, "Decompiled exactly 2 sections");
assert_true($decompiled_sections[0]['block'] === 'awx-section', "First section block is awx-section");
assert_true($decompiled_sections[0]['inner'][0]['block'] === 'awx-hero', "Nested hero block is awx-hero");
assert_true($decompiled_sections[0]['inner'][0]['attrs']['content.title'] === 'Deterministic Compute for AI Teams', "Hero title preserved");

echo "\n🎉 All Phase 4 tests passed successfully!\n";
