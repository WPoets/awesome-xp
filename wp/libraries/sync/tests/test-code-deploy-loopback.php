<?php
/**
 * AWX Sync: end-to-end test of a code deploy with Dev and Prod in one
 * WordPress. The Sync_Client transport is replaced by a loopback that calls
 * the Prod receivers directly and maps every code post type to a "tgt_"
 * twin, so the same database holds both sides.
 *
 *   AWX_TEST_WP_LOAD=/path/to/wp-load.php php test-code-deploy-loopback.php
 *
 * Creates and finally deletes posts, runs, steps, sets and a token. Use a
 * throwaway install.
 */
$wp_load = getenv('AWX_TEST_WP_LOAD') ?: ($argv[1] ?? '/Users/macbook/development/wptest/wp-load.php');
if (!is_file($wp_load)) {
	fwrite(STDERR, "SKIP: no WordPress at {$wp_load}\n");
	exit(0);
}
if (function_exists('mysqli_report')) {
	mysqli_report(MYSQLI_REPORT_OFF);
}
define('WP_USE_THEMES', false);
define('AWX_SYNC_ROLE', 'source');
define('AWX_SYNC_ALLOW_CODE', true);
define('AWX_SYNC_ALLOW_HTTP', true);
define('AWX_SYNC_TARGET_URL', 'http://target.test');
define('AWX_SYNC_TOKEN', str_repeat('a', 16) . '.' . str_repeat('b', 48));
define('AWESOME_CORE_POST_TYPE', 'awesome_core');
define('AWESOME_APPS_POST_TYPE', 'aw2_app');
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Minimal stand-in for the plugin runtime: one service handler and one app.
class aw2_library {
	public static $stack = array(
		'handlers' => array(
			'mailer' => array('post_type' => 'svc_mailer', '@service' => true, 'service_label' => 'Mailer'),
			'util'   => array('namespace' => 'x'),
		),
		'apps' => array(
			'crm' => array('name' => 'CRM', 'slug' => 'crm', 'post_id' => 0, 'collection' => array(
				'config'  => array('post_type' => 'crm_config'),
				'modules' => array('post_type' => 'crm_modules'),
				'pages'   => array('post_type' => 'crm_pages'),
			)),
		),
	);
	public static function &get_array_ref($key) {
		if (!isset(self::$stack[$key])) {
			self::$stack[$key] = array();
		}
		return self::$stack[$key];
	}
}

require_once $wp_load;
// Let the WP-CLI command class load so its flag parser can be tested.
if (!defined('WP_CLI')) {
	define('WP_CLI', true);
}
if (!class_exists('WP_CLI_Command')) {
	class WP_CLI_Command {}
}
if (!class_exists('WP_CLI')) {
	class WP_CLI { public static function add_command() {} }
}
require_once dirname(__DIR__) . '/sync-init.php';
aw2\sync\init();
do_action('init');

use aw2\sync\Code_Catalog;
use aw2\sync\Code_Item;
use aw2\sync\Code_Orchestrator;
use aw2\sync\Code_Receiver;
use aw2\sync\Deploy_Sets;
use aw2\sync\Identity;
use aw2\sync\Manifest_Store;
use aw2\sync\Receiver;
use aw2\sync\Schema;
use aw2\sync\Step_Store;
use aw2\sync\Sync_Client;
use aw2\sync\Sync_Exception;
use aw2\sync\Sync_Http_Exception;

$failures = 0;
$total = 0;
$created = array('posts' => array(), 'runs' => array());
function assert_true($c, $m) { global $failures, $total; $total++; if (!$c) { $failures++; echo "❌ FAIL: $m\n"; return; } echo "✅ PASS: $m\n"; }

Schema::install();
update_option(Schema::OPTION, Schema::DB_VERSION, false);

register_post_type('awesome_core', array('public' => false, 'hierarchical' => true, 'supports' => array('title', 'editor', 'revisions', 'custom-fields')));
foreach (array('svc_mailer', 'crm_config', 'crm_modules', 'aw_gb_blocks', 'aw2_app', 'tgt_awesome_core', 'tgt_svc_mailer', 'tgt_crm_config', 'tgt_crm_modules', 'tgt_aw_gb_blocks', 'tgt_aw2_app') as $pt) {
	register_post_type($pt, array('public' => false, 'hierarchical' => $pt === 'tgt_awesome_core', 'supports' => array('title', 'editor', 'revisions', 'custom-fields')));
}

