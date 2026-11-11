<?php
/**
 * Test Suite for AWX Sync component 7: Schema (tables).
 *
 * Runs against a real WordPress install so dbDelta is exercised for real.
 * Point it at one with either:
 *   AWX_TEST_WP_LOAD=/path/to/wp-load.php php test-schema.php
 *   php test-schema.php /path/to/wp-load.php
 * With no install given it probes a few common sibling paths. If none boots,
 * the DB-backed sections are skipped and only the pure-PHP checks run.
 *
 * NOTE: the DB sections drop and recreate the awx_sync_* tables and delete
 * the awx_sync_db_version option on the target site. Use a throwaway site.
 */

function assert_true($condition, $message) {
    if (!$condition) {
        echo "❌ FAIL: $message\n";
        exit(1);
    }
    echo "✅ PASS: $message\n";
}

// ---------------------------------------------------------------------------
// Bootstrap WordPress if we can find one
// ---------------------------------------------------------------------------
$wp_load = getenv('AWX_TEST_WP_LOAD') ?: ($argv[1] ?? '');
if ($wp_load === '') {
    $candidates = [
        dirname(__DIR__, 7) . '/wp-load.php',             // plugin at wp-content/plugins/awesome-xp
        '/Users/macbook/development/wptest/wp-load.php',  // local dev default
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) { $wp_load = $c; break; }
    }
}

$have_wp = false;
if ($wp_load !== '' && is_file($wp_load)) {
    // WP < 5.9 does not reset mysqli's report mode; on PHP 8.1+ dbDelta's
    // DESCRIBE probe on a missing table would otherwise throw.
    if (function_exists('mysqli_report')) {
        mysqli_report(MYSQLI_REPORT_OFF);
    }
    define('WP_USE_THEMES', false);
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    require_once $wp_load;
    $have_wp = defined('ABSPATH') && isset($GLOBALS['wpdb']);
}

if (!$have_wp) {
    // Minimal stubs so the class file loads and table() can be exercised.
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/');
    }
    $GLOBALS['wpdb'] = new class { public $prefix = 'wp_'; };
}

require_once dirname(__DIR__) . '/lib/class-schema.php';

use aw2\sync\Schema;

echo "=== Running AWX Sync Schema Test Suite ===\n";
echo $have_wp ? "WordPress: " . get_bloginfo('version') . " at " . ABSPATH . "\n\n" : "WordPress: not found — DB sections skipped\n\n";

// ---------------------------------------------------------------------------
// 1. Class surface
// ---------------------------------------------------------------------------
echo "1. Checking class surface...\n";
assert_true(class_exists('aw2\sync\Schema'), "aw2\\sync\\Schema class exists");
assert_true(Schema::DB_VERSION === 2, "DB_VERSION is 2");
assert_true(Schema::OPTION === 'awx_sync_db_version', "OPTION is 'awx_sync_db_version'");
foreach (['init','table','maybe_upgrade','install','tables_exist','purge_nonces','purge_logs','uninstall','sql'] as $m) {
    assert_true(method_exists(Schema::class, $m) && (new ReflectionMethod(Schema::class, $m))->isStatic(), "public static $m() exists");
}

// ---------------------------------------------------------------------------
// 2. table()
// ---------------------------------------------------------------------------
echo "\n2. Testing table() name resolution...\n";
global $wpdb;
$p = $wpdb->prefix;
assert_true(Schema::table('runs')   === $p . 'awx_sync_runs',   "table('runs') is prefixed");
assert_true(Schema::table('steps')  === $p . 'awx_sync_steps',  "table('steps') is prefixed");
assert_true(Schema::table('tokens') === $p . 'awx_sync_tokens', "table('tokens') is prefixed");
assert_true(Schema::table('log')    === $p . 'awx_sync_log',    "table('log') is prefixed");
assert_true(Schema::table('nonces') === $p . 'awx_sync_nonces', "table('nonces') is prefixed");

$threw = false;
try { Schema::table('users'); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, "table('users') throws InvalidArgumentException");

$threw = false;
try { Schema::table(''); } catch (InvalidArgumentException $e) { $threw = true; }
assert_true($threw, "table('') throws InvalidArgumentException");

