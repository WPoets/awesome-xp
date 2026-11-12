<?php
/**
 * Unit test for the code-deploy planner and item hashing. No WordPress.
 *
 *   php wp/libraries/sync/tests/test-code-planner.php
 */
if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}
define('AWX_SYNC_PROTOCOL', 1);

$lib = dirname(__DIR__) . '/lib/';
require_once $lib . 'class-code-item.php';
require_once $lib . 'class-code-planner.php';

use aw2\sync\Code_Item;
use aw2\sync\Code_Planner;

$failures = 0;
$total = 0;
function assert_true($c, $m) { global $failures, $total; $total++; if (!$c) { $failures++; echo "❌ FAIL: $m\n"; return; } echo "✅ PASS: $m\n"; }
$n = 0;
$uuid_gen = function () use (&$n) { $n++; return sprintf('00000000-0000-4000-8000-%012d', $n); };

echo "=== 1. Code_Item hashing ===\n";
$a = Code_Item::hash_parts('core', 'Header', 'publish', null, "[a]\r\n[/a]", array('_aw_version' => array('2'), '_edit_lock' => array('x'), '_awx_sync_uuid' => array('u')));
$b = Code_Item::hash_parts('core', 'Header', 'publish', null, "[a]\n[/a]", array('_aw_version' => array('2')));
assert_true($a === $b, 'hash ignores CRLF vs LF, _edit_lock and _awx_* meta');
$c = Code_Item::hash_parts('core', 'Header', 'publish', null, "[a]\n[/a]", array('_aw_version' => array('3')));
assert_true($a !== $c, 'hash changes with synced meta (_aw_version)');
$d = Code_Item::hash_parts('core', 'Header', 'publish', 'parent', "[a]\n[/a]", array('_aw_version' => array('2')));
assert_true($a !== $d, 'hash changes with the parent slug');
$e = Code_Item::hash_parts('core', 'Header', 'draft', null, "[a]\n[/a]", array('_aw_version' => array('2')));
assert_true($a !== $e, 'hash changes with status');
$g1 = Code_Item::hash_parts('acf', '', '', null, '', array(), array('key' => 'group_1', 'fields' => array(array('name' => 'x', 'type' => 'text')), 'title' => 'G'));
$g2 = Code_Item::hash_parts('acf', '', '', null, '', array(), array('title' => 'G', 'fields' => array(array('type' => 'text', 'name' => 'x')), 'key' => 'group_1'));
assert_true($g1 === $g2, 'ACF group hash is key-order independent');
$filtered = Code_Item::filter_meta(array('b' => array('2'), 'a' => '1', '_awx_x' => array('z'), '_edit_last' => array('1')));
assert_true(array_keys($filtered) === array('a', 'b') && $filtered['a'] === array('1'), 'filter_meta drops skipped keys, normalises scalars and sorts');
$s1 = Code_Item::hash_parts('styles', 'Custom Styles', 'publish', null, '{"version": 3, "styles": {"color": {"text": "#000"}}}', array());
$s2 = Code_Item::hash_parts('styles', 'Custom Styles', 'publish', null, "{\n  \"styles\": {\n    \"color\": {\n      \"text\": \"#000\"\n    }\n  },\n  \"version\": 3\n}", array());
assert_true($s1 === $s2, 'styles hash is whitespace and key-order independent (canonical JSON)');
$opt = Code_Item::from_option('awx_token_overrides', array('colors' => array('primary' => '#ff0000')));
assert_true($opt->kind === 'option' && $opt->post_type === 'option' && $opt->slug === 'awx_token_overrides', 'from_option sets kind=option and slug');
$item = new Code_Item('module', 'crm_modules', 'lead-form');
$item->title = 'Lead form';
$item->content = '[x]';
$item->meta = array('k' => array('v'));
$payload = $item->to_payload();
$back = Code_Item::from_payload($payload);
assert_true($back->key() === 'crm_modules:lead-form' && $back->hash() === $item->hash() && $payload['hash'] === $item->hash(), 'payload round-trips with the same hash and key');
$threw = false;
try { Code_Item::from_payload(array('kind' => 'evil', 'post_type' => 'x', 'slug' => 'y')); } catch (InvalidArgumentException $ex) { $threw = true; }
assert_true($threw, 'unknown kind rejected');

