<?php
/**
 * AWX Sync: integration test of the Prod code-deploy receiver (/code/*)
 * against a real WordPress.
 *
 *   AWX_TEST_WP_LOAD=/path/to/wp-load.php php test-code-receiver-integration.php
 *
 * Creates (and finally deletes) two tokens, a handful of code posts in test
 * post types and the awx-sync service user on that site. Use a throwaway
 * install. Re-runs itself in a child process without AWX_SYNC_ALLOW_CODE to
 * check that the routes are then absent.
 */
$flags = array_values(array_filter(array_slice($argv, 1), function ($a) { return strpos($a, '--') === 0; }));
$args  = array_values(array_filter(array_slice($argv, 1), function ($a) { return strpos($a, '--') !== 0; }));
$wp_load = getenv('AWX_TEST_WP_LOAD') ?: ($args[0] ?? '/Users/macbook/development/wptest/wp-load.php');
if (!is_file($wp_load)) {
	fwrite(STDERR, "SKIP: no WordPress at {$wp_load}\n");
	exit(0);
}
$routes_check_only = in_array('--routes-check', $flags, true);
if (function_exists('mysqli_report')) {
	mysqli_report(MYSQLI_REPORT_OFF);
}
define('WP_USE_THEMES', false);
define('AWX_SYNC_ROLE', 'target');
define('AWX_SYNC_ALLOW_HTTP', true);
if (!$routes_check_only) {
	define('AWX_SYNC_ALLOW_CODE', true);
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once $wp_load;
require_once dirname(__DIR__) . '/sync-init.php';
aw2\sync\init();

use aw2\sync\Audit_Log;
use aw2\sync\Code_Item;
use aw2\sync\Code_Receiver;
use aw2\sync\Identity;
use aw2\sync\Receiver;
use aw2\sync\Schema;
use aw2\sync\Service_User;
use aw2\sync\Signer;
use aw2\sync\Step_Store;
use aw2\sync\Sync_Http_Exception;
use aw2\sync\Token_Store;

// ---- child mode: are the code routes absent without AWX_SYNC_ALLOW_CODE? ----
if ($routes_check_only) {
	$routes = array_keys(rest_get_server()->get_routes());
	$code   = array_values(array_filter($routes, function ($r) { return strpos($r, '/awx-sync/v1/code/') === 0; }));
	$base   = in_array('/awx-sync/v1/handshake', $routes, true);
	echo json_encode(array('code_routes' => $code, 'base_present' => $base, 'allowed' => Code_Receiver::allowed()));
	exit(0);
}

// Test post types: a hierarchical "core" type with revisions (like awesome_core on a
// real site) and a block type; the app module type is deliberately NOT registered.
$T_CORE = 'awesome_core';
$T_DGB  = 'aw_gb_blocks';
$T_APP  = 'aw2_app';
$T_MOD  = 'awxtest_app_modules';
if (!post_type_exists($T_CORE)) {
	register_post_type($T_CORE, array('public' => false, 'hierarchical' => true, 'supports' => array('title', 'editor', 'revisions', 'custom-fields', 'page-attributes')));
}
if (!post_type_exists($T_DGB)) {
	register_post_type($T_DGB, array('public' => false, 'supports' => array('title', 'editor', 'custom-fields')));
}

$failures = 0;
$asserts  = 0;
$created  = array('posts' => array(), 'tokens' => array(), 'runs' => array());
function assert_true($c, $m) { global $failures, $asserts; $asserts++; if (!$c) { $failures++; echo "❌ FAIL: $m\n"; return; } echo "✅ PASS: $m\n"; }
function track_post($id) { global $created; if ($id && !in_array((int) $id, $created['posts'], true)) { $created['posts'][] = (int) $id; } }

Schema::install();
update_option(Schema::OPTION, Schema::DB_VERSION, false);
$tok_code = Token_Store::create('integration-code', 1, array('code'));
$tok_sync = Token_Store::create('integration-sync-only', 1, array('sync'));
$created['tokens'][] = $tok_code['token_id'];
$created['tokens'][] = $tok_sync['token_id'];
$code_parts = Signer::split_token($tok_code['token']);
$sync_parts = Signer::split_token($tok_sync['token']);
$server = rest_get_server(); // registers routes

function call($method, $route, $body = array(), $parts = null) {
	$json = $body === null ? '' : wp_json_encode($body);
	$req  = new WP_REST_Request($method, '/awx-sync/v1' . $route);
	if ($parts) {
		$path = Signer::normalise_path(rest_get_url_prefix() . '/awx-sync/v1' . $route);
		foreach (Signer::sign($parts['token_id'], $parts['secret'], $method, $path, $json) as $k => $v) {
			$req->set_header($k, $v);
		}
	}
	if ($method === 'POST') {
		$req->set_header('Content-Type', 'application/json');
		$req->set_body($json);
	}
	$res = rest_do_request($req);
	return array($res->get_status(), $res->get_data());
}
function item($kind, $post_type, $slug, $title, $content, $meta = array(), $parent = null, $status = 'publish') {
	$it = new Code_Item($kind, $post_type, $slug);
	$it->title = $title; $it->content = $content; $it->meta = Code_Item::filter_meta($meta); $it->parent_slug = $parent; $it->status = $status;
	$it->meta_skipped = Code_Receiver::meta_skipped($kind, $slug);
	return $it;
}
function count_slug($post_type, $slug) {
	global $wpdb;
	return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status NOT IN ('trash','auto-draft')", $post_type, $slug));
}

$run = wp_generate_uuid4();
$created['runs'][] = $run;

echo "=== 1. Routes, scope and handshake ===\n";
$routes = array_keys($server->get_routes());
foreach (array('plan', 'modules', 'acf-field-groups', 'finalize') as $r) {
	assert_true(in_array('/awx-sync/v1/code/' . $r, $routes, true), "route /code/{$r} is registered with AWX_SYNC_ALLOW_CODE");
}
foreach (array('/code/plan' => array(), '/code/modules' => array('run_id' => $run, 'step_id' => wp_generate_uuid4()), '/code/acf-field-groups' => array('run_id' => $run, 'step_id' => wp_generate_uuid4()), '/code/finalize' => array('run_id' => $run, 'step_id' => wp_generate_uuid4())) as $route => $body) {
	list($st, $d) = call('POST', $route, $body, $sync_parts);
	assert_true($st === 403 && $d['code'] === 'awx_sync_scope_denied', "sync-only token is refused on {$route} ({$st})");
}
list($st, $d) = call('POST', '/code/plan', array(), null);
assert_true($st === 401, 'unsigned request to /code/plan is rejected');
// Handshake and /runs keep the `sync` scope, so a token that only has `code` cannot use them.
list($st, $d) = call('POST', '/handshake', array('protocol' => 1), $code_parts);
assert_true($st === 403 && $d['code'] === 'awx_sync_scope_denied', 'code-only token is refused on /handshake (needs sync scope)');
list($st, $d) = call('POST', '/handshake', array('protocol' => 1), $sync_parts);
assert_true($st === 200 && $d['allow_code'] === true && $d['acf_active'] === false, 'handshake reports allow_code=true and acf_active=false on this site');

echo "\n=== 2. Plan before anything is deployed ===\n";
$stray = wp_insert_post(array('post_type' => $T_CORE, 'post_name' => 'awxtest-stray', 'post_title' => 'Stray', 'post_status' => 'publish', 'post_content' => 'x'), true);
track_post($stray);
$mod_a = item('core', $T_CORE, 'awxtest-mod-a', 'Mod A', "[aw2.module name='x']\r\nline two", array('_aw_version' => array('1'), 'awx_arr' => array(serialize(array('a' => 1, 'b' => array('c' => 'd')))), '_edit_lock' => array('123:1'), '_awx_sync_uuid' => array('nope')));
assert_true(!isset($mod_a->meta['_edit_lock']) && !isset($mod_a->meta['_awx_sync_uuid']) && isset($mod_a->meta['awx_arr']), 'item meta filtered: _edit_lock and _awx_* dropped');
list($st, $d) = call('POST', '/code/plan', array(
	'items'       => array(array('kind' => 'core', 'post_type' => $T_CORE, 'slug' => 'awxtest-mod-a', 'hash' => $mod_a->hash())),
	'collections' => array(array('kind' => 'core', 'post_type' => $T_CORE)),
	'apps'        => array('awxtest-app'),
	'acf_keys'    => array('group_awxtest'),
), $code_parts);
assert_true($st === 200 && $d['allow_code'] === true && $d['acf_active'] === false && in_array($T_CORE, $d['post_types'], true), "plan answers with allow_code, acf_active and post types ({$st})");
$row = $d['items']["{$T_CORE}:awxtest-mod-a"] ?? null;
assert_true($row && $row['status'] === 'new' && $row['prod_id'] === null && $row['has_revisions'] === true, 'missing module reports new (and the type keeps revisions)');
assert_true($d['apps']['awxtest-app'] === false, 'app slug not on prod');
assert_true($d['acf']['group_awxtest']['status'] === 'acf_inactive', 'ACF key reports acf_inactive when ACF is absent');
$only = array_column($d['only_on_prod'][$T_CORE] ?? array(), 'slug');
assert_true(in_array('awxtest-stray', $only, true) && !in_array('awxtest-mod-a', $only, true), 'only_on_prod lists the stray prod module and not the requested one');

echo "\n=== 3. Module write, replay, stamps ===\n";
$step1 = wp_generate_uuid4();
$body  = array('run_id' => $run, 'step_id' => $step1, 'item' => $mod_a->to_payload(), 'expected_prod_hash' => null, 'overwrite' => false);
list($st, $d) = call('POST', '/code/modules', $body, $code_parts);
assert_true($st === 200 && $d['action'] === 'created' && !empty($d['post_id']) && $d['key'] === "{$T_CORE}:awxtest-mod-a", "module created ({$st} " . ($d['message'] ?? '') . ')');
$mod_a_id = (int) ($d['post_id'] ?? 0);
track_post($mod_a_id);
if ($mod_a_id) {
	$p = get_post($mod_a_id);
	assert_true($p->post_type === $T_CORE && $p->post_name === 'awxtest-mod-a' && $p->post_status === 'publish' && $p->post_title === 'Mod A', 'post saved with the given type, slug, status and title');
	assert_true((int) $p->post_author === Service_User::ensure_user(), 'module owned by the service user');
	assert_true(get_post_meta($mod_a_id, '_aw_version', true) === '1' && get_post_meta($mod_a_id, 'awx_arr', true) === array('a' => 1, 'b' => array('c' => 'd')), 'meta written: scalar kept, serialized array round-trips without double serialization');
	assert_true(get_post_meta($mod_a_id, '_edit_lock', true) === '' && get_post_meta($mod_a_id, '_awx_sync_uuid', true) === '', 'skipped meta keys were not written');
	$saved_hash = Code_Item::from_post('core', get_post($mod_a_id))->hash();
	assert_true($saved_hash === $mod_a->hash(), 'Code_Item hash of the saved post equals the source item hash');
	assert_true(get_post_meta($mod_a_id, Identity::META_CONTENT_HASH, true) === $saved_hash && get_post_meta($mod_a_id, Receiver::META_SOURCE_HASH, true) === $mod_a->hash(), '_awx_content_hash and _awx_source_hash stamped');
}
list($st2, $d2) = call('POST', '/code/modules', $body, $code_parts);
assert_true($st2 === 200 && !empty($d2['replayed']) && $d2['post_id'] === $mod_a_id && count_slug($T_CORE, 'awxtest-mod-a') === 1, 'same step_id again is replayed; exactly one post with that slug');
$revs_before = count(wp_get_post_revisions($mod_a_id));

list($st, $d) = call('POST', '/code/plan', array('items' => array(array('kind' => 'core', 'post_type' => $T_CORE, 'slug' => 'awxtest-mod-a', 'hash' => $mod_a->hash()))), $code_parts);
assert_true($d['items']["{$T_CORE}:awxtest-mod-a"]['status'] === 'unchanged' && $d['items']["{$T_CORE}:awxtest-mod-a"]['prod_id'] === $mod_a_id, 'plan after the write: unchanged');
list($st, $d) = call('POST', '/code/plan', array('items' => array(array('kind' => 'core', 'post_type' => $T_CORE, 'slug' => 'awxtest-mod-a', 'hash' => 'different'))), $code_parts);
assert_true($d['items']["{$T_CORE}:awxtest-mod-a"]['status'] === 'changed' && $d['items']["{$T_CORE}:awxtest-mod-a"]['never_synced'] === false, 'plan with a new source hash: changed');

echo "\n=== 4. Update creates a revision; identical re-send is unchanged ===\n";
$mod_a2 = item('core', $T_CORE, 'awxtest-mod-a', 'Mod A v2', "[aw2.module name='y']", array('_aw_version' => array('2')));
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $mod_a2->to_payload(), 'expected_prod_hash' => $mod_a->hash(), 'overwrite' => false), $code_parts);
assert_true($st === 200 && $d['action'] === 'updated' && $d['post_id'] === $mod_a_id && !empty($d['revision_id']), "update with the right expected hash succeeds and returns a revision id ({$st})");
assert_true(count(wp_get_post_revisions($mod_a_id)) > $revs_before, 'a revision was kept');
assert_true(get_post_meta($mod_a_id, '_aw_version', true) === '2' && get_post_meta($mod_a_id, 'awx_arr', true) === '', 'meta replaced: old key removed, new value written');
assert_true(get_post($mod_a_id)->post_title === 'Mod A v2' && Code_Item::from_post('core', get_post($mod_a_id))->hash() === $mod_a2->hash(), 'title and content updated; saved hash matches v2');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $mod_a2->to_payload(), 'expected_prod_hash' => $mod_a2->hash(), 'overwrite' => false), $code_parts);
$revs_now = count(wp_get_post_revisions($mod_a_id));
assert_true($st === 200 && $d['action'] === 'unchanged' && $d['post_id'] === $mod_a_id, 'identical item again: action unchanged');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $mod_a2->to_payload(), 'expected_prod_hash' => $mod_a2->hash(), 'overwrite' => false), $code_parts);
assert_true(count(wp_get_post_revisions($mod_a_id)) === $revs_now, 'unchanged write adds no revision');