// ---------------------------------------------------------------------------
// 3. SQL shape (dbDelta formatting rules)
// ---------------------------------------------------------------------------
echo "\n3. Checking dbDelta formatting of generated SQL...\n";
$sql = Schema::sql();
assert_true(count($sql) === 5, "sql() returns 5 CREATE TABLE statements");
foreach ($sql as $stmt) {
    assert_true(strpos($stmt, 'PRIMARY KEY  (') !== false, "two spaces after PRIMARY KEY in " . substr($stmt, 13, 30));
    assert_true(strpos($stmt, '`') === false, "no backticks in " . substr($stmt, 13, 30));
    assert_true(strpos($stmt, 'ENGINE=InnoDB') !== false, "InnoDB engine in " . substr($stmt, 13, 30));
}

if (!$have_wp) {
    echo "\n⚠️  No WordPress install available; skipping DB-backed tests.\n";
    echo "\n🎉 All available Schema tests passed!\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// 4. Fresh install creates all five tables
// ---------------------------------------------------------------------------
echo "\n4. Testing fresh install...\n";
Schema::uninstall();
$exists = Schema::tables_exist();
assert_true(count($exists) === 5 && !in_array(true, $exists, true), "after uninstall() no awx_sync tables exist");
assert_true(get_option(Schema::OPTION) === false, "after uninstall() the version option is gone");

Schema::install();
$exists = Schema::tables_exist();
assert_true(!in_array(false, $exists, true), "install() creates all five tables: " . implode(',', array_keys($exists)));

// dbDelta should now consider the schema in sync (no ALTERs on a second run).
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$second = dbDelta(Schema::sql(), false);
assert_true(empty($second), "second dbDelta run reports no changes (schema matches live tables)" . (empty($second) ? '' : ' — ' . json_encode($second)));

$engines = $wpdb->get_results($wpdb->prepare(
    "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s",
    DB_NAME, $p . 'awx_sync_%'
));
assert_true(count($engines) === 5, "information_schema lists 5 awx_sync tables");
foreach ($engines as $row) {
    assert_true($row->ENGINE === 'InnoDB', "{$row->TABLE_NAME} uses InnoDB");
    assert_true(strpos($row->TABLE_COLLATION, 'utf8mb4') === 0, "{$row->TABLE_NAME} collation is utf8mb4 ({$row->TABLE_COLLATION})");
}

$idx = $wpdb->get_results("SHOW INDEX FROM " . Schema::table('steps'));
$unique_run_step = array_filter($idx, function ($i) { return $i->Key_name === 'run_step' && (int) $i->Non_unique === 0; });
assert_true(count($unique_run_step) === 2, "steps has UNIQUE (run_id, step_id)");

// ---------------------------------------------------------------------------
// 5. maybe_upgrade(): installs when missing, no-op when current
// ---------------------------------------------------------------------------
echo "\n5. Testing maybe_upgrade() gating...\n";
Schema::uninstall();
Schema::maybe_upgrade();
assert_true((int) get_option(Schema::OPTION) === Schema::DB_VERSION, "maybe_upgrade() with no option installs and stores DB_VERSION");
assert_true(!in_array(false, Schema::tables_exist(), true), "maybe_upgrade() created the tables");
assert_true(get_option('awx_sync_db_upgrade_lock') === false, "lock option released after upgrade");

// Prove no-op: drop one table, keep option at DB_VERSION, call again — table stays gone.
$wpdb->query("DROP TABLE IF EXISTS " . Schema::table('nonces'));
assert_true(Schema::tables_exist()['nonces'] === false, "nonces table dropped for no-op probe");
Schema::maybe_upgrade();
assert_true(Schema::tables_exist()['nonces'] === false, "maybe_upgrade() is a no-op when option equals DB_VERSION");

// Lower the option: upgrade runs and recreates the missing table.
update_option(Schema::OPTION, 0);
Schema::maybe_upgrade();
assert_true(Schema::tables_exist()['nonces'] === true, "maybe_upgrade() re-runs install() when option is lower");
assert_true((int) get_option(Schema::OPTION) === Schema::DB_VERSION, "option bumped back to DB_VERSION");

// Concurrency guard: a fresh lock held by "another request" makes us bail.
update_option(Schema::OPTION, 0);
$wpdb->query("DROP TABLE IF EXISTS " . Schema::table('nonces'));
add_option('awx_sync_db_upgrade_lock', time(), '', 'no');
Schema::maybe_upgrade();
assert_true(Schema::tables_exist()['nonces'] === false, "maybe_upgrade() bails while another request holds the lock");
assert_true((int) get_option(Schema::OPTION) === 0, "option untouched while lock is held");

// A stale lock (>60s old) is reclaimed.
update_option('awx_sync_db_upgrade_lock', time() - 120);
Schema::maybe_upgrade();
assert_true(Schema::tables_exist()['nonces'] === true, "maybe_upgrade() reclaims a stale lock and installs");
assert_true(get_option('awx_sync_db_upgrade_lock') === false, "stale lock released afterwards");

// ---------------------------------------------------------------------------
// 6. purge_nonces() deletes only old rows
// ---------------------------------------------------------------------------
echo "\n6. Testing purge_nonces()...\n";
$nonces = Schema::table('nonces');
$wpdb->query("DELETE FROM {$nonces}");
$now = time();
$rows = [
    ['n_fresh_0000000000000000000000001', 0],
    ['n_recent_000000000000000000000002', 100],
    ['n_old_00000000000000000000000003', 2000],
    ['n_older_0000000000000000000000004', 86400],
];
foreach ($rows as [$nonce, $age]) {
    $wpdb->insert($nonces, [
        'nonce'    => substr($nonce, 0, 32),
        'token_id' => 'tok_test_0000000',
        'seen_at'  => gmdate('Y-m-d H:i:s', $now - $age),
    ]);
}
assert_true((int) $wpdb->get_var("SELECT COUNT(*) FROM {$nonces}") === 4, "4 nonce rows inserted");
$deleted = Schema::purge_nonces(900);
assert_true($deleted === 2, "purge_nonces(900) deleted exactly 2 rows (got $deleted)");
$left = $wpdb->get_col("SELECT nonce FROM {$nonces} ORDER BY nonce");
assert_true(count($left) === 2 && strpos($left[0], 'n_fresh') === 0 && strpos($left[1], 'n_recent') === 0, "only fresh and recent nonces remain");
assert_true(Schema::purge_nonces(900) === 0, "second purge_nonces() deletes nothing");
assert_true(Schema::purge_nonces(50) === 1, "purge_nonces(50) deletes the 100s-old row but keeps the one seen this second");
assert_true((int) $wpdb->get_var("SELECT COUNT(*) FROM {$nonces}") === 1, "1 nonce row remains");

// ---------------------------------------------------------------------------
// 7. purge_logs() deletes only old rows
// ---------------------------------------------------------------------------
echo "\n7. Testing purge_logs()...\n";
$log = Schema::table('log');
$wpdb->query("DELETE FROM {$log}");
foreach ([0, 10, 100] as $days) {
    $wpdb->insert($log, [
        'route'       => '/awx-sync/v1/ping',
        'method'      => 'GET',
        'result'      => 'ok',
        'http_status' => 200,
        'created_at'  => gmdate('Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS),
    ]);
}
assert_true(Schema::purge_logs(90) === 1, "purge_logs(90) deletes only the 100-day-old row");
assert_true((int) $wpdb->get_var("SELECT COUNT(*) FROM {$log}") === 2, "2 log rows remain");

// ---------------------------------------------------------------------------
// 8. Cron scheduling
// ---------------------------------------------------------------------------
echo "\n8. Testing daily cleanup scheduling...\n";
wp_clear_scheduled_hook(Schema::CRON_HOOK);
assert_true(wp_next_scheduled(Schema::CRON_HOOK) === false, "cron hook not scheduled before init");
Schema::maybe_schedule_cleanup();
$first = wp_next_scheduled(Schema::CRON_HOOK);
assert_true($first !== false, "maybe_schedule_cleanup() schedules awx_sync_daily_cleanup");
Schema::maybe_schedule_cleanup();
assert_true(wp_next_scheduled(Schema::CRON_HOOK) === $first, "second call does not duplicate the event");
Schema::init();
assert_true(has_action('init', ['aw2\sync\Schema', 'maybe_upgrade']) === 5, "init() hooks maybe_upgrade on init at priority 5");
assert_true(has_action(Schema::CRON_HOOK, ['aw2\sync\Schema', 'daily_cleanup']) !== false, "init() hooks daily_cleanup on the cron event");

// ---------------------------------------------------------------------------
// 9. uninstall()
// ---------------------------------------------------------------------------
echo "\n9. Testing uninstall()...\n";
Schema::uninstall();
assert_true(!in_array(true, Schema::tables_exist(), true), "uninstall() drops all tables");
assert_true(get_option(Schema::OPTION) === false, "uninstall() deletes the version option");
assert_true(wp_next_scheduled(Schema::CRON_HOOK) === false, "uninstall() clears the cron event");

echo "\n🎉 All Schema tests passed successfully!\n";
