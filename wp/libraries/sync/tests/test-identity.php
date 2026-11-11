<?php
/**
 * Test Suite for AWX Sync component 1: Identity.
 *
 * Runs in two modes, following the convention of
 * wp/libraries/gutenberg/tests/test-phase1.php:
 *
 *   1. Stand-alone (default): minimal WordPress stubs plus an in-memory SQLite
 *      $wpdb so the real $wpdb-based queries in Identity are exercised.
 *
 *        php wp/libraries/sync/tests/test-identity.php
 *
 *   2. Against a real WordPress install: point AWX_WP_LOAD at its wp-load.php
 *      (or run through `wp eval-file`). Test posts/attachments are created and
 *      deleted again; nothing else on the site is touched.
 *
 *        AWX_WP_LOAD=/path/to/wp-load.php php wp/libraries/sync/tests/test-identity.php
 *        wp eval-file wp/libraries/sync/tests/test-identity.php
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// ---------------------------------------------------------------------------
// Bootstrap: real WordPress if requested/available, otherwise stubs.
// ---------------------------------------------------------------------------
$awx_wp_load = getenv('AWX_WP_LOAD');
if (!defined('ABSPATH') && $awx_wp_load && file_exists($awx_wp_load)) {
    $_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
    $_SERVER['SERVER_NAME']    = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    require_once $awx_wp_load;
}

$AWX_REAL_WP = defined('ABSPATH') && function_exists('wp_insert_post') && isset($GLOBALS['wpdb']);

if (!$AWX_REAL_WP) {
    require __DIR__ . '/stubs.php';
}

require_once dirname(__DIR__) . '/lib/class-identity.php';

use aw2\sync\Identity;

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------
$AWX_CLEANUP = ['posts' => [], 'files' => []];

/**
 * Posts that already carried sync meta before the run. backfill() walks the
 * whole site, so afterwards we strip the meta it added to anything else and
 * leave the install exactly as we found it.
 */
function awx_snapshot_meta(): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
        Identity::META_UUID, Identity::META_FILE_HASH
    ), ARRAY_A);
    $seen = [];
    foreach ((array) $rows as $r) {
        $seen[$r['meta_key'] . ':' . $r['post_id']] = true;
    }
    return $seen;
}