echo "\n=== 5. Prod edit: plan says prod_edited, write needs overwrite ===\n";
$plan_hash_before_edit = $mod_a2->hash();
wp_update_post(array('ID' => $mod_a_id, 'post_content' => "[aw2.module name='y'] edited on prod"));
list($st, $d) = call('POST', '/code/plan', array('items' => array(array('kind' => 'core', 'post_type' => $T_CORE, 'slug' => 'awxtest-mod-a', 'hash' => $mod_a2->hash()))), $code_parts);
$row = $d['items']["{$T_CORE}:awxtest-mod-a"];
$prod_hash_edited = Code_Item::from_post('core', get_post($mod_a_id))->hash();
assert_true($row['status'] === 'prod_edited' && $row['prod_hash'] === $prod_hash_edited && $row['last_synced_hash'] === $plan_hash_before_edit, 'plan reports prod_edited with the current prod hash and the last synced hash');
$mod_a3 = item('core', $T_CORE, 'awxtest-mod-a', 'Mod A v3', "[aw2.module name='z']", array('_aw_version' => array('3')));
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $mod_a3->to_payload(), 'expected_prod_hash' => $plan_hash_before_edit, 'overwrite' => false), $code_parts);
assert_true($st === 409 && $d['code'] === 'awx_sync_edited_on_prod' && $d['data']['details']['prod_hash'] === $prod_hash_edited, 'stale expected hash without overwrite: 409 edited_on_prod with the prod hash');
assert_true(strpos(get_post($mod_a_id)->post_content, 'edited on prod') !== false, 'nothing was written on 409');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $mod_a3->to_payload(), 'expected_prod_hash' => $plan_hash_before_edit, 'overwrite' => true), $code_parts);
assert_true($st === 200 && $d['action'] === 'updated' && strpos(get_post($mod_a_id)->post_content, 'edited on prod') === false, 'with overwrite the prod edit is replaced');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $mod_a2->to_payload(), 'expected_prod_hash' => null, 'overwrite' => false), $code_parts);
assert_true($st === 200 && $d['action'] === 'updated', 'no expected_prod_hash means no conflict check');

