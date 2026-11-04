<?php
/**
 * Test Suite for Phase 1: AWX Pattern Bridge and WAL Engine
 */

// Define mock WP constants and functions if running outside WordPress
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Minimal WordPress function stubs for testing outside full WP runtime
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $raw_key = $key;
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key);
        return $key;
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9_\-]/', '-', $title);
        $title = preg_replace('/-+/', '-', $title);
        return trim($title, '-');
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
    public function __construct($args = []) {}
    public function have_posts() { return !empty($this->posts); }
}

// Load the Phase 1 classes
require_once dirname(__DIR__) . '/lib/class-pattern-bridge.php';
require_once dirname(__DIR__) . '/lib/class-wal-engine.php';

function assert_true($condition, $message) {
    if (!$condition) {
        echo "❌ FAIL: $message\n";
        exit(1);
    }
    echo "✅ PASS: $message\n";
}

echo "=== Running Phase 1 Verification Test Suite ===\n\n";

// 1. Verify Class Definitions and Aliases
echo "1. Checking Class Definitions & Aliases...\n";
assert_true(class_exists('AWX_Pattern_Bridge'), "Root AWX_Pattern_Bridge class exists");
assert_true(class_exists('aw2\gutenberg_blocks\AWX_Pattern_Bridge'), "Namespaced AWX_Pattern_Bridge class exists");
assert_true(class_exists('WAL_Transpiler'), "Root WAL_Transpiler class exists");
assert_true(class_exists('aw2\gutenberg_blocks\WAL_Transpiler'), "Namespaced WAL_Transpiler class exists");
assert_true(class_exists('WAL_Decompiler'), "Root WAL_Decompiler class exists");
assert_true(class_exists('aw2\gutenberg_blocks\WAL_Decompiler'), "Namespaced WAL_Decompiler class exists");

// 2. Verify Pattern Bridge Constants
echo "\n2. Checking Pattern Bridge Metadata Constants...\n";
assert_true(AWX_Pattern_Bridge::META_SLUG === '_awx_pattern_slug', "META_SLUG is '_awx_pattern_slug'");
assert_true(AWX_Pattern_Bridge::META_MANAGED === '_awx_pattern_managed', "META_MANAGED is '_awx_pattern_managed'");

// 3. Test Pattern Bridge Validation
echo "\n3. Testing Pattern Bridge Validation...\n";
$import_res = AWX_Pattern_Bridge::import_bundle([
    ['slug' => '', 'content' => 'test'],
    ['slug' => 'test-pattern', 'content' => '']
]);
assert_true(count($import_res['errors']) === 2, "Rejects empty slug or content");

// 4. Test WAL Transpiler AST Mapping
echo "\n4. Testing WAL Transpiler AST Node Mapping...\n";
$test_wal_sections = [
    [
        'block' => 'awx-section',
        'attrs' => [
            'style.surface' => 'surface',
            'style.padding' => 'default'
        ],
        'inner' => [
            [
                'block' => 'awx-hero',
                'attrs' => [
                    'content.title' => 'Test Hero Headline',
                    'content.badge' => 'Beta',
                    'style.level'   => 'h1'
                ],
                'inner' => [
                    [
                        'block' => 'core/button',
                        'attrs' => [
                            'text' => 'Click Here',
                            'url'  => 'https://example.com'
                        ]
                    ]
                ]
            ],
            [
                'block' => 'awx-feature-grid',
                'attrs' => [
                    'style.columns' => '3'
                ],
                'inner' => [
                    [
                        'block' => 'core/paragraph',
                        'attrs' => [
                            'content' => 'Feature card description.'
                        ]
                    ]
                ]
            ]
        ]
    ]
];

$ast = WAL_Transpiler::transpile_nodes($test_wal_sections);

assert_true(count($ast) === 1, "Top-level section emitted");
assert_true($ast[0]['blockName'] === 'dgb/awx-section', "awx-section auto-prefixed with dgb/");
assert_true($ast[0]['attrs']['style.surface'] === 'surface', "Flat attributes preserved in section");
assert_true(count($ast[0]['innerBlocks']) === 2, "Section contains 2 inner blocks");

$hero_ast = $ast[0]['innerBlocks'][0];
assert_true($hero_ast['blockName'] === 'dgb/awx-hero', "awx-hero auto-prefixed with dgb/");
assert_true($hero_ast['attrs']['content.title'] === 'Test Hero Headline', "Hero attributes preserved");
assert_true(count($hero_ast['innerBlocks']) === 1, "Hero contains 1 inner block");

$btn_ast = $hero_ast['innerBlocks'][0];
assert_true($btn_ast['blockName'] === 'core/button', "core/button keeps core/ prefix without duplicate dgb/");
assert_true($btn_ast['attrs']['text'] === 'Click Here', "Button attrs preserved");

$grid_ast = $ast[0]['innerBlocks'][1];
assert_true($grid_ast['blockName'] === 'dgb/awx-feature-grid', "awx-feature-grid auto-prefixed");
assert_true($grid_ast['innerBlocks'][0]['blockName'] === 'core/paragraph', "core/paragraph keeps core/ prefix");

// 5. Test WAL Decompiler Round-Trip
echo "\n5. Testing WAL Decompiler (AST -> WAL)...\n";
$recovered_wal = WAL_Decompiler::ast_to_wal($ast);

assert_true(count($recovered_wal) === 1, "Recovered 1 top-level section");
assert_true($recovered_wal[0]['block'] === 'awx-section', "Stripped dgb/ prefix back to awx-section");
assert_true($recovered_wal[0]['attrs']['style.surface'] === 'surface', "Recovered section attributes");
assert_true(count($recovered_wal[0]['inner']) === 2, "Recovered 2 section inner nodes");

$recovered_hero = $recovered_wal[0]['inner'][0];
assert_true($recovered_hero['block'] === 'awx-hero', "Recovered awx-hero without dgb/ prefix");
assert_true($recovered_hero['attrs']['content.title'] === 'Test Hero Headline', "Recovered hero attributes");

$recovered_btn = $recovered_hero['inner'][0];
assert_true($recovered_btn['block'] === 'core/button', "Recovered core/button unchanged");

// 6. Test Empty AST Handling
echo "\n6. Testing Decompiler with Whitespace/Empty Delimiter Blocks...\n";
$dirty_ast = [
    ['blockName' => null, 'attrs' => [], 'innerBlocks' => []], // Gutenberg blank delimiter
    $ast[0],
    ['blockName' => '', 'attrs' => [], 'innerBlocks' => []]
];
$cleaned_wal = WAL_Decompiler::ast_to_wal($dirty_ast);
assert_true(count($cleaned_wal) === 1, "Empty/whitespace delimiter blocks filtered out");
assert_true($cleaned_wal[0]['block'] === 'awx-section', "Actual block retained");

echo "\n🎉 All Phase 1 tests passed successfully!\n";