echo "\n=== 2. Rows: statuses and defaults ===\n";
$items = array(
	array('kind' => 'app', 'post_type' => 'aw2_app', 'slug' => 'crm', 'title' => 'CRM', 'hash' => 'h-app', 'content' => ''),
	array('kind' => 'core', 'post_type' => 'awesome_core', 'slug' => 'header', 'title' => 'Header', 'hash' => 'h1', 'content' => '[a]'),
	array('kind' => 'core', 'post_type' => 'awesome_core', 'slug' => 'footer', 'title' => 'Footer', 'hash' => 'h2', 'content' => 'see https://dev.example.com/x'),
	array('kind' => 'service', 'post_type' => 'svc_mail', 'slug' => 'send', 'title' => 'Send', 'hash' => 'h3', 'content' => ''),
	array('kind' => 'config', 'post_type' => 'crm_config', 'slug' => 'settings', 'title' => 'Settings', 'hash' => 'h4', 'content' => '', 'meta_skipped' => true),
	array('kind' => 'module', 'post_type' => 'crm_modules', 'slug' => 'lead', 'title' => 'Lead', 'hash' => 'h5', 'content' => ''),
	array('kind' => 'dgb', 'post_type' => 'aw_gb_blocks', 'slug' => 'hero', 'title' => 'Hero', 'hash' => 'h6', 'content' => ''),
	array('kind' => 'acf', 'post_type' => 'acf-field-group', 'slug' => 'group_1', 'title' => 'Group 1', 'hash' => 'h7', 'content' => ''),
	array('kind' => 'styles', 'post_type' => 'wp_global_styles', 'slug' => 'twentytwentyfour', 'title' => 'Global Styles', 'hash' => 'h-styles', 'content' => '{}'),
	array('kind' => 'option', 'post_type' => 'option', 'slug' => 'awx_token_overrides', 'title' => 'Token overrides (awx_token_overrides)', 'hash' => 'h-tokens', 'content' => '{}'),
);
$manifest = array(
	'protocol' => 1,
	'items' => $items,
	'item_ids' => array(),
	'content' => array(
		array('kind' => 'page', 'post_id' => 10, 'app' => 'crm', 'collection' => 'pages', 'post_type' => 'crm_pages', 'title' => 'Home', 'slug' => 'home', 'manifest' => array('page' => array('uuid' => 'p-10'))),
		array('kind' => 'page', 'post_id' => 11, 'app' => 'shop', 'collection' => 'pages', 'post_type' => 'shop_pages', 'title' => 'Cart', 'slug' => 'cart', 'manifest' => array('page' => array('uuid' => 'p-11'))),
		array('kind' => 'pattern', 'block_id' => 50, 'slug' => 'cta', 'title' => 'CTA', 'manifest' => array('page' => null)),
		array('kind' => 'template', 'post_id' => 20, 'slug' => 'archive', 'title' => 'Archive Template', 'post_type' => 'wp_template', 'manifest' => array('page' => array('uuid' => 'tmpl-20', 'type' => 'wp_template'))),
		array('kind' => 'page', 'post_id' => 12, 'app' => 'crm', 'collection' => 'pages', 'post_type' => 'crm_pages', 'title' => 'Broken', 'manifest' => null, 'error' => array('code' => 'post_without_uuid', 'message' => 'no uuid')),
	),
	'collections' => array(array('kind' => 'core', 'post_type' => 'awesome_core'), array('kind' => 'module', 'post_type' => 'crm_modules')),
	'apps' => array('crm', 'shop'),
	'notes' => array(array('code' => 'settings_meta_skipped', 'message' => 'Settings skipped')),
	'handshake' => array('protocol' => 1, 'allow_code' => true, 'acf_active' => true, 'unfiltered_html' => true, 'theme' => 'twentytwentyfour'),
	'source_hosts' => array('dev.example.com'),
);
$answer = array(
	'allow_code' => true,
	'acf_active' => true,
	'items' => array(
		'aw2_app:crm'          => array('status' => 'new'),
		'awesome_core:header'  => array('status' => 'unchanged', 'prod_id' => 1, 'prod_hash' => 'h1'),
		'awesome_core:footer'  => array('status' => 'changed', 'prod_id' => 2, 'prod_hash' => 'old', 'never_synced' => true),
		'svc_mail:send'        => array('status' => 'prod_edited', 'prod_id' => 3, 'prod_hash' => 'edited'),
		'crm_config:settings'  => array('status' => 'changed', 'prod_id' => 4, 'prod_hash' => 'x'),
		'crm_modules:lead'     => array('status' => 'new'),
		'aw_gb_blocks:hero'    => array('status' => 'new'),
		'wp_global_styles:twentytwentyfour' => array('status' => 'changed', 'prod_id' => 88, 'prod_hash' => 's-old'),
		'option:awx_token_overrides'        => array('status' => 'prod_edited', 'prod_id' => null, 'prod_hash' => 'tok-edited'),
	),
	'acf' => array('group_1' => array('status' => 'changed', 'prod_id' => 9, 'prod_hash' => 'g-old')),
	'only_on_prod' => array('crm_modules' => array(array('slug' => 'legacy', 'title' => 'Legacy'))),
	'apps' => array('crm' => false, 'shop' => true),
);
$subplans = array(
	0 => array('answer' => array('page' => array('status' => 'missing'), 'dgb_blocks' => array('hero' => array('status' => 'missing'))), 'built' => array(
		'steps' => array(array('step_id' => 's', 'type' => 'media', 'object_uuid' => 'm-1', 'payload' => array('mode' => 'push')), array('step_id' => 's', 'type' => 'page', 'object_uuid' => 'p-10', 'payload' => array('action' => 'create'))),
		'blockers' => array(array('code' => 'dgb_block_missing', 'message' => 'Block dgb/hero is not defined on the target. Deploy the block definition first.')),
		'warnings' => array(), 'summary' => array('nothing_to_do' => false, 'media_count' => 1, 'blocks_count' => 0, 'from_status' => null, 'to_status' => 'publish'),
	)),
	1 => array('answer' => array('page' => array('status' => 'exists', 'prod_id' => 77, 'prod_hash' => 'ph', 'edited_on_prod' => true, 'last_synced_hash' => 'l')), 'built' => array(
		'steps' => array(array('step_id' => 's', 'type' => 'media', 'object_uuid' => 'm-1', 'payload' => array('mode' => 'push')), array('step_id' => 's', 'type' => 'page', 'object_uuid' => 'p-11', 'payload' => array('action' => 'update'))),
		'blockers' => array(), 'warnings' => array(array('code' => 'edited_on_prod', 'message' => 'edited')), 'summary' => array('nothing_to_do' => false, 'media_count' => 1, 'blocks_count' => 0, 'from_status' => 'publish', 'to_status' => 'publish'),
	)),
	2 => array('answer' => array('blocks' => array('b-50' => array('status' => 'exists_changed', 'prod_id' => 60))), 'built' => array(
		'steps' => array(array('step_id' => 's', 'type' => 'block', 'object_uuid' => 'b-50', 'payload' => array())),
		'blockers' => array(), 'warnings' => array(), 'summary' => array('nothing_to_do' => false, 'media_count' => 0, 'blocks_count' => 1),
	)),
	3 => array('answer' => array('page' => array('status' => 'missing')), 'built' => array(
		'steps' => array(array('step_id' => 's', 'type' => 'page', 'object_uuid' => 'tmpl-20', 'payload' => array('action' => 'create'))),
		'blockers' => array(), 'warnings' => array(), 'summary' => array('nothing_to_do' => false, 'media_count' => 0, 'blocks_count' => 0, 'from_status' => null, 'to_status' => 'publish'),
	)),
);
$plan = Code_Planner::rows($manifest, $answer, $subplans);
$rows = array();
foreach ($plan['rows'] as $r) { $rows[$r['key']] = $r; }
$st = function ($k) use ($rows) { return $rows[$k]['status'] ?? 'absent'; };
$sel = function ($k) use ($rows) { return !empty($rows[$k]['selected']); };
assert_true(empty($plan['blockers']), 'no run-level blockers when the target allows code');
assert_true($st('aw2_app:crm') === 'new' && $sel('aw2_app:crm'), 'new app definition is ticked');
assert_true($st('awesome_core:header') === 'unchanged' && !$sel('awesome_core:header'), 'unchanged module is not ticked');
assert_true($st('awesome_core:footer') === 'changed' && $rows['awesome_core:footer']['never_synced'] && $sel('awesome_core:footer'), 'never-synced changed module is ticked and flagged');
$codes = function ($k) use ($rows) { return array_map(function ($w) { return $w['code']; }, $rows[$k]['warnings']); };
assert_true(in_array('source_host_in_code', $codes('awesome_core:footer'), true), 'module mentioning the source host gets a warning');
assert_true(in_array('never_synced', $codes('awesome_core:footer'), true), 'never-synced warning present');
assert_true($st('svc_mail:send') === 'prod_edited' && $sel('svc_mail:send') && in_array('edited_on_prod', $codes('svc_mail:send'), true), 'prod-edited service module is ticked with a warning');
assert_true($rows['crm_config:settings']['meta_skipped'] && in_array('settings_meta_skipped', $codes('crm_config:settings'), true), 'settings module shows the meta-skipped note');
assert_true($st('crm_modules:legacy') === 'only_on_prod' && !$rows['crm_modules:legacy']['selectable'] && $rows['crm_modules:legacy']['kind'] === 'module', 'only-on-target row is listed, unselectable, with the collection kind');
assert_true($st('acf-field-group:group_1') === 'changed' && $sel('acf-field-group:group_1'), 'ACF group row from the acf answer');
assert_true($st('page:10') === 'new' && $sel('page:10'), 'page of an app new on the target is pre-ticked');
assert_true(in_array('dgb_deployed_in_run', $codes('page:10'), true) && $rows['page:10']['selectable'], 'missing dgb block deployed in the same run downgrades the blocker to a warning');
assert_true($st('page:11') === 'prod_edited' && !$sel('page:11'), 'page of an existing app is not pre-ticked');
assert_true($st('pattern:cta') === 'changed' && $sel('pattern:cta'), 'changed pattern is ticked');
assert_true($st('template:archive') === 'new' && $sel('template:archive'), 'new template is ticked');
assert_true($st('wp_global_styles:twentytwentyfour') === 'changed' && $sel('wp_global_styles:twentytwentyfour'), 'changed global styles is ticked');
assert_true($st('option:awx_token_overrides') === 'prod_edited' && $sel('option:awx_token_overrides') && in_array('edited_on_prod', $codes('option:awx_token_overrides'), true), 'prod-edited option is ticked with edited_on_prod warning');
assert_true($st('page:12') === 'blocked' && !$rows['page:12']['selectable'] && $codes('page:12') === array('post_without_uuid'), 'content that failed to analyze is a blocked row');
assert_true($plan['summary']['by_status']['new'] === 5 && $plan['summary']['by_status']['only_on_prod'] === 1 && $plan['summary']['by_status']['blocked'] === 1, 'summary counts by status');
assert_true($plan['summary']['nothing_to_do'] === false, 'nothing_to_do false when writes exist');
assert_true(count($plan['warnings']) === 1 && $plan['warnings'][0]['code'] === 'settings_meta_skipped', 'manifest notes become run warnings');