echo "\n=== 6. Never-synced identical module and hierarchy ===\n";
$plain = wp_insert_post(array('post_type' => $T_CORE, 'post_name' => 'awxtest-plain', 'post_title' => 'Plain', 'post_status' => 'publish', 'post_content' => 'plain content'), true);
track_post($plain);
$plain_hash = Code_Item::from_post('core', get_post($plain))->hash();
list($st, $d) = call('POST', '/code/plan', array('items' => array(
	array('kind' => 'core', 'post_type' => $T_CORE, 'slug' => 'awxtest-plain', 'hash' => $plain_hash),
	array('kind' => 'core', 'post_type' => $T_CORE, 'slug' => 'awxtest-stray', 'hash' => 'not-the-same'),
)), $code_parts);
assert_true($d['items']["{$T_CORE}:awxtest-plain"]['status'] === 'unchanged' && $d['items']["{$T_CORE}:awxtest-plain"]['never_synced'] === false && $d['items']["{$T_CORE}:awxtest-plain"]['last_synced_hash'] === null, 'never-synced identical module reports unchanged');
assert_true($d['items']["{$T_CORE}:awxtest-stray"]['status'] === 'changed' && $d['items']["{$T_CORE}:awxtest-stray"]['never_synced'] === true, 'never-synced different module reports changed with never_synced');
$child = item('core', $T_CORE, 'awxtest-mod-child', 'Child', 'child', array(), 'awxtest-mod-a');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $child->to_payload()), $code_parts);
track_post($d['post_id'] ?? 0);
assert_true($st === 200 && (int) get_post($d['post_id'])->post_parent === $mod_a_id && Code_Item::from_post('core', get_post($d['post_id']))->hash() === $child->hash(), 'parent_slug resolved to the prod parent within the same post type');
$orphan = item('core', $T_CORE, 'awxtest-mod-orphan', 'Orphan', 'orphan', array(), 'awxtest-no-such-parent');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $orphan->to_payload()), $code_parts);
track_post($d['post_id'] ?? 0);
assert_true($st === 200 && (int) get_post($d['post_id'])->post_parent === 0 && ($d['warnings'][0]['code'] ?? '') === 'parent_missing', 'unknown parent: saved at top level with a parent_missing warning');
$dotted = item('core', $T_CORE, 'awxtest.dotted', 'Dotted', 'dotted');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $dotted->to_payload()), $code_parts);
track_post($d['post_id'] ?? 0);
assert_true($st === 200 && get_post($d['post_id'])->post_name === 'awxtest.dotted', 'a slug WordPress would rewrite is restored (identity preserved)');

