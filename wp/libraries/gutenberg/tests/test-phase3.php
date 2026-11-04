<?php
/**
 * Test Suite for Phase 3: Pattern Bridge Tooling, Import/Export, and WAL Integration
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Minimal WordPress function stubs for testing outside full WP runtime
$GLOBALS['mock_wp_blocks'] = [];
$GLOBALS['mock_meta'] = [];
$GLOBALS['mock_terms'] = [];

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

        foreach ($GLOBALS['mock_wp_blocks'] as $id => $post) {
            if ($post->post_type !== ($args['post_type'] ?? 'post')) {
                continue;
            }
            if ($target_key) {
                $actual_val = $GLOBALS['mock_meta'][$id][$target_key] ?? null;
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
        static $auto_id = 500;
        $id = ++$auto_id;
        $post = (object)$post_data;
        $post->ID = $id;
        $GLOBALS['mock_wp_blocks'][$id] = $post;

        if (!empty($post_data['meta_input'])) {
            foreach ($post_data['meta_input'] as $mk => $mv) {
                $GLOBALS['mock_meta'][$id][$mk] = $mv;
            }
        }
        return $id;
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post($post_data, $wp_error = false) {
        $id = $post_data['ID'];
        if (isset($GLOBALS['mock_wp_blocks'][$id])) {
            foreach ($post_data as $k => $v) {
                $GLOBALS['mock_wp_blocks'][$id]->$k = $v;
            }
            if (!empty($post_data['meta_input'])) {
                foreach ($post_data['meta_input'] as $mk => $mv) {
                    $GLOBALS['mock_meta'][$id][$mk] = $mv;
                }
            }
            return $id;
        }
        return new WP_Error("Post not found");
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key, $single = false) {
        return $GLOBALS['mock_meta'][$id][$key] ?? '';
    }
}

if (!function_exists('wp_set_object_terms')) {
    function wp_set_object_terms($id, $terms, $taxonomy) {
        $GLOBALS['mock_terms'][$id][$taxonomy] = (array)$terms;
        return true;
    }
}

if (!function_exists('wp_get_object_terms')) {
    function wp_get_object_terms($id, $taxonomy, $args = []) {
        return $GLOBALS['mock_terms'][$id][$taxonomy] ?? [];
    }
}

if (!function_exists('parse_blocks')) {
    function parse_blocks($content) {
        // Minimal parser stub for testing pattern resolution
        preg_match_all('/<!--\s+(wp:([a-z0-9_\-\/]+))\s+({.*?})\s+(\/-->|-->)/s', $content, $matches, PREG_SET_ORDER);
        $blocks = [];
        foreach ($matches as $m) {
            $block_name = $m[2];
            $attrs = json_decode(stripslashes($m[3]), true) ?: [];
            $blocks[] = [
                'blockName'    => $block_name,
                'attrs'        => $attrs,
                'innerBlocks'  => [],
                'innerHTML'    => '',
                'innerContent' => []
            ];
        }
        return $blocks;
    }
}

// Load Phase 1 classes
require_once dirname(__DIR__) . '/lib/class-pattern-bridge.php';
require_once dirname(__DIR__) . '/lib/class-wal-engine.php';

function assert_true($condition, $message) {
    if (!$condition) {
        echo "❌ FAIL: $message\n";
        exit(1);
    }
    echo "✅ PASS: $message\n";
}

echo "=== Running Phase 3 Verification Test Suite ===\n\n";

// 1. Validate Fixture File
echo "1. Checking Pattern Pack Fixture (patterns.json)...\n";
$fixture_path = dirname(__DIR__) . '/fixtures/patterns.json';
assert_true(file_exists($fixture_path), "patterns.json fixture exists on disk");

$manifest = json_decode(file_get_contents($fixture_path), true);
assert_true(is_array($manifest) && count($manifest) >= 4, "patterns.json contains at least 4 patterns");

$fixture_slugs = array_column($manifest, 'slug');
assert_true(in_array('pricing.3_tier_standard', $fixture_slugs), "Fixture includes 'pricing.3_tier_standard'");
assert_true(in_array('hero.standard_conversion', $fixture_slugs), "Fixture includes 'hero.standard_conversion'");
assert_true(in_array('features.3_col_grid', $fixture_slugs), "Fixture includes 'features.3_col_grid'");
assert_true(in_array('faq.accordion_standard', $fixture_slugs), "Fixture includes 'faq.accordion_standard'");

// 2. Import Pattern Pack via Bridge
echo "\n2. Testing Pattern Import via AWX_Pattern_Bridge::import_bundle()...\n";
$import_res = \aw2\gutenberg_blocks\AWX_Pattern_Bridge::import_bundle($manifest);

assert_true($import_res['created'] === count($manifest), "All patterns created on initial import");
assert_true($import_res['updated'] === 0, "Zero patterns updated on fresh import");
assert_true(empty($import_res['errors']), "Zero import errors reported");

// 3. Verify Metadata & Storage Contracts
echo "\n3. Checking Metadata Contracts on Imported Patterns...\n";
foreach ($GLOBALS['mock_wp_blocks'] as $id => $post) {
    assert_true($post->post_type === 'wp_block', "Post #$id is in 'wp_block' post type");

    $sync_status = get_post_meta($id, 'wp_pattern_sync_status', true);
    assert_true($sync_status === 'unsynced', "Pattern #$id has wp_pattern_sync_status = 'unsynced'");

    $is_managed = get_post_meta($id, \aw2\gutenberg_blocks\AWX_Pattern_Bridge::META_MANAGED, true);
    assert_true((int)$is_managed === 1, "Pattern #$id is marked _awx_pattern_managed = 1");

    $pattern_slug = get_post_meta($id, \aw2\gutenberg_blocks\AWX_Pattern_Bridge::META_SLUG, true);
    assert_true(!empty($pattern_slug), "Pattern #$id has explicit _awx_pattern_slug: '$pattern_slug'");

    $categories = wp_get_object_terms($id, 'wp_pattern_category');
    assert_true(!empty($categories), "Pattern #$id has terms assigned in 'wp_pattern_category'");
}

// 4. Test Idempotent Re-Import
echo "\n4. Testing Idempotent Re-Import...\n";
$reimport_res = \aw2\gutenberg_blocks\AWX_Pattern_Bridge::import_bundle($manifest);
assert_true($reimport_res['created'] === 0, "Zero new posts created on re-import");
assert_true($reimport_res['updated'] === count($manifest), "All existing patterns updated idempotently");
assert_true(empty($reimport_res['errors']), "Zero re-import errors");

// 5. Test Pattern Export
echo "\n5. Testing Pattern Export via AWX_Pattern_Bridge::export_bundle()...\n";
$exported = \aw2\gutenberg_blocks\AWX_Pattern_Bridge::export_bundle();
assert_true(count($exported) === count($manifest), "Export returned exact count of managed patterns");
assert_true(!empty($exported[0]['slug']), "Exported pattern contains slug");
assert_true(!empty($exported[0]['content']), "Exported pattern contains content");
assert_true(!empty($exported[0]['categories']), "Exported pattern contains categories");

// 6. Test WAL Transpiler Pattern Resolution & Overrides
echo "\n6. Testing WAL Transpiler Pattern Resolution & Overrides...\n";
$wal_node_with_pattern = [
    [
        'pattern'   => 'pricing.3_tier_standard',
        'overrides' => [
            'content.badge' => 'Transparent Tiers',
            'style.columns' => '4'
        ]
    ]
];

$ast = \aw2\gutenberg_blocks\WAL_Transpiler::transpile_nodes($wal_node_with_pattern);

assert_true(count($ast) >= 1, "Pattern expanded into block AST nodes");
assert_true($ast[0]['blockName'] === 'dgb/awx-pricing', "Resolved root block is dgb/awx-pricing");
assert_true($ast[0]['attrs']['content.badge'] === 'Transparent Tiers', "Override 'content.badge' applied to root pattern block");
assert_true($ast[0]['attrs']['style.columns'] === '4', "Override 'style.columns' applied to root pattern block");

echo "\n🎉 All Phase 3 tests passed successfully!\n";
