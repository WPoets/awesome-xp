<?php
/**
 * Test Suite for AWX Sync Release_Store (audit trail & release ledger).
 *
 * Exercises:
 *  1. Release_Store::start()
 *  2. Release_Store::complete()
 *  3. OOM protection: recent() excludes bulky items JSON
 *  4. Single-release get() includes items
 *  5. Filtering by kind and status
 *  6. 3-tier pruning: items stripping (30d), row retention (90d), count cap
 *  7. Idempotent upsert via record()
 */

require_once __DIR__ . '/stubs.php';
require_once dirname(__DIR__) . '/lib/class-release-store.php';

use aw2\sync\Release_Store;

function assert_true($condition, $message) {
    if (!$condition) {
        echo "❌ FAIL: $message\n";
        exit(1);
    }
    echo "✅ PASS: $message\n";
}

function assert_equals($expected, $actual, $message) {
    if ($expected !== $actual) {
        $exp_str = var_export($expected, true);
        $act_str = var_export($actual, true);
        echo "❌ FAIL: $message (expected: $exp_str, got: $act_str)\n";
        exit(1);
    }
    echo "✅ PASS: $message\n";
}

echo "=== Running AWX Sync Release_Store Test Suite ===\n\n";

// ---------------------------------------------------------------------------
// 1. start() and initial record
// ---------------------------------------------------------------------------
echo "1. Testing start()...\n";
$run_id_1 = wp_generate_uuid4();
$meta_1 = [
    'label'        => 'Deploy Feature X',
    'author_id'    => 42,
    'author_name'  => 'Alice Developer',
    'author_email' => 'alice@company.com',
    'client_ip'    => '198.51.100.24',
    'source_url'   => 'https://dev-alice.local',
    'target_url'   => 'https://prod.company.com',
    'token_id'     => 'tok_test12345678',
];

$ok = Release_Store::start($run_id_1, 'code', $meta_1);
assert_true($ok, 'start() returned true');

$row_1 = Release_Store::get($run_id_1, false);
assert_true($row_1 !== null, 'get() found the newly started release');
assert_equals('running', $row_1['status'], 'status is running');
assert_equals('code', $row_1['kind'], 'kind is code');
assert_equals('Deploy Feature X', $row_1['label'], 'label preserved');
assert_equals('Alice Developer', $row_1['author_name'], 'author_name preserved');
assert_equals('alice@company.com', $row_1['author_email'], 'author_email preserved');
assert_equals('198.51.100.24', $row_1['client_ip'], 'client_ip preserved');
assert_equals('tok_test12345678', $row_1['token_id'], 'token_id preserved');
assert_true(empty($row_1['items']), 'items is omitted when include_items=false');

// ---------------------------------------------------------------------------
// 2. complete() with items and summary
// ---------------------------------------------------------------------------
echo "\n2. Testing complete()...\n";
$summary_1 = [
    'by_kind'   => ['core' => 2, 'dgb' => 1, 'app' => 3],
    'by_status' => ['new' => 3, 'changed' => 3],
];
$items_1 = [
    ['key' => 'core:auth', 'kind' => 'core', 'title' => 'Auth Module', 'action' => 'updated'],
    ['key' => 'core:api', 'kind' => 'core', 'title' => 'API Module', 'action' => 'updated'],
    ['key' => 'dgb:hero', 'kind' => 'dgb', 'title' => 'Hero Block', 'action' => 'created'],
    ['key' => 'app:crm/lead', 'kind' => 'app', 'title' => 'Lead Entity', 'action' => 'created'],
    ['key' => 'app:crm/contact', 'kind' => 'app', 'title' => 'Contact Entity', 'action' => 'created'],
    ['key' => 'app:crm/deal', 'kind' => 'app', 'title' => 'Deal Entity', 'action' => 'updated'],
];

$ok = Release_Store::complete($run_id_1, 'success', $summary_1, $items_1);
assert_true($ok, 'complete() returned true');

$completed_row = Release_Store::get($run_id_1, true);
assert_equals('success', $completed_row['status'], 'status updated to success');
assert_equals(6, $completed_row['items_count'], 'items_count is 6');
assert_true($completed_row['duration_s'] >= 0.0, 'duration_s is non-negative');
assert_equals(6, count($completed_row['items']), 'items loaded when include_items=true');
assert_equals('core:auth', $completed_row['items'][0]['key'], 'first item key matches');

// ---------------------------------------------------------------------------
// 3. OOM protection: recent() excludes bulky items JSON
// ---------------------------------------------------------------------------
echo "\n3. Testing recent() OOM protection and filtering...\n";