echo "\n=== 7. App definition + module of an unregistered post type ===\n";
assert_true(!post_type_exists($T_MOD), "precondition: {$T_MOD} is not registered");
$app = item('app', $T_APP, 'awxtest-app', 'AWX Test App', 'app definition', array('modules_collection' => array($T_MOD), 'config_collection' => array('awxtest_app_config')));
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $app->to_payload()), $code_parts);
track_post($d['post_id'] ?? 0);
$app_id = (int) ($d['post_id'] ?? 0);
assert_true($st === 200 && $d['action'] === 'created' && get_post_type($app_id) === $T_APP && get_post_meta($app_id, 'modules_collection', true) === $T_MOD, "app definition landed with its collection meta ({$st})");
$appmod = item('module', $T_MOD, 'awxtest-app-mod', 'App module', '[app.module]', array('_aw_version' => array('1')));
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $appmod->to_payload()), $code_parts);
track_post($d['post_id'] ?? 0);
assert_true($st === 200 && $d['action'] === 'created' && get_post($d['post_id'])->post_type === $T_MOD && get_post($d['post_id'])->post_status === 'publish' && get_post($d['post_id'])->post_name === 'awxtest-app-mod', 'module of the unregistered post type landed as publish');
$settings = item('config', 'awxtest_app_config', 'settings', 'Settings', 'settings body', array('secret_env' => array('prod-only')));
assert_true($settings->meta_skipped === true, 'config:settings item is meta_skipped');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $settings->to_payload()), $code_parts);
track_post($d['post_id'] ?? 0);
$settings_id = (int) ($d['post_id'] ?? 0);
add_post_meta($settings_id, 'prod_setting', 'keep-me');
$settings2 = item('config', 'awxtest_app_config', 'settings', 'Settings', 'settings body v2', array('secret_env' => array('dev-only')));
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $settings2->to_payload()), $code_parts);
assert_true($st === 200 && $d['action'] === 'updated' && get_post_meta($settings_id, 'prod_setting', true) === 'keep-me' && get_post_meta($settings_id, 'secret_env', true) === '', 'settings module: content updated, prod meta untouched, dev meta not written');
list($st, $d) = call('POST', '/code/plan', array('apps' => array('awxtest-app'), 'items' => array(array('kind' => 'app', 'post_type' => $T_APP, 'slug' => 'awxtest-app', 'hash' => $app->hash()))), $code_parts);
assert_true($d['apps']['awxtest-app'] === true && $d['items']["{$T_APP}:awxtest-app"]['status'] === 'unchanged', 'plan now sees the app and reports it unchanged');