function awx_cleanup()
{
    global $AWX_CLEANUP, $AWX_META_BEFORE;
    foreach ($AWX_CLEANUP['posts'] as $id) {
        wp_delete_post($id, true);
    }
    foreach ($AWX_CLEANUP['files'] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    if (is_array($AWX_META_BEFORE)) {
        foreach (awx_snapshot_meta() as $key => $_) {
            if (isset($AWX_META_BEFORE[$key])) {
                continue;
            }
            list($meta_key, $post_id) = explode(':', $key, 2);
            delete_post_meta((int) $post_id, $meta_key);
        }
    }
}

function assert_true($condition, $message)
{
    if (!$condition) {
        echo "❌ FAIL: $message\n";
        awx_cleanup();
        exit(1);
    }
    echo "✅ PASS: $message\n";
}

function awx_make_post(array $overrides = []): int
{
    global $AWX_CLEANUP;
    $data = array_merge([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => 'AWX Sync identity test ' . wp_generate_uuid4(),
        'post_content' => '<!-- wp:paragraph --><p>identity</p><!-- /wp:paragraph -->',
    ], $overrides);
    $id = wp_insert_post($data, true);
    if (is_wp_error($id) || !$id) {
        echo "❌ FAIL: could not insert test post\n";
        awx_cleanup();
        exit(1);
    }
    $AWX_CLEANUP['posts'][] = (int) $id;
    return (int) $id;
}

/**
 * Create a real file and an attachment post pointing at it.
 */
function awx_make_attachment(string $contents): array
{
    global $AWX_CLEANUP, $AWX_REAL_WP;

    if ($AWX_REAL_WP) {
        $upload = wp_upload_dir();
        $path   = trailingslashit($upload['basedir']) . 'awx-sync-test-' . wp_generate_uuid4() . '.txt';
    } else {
        $path = sys_get_temp_dir() . '/awx-sync-test-' . wp_generate_uuid4() . '.txt';
    }
    file_put_contents($path, $contents);
    $AWX_CLEANUP['files'][] = $path;

    $id = wp_insert_attachment([
        'post_mime_type' => 'text/plain',
        'post_title'     => 'AWX Sync identity test file',
        'post_status'    => 'inherit',
    ], $path);
    if (is_wp_error($id) || !$id) {
        echo "❌ FAIL: could not insert test attachment\n";
        awx_cleanup();
        exit(1);
    }
    $AWX_CLEANUP['posts'][] = (int) $id;
    return [(int) $id, $path];
}

echo '=== Running AWX Sync Identity Test Suite (' . ($AWX_REAL_WP ? 'real WordPress' : 'stand-alone stubs') . ") ===\n\n";

Identity::init();
$AWX_META_BEFORE = awx_snapshot_meta();

// 1. Constants
echo "1. Checking meta key constants...\n";
assert_true(Identity::META_UUID === '_awx_sync_uuid', "META_UUID is '_awx_sync_uuid'");
assert_true(Identity::META_CONTENT_HASH === '_awx_content_hash', "META_CONTENT_HASH is '_awx_content_hash'");
assert_true(Identity::META_FILE_HASH === '_awx_file_hash', "META_FILE_HASH is '_awx_file_hash'");

// 2. UUID assigned on save, stable across updates
echo "\n2. UUID assigned on save and stable across updates...\n";
$page_id = awx_make_post();
$uuid_a  = Identity::get_uuid($page_id);
assert_true($uuid_a !== null, 'UUID assigned by save_post on insert');
assert_true(Identity::is_valid_uuid((string) $uuid_a), 'Assigned UUID is a valid UUIDv4');

wp_update_post(['ID' => $page_id, 'post_title' => 'AWX Sync identity test (updated)']);
assert_true(Identity::get_uuid($page_id) === $uuid_a, 'UUID unchanged after wp_update_post');
assert_true(Identity::ensure_uuid($page_id) === $uuid_a, 'ensure_uuid returns the existing UUID, never a new one');

$draft_id = awx_make_post(['post_status' => 'auto-draft', 'post_title' => 'Auto Draft']);
assert_true(Identity::get_uuid($draft_id) === null, 'auto-draft posts are not stamped');

assert_true(in_array('wp_block', Identity::tracked_post_types(), true), 'wp_block is a tracked post type');
assert_true(!in_array('attachment', Identity::tracked_post_types(), true), 'attachment is not stamped via save_post');

// 3. find_by_uuid with 0 / 1 / 2 matches
echo "\n3. find_by_uuid with 0, 1 and 2 matches...\n";
assert_true(Identity::find_by_uuid(wp_generate_uuid4()) === null, 'Unknown UUID returns null');
assert_true(Identity::find_by_uuid('') === null, 'Empty UUID returns null');
assert_true(Identity::find_by_uuid($uuid_a) === $page_id, 'Known UUID resolves to the post');
assert_true(Identity::find_by_uuid($uuid_a, ['page']) === $page_id, 'Post-type filter including the type still resolves');
assert_true(Identity::find_by_uuid($uuid_a, ['post']) === null, 'Post-type filter excluding the type returns null');

$page2_id = awx_make_post();
update_post_meta($page2_id, Identity::META_UUID, $uuid_a); // forge a duplicate
assert_true(Identity::find_by_uuid($uuid_a) === null, 'Duplicate UUID (2 matches) returns null');
delete_post_meta($page2_id, Identity::META_UUID);
assert_true(Identity::find_by_uuid($uuid_a) === $page_id, 'Resolves again once the duplicate is removed');

// 4. pair()
echo "\n4. pair() validation...\n";
$bad = Identity::pair($page2_id, 'not-a-uuid');
assert_true(is_wp_error($bad) && $bad->get_error_code() === 'awx_sync_invalid_uuid', 'pair() rejects a malformed UUID');

$taken = Identity::pair($page2_id, $uuid_a);
assert_true(is_wp_error($taken) && $taken->get_error_code() === 'awx_sync_uuid_taken', 'pair() refuses a UUID held by another post');
assert_true(Identity::get_uuid($page2_id) !== $uuid_a, 'Refused pairing did not stamp the UUID');

$missing = Identity::pair(987654321, wp_generate_uuid4());
assert_true(is_wp_error($missing) && $missing->get_error_code() === 'awx_sync_no_post', 'pair() rejects a non-existent post');

$fresh = wp_generate_uuid4();
assert_true(Identity::pair($page2_id, strtoupper($fresh)) === true, 'pair() accepts a fresh UUID (case-insensitive)');
assert_true(Identity::get_uuid($page2_id) === $fresh, 'pair() stored the UUID lowercased');
assert_true(Identity::pair($page2_id, $fresh) === true, 'pair() with the same UUID again is idempotent');
assert_true(Identity::find_by_uuid($fresh) === $page2_id, 'Paired UUID resolves via find_by_uuid');

// 5. content_hash
echo "\n5. content_hash...\n";
assert_true(Identity::content_hash('abc') === hash('sha256', 'abc'), 'content_hash is SHA-256 of the string');
assert_true(Identity::content_hash('') === hash('sha256', ''), 'content_hash of empty string is defined');

// 6. file_hash for an attachment
echo "\n6. file_hash for an attachment...\n";
list($att_id, $att_path) = awx_make_attachment('hello awx');
$expected = hash('sha256', 'hello awx');
assert_true(Identity::get_uuid($att_id) !== null, 'add_attachment assigns a UUID');
assert_true(get_post_meta($att_id, Identity::META_FILE_HASH, true) === $expected, 'add_attachment computes and caches the file hash');
assert_true(Identity::file_hash($att_id) === $expected, 'file_hash returns the SHA-256 of the attached file');

file_put_contents($att_path, 'changed contents');
assert_true(Identity::file_hash($att_id) === $expected, 'file_hash serves the cached value by default');
assert_true(Identity::file_hash($att_id, true) === hash('sha256', 'changed contents'), 'file_hash(recompute=true) re-reads the file');
assert_true(get_post_meta($att_id, Identity::META_FILE_HASH, true) === hash('sha256', 'changed contents'), 'Recompute refreshes the cached hash');

unlink($att_path);
delete_post_meta($att_id, Identity::META_FILE_HASH);
assert_true(Identity::file_hash($att_id) === null, 'file_hash returns null when the file is missing');
file_put_contents($att_path, 'restored');

// 7. pair_patterns map
echo "\n7. pair_patterns slug map...\n";
$slug     = 'awx-test-pattern-' . substr(wp_generate_uuid4(), 0, 8);
$block_id = awx_make_post(['post_type' => 'wp_block', 'post_title' => 'AWX test pattern']);
update_post_meta($block_id, '_awx_pattern_slug', $slug);
$map = Identity::pair_patterns();
assert_true(isset($map[$slug]) && $map[$slug] === $block_id, 'pair_patterns maps _awx_pattern_slug to the wp_block ID');
assert_true(Identity::get_uuid($block_id) !== null, 'wp_block received a UUID on save');

// 8. backfill: dry run writes nothing, real run writes, second run skips
echo "\n8. backfill...\n";
delete_post_meta($att_id, Identity::META_UUID);
delete_post_meta($att_id, Identity::META_FILE_HASH);
delete_post_meta($page_id, Identity::META_UUID);

$dry = Identity::backfill(['posts' => true, 'dry_run' => true, 'batch' => 2]);
assert_true($dry['stamped'] >= 2, 'Dry run counts the posts that need a UUID');
assert_true($dry['hashed'] >= 1, 'Dry run counts the attachments that need a hash');
assert_true($dry['scanned'] >= 4, 'Dry run scanned attachments and posts across batches');
assert_true(Identity::get_uuid($att_id) === null, 'Dry run did not write the attachment UUID');
assert_true(get_post_meta($att_id, Identity::META_FILE_HASH, true) === '', 'Dry run did not write the file hash');
assert_true(Identity::get_uuid($page_id) === null, 'Dry run did not write the page UUID');

$only_attachments = Identity::backfill(['dry_run' => true]);
assert_true($only_attachments['stamped'] >= 1 && Identity::get_uuid($page_id) === null, 'Without posts=true only attachments are considered');

$real = Identity::backfill(['posts' => true, 'batch' => 2]);
assert_true($real['stamped'] >= 2 && $real['hashed'] >= 1, 'Real backfill reports stamps and hashes');
assert_true(Identity::get_uuid($att_id) !== null, 'Backfill stamped the attachment UUID');
assert_true(get_post_meta($att_id, Identity::META_FILE_HASH, true) === hash('sha256', 'restored'), 'Backfill computed the file hash');
assert_true(Identity::get_uuid($page_id) !== null, 'Backfill stamped the page UUID');

$again = Identity::backfill(['posts' => true]);
assert_true($again['stamped'] === 0 && $again['hashed'] === 0, 'Second backfill has nothing left to write');
assert_true($again['skipped'] === $again['scanned'] - $again['missing_file'], 'Everything already stamped is counted as skipped');

awx_cleanup();
echo "\n🎉 All AWX Sync Identity tests passed!\n";
