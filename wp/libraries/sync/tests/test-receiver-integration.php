<?php
/**
 * AWX Sync: integration test of the Prod receiver against a real WordPress.
 *
 *   AWX_TEST_WP_LOAD=/path/to/wp-load.php php test-receiver-integration.php
 *
 * Creates (and finally deletes) a token, an attachment, a reusable block, a
 * page, a category term and the awx-sync service user on that site. Use a
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
define('AWX_SYNC_ROLE', 'target');
define('AWX_SYNC_ALLOW_HTTP', true);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once $wp_load;
require_once dirname(__DIR__) . '/sync-init.php';
aw2\sync\init();

use aw2\sync\Identity;
use aw2\sync\Media_Handler;
use aw2\sync\Schema;
use aw2\sync\Service_User;
use aw2\sync\Signer;
use aw2\sync\Step_Store;
use aw2\sync\Sync_Http_Exception;
use aw2\sync\Token_Store;
use aw2\sync\Transformer;

$failures = 0;
$created  = array('posts' => array(), 'terms' => array(), 'tokens' => array());
function assert_true($c, $m) { global $failures; if (!$c) { $failures++; echo "❌ FAIL: $m\n"; return; } echo "✅ PASS: $m\n"; }

Schema::install();
update_option(Schema::OPTION, Schema::DB_VERSION, false);
$tok = Token_Store::create('integration', 1);
$created['tokens'][] = $tok['token_id'];
$parts = Signer::split_token($tok['token']);
$server = rest_get_server(); // registers routes

function call($method, $route, $body = array(), $parts = null, $tamper = null) {
	$json = $body === null ? '' : wp_json_encode($body);
	$req  = new WP_REST_Request($method, '/awx-sync/v1' . $route);
	if ($parts) {
		$path = Signer::normalise_path(rest_get_url_prefix() . '/awx-sync/v1' . $route);
		$headers = Signer::sign($parts['token_id'], $parts['secret'], $method, $path, $json);
		if ($tamper) {
			$headers = $tamper($headers);
		}
		foreach ($headers as $k => $v) {
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

echo "=== 1. Authentication ===\n";
list($st, $d) = call('POST', '/handshake', array('protocol' => 1), null);
assert_true($st === 401, "unsigned request is rejected ({$st})");
list($st, $d) = call('POST', '/handshake', array('protocol' => 1), $parts, function ($h) { $h[Signer::H_SIGNATURE] = str_repeat('0', 64); return $h; });
assert_true($st === 401 && $d['code'] === 'awx_sync_bad_signature', 'wrong signature rejected');
list($st, $d) = call('POST', '/handshake', array('protocol' => 1), $parts, function ($h) { $h[Signer::H_TIMESTAMP] = (string) (time() - 1000); return $h; });
assert_true($st === 400 && $d['code'] === 'awx_sync_stale_timestamp', 'stale timestamp rejected');
$fixed = null;
list($st, $d) = call('POST', '/handshake', array('protocol' => 1), $parts, function ($h) use (&$fixed) { $fixed = $h; return $h; });
assert_true($st === 200 && (int) $d['protocol'] === 1 && strlen($d['registry_hash']) === 64 && is_array($d['post_types']), 'signed handshake answers with protocol, registry hash and post types');
assert_true($d['push_allowed'] === true && $d['pull']['configured'] === false && isset($d['limits']['upload_max_bytes']), 'handshake reports push allowed, pull unconfigured and upload limit');
list($st, $d) = call('POST', '/handshake', array('protocol' => 1), $parts, function ($h) use ($fixed) { return $fixed; });
assert_true($st === 409 && $d['code'] === 'awx_sync_replayed_nonce', 'replayed nonce rejected');
Token_Store::revoke($tok['token_id']);
list($st, $d) = call('POST', '/handshake', array('protocol' => 1), $parts);
assert_true($st === 401 && $d['code'] === 'awx_sync_token_revoked', 'revoked token rejected');
$tok = Token_Store::create('integration-2', 1);
$created['tokens'][] = $tok['token_id'];
$parts = Signer::split_token($tok['token']);

echo "\n=== 2. Media store ===\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$tmp = tempnam(sys_get_temp_dir(), 'awx') . '.png';
file_put_contents($tmp, $png);
$M1 = wp_generate_uuid4();
$mh = new Media_Handler();
try {
	$mh->store($tmp, array('uuid' => $M1, 'sha256' => 'deadbeef', 'file' => 'dot.png'), false);
	assert_true(false, 'hash mismatch throws');
} catch (Sync_Http_Exception $e) {
	assert_true($e->code_slug === 'hash_mismatch' && $e->status === 422, 'hash mismatch throws 422');
}
$txt = tempnam(sys_get_temp_dir(), 'awx') . '.txt';
file_put_contents($txt, 'hello');
try {
	$mh->store($txt, array('uuid' => wp_generate_uuid4(), 'sha256' => hash('sha256', 'hello'), 'file' => 'x.txt'), false);
	assert_true(false, 'blocked mime throws');
} catch (Sync_Http_Exception $e) {
	assert_true($e->code_slug === 'mime_blocked', 'text file is blocked by the MIME allowlist');
}
$php = tempnam(sys_get_temp_dir(), 'awx') . '.png';
file_put_contents($php, "<?php echo 1;");
try {
	$mh->store($php, array('uuid' => wp_generate_uuid4(), 'sha256' => hash('sha256', "<?php echo 1;"), 'file' => 'evil.png'), false);
	assert_true(false, 'php disguised as png throws');
} catch (Sync_Http_Exception $e) {
	assert_true($e->code_slug === 'mime_blocked', 'PHP disguised as PNG is blocked');
}
file_put_contents($tmp, $png);
$r = $mh->store($tmp, array('uuid' => $M1, 'sha256' => hash('sha256', $png), 'file' => 'dot.png', 'alt' => 'A dot', 'title' => 'Dot'), false);
$created['posts'][] = $r['id'];
assert_true($r['created'] === true && get_post_type($r['id']) === 'attachment' && get_post_meta($r['id'], Identity::META_UUID, true) === $M1, 'png stored as attachment with uuid');
assert_true(get_post_meta($r['id'], Identity::META_FILE_HASH, true) === hash('sha256', $png) && get_post_meta($r['id'], '_wp_attachment_image_alt', true) === 'A dot', 'file hash and alt text stamped');
assert_true(get_post($r['id'])->post_author === (string) Service_User::ensure_user(), 'attachment owned by the service user');
file_put_contents($tmp, $png);
$r2 = $mh->store($tmp, array('uuid' => $M1, 'sha256' => hash('sha256', $png), 'file' => 'dot.png'), false);
assert_true($r2['created'] === false && $r2['id'] === $r['id'], 'same uuid replaces in place, id unchanged');
$media_id = $r['id'];
@unlink($txt); @unlink($php);

echo "\n=== 3. Reusable block via REST ===\n";
$B1 = wp_generate_uuid4();
$run = wp_generate_uuid4();
$step = wp_generate_uuid4();
$block_content = '<!-- wp:image {"id":"awx-media://' . $M1 . '"} --><figure class="wp-block-image"><img src="awx-media://' . $M1 . '/full" class="wp-image-awx-media-' . $M1 . '"/></figure><!-- /wp:image -->';
list($st, $d) = call('POST', '/blocks', array('run_id' => $run, 'step_id' => $step, 'uuid' => $B1, 'slug' => 'int-cta', 'title' => 'Int CTA', 'content' => $block_content, 'hash' => 'h1', 'pattern_slug' => 'cta.int'), $parts);
assert_true($st === 200 && !empty($d['id']) && $d['created'] === true, "block created ({$st})");
if (!empty($d['id'])) {
	$created['posts'][] = $d['id'];
	$bp = get_post($d['id']);
	assert_true($bp->post_type === 'wp_block' && strpos($bp->post_content, '{"id":' . $media_id . '}') !== false && strpos($bp->post_content, 'wp-image-' . $media_id) !== false, 'block content resolved to the local attachment id');
	assert_true(empty(Transformer::leftover_tokens($bp->post_content)), 'no tokens left in the block');
	assert_true(get_post_meta($d['id'], '_awx_pattern_slug', true) === 'cta.int' && get_post_meta($d['id'], aw2\sync\Receiver::META_SOURCE_HASH, true) === 'h1' && get_post_meta($d['id'], Identity::META_CONTENT_HASH, true) === hash('sha256', $bp->post_content), 'block meta stamped with source and saved hashes');
}
list($st2, $d2) = call('POST', '/blocks', array('run_id' => $run, 'step_id' => $step, 'uuid' => $B1, 'slug' => 'int-cta', 'title' => 'Int CTA', 'content' => $block_content, 'hash' => 'h1'), $parts);
assert_true($st2 === 200 && !empty($d2['replayed']) && $d2['id'] === $d['id'], 'repeated step_id is replayed, nothing written twice');
$missing_uuid = wp_generate_uuid4();
list($st, $d) = call('POST', '/blocks', array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'uuid' => wp_generate_uuid4(), 'content' => '<!-- wp:image {"id":"awx-media://' . $missing_uuid . '"} /-->', 'hash' => 'h'), $parts);
assert_true($st === 422 && $d['code'] === 'awx_sync_unresolved_references' && $d['data']['details']['missing'][0]['key'] === $missing_uuid, 'unresolved media reference gives 422 with the missing uuid');

echo "\n=== 4. Plan ===\n";
$P1 = wp_generate_uuid4();
list($st, $d) = call('POST', '/plan', array('page' => array('uuid' => $P1, 'type' => 'page', 'status' => 'publish', 'hash' => 'x'), 'media' => array($M1 => array('sha256' => hash('sha256', $png))), 'blocks' => array(array('uuid' => $B1, 'hash' => 'h1')), 'terms' => array('category:int-news' => array('taxonomy' => 'category', 'slug' => 'int-news', 'name' => 'Int News')), 'dgb_blocks' => array('awx-hero' => '1'), 'modules' => array('nope'), 'post_types' => array('page', 'nope_type')), $parts);
assert_true($st === 200 && $d['page']['status'] === 'missing' && $d['page']['type_allowed'] === true, 'plan: page missing, type allowed');
assert_true($d['media'][$M1]['status'] === 'exists_unchanged' && $d['blocks'][$B1]['status'] === 'exists_unchanged', 'plan: media and block unchanged');
assert_true($d['terms']['category:int-news']['status'] === 'will_create' && $d['dgb_blocks']['awx-hero']['status'] === 'missing', 'plan: term will be created, dgb block missing on this site');
assert_true($d['modules']['nope']['status'] === 'missing' && $d['post_types']['nope_type']['status'] === 'missing' && $d['post_types']['page']['status'] === 'exists', 'plan: modules and post types checked');

echo "\n=== 5. Page via REST ===\n";
$page_content = '<!-- wp:block {"ref":"awx-block://' . $B1 . '"} /-->' . "\n\n" . '<!-- wp:paragraph --><p>See <a href="awx-site://pricing/">pricing</a> and <img src="awx-media://' . $M1 . '/full"/> <span style="color:red">styled</span> <iframe src="https://example.com"></iframe></p><!-- /wp:paragraph -->';
$page = array('run_id' => $run, 'step_id' => wp_generate_uuid4(), 'uuid' => $P1, 'type' => 'page', 'slug' => 'int-sync-page', 'title' => 'Int Sync Page', 'status' => 'draft', 'content' => $page_content, 'hash' => 'ph1', 'featured_media' => $M1, 'terms' => array('category:int-news'), 'terms_catalog' => array('category:int-news' => array('taxonomy' => 'category', 'slug' => 'int-news', 'name' => 'Int News', 'parent' => null)), 'source_hosts' => array('dev.example.com'), 'author_login' => 'nobody-here');
list($st, $d) = call('POST', '/pages', $page, $parts);
assert_true($st === 200 && !empty($d['id']) && $d['created'] === true && $d['status'] === 'draft', "page created ({$st} " . ($d['message'] ?? '') . ')');
$page_id = $d['id'] ?? 0;
if ($page_id) {
	$created['posts'][] = $page_id;
	$pp = get_post($page_id);
	$block_id = $created['posts'][1];
	assert_true(strpos($pp->post_content, '{"ref":' . $block_id . '}') !== false, 'block ref resolved to local block id');
	assert_true(strpos($pp->post_content, home_url('/pricing/')) !== false, 'site token resolved to the local home url');
	assert_true(strpos($pp->post_content, 'style="color:red"') !== false && strpos($pp->post_content, '<iframe') !== false, 'inline style and iframe survived (unfiltered_html service user)');
	assert_true((int) get_post_thumbnail_id($page_id) === $media_id, 'featured image set from uuid');
	$terms = wp_get_object_terms($page_id, 'category', array('fields' => 'slugs'));
	assert_true(in_array('int-news', $terms, true), 'category created and assigned by slug');
	$t = get_term_by('slug', 'int-news', 'category');
	if ($t) { $created['terms'][] = $t->term_id; }
	assert_true(get_post_meta($page_id, Identity::META_UUID, true) === $P1 && get_post_meta($page_id, aw2\sync\Receiver::META_SOURCE_HASH, true) === 'ph1' && get_post_meta($page_id, Identity::META_CONTENT_HASH, true) === hash('sha256', $pp->post_content), 'page uuid, source hash and saved hash stamped');
	assert_true((int) $pp->post_author === Service_User::ensure_user(), 'unknown author login falls back to the service user');

	list($st, $d) = call('POST', '/plan', array('page' => array('uuid' => $P1, 'type' => 'page', 'status' => 'draft', 'hash' => 'ph1')), $parts);
	assert_true($st === 200 && $d['page']['status'] === 'exists' && $d['page']['edited_on_prod'] === false && $d['page']['unchanged'] === true, 'plan after a sync: page exists, not edited on prod, unchanged');
	$prod_hash = hash('sha256', $pp->post_content);
	$upd = $page; $upd['step_id'] = wp_generate_uuid4(); $upd['hash'] = 'ph2'; $upd['status'] = 'publish'; $upd['expected_prod_hash'] = 'wrong';
	list($st, $d) = call('POST', '/pages', $upd, $parts);
	assert_true($st === 409 && $d['code'] === 'awx_sync_conflict', 'wrong expected hash gives 409 conflict');
	$upd['expected_prod_hash'] = $prod_hash;
	list($st, $d) = call('POST', '/pages', $upd, $parts);
	assert_true($st === 200 && $d['created'] === false && get_post_status($page_id) === 'publish' && !empty($d['revision_id']), 'update with correct hash publishes and keeps a revision');
	if (getenv('AWX_DEBUG')) { echo "   update -> {$st} " . json_encode($d) . " status=" . get_post_status($page_id) . "\n"; }

	// Edit on prod, then sync without overwrite -> 409; with overwrite -> ok
	wp_update_post(array('ID' => $page_id, 'post_content' => $pp->post_content . '<!-- wp:paragraph --><p>edited on prod</p><!-- /wp:paragraph -->'));
	$prod_hash2 = hash('sha256', get_post($page_id)->post_content);
	$upd['step_id'] = wp_generate_uuid4(); $upd['expected_prod_hash'] = $prod_hash2; $upd['hash'] = 'ph3';
	list($st, $d) = call('POST', '/pages', $upd, $parts);
	assert_true($st === 409 && $d['code'] === 'awx_sync_edited_on_prod', 'page edited on prod needs explicit overwrite');
	$upd['overwrite'] = true; $upd['step_id'] = wp_generate_uuid4();
	list($st, $d) = call('POST', '/pages', $upd, $parts);
	assert_true($st === 200 && strpos(get_post($page_id)->post_content, 'edited on prod') === false, 'overwrite replaces the prod edits');
	$revs = wp_get_post_revisions($page_id);
	assert_true(count($revs) >= 2, 'revisions kept for rollback (' . count($revs) . ')');

	list($st, $d) = call('GET', '/runs/' . $run, null, $parts);
	assert_true($st === 200 && count($d['steps']) >= 4, 'run status lists the recorded steps');
	list($st, $d) = call('POST', '/pages', array_merge($upd, array('step_id' => wp_generate_uuid4(), 'status' => 'trash')), $parts);
	assert_true($st === 422 && $d['code'] === 'awx_sync_status_not_allowed', 'trash status refused');
}

echo "\n=== 6. Audit log ===\n";
$log = aw2\sync\Audit_Log::recent(50);
$results = array_unique(array_column($log, 'result'));
assert_true(in_array('ok', $results, true) && in_array('denied', $results, true) && in_array('conflict', $results, true) && in_array('invalid', $results, true), 'audit log recorded ok, denied, conflict and invalid calls');

// ---- cleanup ----
echo "\n=== cleanup ===\n";
foreach (array_reverse($created['posts']) as $pid) {
	if (get_post_type($pid) === 'attachment') {
		wp_delete_attachment($pid, true);
	} else {
		wp_delete_post($pid, true);
	}
}
foreach ($created['terms'] as $tid) {
	wp_delete_term($tid, 'category');
}
global $wpdb;
foreach ($created['tokens'] as $tid) {
	$wpdb->delete(Schema::table('tokens'), array('token_id' => $tid));
	$wpdb->delete(Schema::table('log'), array('token_id' => $tid));
	$wpdb->delete(Schema::table('nonces'), array('token_id' => $tid));
}
$wpdb->delete(Schema::table('steps'), array('run_id' => $run));
$svc = get_user_by('login', Service_User::LOGIN);
if ($svc) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user($svc->ID);
}
remove_role(Service_User::ROLE);
@unlink($tmp);
echo "cleaned up\n\n";

if ($failures > 0) {
	echo "❌ {$failures} assertion(s) failed.\n";
	exit(1);
}
echo "✅ All assertions passed.\n";