echo "\n=== 8. Invalid payloads ===\n";
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => array('kind' => 'core', 'post_type' => $T_CORE, 'slug' => 'Bad Slug!', 'content' => '')), $code_parts);
assert_true($st === 400 && $d['code'] === 'awx_sync_invalid_payload', 'bad slug: 400 invalid_payload');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => array('kind' => 'core', 'post_type' => 'bad type', 'slug' => 'ok', 'content' => '')), $code_parts);
assert_true($st === 400 && $d['code'] === 'awx_sync_invalid_payload', 'bad post type: 400 invalid_payload');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => array('kind' => 'acf', 'post_type' => $T_CORE, 'slug' => 'ok', 'content' => '')), $code_parts);
assert_true($st === 400 && $d['code'] === 'awx_sync_invalid_payload', 'kind acf on /code/modules: 400 invalid_payload');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => array('kind' => 'nope', 'post_type' => $T_CORE, 'slug' => 'ok', 'content' => '')), $code_parts);
assert_true($st === 400 && $d['code'] === 'awx_sync_invalid_payload', 'unknown kind: 400 invalid_payload');
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => array('kind' => 'core', 'post_type' => $T_CORE, 'slug' => 'awxtest-trash', 'content' => '', 'status' => 'trash')), $code_parts);
assert_true($st === 422 && $d['code'] === 'awx_sync_status_not_allowed', 'trash status: 422');
list($st, $d) = call('POST', '/code/modules', array('run_id' => 'x', 'step_id' => 'y', 'item' => $mod_a->to_payload()), $code_parts);
assert_true($st === 400 && $d['code'] === 'awx_sync_invalid_payload', 'non-UUID run/step ids: 400');

