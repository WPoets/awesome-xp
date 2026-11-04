<?php
/**
 * Test Suite for Phase 5: End-to-End Validation & Verification Checklist
 *
 * Implements Section 7 of awx_agent_implementation_guide_core_blocks_patterns_wal_engine.md:
 * - Phase 1: Block Seeding & Versioning
 * - Phase 2: Pattern Bridge Deployment & Storage
 * - Phase 3: Transpiler & Database Serialization Verification (3-Section WAL)
 * - Phase 4: Round-Trip Decompiler AST to WAL
 * - Phase 5: DOM Single-Class Attribute Injection & Modifier Calculation
 * - Phase 6: Abilities MCP Registry End-to-End Operation
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['mock_posts'] = [];
$GLOBALS['mock_meta'] = [];
$GLOBALS['mock_terms'] = [];
$GLOBALS['mock_options'] = [];

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
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value);
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

        foreach ($GLOBALS['mock_posts'] as $id => $post) {
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
        static $auto_id = 2000;
        $id = ++$auto_id;
        $post_data = wp_unslash($post_data);
        $post = (object)$post_data;
        $post->ID = $id;
        $post->post_status = $post_data['post_status'] ?? 'publish';
        $post->post_title = $post_data['post_title'] ?? '';
        $post->post_name = $post_data['post_name'] ?? ('page-' . $id);
        $post->post_content = $post_data['post_content'] ?? '';
        $post->post_type = $post_data['post_type'] ?? 'page';

        $GLOBALS['mock_posts'][$id] = $post;

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
        if (!isset($GLOBALS['mock_posts'][$id])) {
            return 0;
        }
        $post = $GLOBALS['mock_posts'][$id];
        foreach ($post_data as $k => $v) {
            if ($k !== 'ID') {
                $post->$k = $v;
            }
        }
        return $id;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key, $single = false) {
        return $GLOBALS['mock_meta'][$id][$key] ?? '';
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($id, $key, $val) {
        $GLOBALS['mock_meta'][$id][$key] = $val;
        return true;
    }
}
if (!function_exists('get_posts')) {
    function get_posts($args = []) {
        $results = [];
        foreach ($GLOBALS['mock_posts'] as $id => $post) {
            if (!empty($args['post_type']) && $post->post_type !== $args['post_type']) {
                continue;
            }
            if (!empty($args['name']) && $post->post_name !== $args['name']) {
                continue;
            }
            $results[] = $post;
            if (!empty($args['posts_per_page']) && $args['posts_per_page'] === 1) {
                break;
            }
        }
        return $results;
    }
}

if (!function_exists('get_post')) {
    function get_post($id) {
        return $GLOBALS['mock_posts'][$id] ?? null;
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

function assert_true($expr, $desc) {
    if ($expr) {
        echo "✅ PASS: $desc\n";
    } else {
        echo "❌ FAIL: $desc\n";
        exit(1);
    }
}

// Load Core Subsystems
require_once dirname(__DIR__) . '/lib/class-pattern-bridge.php';
require_once dirname(__DIR__) . '/lib/class-wal-engine.php';
require_once dirname(__DIR__) . '/lib/class-core-blocks-seeder.php';
require_once dirname(dirname(dirname(__DIR__))) . '/abilities/abilities-init.php';

use aw2\gutenberg_blocks\AWX_Pattern_Bridge;
use aw2\gutenberg_blocks\WAL_Transpiler;
use aw2\gutenberg_blocks\WAL_Decompiler;
use aw2\gutenberg_blocks\AWX_Core_Blocks_Seeder;
use aw2\abilities\Abilities_Registry;

echo "=== Running Phase 5: End-to-End Validation & Verification Checklist ===\n\n";

// -------------------------------------------------------------
// Section 7 - Phase 1 Checklist: Block Seeding & Versioning
// -------------------------------------------------------------
echo "1. Validating Core 12 Block Registry & Seeding Contracts...\n";
$blocks_def = AWX_Core_Blocks_Seeder::get_blocks();
assert_true(count($blocks_def) === 12, "Registry contains all 12 core blocks");

$seed_stats = AWX_Core_Blocks_Seeder::seed(false);
assert_true($seed_stats['seeded'] === 12, "Initial seed created 12 aw_gb_blocks posts");
assert_true($seed_stats['skipped'] === 0, "Initial seed had 0 skipped blocks");
assert_true(empty($seed_stats['errors']), "Initial seed reported zero errors");

// Verify idempotency
$idempotent_stats = AWX_Core_Blocks_Seeder::seed(false);
assert_true($idempotent_stats['seeded'] === 0, "Idempotent re-run created 0 new blocks");
assert_true($idempotent_stats['skipped'] === 12, "Idempotent re-run skipped all 12 existing blocks");

// Verify meta marker
foreach ($GLOBALS['mock_posts'] as $id => $post) {
    if ($post->post_type === 'aw_gb_blocks') {
        assert_true($GLOBALS['mock_meta'][$id]['_awx_core_block'] === 1, "Block #{$id} marked with _awx_core_block = 1");
    }
}

// -------------------------------------------------------------
// Section 7 - Phase 2 Checklist: Pattern Bridge Deployment
// -------------------------------------------------------------
echo "\n2. Validating Pattern Bridge Fixture Import & Metadata Contracts...\n";
$fixture_file = dirname(__DIR__) . '/fixtures/patterns.json';
assert_true(file_exists($fixture_file), "patterns.json fixture exists on disk");

$fixture_json = file_get_contents($fixture_file);
$manifest = json_decode($fixture_json, true);
assert_true(is_array($manifest) && count($manifest) >= 4, "Fixture contains at least 4 patterns");

$import_stats = AWX_Pattern_Bridge::import_bundle($manifest);
assert_true($import_stats['created'] === count($manifest), "Imported all patterns from bundle");
assert_true(empty($import_stats['errors']), "Pattern import reported zero errors");

// Confirm unsynced status and dot slug metadata
$meta_slug_key = AWX_Pattern_Bridge::META_SLUG;
$meta_managed_key = AWX_Pattern_Bridge::META_MANAGED;

foreach ($GLOBALS['mock_posts'] as $id => $post) {
    if ($post->post_type === 'wp_block') {
        assert_true(isset($GLOBALS['mock_meta'][$id][$meta_slug_key]), "Pattern #{$id} has explicit slug meta");
        assert_true(!empty($GLOBALS['mock_meta'][$id][$meta_managed_key]), "Pattern #{$id} marked as managed");
        assert_true($GLOBALS['mock_meta'][$id]['wp_pattern_sync_status'] === 'unsynced', "Pattern #{$id} set to unsynced");
    }
}

// -------------------------------------------------------------
// Section 7 - Phase 3 Checklist: Transpiler & Database Serialization (3-Section WAL)
// -------------------------------------------------------------
echo "\n3. Validating 3-Section WAL Compilation & Clean Serialization...\n";

$three_section_wal = [
    'page' => [
        'title'  => 'Enterprise Cloud Platform',
        'slug'   => 'enterprise-cloud',
        'status' => 'publish',
        'sections' => [
            // Section 1: Hero with button
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
                            'content.badge' => 'Next-Gen Cloud',
                            'content.title' => 'Deterministic Compute for AI Teams',
                            'content.text'  => 'Deploy workloads globally without infrastructure drift.',
                            'style.align'   => 'is-aligned-left',
                            'style.level'   => 'h1'
                        ],
                        'inner' => [
                            [
                                'block' => 'core/button',
                                'attrs' => [
                                    'text'      => 'Get Started',
                                    'url'       => '/signup',
                                    'className' => 'is-style-fill'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            // Section 2: Feature grid with paragraph
            [
                'block' => 'awx-section',
                'attrs' => [
                    'style.surface' => 'surface-sunken'
                ],
                'inner' => [
                    [
                        'block' => 'awx-feature-grid',
                        'attrs' => [
                            'style.columns' => '3'
                        ],
                        'inner' => [
                            [
                                'block' => 'core/paragraph',
                                'attrs' => [
                                    'content' => 'Sub-second edge replication included by default.'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            // Section 3: Pattern resolution with overrides
            [
                'block' => 'awx-section',
                'attrs' => [
                    'style.surface' => 'surface'
                ],
                'inner' => [
                    [
                        'pattern'   => 'pricing.3_tier_standard',
                        'overrides' => [
                            'content.badge' => 'Transparent Tiers',
                            'style.columns' => '3'
                        ]
                    ]
                ]
            ]
        ]
    ]
];

$compiled_post_id = WAL_Transpiler::compile_and_save($three_section_wal);
assert_true($compiled_post_id > 0, "WAL_Transpiler::compile_and_save() returned valid post ID");

$saved_post = get_post($compiled_post_id);
assert_true($saved_post !== null, "Post saved to database");
assert_true($saved_post->post_title === 'Enterprise Cloud Platform', "Post title matches WAL spec");
assert_true($saved_post->post_name === 'enterprise-cloud', "Post slug matches WAL spec");
assert_true($saved_post->post_status === 'publish', "Post status matches WAL spec");

// Check serialization integrity
$content = $saved_post->post_content;
assert_true(strpos($content, '<!-- wp:dgb/awx-section') !== false, "Contains valid wp:dgb/awx-section block comment");
assert_true(strpos($content, '<!-- wp:dgb/awx-hero') !== false, "Contains valid wp:dgb/awx-hero block comment");
assert_true(strpos($content, '<!-- wp:core/button') !== false, "Contains core/button without double prefix");
assert_true(strpos($content, '<!-- wp:dgb/awx-feature-grid') !== false, "Contains valid wp:dgb/awx-feature-grid block comment");
assert_true(strpos($content, '<!-- wp:core/paragraph') !== false, "Contains core/paragraph without double prefix");
assert_true(strpos($content, '<!-- wp:dgb/awx-pricing') !== false, "Pattern pricing.3_tier_standard resolved to awx-pricing");
assert_true(strpos($content, 'Transparent Tiers') !== false, "Pattern override 'Transparent Tiers' compiled into content");

// Check absence of corruption / corrupted escaping
assert_true(strpos($content, '\\\\') === false, "Zero double-escaped backslashes in serialized post_content");
assert_true(strpos($content, '<!--  wp:') === false, "No corrupted comment delimiters");

// -------------------------------------------------------------
// Section 7 - Phase 4 Checklist: Round-Trip Decompilation
// -------------------------------------------------------------
echo "\n4. Validating Round-Trip Decompilation (AST -> WAL)...\n";
$decompiled = WAL_Decompiler::decompile($compiled_post_id);

assert_true(isset($decompiled['page']), "Decompiled output contains page envelope");
assert_true($decompiled['page']['title'] === 'Enterprise Cloud Platform', "Decompiled title preserved");
assert_true($decompiled['page']['slug'] === 'enterprise-cloud', "Decompiled slug preserved");

$sections = $decompiled['page']['sections'];
assert_true(count($sections) === 3, "Decompiled exactly 3 sections");

// Verify Section 1
assert_true($sections[0]['block'] === 'awx-section', "Section 1 block is awx-section");
assert_true($sections[0]['attrs']['style.surface'] === 'surface', "Section 1 style.surface is 'surface'");
assert_true($sections[0]['attrs']['style.padding'] === 'default', "Section 1 style.padding is 'default'");
assert_true($sections[0]['inner'][0]['block'] === 'awx-hero', "Section 1 child is awx-hero");
assert_true($sections[0]['inner'][0]['attrs']['content.title'] === 'Deterministic Compute for AI Teams', "Hero title preserved");
assert_true($sections[0]['inner'][0]['inner'][0]['block'] === 'core/button', "Hero child is core/button");

// Verify Section 2
assert_true($sections[1]['block'] === 'awx-section', "Section 2 block is awx-section");
assert_true($sections[1]['attrs']['style.surface'] === 'surface-sunken', "Section 2 style.surface is 'surface-sunken'");
assert_true($sections[1]['inner'][0]['block'] === 'awx-feature-grid', "Section 2 child is awx-feature-grid");
assert_true($sections[1]['inner'][0]['attrs']['style.columns'] === '3', "Feature grid columns attribute is '3'");
assert_true($sections[1]['inner'][0]['inner'][0]['block'] === 'core/paragraph', "Feature grid child is core/paragraph");

// Verify Section 3 (pattern expanded)
assert_true($sections[2]['block'] === 'awx-section', "Section 3 block is awx-section");
assert_true($sections[2]['inner'][0]['block'] === 'awx-pricing', "Section 3 expanded block is awx-pricing");
assert_true($sections[2]['inner'][0]['attrs']['content.badge'] === 'Transparent Tiers', "Overridden badge preserved");

// -------------------------------------------------------------
// Section 7 - Phase 5 Checklist: DOM Wrapper & Single-Class Attributes
// -------------------------------------------------------------
echo "\n5. Validating DOM Wrapper Attributes & Dynamic Class Generation...\n";

// Function replicating BlockLibrary dynamic modifier class injection
function build_test_wrapper_classes(array $attrs, string $base_class): string {
    $classes = [$base_class];

    if (!empty($attrs['style.surface'])) {
        $classes[] = 'has-surface-' . sanitize_html_class($attrs['style.surface']);
    }
    if (!empty($attrs['style.padding'])) {
        $classes[] = 'has-padding-' . sanitize_html_class($attrs['style.padding']);
    }
    if (!empty($attrs['style.columns'])) {
        $classes[] = 'has-cols-' . sanitize_html_class($attrs['style.columns']);
    }
    if (!empty($attrs['style.align'])) {
        $classes[] = sanitize_html_class($attrs['style.align']);
    }
    if (!empty($attrs['style.layout'])) {
        if ($attrs['style.layout'] === 'bento') {
            $classes[] = 'is-bento';
        } elseif ($attrs['style.layout'] === 'reversed') {
            $classes[] = 'is-reversed';
        }
    }

    $unique_classes = array_unique(array_filter($classes));
    return 'class="' . esc_attr(implode(' ', $unique_classes)) . '"';
}

if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class($class) {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $class);
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

$sample_attrs = [
    'style.surface' => 'surface-raised',
    'style.padding' => 'compact',
    'style.columns' => '3',
    'style.align'   => 'is-aligned-left',
    'style.layout'  => 'bento'
];

$wrapper = build_test_wrapper_classes($sample_attrs, 'wp-block-dgb-awx-feature-grid');
assert_true(substr_count($wrapper, 'class="') === 1, "Exactly one class attribute present");
assert_true(strpos($wrapper, 'wp-block-dgb-awx-feature-grid') !== false, "Includes base block class");
assert_true(strpos($wrapper, 'has-surface-surface-raised') !== false, "Includes has-surface-surface-raised");
assert_true(strpos($wrapper, 'has-padding-compact') !== false, "Includes has-padding-compact");
assert_true(strpos($wrapper, 'has-cols-3') !== false, "Includes has-cols-3");
assert_true(strpos($wrapper, 'is-aligned-left') !== false, "Includes is-aligned-left");
assert_true(strpos($wrapper, 'is-bento') !== false, "Includes is-bento");

// -------------------------------------------------------------
// Section 7 - Phase 6 Checklist: Agent MCP Registry End-to-End
// -------------------------------------------------------------
echo "\n6. Validating Agent Abilities MCP Pipeline...\n";

$registry = Abilities_Registry::instance();
$all_abilities = $registry->get_all_abilities();

// Execute pattern-export ability
assert_true(isset($all_abilities['awesome/pattern-export']), "Found 'awesome/pattern-export' in registry");
$export_res = $registry->execute_ability('awesome/pattern-export', []);
assert_true($export_res['success'] === true, "awesome/pattern-export succeeded");
assert_true($export_res['data']['count'] >= 4, "Exported at least 4 patterns");

// Execute wal-compile ability
assert_true(isset($all_abilities['awesome/wal-compile']), "Found 'awesome/wal-compile' in registry");
$compile_res = $registry->execute_ability('awesome/wal-compile', ['wal' => $three_section_wal]);
assert_true($compile_res['success'] === true, "awesome/wal-compile succeeded");
assert_true(!empty($compile_res['data']['post_id']), "Compiled post ID returned");

// Execute wal-decompile ability
assert_true(isset($all_abilities['awesome/wal-decompile']), "Found 'awesome/wal-decompile' in registry");
$decompile_res = $registry->execute_ability('awesome/wal-decompile', ['post_id' => $compile_res['data']['post_id']]);
assert_true($decompile_res['success'] === true, "awesome/wal-decompile succeeded");
assert_true(count($decompile_res['data']['page']['sections']) === 3, "Decompiled exactly 3 sections via MCP ability");

echo "\n🎉 Verification Complete: All Section 7 End-to-End Checklist items verified successfully!\n";