// Add a failed code run
$run_id_2 = wp_generate_uuid4();
Release_Store::start($run_id_2, 'code', ['label' => 'Broken Deploy', 'author_name' => 'Bob']);
Release_Store::complete($run_id_2, 'failed', ['by_kind' => ['core' => 1]], [['key' => 'core:broken', 'action' => 'failed']]);

// Add a page sync run
$run_id_3 = wp_generate_uuid4();
Release_Store::start($run_id_3, 'page', ['label' => 'Sync About Page', 'author_name' => 'Carol']);
Release_Store::complete($run_id_3, 'success', ['by_kind' => ['page' => 1]], [['key' => 'page:about', 'action' => 'updated']]);

$all_recent = Release_Store::recent(10, [], false);
assert_equals(3, count($all_recent), 'recent() returns all 3 releases');

foreach ($all_recent as $r) {
    assert_true(
        empty($r['items']),
        "Release {$r['release_id']} in recent() list view has no items JSON (OOM prevention verified)"
    );
}

// Filter by kind=page
$page_recent = Release_Store::recent(10, ['kind' => 'page'], false);
assert_equals(1, count($page_recent), 'filtered by kind=page returns 1 release');
assert_equals($run_id_3, $page_recent[0]['release_id'], 'matched page release id');

// Filter by status=failed
$failed_recent = Release_Store::recent(10, ['status' => 'failed'], false);
assert_equals(1, count($failed_recent), 'filtered by status=failed returns 1 release');
assert_equals($run_id_2, $failed_recent[0]['release_id'], 'matched failed release id');

// ---------------------------------------------------------------------------
// 4. Data retention and 3-tier pruning
// ---------------------------------------------------------------------------
echo "\n4. Testing 3-tier pruning (Release_Store::purge())...\n";

global $wpdb;
$table = $wpdb->prefix . 'awx_sync_releases';

// Backdate release 1 to 40 days ago (should have items pruned, but row retained)
$date_40d = gmdate('Y-m-d H:i:s', time() - (40 * 86400));
$wpdb->query($wpdb->prepare("UPDATE {$table} SET created_at = %s WHERE release_id = %s", $date_40d, $run_id_1));

// Add release 4 backdated to 100 days ago (should be completely deleted)
$run_id_4 = wp_generate_uuid4();
$date_100d = gmdate('Y-m-d H:i:s', time() - (100 * 86400));
Release_Store::start($run_id_4, 'code', ['label' => 'Ancient Deploy']);
$wpdb->query($wpdb->prepare("UPDATE {$table} SET created_at = %s WHERE release_id = %s", $date_100d, $run_id_4));

// Run purge with keep_days=90, keep_count=100, prune_items_days=30
$purge_result = Release_Store::purge(90, 100, 30);

assert_equals(1, $purge_result['items_pruned'], 'pruned items for release older than 30 days');
assert_equals(1, $purge_result['deleted_days'], 'deleted release older than 90 days');

// Verify release 1 row still exists but items are gone
$r1_after = Release_Store::get($run_id_1, true);
assert_true($r1_after !== null, 'release 1 header row retained after 40 days');
assert_true(empty($r1_after['items']), 'release 1 items JSON was pruned to save database space');
assert_equals(6, $r1_after['items_count'], 'release 1 items_count counter preserved');

// Verify release 4 is completely gone
$r4_after = Release_Store::get($run_id_4, true);
assert_true($r4_after === null, 'ancient release 4 deleted');

// Test keep_count cap (e.g. keep_count = 1)
$purge_count_result = Release_Store::purge(999, 1, 999);
assert_true($purge_count_result['deleted_count'] >= 1, 'deleted excess releases beyond keep_count cap');
$remaining = Release_Store::recent(50, [], false);
assert_equals(1, count($remaining), 'only 1 release remains when keep_count=1');

// ---------------------------------------------------------------------------
// 5. Idempotent upsert via record()
// ---------------------------------------------------------------------------
echo "\n5. Testing idempotent upsert...\n";
$upsert_id = wp_generate_uuid4();
Release_Store::record([
    'release_id'  => $upsert_id,
    'kind'        => 'code',
    'status'      => 'running',
    'label'       => 'Initial',
    'items_count' => 0,
]);
assert_equals('Initial', Release_Store::get($upsert_id)['label'], 'initial record inserted');

// Upsert with updated label and status
Release_Store::record([
    'release_id'  => $upsert_id,
    'status'      => 'success',
    'label'       => 'Updated Label',
    'items_count' => 5,
]);
$updated = Release_Store::get($upsert_id);
assert_equals('Updated Label', $updated['label'], 'record() updated label on existing release_id');
assert_equals('success', $updated['status'], 'record() updated status');
assert_equals(5, $updated['items_count'], 'record() updated items_count');

echo "\n🎉 All AWX Sync Release_Store tests passed!\n";