echo "\n=== 9. ACF route without ACF ===\n";
assert_true(!function_exists('acf_import_field_group'), 'precondition: ACF is not active on this install');
list($st, $d) = call('POST', '/code/acf-field-groups', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'key' => 'group_awxtest', 'group' => array('key' => 'group_awxtest', 'title' => 'T', 'fields' => array()), 'hash' => 'h'), $code_parts);
assert_true($st === 422 && $d['code'] === 'awx_sync_acf_inactive', 'ACF route answers 422 acf_inactive');

echo "\n=== 10. Block definition + finalize ===\n";
if (!function_exists('aw2\gutenberg_blocks\dgb')) {
	eval('
	namespace aw2\gutenberg_blocks;
	class AWX_Test_DGB {
		public $invalidated = 0;
		public $recompiled = array();
		public function invalidate_registry() { $this->invalidated++; }
		public function recompile_single_block_assets($slug) { $this->recompiled[] = $slug; return array("css" => null, "js" => null); }
	}
	function dgb() { static $i; if (!$i) { $i = new AWX_Test_DGB(); } return $i; }
	');
}
if (!function_exists('aw2\global_cache\flush')) {
	eval('namespace aw2\global_cache; function flush($atts, $content = null, $shortcode = null) { $GLOBALS["awx_test_gc_flushed"] = ($GLOBALS["awx_test_gc_flushed"] ?? 0) + 1; return true; }');
}
$block = item('dgb', $T_DGB, 'awxtest-block', 'AWX Test Block', '{"name":"awxtest-block"}', array('_aw_version' => array('1.0.0')));
list($st, $d) = call('POST', '/code/modules', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'item' => $block->to_payload()), $code_parts);
track_post($d['post_id'] ?? 0);
assert_true($st === 200 && $d['action'] === 'created' && $d['kind'] === 'dgb' && get_post_meta($d['post_id'], '_aw_version', true) === '1.0.0', 'aw_gb_blocks definition written with its version');
$written = Code_Receiver::written_in_run($run);
assert_true($written['blocks'] === array('awxtest-block') && $written['app_created'] === true && count($written['items']) >= 8, 'run bookkeeping (from the step rows): one block slug, an app was created');
$dgb = \aw2\gutenberg_blocks\dgb();
$hook_calls = array();
add_action('awx_sync_after_code_deploy', function ($rid, $w) use (&$hook_calls) { $hook_calls[] = array($rid, $w); }, 10, 2);
$fstep = wp_generate_uuid4();
list($st, $d) = call('POST', '/code/finalize', array('run_id' => $run, 'step_id' => $fstep), $code_parts);
assert_true($st === 200 && $d['flushed'] === true && $d['blocks_recompiled'] === array('awxtest-block') && $d['rewrite_flushed'] === true, "finalize flushed, recompiled the block and flushed rewrites ({$st})");
assert_true($dgb->invalidated === 1 && $dgb->recompiled === array('awxtest-block'), 'dgb registry invalidated once and the written slug recompiled');
assert_true(($GLOBALS['awx_test_gc_flushed'] ?? 0) === 1, 'global cache flushed');
assert_true(count($hook_calls) === 1 && $hook_calls[0][0] === $run && $hook_calls[0][1]['blocks'] === array('awxtest-block'), 'awx_sync_after_code_deploy fired once with the run id and the written set');
$d2 = (new Code_Receiver())->finalize($run, $fstep);
assert_true(!empty($d2['replayed']) && $d2['blocks_recompiled'] === array('awxtest-block') && $dgb->invalidated === 1 && count($hook_calls) === 1, 'finalize with the same step_id is replayed: no second flush');
$run2 = wp_generate_uuid4();
$created['runs'][] = $run2;
$d3 = (new Code_Receiver())->finalize($run2, wp_generate_uuid4());
assert_true($d3['blocks_recompiled'] === array() && $d3['rewrite_flushed'] === false && $d3['written'] === 0, 'finalize of an empty run recompiles nothing and does not flush rewrites');