echo "\n=== 3. Rows: blockers ===\n";
$blocked = Code_Planner::rows($manifest, array('allow_code' => false, 'items' => array(), 'acf' => array()), array());
assert_true(!empty($blocked['blockers']) && $blocked['blockers'][0]['code'] === 'code_not_allowed', 'target without AWX_SYNC_ALLOW_CODE blocks the run');
$noacf = Code_Planner::rows($manifest, $answer + array(), $subplans);
$ans2 = $answer; $ans2['acf_active'] = false;
$noacf = Code_Planner::rows($manifest, $ans2, $subplans);
$r2 = array(); foreach ($noacf['rows'] as $r) { $r2[$r['key']] = $r; }
assert_true($r2['acf-field-group:group_1']['status'] === 'blocked' && !$r2['acf-field-group:group_1']['selectable'], 'ACF rows are blocked when ACF is inactive on the target');
assert_true(empty($noacf['blockers']), 'inactive ACF blocks rows, not the run');
$m2 = $manifest; $m2['handshake']['protocol'] = 2;
$proto = Code_Planner::rows($m2, $answer, $subplans);
assert_true($proto['blockers'][0]['code'] === 'protocol_mismatch', 'protocol mismatch blocks the run');
$m_mismatch = $manifest;
$m_mismatch['handshake']['theme'] = 'twentytwentyfive';
$plan_mismatch = Code_Planner::rows($m_mismatch, $answer, $subplans);
$r_mismatch = array(); foreach ($plan_mismatch['rows'] as $r) { $r_mismatch[$r['key']] = $r; }
assert_true($r_mismatch['wp_global_styles:twentytwentyfour']['status'] === 'blocked' && !$r_mismatch['wp_global_styles:twentytwentyfour']['selectable'], 'global styles row blocked on theme mismatch');
assert_true(in_array('theme_mismatch', array_map(function ($w) { return $w['code']; }, $r_mismatch['wp_global_styles:twentytwentyfour']['warnings']), true), 'theme_mismatch warning present');
$all_same = $answer;
foreach ($all_same['items'] as $k => &$v) { $v = array('status' => 'unchanged'); } unset($v);
$all_same['acf'] = array('group_1' => array('status' => 'unchanged'));
$m3 = $manifest; $m3['content'] = array();
$same = Code_Planner::rows($m3, $all_same, array());
assert_true($same['summary']['nothing_to_do'] === true && $same['selected_default'] === array(), 'all unchanged → nothing to do, nothing ticked');

