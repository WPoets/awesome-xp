<?php
/**
 * AWX Sync: signer, token encryption, planner and client-retry tests.
 * Standalone: php wp/libraries/sync/tests/test-sync-core.php
 */
if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}
define('AUTH_KEY', 'test-auth-key-with-more-than-32-characters-xxxxxxxx');
define('AUTH_SALT', 'test-auth-salt');
define('AWX_SYNC_PROTOCOL', 1);
define('DAY_IN_SECONDS', 86400);

// Minimal WordPress stubs
class WP_Error {
	private $code; private $message; private $data;
	public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function wp_json_encode($v) { return json_encode($v); }
function untrailingslashit($s) { return rtrim($s, '/'); }
function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
function wp_generate_uuid4() { return sprintf('%04x%04x-%04x-4%03x-8%03x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)); }

$lib = dirname(__DIR__) . '/lib/';
require_once $lib . 'interface-site-resolver.php';
require_once $lib . 'class-transform-result.php';
require_once $lib . 'class-block-serializer.php';
require_once $lib . 'class-reference-registry.php';
require_once $lib . 'class-transformer.php';
require_once $lib . 'class-extractor.php';
require_once $lib . 'class-signer.php';
require_once $lib . 'class-planner.php';
require_once $lib . 'class-token-store.php';
require_once $lib . 'class-prod-routes.php';
require_once $lib . 'class-sync-client.php';

use aw2\sync\Planner;
use aw2\sync\Signer;
use aw2\sync\Sync_Client;
use aw2\sync\Token_Store;

$failures = 0;
function assert_true($c, $m) { global $failures; if (!$c) { $failures++; echo "❌ FAIL: $m\n"; return; } echo "✅ PASS: $m\n"; }

echo "=== 1. Signer ===\n";
$secret = Signer::new_secret();
$id = Signer::new_token_id();
assert_true(strlen($id) === 16 && strlen($secret) >= 40, 'token id and secret have the expected lengths');
$parts = Signer::split_token($id . '.' . $secret);
assert_true($parts !== null && $parts['token_id'] === $id && $parts['secret'] === $secret, 'token splits into id and secret');
assert_true(Signer::split_token('short.x') === null, 'malformed token rejected');
$body = '{"a":1}';
$h = Signer::sign($id, $secret, 'post', '/wp-json/awx-sync/v1/plan', $body, 1000, str_repeat('ab', 16));
assert_true(Signer::verify($secret, $h, 'POST', '/wp-json/awx-sync/v1/plan/', $body, 1100) === true, 'signature verifies (method case and trailing slash normalised)');
assert_true(Signer::verify($secret, $h, 'POST', '/wp-json/awx-sync/v1/plan', $body . ' ', 1100) === 'bad_signature', 'tampered body fails');
assert_true(Signer::verify($secret, $h, 'POST', '/wp-json/awx-sync/v1/pages', $body, 1100) === 'bad_signature', 'different path fails');
assert_true(Signer::verify('other', $h, 'POST', '/wp-json/awx-sync/v1/plan', $body, 1100) === 'bad_signature', 'wrong secret fails');
assert_true(Signer::verify($secret, $h, 'POST', '/wp-json/awx-sync/v1/plan', $body, 1000 + 301) === 'stale_timestamp', 'timestamp outside the 5 minute window fails');
assert_true(Signer::verify($secret, array('x-awx-token-id' => $id), 'POST', '/x', $body) === 'missing_headers', 'missing headers reported');
$lower = array_change_key_case($h, CASE_LOWER);
assert_true(Signer::verify($secret, $lower, 'POST', '/wp-json/awx-sync/v1/plan', $body, 1100) === true, 'lower-case header names accepted');
assert_true(Signer::normalise_path('https://x.test/wp-json/awx-sync/v1/plan?x=1') === '/wp-json/awx-sync/v1/plan', 'normalise_path strips host and query');

echo "\n=== 2. Token secret encryption ===\n";
$enc = Token_Store::encrypt('hello-secret');
assert_true(is_string($enc) && strpos($enc, 'v1:') === 0, 'encrypt produces a versioned blob');
assert_true(Token_Store::decrypt($enc) === 'hello-secret', 'decrypt restores the secret');
assert_true(Token_Store::encrypt('hello-secret') !== $enc, 'each encryption uses a fresh iv');
$tampered = substr($enc, 0, -4) . 'AAAA';
assert_true(Token_Store::decrypt($tampered) === null, 'tampered blob fails authentication');
assert_true(Token_Store::secret(array('secret_enc' => $enc, 'token_hash' => Signer::hash_secret('hello-secret'))) === 'hello-secret', 'secret() checks the hash and returns the plain secret');
assert_true(Token_Store::secret(array('secret_enc' => $enc, 'token_hash' => Signer::hash_secret('other'))) === null, 'secret() refuses when the hash does not match');

echo "\n=== 3. Planner ===\n";
$gen = function () { static $n = 0; $n++; return sprintf('00000000-0000-4000-8000-%012d', $n); };
$manifest = array(
	'protocol' => 1, 'registry_hash' => 'R',
	'page' => array('uuid' => 'P', 'type' => 'page', 'status' => 'publish', 'hash' => 'H1'),
	'media' => array('M1' => array('sha256' => 'a', 'bytes' => 1000, 'file' => 'a.jpg'), 'M2' => array('sha256' => 'b', 'bytes' => 50 * 1048576, 'file' => 'big.mp4'), 'M3' => array('sha256' => 'c', 'bytes' => 10, 'file' => 'same.png')),
	'blocks' => array(array('uuid' => 'B1', 'hash' => 'x', 'title' => 'CTA'), array('uuid' => 'B2', 'hash' => 'y', 'title' => 'Wrap')),
	'dgb_blocks' => array('awx-hero' => '2'),
	'warnings' => array(array('code' => 'unmapped_dev_url', 'message' => 'w')),
);
$plan = array(
	'page' => array('status' => 'exists', 'type_allowed' => true, 'prod_status' => 'draft', 'prod_hash' => 'PH', 'last_synced_hash' => 'OLD', 'edited_on_prod' => true, 'unchanged' => false, 'permalink' => 'https://www.example.com/p/'),
	'media' => array('M1' => array('status' => 'missing'), 'M2' => array('status' => 'exists_changed'), 'M3' => array('status' => 'exists_unchanged')),
	'blocks' => array('B1' => array('status' => 'exists_changed', 'affected' => array(array('id' => 9, 'title' => 'Other'))), 'B2' => array('status' => 'exists_unchanged')),
	'dgb_blocks' => array('awx-hero' => array('status' => 'ok', 'version' => '2')),
	'warnings' => array(),
);
$hs = array('protocol' => 1, 'registry_hash' => 'R', 'push_allowed' => true, 'pull' => array('probe_ok' => true), 'limits' => array('upload_max_bytes' => 8 * 1048576), 'unfiltered_html' => true);
$b = Planner::build($manifest, $plan, $hs, $gen);
$types = array_map(function ($s) { return $s['type'] . ':' . ($s['payload']['mode'] ?? $s['object_uuid']); }, $b['steps']);
assert_true($types === array('media:push', 'media:pull', 'block:B1', 'page:P'), 'steps: small file pushed, big file pulled, changed block, page (' . implode(',', $types) . ')');
assert_true($b['media_mode'] === 'mixed' && $b['summary']['media_push'] === 1 && $b['summary']['media_pull'] === 1, 'media mode mixed');
assert_true($b['summary']['publishes'] === true && $b['summary']['unpublishes'] === false && $b['summary']['from_status'] === 'draft', 'status transition draft -> publish flagged as publishing');
assert_true($b['summary']['edited_on_prod'] === true, 'edited on prod flagged');
assert_true(count($b['summary']['affected_pages']) === 1 && $b['summary']['affected_pages'][0]['id'] === 9, 'affected pages collected from changed blocks');
assert_true(empty($b['blockers']), 'no blockers');
$codes = array_column($b['warnings'], 'code');
assert_true(in_array('unmapped_dev_url', $codes, true) && in_array('edited_on_prod', $codes, true), 'manifest and plan warnings merged');

$hs2 = $hs; $hs2['pull']['probe_ok'] = false;
$b2 = Planner::build($manifest, $plan, $hs2, $gen);
assert_true(count($b2['blockers']) === 1 && $b2['blockers'][0]['code'] === 'no_media_transport' && strpos($b2['blockers'][0]['message'], 'big.mp4') === 0, 'oversize file with no pull is a blocker');
$hs3 = $hs; $hs3['push_allowed'] = false;
$b3 = Planner::build($manifest, $plan, $hs3, $gen);
assert_true($b3['media_mode'] === 'pull' && $b3['summary']['media_pull'] === 2, 'push disabled falls back to pull for every file');
$plan4 = $plan; $plan4['dgb_blocks']['awx-hero'] = array('status' => 'version_mismatch', 'version' => '1');
$b4 = Planner::build($manifest, $plan4, $hs, $gen);
assert_true($b4['blockers'][0]['code'] === 'dgb_block_version', 'dgb version mismatch blocks the run');
$plan5 = array('page' => array('status' => 'exists', 'type_allowed' => true, 'prod_status' => 'publish', 'last_synced_hash' => 'H1', 'prod_hash' => 'H1', 'edited_on_prod' => false, 'unchanged' => true), 'media' => array('M3' => array('status' => 'exists_unchanged')), 'blocks' => array('B1' => array('status' => 'exists_unchanged'), 'B2' => array('status' => 'exists_unchanged')), 'dgb_blocks' => array());
$m5 = $manifest; $m5['media'] = array('M3' => $manifest['media']['M3']); $m5['warnings'] = array();
$b5 = Planner::build($m5, $plan5, $hs, $gen);
assert_true($b5['summary']['nothing_to_do'] === true && empty($b5['steps']), 'unchanged page with nothing changed yields zero steps');
$hs6 = $hs; $hs6['protocol'] = 2;
$b6 = Planner::build($manifest, $plan, $hs6, $gen);
assert_true($b6['blockers'][0]['code'] === 'protocol_mismatch', 'protocol mismatch blocks');
$plan7 = $plan; $plan7['page']['last_synced_hash'] = null; $plan7['page']['edited_on_prod'] = null;
$b7 = Planner::build($manifest, $plan7, $hs, $gen);
assert_true($b7['summary']['edited_on_prod'] === true && in_array('no_sync_history', array_column($b7['warnings'], 'code'), true), 'manually paired page with no history requires overwrite');

echo "\n=== 4. Client retries ===\n";
$client = new Sync_Client('https://www.example.com', $id . '.' . $secret);
$calls = array();
$client->sleeper = function ($s) {};
$client->transport = function ($url, $args) use (&$calls, $secret) {
	$calls[] = array($url, $args);
	$n = count($calls);
	if ($n < 3) {
		return array('response' => array('code' => 503), 'body' => json_encode(array('code' => 'awx_sync_busy', 'message' => 'busy', 'data' => array('status' => 503, 'retryable' => true))));
	}
	return array('response' => array('code' => 200), 'body' => json_encode(array('ok' => true)));
};
$res = $client->post('/plan', array('x' => 1));
assert_true(is_array($res) && $res['ok'] === true && count($calls) === 3, 'retryable 503 is retried and the third attempt succeeds');
assert_true($calls[0][0] === 'https://www.example.com/wp-json/awx-sync/v1/plan', 'url built from base, rest prefix and namespace');
assert_true($calls[0][1]['headers'][Signer::H_NONCE] !== $calls[1][1]['headers'][Signer::H_NONCE], 'each attempt carries a fresh nonce');
$hdr = $calls[2][1]['headers'];
assert_true(Signer::verify($secret, $hdr, 'POST', '/wp-json/awx-sync/v1/plan', $calls[2][1]['body']) === true, 'request headers verify against the secret');
$calls = array();
$client->transport = function () use (&$calls) { $calls[] = 1; return array('response' => array('code' => 422), 'body' => json_encode(array('code' => 'awx_sync_unresolved_references', 'message' => 'missing', 'data' => array('status' => 422, 'retryable' => false, 'details' => array('missing' => array('m'))))));
};
$res = $client->post('/pages', array());
assert_true(is_wp_error($res) && count($calls) === 1 && $res->get_error_code() === 'awx_sync_unresolved_references' && $res->get_error_data()['details']['missing'] === array('m'), '422 is not retried and carries details');
$calls = array();
$client->transport = function () use (&$calls) { $calls[] = 1; return array('response' => array('code' => 401), 'body' => '{"code":"awx_sync_bad_signature","message":"no","data":{"status":401,"retryable":false}}'); };
$res = $client->post('/plan', array());
assert_true(is_wp_error($res) && count($calls) === 1 && $res->get_error_data()['status'] === 401, '401 stops immediately');
$calls = array();
$client->transport = function () use (&$calls) { $calls[] = 1; return new WP_Error('http_request_failed', 'timeout'); };
$res = $client->post('/plan', array());
assert_true(is_wp_error($res) && count($calls) === 3 && $res->get_error_code() === 'awx_sync_transport', 'transport failures are retried three times');

$tmp = tempnam(sys_get_temp_dir(), 'awx');
file_put_contents($tmp, 'FILEDATA');
$calls = array();
$client->transport = function ($url, $args) use (&$calls) { $calls[] = $args; return array('response' => array('code' => 200), 'body' => '{"id":5}'); };
$res = $client->post_file('/media', array('run_id' => 'r', 'media' => array('uuid' => 'u')), $tmp, 'a.jpg', 'image/jpeg');
unlink($tmp);
$args = $calls[0];
assert_true(is_array($res) && strpos($args['headers']['Content-Type'], 'multipart/form-data; boundary=') === 0 && strpos($args['body'], 'FILEDATA') !== false && strpos($args['body'], 'name="meta"') !== false, 'multipart body carries meta and file');
assert_true(Signer::verify($secret, $args['headers'], 'POST', '/wp-json/awx-sync/v1/media', json_encode(array('run_id' => 'r', 'media' => array('uuid' => 'u')))) === true, 'multipart request is signed over the meta JSON');

echo "\n";
if ($failures > 0) {
	echo "❌ {$failures} assertion(s) failed.\n";
	exit(1);
}
echo "✅ All assertions passed.\n";