echo "\n=== 11. Run status and audit log ===\n";
list($st, $d) = call('GET', '/runs/' . $run, null, $sync_parts);
$types = array_unique(array_column($d['steps'], 'type'));
$keys  = array_column($d['steps'], 'object_uuid');
assert_true($st === 200 && in_array('code', $types, true) && in_array('finalize', $types, true) && in_array("{$T_CORE}:awxtest-mod-a", $keys, true), 'run status lists code and finalize steps keyed by post_type:slug');
$log = Audit_Log::recent(200, array('run_id' => $run));
$objs = array_unique(array_filter(array_column($log, 'object_uuid')));
$routes_logged = array_unique(array_column($log, 'route'));
assert_true(in_array("{$T_CORE}:awxtest-mod-a", $objs, true) && in_array('acf-field-group:group_awxtest', $objs, true), 'audit rows carry post_type:slug for modules and ACF keys');
assert_true(in_array('code/modules', $routes_logged, true) && in_array('code/finalize', $routes_logged, true) && in_array('conflict', array_column($log, 'result'), true), 'audit log recorded the code routes and the conflict');

echo "\n=== 12. Routes absent without AWX_SYNC_ALLOW_CODE (child process) ===\n";
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($wp_load) . ' --routes-check 2>/dev/null';
$out = shell_exec($cmd);
$j = json_decode((string) $out, true);
assert_true(is_array($j) && $j['allowed'] === false && $j['base_present'] === true && $j['code_routes'] === array(), 'without the constant the base routes exist but no /code/* route does (' . trim((string) $out) . ')');

// ---- cleanup ----
echo "\n=== cleanup ===\n";
global $wpdb;
foreach (array_reverse($created['posts']) as $pid) {
	wp_delete_post($pid, true);
}
foreach ($created['tokens'] as $tid) {
	$wpdb->delete(Schema::table('tokens'), array('token_id' => $tid));
	$wpdb->delete(Schema::table('log'), array('token_id' => $tid));
	$wpdb->delete(Schema::table('nonces'), array('token_id' => $tid));
}
foreach ($created['runs'] as $rid) {
	$wpdb->delete(Schema::table('steps'), array('run_id' => $rid));
	$wpdb->delete(Schema::table('log'), array('run_id' => $rid));
}
$svc = get_user_by('login', Service_User::LOGIN);
if ($svc) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user($svc->ID);
}
remove_role(Service_User::ROLE);
$left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name LIKE 'awxtest%' OR post_type IN ('awxtest_app_modules','awxtest_app_config')");
echo "cleaned up (leftover test posts: {$left})\n\n";

if ($failures > 0) {
	echo "❌ {$failures} of {$asserts} assertion(s) failed.\n";
	exit(1);
}
echo "✅ All {$asserts} assertions passed.\n";