echo "\n=== 4. Steps ===\n";
$plan['subplans'] = $subplans;
$built = Code_Planner::steps($plan, $plan['selected_default'], $uuid_gen);
$types = array_map(function ($s) { return $s['type'] . ':' . ($s['object_uuid'] ?? '-'); }, $built['steps']);
assert_true($types[0] === 'code:aw2_app:crm', 'app definition first');
assert_true(in_array('code:awesome_core:footer', $types, true) && !in_array('code:awesome_core:header', $types, true), 'changed module included, unchanged not');
assert_true(array_search('acf:acf-field-group:group_1', $types, true) > array_search('code:aw_gb_blocks:hero', $types, true), 'ACF after block definitions');
$pattern_pos = array_search('block:b-50', $types, true);
$template_pos = array_search('page:tmpl-20', $types, true);
$page_pos = array_search('page:p-10', $types, true);
assert_true($pattern_pos !== false && $template_pos !== false && $page_pos !== false && $pattern_pos < $template_pos && $template_pos < $page_pos && $pattern_pos > array_search('acf:acf-field-group:group_1', $types, true), 'patterns after code and before templates and pages');
$acf_pos = array_search('acf:acf-field-group:group_1', $types, true);
$styles_pos = array_search('code:wp_global_styles:twentytwentyfour', $types, true);
$option_pos = array_search('option:option:awx_token_overrides', $types, true);
assert_true($acf_pos < $styles_pos && $styles_pos < $option_pos && $option_pos < $pattern_pos, 'order: acf < styles < option < patterns');
$opt_step = null; foreach ($built['steps'] as $s) { if ($s['object_uuid'] === 'option:awx_token_overrides') { $opt_step = $s; } }
assert_true($opt_step !== null && $opt_step['type'] === 'option' && $opt_step['payload']['kind'] === 'option', 'option step emitted with type=option');
assert_true(end($types) === 'finalize:-', 'finalize is last');
assert_true($built['overwrite_needed'] === true, 'overwrite needed because a prod-edited item is ticked');
$media_steps = array_filter($built['steps'], function ($s) { return $s['type'] === 'media'; });
assert_true(count($media_steps) === 1, 'duplicate media across content items deduped');
$page_step = null; foreach ($built['steps'] as $s) { if ($s['type'] === 'page') { $page_step = $s; } }
assert_true($page_step['payload']['sub'] === 0 && $page_step['payload']['row'] === 'page:10' && $page_step['payload']['action'] === 'create', 'content steps carry their sub-manifest index and row');
$code_step = $built['steps'][0];
assert_true(array_key_exists('expected_prod_hash', $code_step['payload']) && $code_step['payload']['expected_prod_hash'] === null, 'new item has no expected prod hash');
$svc = null; foreach ($built['steps'] as $s) { if ($s['object_uuid'] === 'svc_mail:send') { $svc = $s; } }
assert_true($svc['payload']['expected_prod_hash'] === 'edited', 'existing item carries the prod hash for the conflict check');

$no_edit = Code_Planner::steps($plan, array('aw2_app:crm', 'awesome_core:header'), $uuid_gen);
assert_true($no_edit['overwrite_needed'] === false && $no_edit['rejected'] === array('awesome_core:header') && count($no_edit['steps']) === 2, 'unchanged key is rejected silently, no overwrite needed, finalize appended');
$threw = false;
try { Code_Planner::steps($plan, array('crm_modules:legacy'), $uuid_gen); } catch (InvalidArgumentException $ex) { $threw = true; }
assert_true($threw, 'only-on-target key cannot be confirmed');
$threw = false;
try { Code_Planner::steps($plan, array('nope:x'), $uuid_gen); } catch (InvalidArgumentException $ex) { $threw = true; }
assert_true($threw, 'unknown key rejected');
$empty = Code_Planner::steps($plan, array(), $uuid_gen);
assert_true($empty['steps'] === array(), 'nothing ticked → no steps, no finalize');

echo "\n" . ($failures ? "❌ {$failures} of {$total} assertions failed\n" : "✅ All {$total} assertions passed\n");
exit($failures ? 1 : 0);
