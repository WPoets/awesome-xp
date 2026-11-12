<?php
/**
 * Pure planner for code-deploy runs: no WordPress calls, unit-tested alone.
 *
 * rows()  turns the expanded selection, the target's /code/plan answer and
 *         the per-content sub-plans into the diff table the screen shows,
 *         with default ticks, warnings and blockers.
 * steps() turns the confirmed ticks into the ordered step list.
 */
namespace aw2\sync;

class Code_Planner {

	const WRITE_STATUSES = array('new', 'changed', 'prod_edited');

	/**
	 * @param array $manifest  {items:[payload...], item_ids:{key=>id}, content:[{kind,post_id|block_id,app,collection,slug,title,manifest,error?}], collections, apps, notes, handshake, source_hosts}
	 * @param array $answer    /code/plan response {items:{key=>{status,...}}, acf:{key=>{status,...}}, only_on_prod:{post_type=>[{slug,title}]}, apps:{slug=>bool}, acf_active, allow_code, post_types}
	 * @param array $subplans  per content index: {answer: /plan response, built: Planner::build() result} (missing when the content item failed to analyze)
	 * @return array {rows:[...], blockers:[...], warnings:[...], summary:{...}, selected_default:[keys]}
	 */
	public static function rows(array $manifest, array $answer, array $subplans): array {
		$rows = $blockers = $warnings = array();
		$hs = (array) ($manifest['handshake'] ?? array());
		$hosts = (array) ($manifest['source_hosts'] ?? array());

		foreach ((array) ($manifest['notes'] ?? array()) as $n) {
			$warnings[] = array('code' => $n['code'], 'message' => $n['message']);
		}
		if (\array_key_exists('allow_code', $answer) ? empty($answer['allow_code']) : (\array_key_exists('allow_code', $hs) && empty($hs['allow_code']))) {
			$blockers[] = array('code' => 'code_not_allowed', 'message' => "The target does not accept code deploys. Add define('AWX_SYNC_ALLOW_CODE', true); to its wp-config.php and give the token the code scope.");
		}
		if (isset($hs['protocol']) && (int) $hs['protocol'] !== (int) ($manifest['protocol'] ?? 1)) {
			$blockers[] = array('code' => 'protocol_mismatch', 'message' => 'The target runs sync protocol ' . $hs['protocol'] . ', this site runs ' . ($manifest['protocol'] ?? 1) . '. Update the plugin on the older site.');
		}
		$acf_active = \array_key_exists('acf_active', $answer) ? !empty($answer['acf_active']) : !empty($hs['acf_active']);

		// Which block definitions this run will write (resolves page blockers below).
		$dgb_in_run = array();
		foreach ((array) ($manifest['items'] ?? array()) as $it) {
			if (($it['kind'] ?? '') === 'dgb') {
				$dgb_in_run[$it['slug']] = true;
			}
		}

		// Code items
		foreach ((array) ($manifest['items'] ?? array()) as $it) {
			$key = $it['post_type'] . ':' . $it['slug'];
			$kind = (string) $it['kind'];
			$a = (array) (($kind === 'acf' ? ($answer['acf'][$it['slug']] ?? null) : ($answer['items'][$key] ?? null)) ?? array('status' => 'new'));
			$status = (string) ($a['status'] ?? 'new');
			$row = array(
				'key'          => $key,
				'kind'         => $kind,
				'post_type'    => $it['post_type'],
				'slug'         => $it['slug'],
				'title'        => (string) ($it['title'] ?? $it['slug']),
				'status'       => $status,
				'never_synced' => !empty($a['never_synced']),
				'prod_id'      => $a['prod_id'] ?? null,
				'prod_hash'    => $a['prod_hash'] ?? null,
				'has_revisions'=> $a['has_revisions'] ?? null,
				'meta_skipped' => !empty($it['meta_skipped']),
				'selectable'   => true,
				'selected'     => false,
				'warnings'     => array(),
			);
			if ($kind === 'acf') {
				if (!$acf_active || $status === 'acf_inactive') {
					$row['status'] = 'blocked';
					$row['selectable'] = false;
					$row['warnings'][] = array('code' => 'acf_inactive', 'message' => 'ACF is not active on the target; field groups cannot be imported.');
				} elseif ($status === 'prod_edited') {
					$row['warnings'][] = array('code' => 'no_revision', 'message' => 'ACF field groups keep no revisions; the overwrite cannot be undone from the target.');
				}
			}
			if (!empty($hosts) && !empty($it['content'])) {
				foreach ($hosts as $h) {
					if ($h !== '' && \stripos((string) $it['content'], (string) $h) !== false) {
						$row['warnings'][] = array('code' => 'source_host_in_code', 'message' => "The module mentions the source host {$h}; code should use site-relative URLs or SITE_URL.");
						break;
					}
				}
			}
			if ($row['status'] === 'prod_edited') {
				$row['warnings'][] = array('code' => 'edited_on_prod', 'message' => 'Edited on the target since the last deploy; deploying overwrites those edits' . ($kind === 'acf' ? '.' : ' (a revision is kept).'));
			} elseif ($row['status'] === 'changed' && $row['never_synced']) {
				$row['warnings'][] = array('code' => 'never_synced', 'message' => 'Exists on the target but was never deployed from here; its current content will be replaced (a revision is kept).');
			}
			if ($row['meta_skipped']) {
				$row['warnings'][] = array('code' => 'settings_meta_skipped', 'message' => 'Settings (postmeta) are not deployed; only the module content is.');
			}
			$row['selected'] = $row['selectable'] && \in_array($row['status'], self::WRITE_STATUSES, true);
			$rows[] = $row;
		}

		// Only on target
		foreach ((array) ($answer['only_on_prod'] ?? array()) as $pt => $list) {
			$kind = 'module';
			foreach ((array) ($manifest['collections'] ?? array()) as $c) {
				if (($c['post_type'] ?? '') === $pt) {
					$kind = $c['kind'];
				}
			}
			foreach ((array) $list as $o) {
				$rows[] = array(
					'key' => $pt . ':' . $o['slug'], 'kind' => $kind, 'post_type' => $pt, 'slug' => (string) $o['slug'], 'title' => (string) ($o['title'] ?? $o['slug']),
					'status' => 'only_on_prod', 'never_synced' => false, 'prod_id' => $o['prod_id'] ?? null, 'prod_hash' => null, 'has_revisions' => null, 'meta_skipped' => false,
					'selectable' => false, 'selected' => false, 'warnings' => array(),
				);
			}
		}

		// Content (app pages/posts and patterns)
		$apps_on_prod = (array) ($answer['apps'] ?? array());
		foreach ((array) ($manifest['content'] ?? array()) as $i => $c) {
			$is_page = ($c['kind'] ?? '') === 'page';
			$key = $is_page ? 'page:' . (int) $c['post_id'] : 'pattern:' . (string) ($c['slug'] ?? $c['block_id']);
			$row = array(
				'key' => $key, 'kind' => $is_page ? 'page' : 'pattern', 'post_type' => (string) ($c['post_type'] ?? 'wp_block'), 'slug' => (string) ($c['slug'] ?? ''), 'title' => (string) ($c['title'] ?? $key),
				'app' => $c['app'] ?? null, 'collection' => $c['collection'] ?? null, 'content_index' => (int) $i,
				'status' => 'new', 'never_synced' => false, 'prod_id' => null, 'prod_hash' => null, 'has_revisions' => null, 'meta_skipped' => false,
				'selectable' => true, 'selected' => false, 'warnings' => array(), 'counts' => array('media' => 0, 'blocks' => 0),
			);
			if (!empty($c['error'])) {
				$row['status'] = 'blocked';
				$row['selectable'] = false;
				$row['warnings'][] = array('code' => $c['error']['code'] ?? 'analyze_failed', 'message' => (string) ($c['error']['message'] ?? 'Could not analyze this item.'));
				$rows[] = $row;
				continue;
			}
			$sp = (array) ($subplans[$i] ?? array());
			$built = (array) ($sp['built'] ?? array());
			$sub_answer = (array) ($sp['answer'] ?? array());
			$summary = (array) ($built['summary'] ?? array());
			if ($is_page) {
				$pp = (array) ($sub_answer['page'] ?? array());
				$exists = ($pp['status'] ?? 'missing') === 'exists';
				if (!empty($summary['nothing_to_do'])) {
					$row['status'] = 'unchanged';
				} elseif ($exists && !empty($pp['edited_on_prod'])) {
					$row['status'] = 'prod_edited';
				} elseif ($exists) {
					$row['status'] = 'changed';
					$row['never_synced'] = ($pp['last_synced_hash'] ?? null) === null;
				} else {
					$row['status'] = 'new';
				}
				$row['prod_id'] = $pp['prod_id'] ?? null;
				$row['prod_hash'] = $pp['prod_hash'] ?? null;
				$row['from_status'] = $summary['from_status'] ?? null;
				$row['to_status'] = $summary['to_status'] ?? null;
			} else {
				$status = 'missing';
				foreach ((array) ($sub_answer['blocks'] ?? array()) as $uuid => $b) {
					$status = (string) ($b['status'] ?? 'missing');
					$row['prod_id'] = $b['prod_id'] ?? null;
				}
				$row['status'] = $status === 'exists_unchanged' && empty($built['steps']) ? 'unchanged' : ($status === 'missing' ? 'new' : 'changed');
			}
			$row['counts'] = array('media' => (int) ($summary['media_count'] ?? 0), 'blocks' => (int) ($summary['blocks_count'] ?? 0));
			foreach ((array) ($built['warnings'] ?? array()) as $w) {
				$row['warnings'][] = $w;
			}
			// Blockers: a missing/behind dgb definition that this run deploys is only a warning.
			foreach ((array) ($built['blockers'] ?? array()) as $b) {
				if (\in_array($b['code'], array('dgb_block_missing', 'dgb_block_version'), true)) {
					$resolved = false;
					foreach ((array) ($sub_answer['dgb_blocks'] ?? array()) as $slug => $d) {
						if (\in_array($d['status'] ?? '', array('missing', 'version_mismatch'), true) && isset($dgb_in_run[$slug]) && \strpos($b['message'], "dgb/{$slug}") !== false) {
							$resolved = true;
						}
					}
					if ($resolved) {
						$row['warnings'][] = array('code' => 'dgb_deployed_in_run', 'message' => $b['message'] . ' It is part of this deploy and is written first.');
						continue;
					}
				}
				$row['status'] = 'blocked';
				$row['selectable'] = false;
				$row['warnings'][] = $b;
			}
			if ($row['selectable']) {
				if ($is_page) {
					$app_new = isset($apps_on_prod[$c['app']]) ? empty($apps_on_prod[$c['app']]) : false;
					$row['selected'] = $app_new && \in_array($row['status'], self::WRITE_STATUSES, true);
				} else {
					$row['selected'] = \in_array($row['status'], self::WRITE_STATUSES, true);
				}
			}
			$rows[] = $row;
		}

		$summary = self::summarize($rows);
		$summary['blocked_run'] = !empty($blockers);
		return array(
			'rows'             => $rows,
			'blockers'         => $blockers,
			'warnings'         => self::dedupe($warnings),
			'summary'          => $summary,
			'selected_default' => \array_values(\array_map(function ($r) { return $r['key']; }, \array_filter($rows, function ($r) { return !empty($r['selected']); }))),
		);
	}