function mk($type, $slug, $title, $content, $meta = array(), $parent = 0) {
	global $created;
	$id = wp_insert_post(array('post_type' => $type, 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_parent' => $parent), true);
	if (is_wp_error($id)) {
		throw new RuntimeException($id->get_error_message());
	}
	foreach ($meta as $k => $v) {
		update_post_meta($id, $k, $v);
	}
	$created['posts'][] = (int) $id;
	return (int) $id;
}
function tgt($type, $slug) {
	global $wpdb;
	$id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status <> 'trash' LIMIT 1", 'tgt_' . $type, $slug));
	return $id ? get_post((int) $id) : null;
}

/* ---- loopback transport: Dev → (map post types) → Prod receivers in-process ---- */
$transport_log = array();
$fail_next = null; // ['route' => 'code/modules', 'status' => 500, 'retryable' => true, 'times' => 1]
$transport = function ($url, $args) use (&$transport_log, &$fail_next) {
	global $created;
	$path  = (string) parse_url($url, PHP_URL_PATH);
	$route = preg_replace('#^.*/awx-sync/v1/#', '', $path);
	$body  = json_decode((string) ($args['body'] ?? ''), true) ?: array();
	$transport_log[] = $route;
	$map   = function ($pt) { return strpos((string) $pt, 'tgt_') === 0 ? $pt : 'tgt_' . $pt; };
	$unmap = function ($key) { return preg_replace('/^tgt_/', '', (string) $key); };
	$respond = function ($status, $data) { return array('response' => array('code' => $status), 'body' => wp_json_encode($data)); };
	try {
		if ($fail_next && $fail_next['route'] === $route && $fail_next['times'] > 0) {
			$fail_next['times']--;
			throw new Sync_Http_Exception('injected', 'Injected failure', $fail_next['status'], array(), $fail_next['retryable']);
		}
		switch ($route) {
			case 'handshake':
				return $respond(200, (new Receiver())->handshake($body));
			case 'code/plan':
				foreach ($body['items'] as &$it) { $it['post_type'] = $map($it['post_type']); }
				unset($it);
				foreach ($body['collections'] as &$c) { $c['post_type'] = $map($c['post_type']); }
				unset($c);
				$out = (new Code_Receiver())->plan($body);
				$items = array();
				foreach ($out['items'] as $k => $v) { $items[$unmap($k)] = $v; }
				$out['items'] = $items;
				$oop = array();
				foreach ($out['only_on_prod'] as $pt => $list) { $oop[$unmap($pt)] = $list; }
				$out['only_on_prod'] = $oop;
				// the app existence check must look at the target twin
				foreach ((array) ($body['apps'] ?? array()) as $slug) { $out['apps'][$slug] = tgt('aw2_app', $slug) !== null; }
				return $respond(200, $out);
			case 'code/modules':
				$body['item']['post_type'] = $map($body['item']['post_type']);
				$res = (new Code_Receiver())->write_module($body, $body['run_id'], $body['step_id']);
				if (!empty($res['post_id']) && !in_array((int) $res['post_id'], $created['posts'], true)) { $created['posts'][] = (int) $res['post_id']; }
				return $respond(200, $res);
			case 'code/acf-field-groups':
				return $respond(200, (new Code_Receiver())->write_acf_group($body, $body['run_id'], $body['step_id']));
			case 'code/finalize':
				return $respond(200, (new Code_Receiver())->finalize($body['run_id'], $body['step_id']));
			default:
				return $respond(404, array('code' => 'rest_no_route', 'message' => 'No route ' . $route, 'data' => array('status' => 404)));
		}
	} catch (Sync_Http_Exception $e) {
		return $respond($e->status, array('code' => 'awx_sync_' . $e->code_slug, 'message' => $e->getMessage(), 'data' => array('status' => $e->status, 'retryable' => $e->retryable, 'details' => $e->details)));
	}
};
$client = Sync_Client::from_config();
$client->transport = $transport;
$client->sleeper = function () {};
$o = new Code_Orchestrator($client);
$uid = 1;
wp_set_current_user($uid);

try {
	echo "=== 1. Source content ===\n";
	$hdr  = mk('awesome_core', 'lb-header', 'Header', '[aw2.module name="x"]', array('_aw_version' => '2', 'note' => 'keep', '_edit_lock' => '1:1'));
	$sub  = mk('awesome_core', 'lb-header-sub', 'Header sub', '[sub]', array(), $hdr);
	$gb   = mk('awesome_core', 'gutenberg-blocks', 'GB', '[acf_blocks.register]');
	$mail = mk('svc_mailer', 'lb-send', 'Send', '[mail.send]');
	$app  = mk('aw2_app', 'crm', 'CRM', 'app def', array('config_collection' => 'crm_config', 'modules_collection' => 'crm_modules', 'pages_collection' => 'crm_pages'));
	aw2_library::$stack['apps']['crm']['post_id'] = $app;
	$set  = mk('crm_config', 'settings', 'Settings', '[settings]', array('api_key' => 'SECRET-DEV', 'endpoint' => 'https://dev.example.com'));
	$lead = mk('crm_modules', 'lead', 'Lead', '[lead] http://localhost/x', array('serialized' => array('a' => 1, 'b' => array(2, 3))));
	$hero = mk('aw_gb_blocks', 'hero', 'Hero', '{"config":{"name":"hero"}}', array('_aw_version' => '4'));

	$catalog = new Code_Catalog();
	$tree = $catalog->build();
	assert_true(count($tree['core']['modules']) === 3 && $tree['core']['modules'][0]['slug'] !== 'lb-header-sub', 'tree lists core modules, child after parent');
	assert_true(count($tree['services']) === 1 && $tree['services'][0]['post_type'] === 'svc_mailer' && count($tree['services'][0]['modules']) === 1, 'tree lists the service post type with its module');
	assert_true(count($tree['apps']) === 1 && $tree['apps'][0]['definition']['slug'] === 'crm' && isset($tree['apps'][0]['collections']['config'], $tree['apps'][0]['collections']['modules'], $tree['apps'][0]['collections']['pages']), 'tree lists the app with definition and collections');
	assert_true($tree['apps'][0]['collections']['config']['modules'][0]['meta_skipped'] === true, 'settings module is flagged meta_skipped in the tree');
	assert_true($tree['blocks']['dgb']['modules'][0]['version'] === '4' && $tree['blocks']['acf']['module']['slug'] === 'gutenberg-blocks', 'block definition shows its version; ACF node finds the registration module');
	assert_true(in_array('crm_pages', Code_Catalog::content_post_types(), true) && in_array('crm_pages', Receiver::allowed_post_types(), true), 'app pages collection is a content post type and allowed on the receiver');

	echo "\n=== 2. Analyze + plan: everything new ===\n";
	$selection = array('core' => '*', 'services' => array('svc_mailer' => '*'), 'apps' => array('crm' => array('definition' => true, 'config' => '*', 'modules' => '*')), 'dgb' => '*', 'acf' => '*');
	$a = $o->analyze_selection($selection, $uid, 'loopback');
	$created['runs'][] = $a['run_id'];
	assert_true($a['counts']['items'] === 8 && $a['counts']['content'] === 0, 'selection expands to 8 code items (3 core, 1 service, app, settings, lead, hero; no ACF groups here): ' . $a['counts']['items']);
	$v = $o->plan($a['run_id']);
	$rows = array();
	foreach ($v['plan']['rows'] as $r) { $rows[$r['key']] = $r; }
	assert_true($v['status'] === 'awaiting_confirm', 'plan awaits confirmation: ' . $v['status']);
	assert_true(($rows['awesome_core:lb-header']['status'] ?? '') === 'new' && ($rows['aw2_app:crm']['status'] ?? '') === 'new' && ($rows['crm_modules:lead']['status'] ?? '') === 'new', 'all items are new on the target');
	assert_true(!empty($rows['crm_modules:lead']['warnings']) && $rows['crm_modules:lead']['warnings'][0]['code'] === 'source_host_in_code', 'module mentioning the source host carries a warning');
	assert_true(($rows['crm_config:settings']['meta_skipped'] ?? false) === true, 'settings row flagged meta_skipped');
	assert_true(count($v['plan']['selected_keys']) === 8, 'all 8 new items ticked by default');
	$order = array_map(function ($r) { return $r['key']; }, $v['plan']['rows']);
	assert_true(array_search('aw2_app:crm', $order) < array_search('awesome_core:lb-header', $order) && array_search('awesome_core:lb-header', $order) < array_search('awesome_core:lb-header-sub', $order), 'rows keep dependency order (app, then core parent before child)');

	echo "\n=== 3. Confirm + run ===\n";
	$threw = null;
	try { $o->confirm_selection($a['run_id'], array('nope:x')); } catch (Sync_Exception $e) { $threw = $e->code_slug; }
	assert_true($threw === 'invalid_selection', 'unknown key rejected at confirm');
	$v = $o->confirm_selection($a['run_id'], $v['plan']['selected_keys']);
	$types = array_map(function ($s) { return $s['type']; }, $v['steps']);
	assert_true($v['status'] === 'running' && count($v['steps']) === 9 && end($types) === 'finalize', '8 code steps plus finalize');
	$guard = 0;
	while ($v['status'] === 'running' && $guard++ < 20) {
		$v = $o->step($a['run_id']);
		if (!empty($v['error'])) { echo "   step error: {$v['error']['code']} {$v['error']['message']}\n"; }
	}
	assert_true($v['status'] === 'done' && $v['progress']['done'] === 9, 'run completes: ' . $v['status'] . ' ' . json_encode($v['progress']));
	$t_hdr = tgt('awesome_core', 'lb-header');
	$t_sub = tgt('awesome_core', 'lb-header-sub');
	assert_true($t_hdr && $t_hdr->post_content === '[aw2.module name="x"]' && get_post_meta($t_hdr->ID, '_aw_version', true) === '2' && get_post_meta($t_hdr->ID, 'note', true) === 'keep' && get_post_meta($t_hdr->ID, '_edit_lock', true) === '', 'core module written with synced meta only');
	assert_true($t_sub && (int) $t_sub->post_parent === (int) $t_hdr->ID, 'hierarchy restored on the target by parent slug');
	$t_set = tgt('crm_config', 'settings');
	assert_true($t_set && get_post_meta($t_set->ID, 'api_key', true) === '' && get_post_meta($t_set->ID, 'endpoint', true) === '', 'settings module written without its environment meta');
	$t_lead = tgt('crm_modules', 'lead');
	assert_true($t_lead && get_post_meta($t_lead->ID, 'serialized', true) === array('a' => 1, 'b' => array(2, 3)), 'serialized meta round-trips');
	$t_hero = tgt('aw_gb_blocks', 'hero');
	assert_true($t_hero && get_post_meta($t_hero->ID, '_aw_version', true) === '4' && get_post_meta($t_hero->ID, Receiver::META_SOURCE_HASH, true) !== '', 'block definition written with version and source hash');
	assert_true(tgt('aw2_app', 'crm') && get_post_meta(tgt('aw2_app', 'crm')->ID, 'modules_collection', true) === 'crm_modules', 'app definition written with its collection meta');
	$ls = get_post_meta($hdr, Code_Catalog::META_LAST_SYNC, true);
	assert_true(is_array($ls) && $ls['run_id'] === $a['run_id'], 'source module records the deploy');
	$fin = end($v['steps']);
	// (block slugs are derived from the aw_gb_blocks post type, which the loopback maps to tgt_aw_gb_blocks, so the recompile list is empty here)
	assert_true(($fin['result']['flushed'] ?? false) === true && (int) ($fin['result']['written'] ?? 0) === 8, 'finalize ran over the 8 written items: ' . json_encode($fin['result']));
	$set_row = Deploy_Sets::get('loopback');
	assert_true($set_row === null, 'a label alone does not create a set');

	echo "\n=== 4. Re-plan: nothing to do ===\n";
	$a2 = $o->analyze_selection($selection, $uid);
	$created['runs'][] = $a2['run_id'];
	$v2 = $o->plan($a2['run_id']);
	$by = $v2['plan']['summary']['by_status'];
	assert_true($v2['status'] === 'done' && $by['unchanged'] === 8 && $v2['plan']['summary']['nothing_to_do'], 'second plan is all unchanged: ' . json_encode($by));

	echo "\n=== 5. Prod edit → overwrite ===\n";
	wp_update_post(array('ID' => $t_hdr->ID, 'post_content' => 'edited on prod'));
	wp_update_post(array('ID' => $lead, 'post_content' => '[lead v2]'));
	$a3 = $o->analyze_selection($selection, $uid);
	$created['runs'][] = $a3['run_id'];
	$v3 = $o->plan($a3['run_id']);
	$rows = array();
	foreach ($v3['plan']['rows'] as $r) { $rows[$r['key']] = $r; }
	assert_true($rows['awesome_core:lb-header']['status'] === 'prod_edited' && $rows['crm_modules:lead']['status'] === 'changed' && $rows['svc_mailer:lb-send']['status'] === 'unchanged', 'plan distinguishes prod_edited, changed and unchanged');
	assert_true($v3['plan']['selected_keys'] === array('awesome_core:lb-header', 'crm_modules:lead') || count($v3['plan']['selected_keys']) === 2, 'only the two items needing a write are ticked');
	$threw = null;
	try { $o->confirm_selection($a3['run_id'], $v3['plan']['selected_keys']); } catch (Sync_Exception $e) { $threw = $e->code_slug; }
	assert_true($threw === 'overwrite_required', 'confirm without overwrite is refused for a prod-edited item');
	$v3 = $o->confirm_selection($a3['run_id'], array('crm_modules:lead'));
	assert_true($v3['status'] === 'running' && count($v3['steps']) === 2, 'confirming only the changed module needs no overwrite');
	while ($v3['status'] === 'running') { $v3 = $o->step($a3['run_id']); }
	assert_true(tgt('crm_modules', 'lead')->post_content === '[lead v2]' && $t_hdr->ID === tgt('awesome_core', 'lb-header')->ID && tgt('awesome_core', 'lb-header')->post_content === 'edited on prod', 'only the ticked module was written');
	$a4 = $o->analyze_selection(array('core' => array('lb-header')), $uid);
	$created['runs'][] = $a4['run_id'];
	$v4 = $o->plan($a4['run_id']);
	$v4 = $o->confirm_selection($a4['run_id'], array('awesome_core:lb-header'), true);
	while ($v4['status'] === 'running') { $v4 = $o->step($a4['run_id']); }
	$t_hdr = tgt('awesome_core', 'lb-header');
	assert_true($v4['status'] === 'done' && $t_hdr->post_content === '[aw2.module name="x"]', 'overwrite restores the source content');
	$revs = wp_get_post_revisions($t_hdr->ID);
	assert_true(count($revs) >= 1, 'a revision of the overwritten module is kept (' . count($revs) . ')');

	echo "\n=== 6. Retry and resume ===\n";
	wp_update_post(array('ID' => $mail, 'post_content' => '[mail.send v2]'));
	$a5 = $o->analyze_selection(array('services' => array('svc_mailer' => '*')), $uid);
	$created['runs'][] = $a5['run_id'];
	$v5 = $o->plan($a5['run_id']);
	$v5 = $o->confirm_selection($a5['run_id'], $v5['plan']['selected_keys']);
	$fail_next = array('route' => 'code/modules', 'status' => 503, 'retryable' => true, 'times' => 3); // the client itself retries 3 times
	$v5 = $o->step($a5['run_id']);
	assert_true(($v5['error']['retryable'] ?? false) === true && $v5['status'] === 'running' && $v5['step']['status'] === 'pending' && $v5['step']['attempts'] === 1, 'retryable failure keeps the step pending and the run running');
	$fail_next = null;
	$v5 = $o->step($a5['run_id']);
	assert_true(empty($v5['error']) && $v5['step']['status'] === 'done', 'retry succeeds');
	$fail_next = array('route' => 'code/finalize', 'status' => 422, 'retryable' => false, 'times' => 1);
	$v5 = $o->step($a5['run_id']);
	assert_true($v5['status'] === 'failed' && ($v5['error']['code'] ?? '') === 'injected', 'non-retryable failure fails the run');
	$v5 = $o->resume($a5['run_id']);
	assert_true($v5['status'] === 'done' && $v5['plan']['summary']['nothing_to_do'], 'resume re-plans; the module already written is unchanged so nothing is left');
	$fail_next = null;

	echo "\n=== 7. Deployment sets and CLI flags ===\n";
	$saved = Deploy_Sets::save('Release 1!', $selection, $uid);
	assert_true($saved['name'] === 'release-1-' && $saved['selection']['core'] === '*', 'set saved with a cleaned name');
	assert_true(count(Deploy_Sets::all()) >= 1 && Deploy_Sets::get('release-1-') !== null, 'set listed and fetched');
	Deploy_Sets::touch('release-1-', $a5['run_id']);
	assert_true(Deploy_Sets::get('release-1-')['last_run_id'] === $a5['run_id'], 'set records its last run');
	assert_true(Deploy_Sets::delete('release-1-') && Deploy_Sets::get('release-1-') === null, 'set deleted');
	$flags = aw2\sync\Sync_CLI_Command::selection_from_flags(array('core' => true, 'services' => 'svc_mailer:lb-send|x', 'apps' => 'crm', 'with-pages' => true, 'blocks' => 'hero,cta', 'acf' => true));
	assert_true($flags['core'] === '*' && $flags['services']['svc_mailer'] === array('lb-send', 'x') && $flags['apps']['crm']['pages'] === '*' && $flags['apps']['crm']['definition'] === true && $flags['dgb'] === array('hero', 'cta') && $flags['acf'] === '*', 'CLI flags map to the selection object: ' . json_encode($flags));
	if (!class_exists('aw2\abilities\operations\Operation_Base')) {
		require_once dirname(__DIR__, 3) . '/abilities/operations/class-operation-base.php';
	}
	require_once dirname(__DIR__) . '/abilities/class-sync-abilities.php';
	require_once dirname(__DIR__) . '/abilities/class-sync-code-abilities.php';
	$clean = aw2\sync\abilities\Code_Operation::clean_selection(array('core' => 'a,b', 'apps' => array('crm' => array('definition' => 1, 'modules' => array('lead'), 'pages' => false)), 'services' => array('svc_mailer' => '*'), 'patterns' => array()));
	assert_true($clean['core'] === array('a', 'b') && $clean['apps']['crm'] === array('definition' => true, 'modules' => array('lead')) && $clean['services']['svc_mailer'] === '*' && !isset($clean['patterns']), 'ability input is normalised');

	echo "\n=== 8. Empty and blocked selections ===\n";
	$threw = null;
	try { $o->analyze_selection(array('patterns' => array('does-not-exist')), $uid); } catch (Sync_Exception $e) { $threw = $e->code_slug; }
	assert_true($threw === 'empty_selection', 'a selection matching nothing is refused');
} catch (Throwable $e) {
	$failures++;
	echo "❌ EXCEPTION: " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

/* ---- cleanup ---- */
global $wpdb;
foreach ($created['posts'] as $pid) {
	wp_delete_post($pid, true);
}
foreach ($created['runs'] as $rid) {
	$wpdb->delete(Schema::table('runs'), array('run_id' => $rid));
	$wpdb->delete(Schema::table('steps'), array('run_id' => $rid));
	$wpdb->delete(Schema::table('log'), array('run_id' => $rid));
}
$wpdb->delete(Schema::table('sets'), array('name' => 'release-1-'));
$left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type LIKE 'tgt_%' OR post_name LIKE 'lb-%'");
echo "cleaned up (leftover test posts: {$left})\n";
echo "\n" . ($failures ? "❌ {$failures} of {$total} assertions failed\n" : "✅ All {$total} assertions passed\n");
exit($failures ? 1 : 0);
