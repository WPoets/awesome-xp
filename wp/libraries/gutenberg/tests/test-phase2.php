<?php
/**
 * Test Suite for Phase 2: Core 12 Block SFC Specifications & Seeder
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Minimal WordPress function stubs for testing outside full WP runtime
$GLOBALS['mock_db_posts'] = [];

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
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return ($thing instanceof \WP_Error);
    }
}
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($post_data, $wp_error = false) {
        static $auto_id = 100;
        $id = ++$auto_id;
        $post = (object)$post_data;
        $post->ID = $id;
        $GLOBALS['mock_db_posts'][$post_data['post_name']] = $post;
        return $id;
    }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post($post_data, $wp_error = false) {
        $name = isset($post_data['post_name']) ? $post_data['post_name'] : '';
        if (isset($GLOBALS['mock_db_posts'][$name])) {
            foreach ($post_data as $k => $v) {
                $GLOBALS['mock_db_posts'][$name]->$k = $v;
            }
            return $post_data['ID'];
        }
        return $post_data['ID'];
    }
}
if (!function_exists('get_posts')) {
    function get_posts($args = []) {
        $name = $args['name'] ?? '';
        if (!empty($name) && isset($GLOBALS['mock_db_posts'][$name])) {
            return [$GLOBALS['mock_db_posts'][$name]];
        }
        return [];
    }
}

// Load seeder
require_once dirname(__DIR__) . '/lib/class-core-blocks-seeder.php';

function assert_true($condition, $message) {
    if (!$condition) {
        echo "❌ FAIL: $message\n";
        exit(1);
    }
    echo "✅ PASS: $message\n";
}

echo "=== Running Phase 2 Verification Test Suite ===\n\n";

// 1. Verify 12 Core Blocks Count & Slugs
echo "1. Checking Core 12 Block Registry...\n";
$blocks = \aw2\gutenberg_blocks\AWX_Core_Blocks_Seeder::get_blocks();
assert_true(count($blocks) === 12, "Exactly 12 core blocks defined");

$expected_slugs = [
    'awx-section',
    'awx-hero',
    'awx-split',
    'awx-feature-grid',
    'awx-post-grid',
    'awx-pricing',
    'awx-accordion',
    'awx-testimonials',
    'awx-cta',
    'awx-stats',
    'awx-logo-cloud',
    'awx-steps'
];

foreach ($expected_slugs as $expected_slug) {
    assert_true(isset($blocks[$expected_slug]), "Block '$expected_slug' is registered");
}

// 2. Validate SFC Structure for Every Block
echo "\n2. Validating SFC Templates (register, controls, styles, render)...\n";
foreach ($blocks as $slug => $data) {
    $sfc = $data['sfc'];

    // Must have [templates.add register]
    assert_true(
        strpos($sfc, '[templates.add register]') !== false && strpos($sfc, '[gt_blocks.register]') !== false,
        "Block '$slug' defines [templates.add register]"
    );

    // Must have [templates.add controls]
    assert_true(
        strpos($sfc, '[templates.add controls]') !== false && strpos($sfc, '[arr.create') !== false,
        "Block '$slug' defines [templates.add controls]"
    );

    // Must have [templates.add styles] wrapped in @layer blocks
    assert_true(
        strpos($sfc, '[templates.add styles]') !== false && strpos($sfc, '@layer blocks') !== false,
        "Block '$slug' defines scoped styles inside @layer blocks"
    );

    // Scoped CSS class check
    assert_true(
        strpos($sfc, ".wp-block-dgb-{$slug}") !== false,
        "Block '$slug' styles target .wp-block-dgb-{$slug}"
    );

    // Must have [templates.add render]
    assert_true(
        strpos($sfc, '[templates.add render]') !== false,
        "Block '$slug' defines [templates.add render]"
    );

    // Must have [template.get wp.wrapper_attributes /] on root tag
    assert_true(
        strpos($sfc, '[template.get wp.wrapper_attributes /]') !== false,
        "Block '$slug' uses [template.get wp.wrapper_attributes /]"
    );

    // Check service hooks in register
    assert_true(
        strpos($sfc, "gb_blocks.{$slug}.render") !== false,
        "Block '$slug' hooks gb_blocks.{$slug}.render"
    );
    assert_true(
        strpos($sfc, "gb_blocks.{$slug}.controls") !== false,
        "Block '$slug' hooks gb_blocks.{$slug}.controls"
    );
    assert_true(
        strpos($sfc, "gb_blocks.{$slug}.styles") !== false,
        "Block '$slug' hooks gb_blocks.{$slug}.styles"
    );
}

// 3. Verify Specific Block Contracts
echo "\n3. Checking Specific Block Contracts...\n";

// Block 1: awx-section InnerBlocks slot
assert_true(
    strpos($blocks['awx-section']['sfc'], 'content.innerblocks') !== false,
    "awx-section defines content.innerblocks"
);

// Block 2: awx-hero buttons slot & typography
assert_true(
    strpos($blocks['awx-hero']['sfc'], 'content.badge') !== false &&
    strpos($blocks['awx-hero']['sfc'], 'content.title') !== false &&
    strpos($blocks['awx-hero']['sfc'], 'content.innerblocks') !== false,
    "awx-hero defines badge, title, and actions innerblocks"
);

// Block 5: awx-post-grid dynamic query
assert_true(
    strpos($blocks['awx-post-grid']['sfc'], 'mysqli.multi.fetch.rows') !== false,
    "awx-post-grid uses mysqli.multi.fetch.rows for query execution"
);

// Block 7: awx-accordion semantic details/summary
assert_true(
    strpos($blocks['awx-accordion']['sfc'], '<details') !== false &&
    strpos($blocks['awx-accordion']['sfc'], '<summary') !== false,
    "awx-accordion implements semantic zero-JS details/summary tags"
);

// Block 10: awx-stats KPI metrics loop
assert_true(
    strpos($blocks['awx-stats']['sfc'], 'wp-block-awx-stat-value') !== false,
    "awx-stats renders stat values and labels"
);

// Block 11: awx-logo-cloud partner images
assert_true(
    strpos($blocks['awx-logo-cloud']['sfc'], 'grayscale(100%)') !== false,
    "awx-logo-cloud defines grayscale filter CSS"
);

// Block 12: awx-steps counter
assert_true(
    strpos($blocks['awx-steps']['sfc'], 'wp-block-awx-step-counter') !== false,
    "awx-steps renders numbered counter step elements"
);

// 4. Test Seeder Mock Run
echo "\n4. Testing Seeder Execution Logic...\n";
$seed_res = \aw2\gutenberg_blocks\AWX_Core_Blocks_Seeder::seed();
assert_true($seed_res['seeded'] === 12, "Initial run successfully seeded 12 blocks");
assert_true($seed_res['skipped'] === 0, "Initial run skipped 0 blocks");
assert_true(empty($seed_res['errors']), "Initial run had zero errors");

// Idempotent run without force
$repeat_res = \aw2\gutenberg_blocks\AWX_Core_Blocks_Seeder::seed(false);
assert_true($repeat_res['seeded'] === 0, "Repeat run seeded 0 new blocks");
assert_true($repeat_res['skipped'] === 12, "Repeat run skipped all 12 existing blocks");

// Force update run
$force_res = \aw2\gutenberg_blocks\AWX_Core_Blocks_Seeder::seed(true);
assert_true($force_res['updated'] === 12, "Force update successfully updated all 12 blocks");
assert_true($force_res['seeded'] === 0, "Force update seeded 0 new blocks");

echo "\n🎉 All Phase 2 tests passed successfully!\n";