	public static function summarize(array $rows): array {
		$by = array('new' => 0, 'changed' => 0, 'unchanged' => 0, 'prod_edited' => 0, 'only_on_prod' => 0, 'blocked' => 0);
		$selected = $sel_edited = 0;
		foreach ($rows as $r) {
			$s = $r['status'];
			$by[$s] = ($by[$s] ?? 0) + 1;
			if (!empty($r['selected'])) {
				$selected++;
				if ($s === 'prod_edited') {
					$sel_edited++;
				}
			}
		}
		return array('by_status' => $by, 'total' => \count($rows), 'selected' => $selected, 'selected_prod_edited' => $sel_edited, 'nothing_to_do' => ($by['new'] + $by['changed'] + $by['prod_edited']) === 0);
	}

	/**
	 * Ordered steps for the confirmed ticks.
	 *
	 * @param array    $plan          rows() output (plus 'subplans' => Planner::build() results per content index)
	 * @param string[] $selected_keys row keys the developer confirmed
	 * @param callable $uuid_gen
	 * @return array {steps:[{step_id,type,object_uuid,payload}], selected:[keys], overwrite_needed:bool, rejected:[keys]}
	 * @throws \InvalidArgumentException for an unknown or unselectable key
	 */
	public static function steps(array $plan, array $selected_keys, callable $uuid_gen): array {
		$rows = array();
		foreach ((array) ($plan['rows'] ?? array()) as $r) {
			$rows[$r['key']] = $r;
		}
		$selected = array();
		$rejected = array();
		foreach ($selected_keys as $k) {
			$k = (string) $k;
			if (!isset($rows[$k])) {
				throw new \InvalidArgumentException('Unknown item ' . $k);
			}
			if (empty($rows[$k]['selectable'])) {
				throw new \InvalidArgumentException('Item ' . $k . ' cannot be deployed (' . $rows[$k]['status'] . ').');
			}
			if ($rows[$k]['status'] === 'unchanged') {
				$rejected[] = $k; // nothing to write
				continue;
			}
			$selected[$k] = true;
		}
		$steps = array();
		$overwrite_needed = false;
		$seen_objects = array();
		$wrote_code = false;

		// Code items in manifest order (dependency order).
		foreach ((array) ($plan['rows'] ?? array()) as $r) {
			if (!isset($selected[$r['key']]) || \in_array($r['kind'], array('page', 'pattern'), true)) {
				continue;
			}
			if ($r['status'] === 'prod_edited') {
				$overwrite_needed = true;
			}
			$steps[] = array(
				'step_id'     => $uuid_gen(),
				'type'        => $r['kind'] === 'acf' ? 'acf' : 'code',
				'object_uuid' => $r['key'],
				'payload'     => array('kind' => $r['kind'], 'title' => $r['title'], 'status' => $r['status'], 'expected_prod_hash' => $r['prod_hash'] ?? null),
			);
			$wrote_code = true;
		}
		// Content: patterns first (pages may use them), then pages in manifest order.
		$content_rows = \array_filter((array) ($plan['rows'] ?? array()), function ($r) use ($selected) {
			return isset($selected[$r['key']]) && \in_array($r['kind'], array('page', 'pattern'), true);
		});
		\usort($content_rows, function ($a, $b) {
			$ka = $a['kind'] === 'pattern' ? 0 : 1;
			$kb = $b['kind'] === 'pattern' ? 0 : 1;
			return $ka <=> $kb ?: $a['content_index'] <=> $b['content_index'];
		});
		foreach ($content_rows as $r) {
			if ($r['status'] === 'prod_edited') {
				$overwrite_needed = true;
			}
			$i = (int) $r['content_index'];
			$built = (array) ($plan['subplans'][$i]['built'] ?? array());
			foreach ((array) ($built['steps'] ?? array()) as $s) {
				$obj = (string) ($s['object_uuid'] ?? '');
				if (\in_array($s['type'], array('media', 'block'), true)) {
					if (isset($seen_objects[$s['type'] . ':' . $obj])) {
						continue;
					}
					$seen_objects[$s['type'] . ':' . $obj] = true;
				}
				$payload = (array) ($s['payload'] ?? array());
				$payload['sub'] = $i;
				$payload['row'] = $r['key'];
				$steps[] = array('step_id' => $uuid_gen(), 'type' => $s['type'], 'object_uuid' => $obj, 'payload' => $payload);
			}
		}
		if (!empty($steps)) {
			$steps[] = array('step_id' => $uuid_gen(), 'type' => 'finalize', 'object_uuid' => null, 'payload' => array('code_written' => $wrote_code));
		}
		return array('steps' => $steps, 'selected' => \array_keys($selected), 'overwrite_needed' => $overwrite_needed, 'rejected' => $rejected);
	}

	private static function dedupe(array $list): array {
		$seen = array();
		$out  = array();
		foreach ($list as $w) {
			$k = $w['code'] . '|' . $w['message'];
			if (isset($seen[$k])) {
				continue;
			}
			$seen[$k] = true;
			$out[] = $w;
		}
		return $out;
	}
}
