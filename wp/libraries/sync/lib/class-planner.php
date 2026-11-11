<?php
/**
 * Turns a manifest, the target's plan answer and the handshake into the list
 * of steps to run plus everything the dry-run screen shows. Pure: no
 * WordPress calls, so it is unit-tested on its own.
 */
namespace aw2\sync;

class Planner {

	/**
	 * @return array {
	 *   steps: [ {step_id, type: media|block|page, object_uuid, payload:{mode?}} ],
	 *   blockers: [ {code,message} ]        (the run cannot proceed)
	 *   warnings: [ {code,message} ]
	 *   summary: {media_count, media_bytes, media_push, media_pull, blocks_count, page_action, from_status, to_status, publishes, unpublishes, edited_on_prod, affected_pages[], nothing_to_do}
	 *   media_mode: push|pull|mixed
	 * }
	 */
	public static function build(array $manifest, array $plan, array $handshake, callable $uuid_gen): array {
		$steps = $blockers = $warnings = array();
		$push_ok    = !empty($handshake['push_allowed']);
		$pull_ok    = !empty($handshake['pull']['probe_ok']);
		$upload_max = isset($handshake['limits']['upload_max_bytes']) ? (int) $handshake['limits']['upload_max_bytes'] : 0;

		foreach ((array) ($manifest['warnings'] ?? array()) as $w) {
			$warnings[] = array('code' => $w['code'], 'message' => $w['message']);
		}
		foreach ((array) ($plan['warnings'] ?? array()) as $w) {
			$warnings[] = array('code' => $w['code'], 'message' => $w['message']);
		}

		// Protocol and registry
		if (isset($handshake['protocol']) && (int) $handshake['protocol'] !== (int) ($manifest['protocol'] ?? 1)) {
			$blockers[] = array('code' => 'protocol_mismatch', 'message' => 'The target runs sync protocol ' . $handshake['protocol'] . ', this site runs ' . ($manifest['protocol'] ?? 1) . '. Update the plugin on the older site.');
		}
		if (!empty($handshake['registry_hash']) && !empty($manifest['registry_hash']) && $handshake['registry_hash'] !== $manifest['registry_hash']) {
			$blockers[] = array('code' => 'registry_mismatch', 'message' => 'The reference registry differs between the two sites. Update the plugin so both sites agree on where references sit.');
		}

		// Page
		$page = (array) ($manifest['page'] ?? array());
		$pp   = (array) ($plan['page'] ?? array());
		if (isset($pp['type_allowed']) && !$pp['type_allowed']) {
			$blockers[] = array('code' => 'post_type_not_allowed', 'message' => 'The target does not accept the post type ' . ($page['type'] ?? '?') . '.');
		}
		$exists = ($pp['status'] ?? 'missing') === 'exists';
		$from   = $exists ? (string) ($pp['prod_status'] ?? '') : null;
		$to     = (string) ($page['status'] ?? 'draft');
		$edited = $exists && (($pp['edited_on_prod'] ?? null) === true || ($pp['last_synced_hash'] ?? null) === null);
		if ($exists && ($pp['edited_on_prod'] ?? null) === true) {
			$warnings[] = array('code' => 'edited_on_prod', 'message' => 'The target page was edited since the last sync. Syncing overwrites those edits (a revision is kept).');
		} elseif ($exists && ($pp['last_synced_hash'] ?? null) === null) {
			$warnings[] = array('code' => 'no_sync_history', 'message' => 'The target page was paired manually and has never been synced; its current content will be replaced (a revision is kept).');
		}
		if (empty($handshake['unfiltered_html'])) {
			$warnings[] = array('code' => 'kses_active', 'message' => 'The target strips unfiltered HTML (DISALLOW_UNFILTERED_HTML); inline styles, iframes and SVG in the content may be removed.');
		}

		// dgb definitions
		foreach ((array) ($plan['dgb_blocks'] ?? array()) as $slug => $d) {
			if (($d['status'] ?? '') === 'missing') {
				$blockers[] = array('code' => 'dgb_block_missing', 'message' => "Block dgb/{$slug} is not defined on the target. Deploy the block definition first.");
			} elseif (($d['status'] ?? '') === 'version_mismatch') {
				$blockers[] = array('code' => 'dgb_block_version', 'message' => "Block dgb/{$slug} is version " . ($manifest['dgb_blocks'][$slug] ?? '?') . " here and " . ($d['version'] ?? '?') . " on the target. Deploy the block definition first.");
			}
		}

		// Media
		$bytes = $push = $pull = 0;
		foreach ((array) ($plan['media'] ?? array()) as $uuid => $m) {
			$status = $m['status'] ?? 'missing';
			if ($status === 'exists_unchanged') {
				continue;
			}
			$info = (array) ($manifest['media'][$uuid] ?? array());
			$size = (int) ($info['bytes'] ?? 0);
			$mode = null;
			if ($push_ok && ($upload_max <= 0 || $size <= $upload_max)) {
				$mode = 'push';
			} elseif ($pull_ok) {
				$mode = 'pull';
			}
			if ($mode === null) {
				$why = !$push_ok && !$pull_ok ? 'The target allows no push uploads and cannot reach this site to pull.' : ("The file is " . self::human($size) . ", above the target upload limit of " . self::human($upload_max) . ", and the target cannot pull from this site.");
				$blockers[] = array('code' => 'no_media_transport', 'message' => ($info['file'] ?? $uuid) . ': ' . $why);
				continue;
			}
			$bytes += $size;
			if ($mode === 'push') {
				$push++;
			} else {
				$pull++;
			}
			$steps[] = array('step_id' => $uuid_gen(), 'type' => 'media', 'object_uuid' => (string) $uuid, 'payload' => array('mode' => $mode, 'status' => $status, 'bytes' => $size, 'file' => $info['file'] ?? ''));
		}

		// Reusable blocks (manifest order is leaves first)
		$affected = array();
		$blocks_count = 0;
		foreach ((array) ($manifest['blocks'] ?? array()) as $b) {
			$pb = (array) ($plan['blocks'][$b['uuid']] ?? array('status' => 'missing'));
			if (($pb['status'] ?? 'missing') === 'exists_unchanged') {
				continue;
			}
			$blocks_count++;
			foreach ((array) ($pb['affected'] ?? array()) as $a) {
				$affected[$a['id']] = $a;
			}
			$steps[] = array('step_id' => $uuid_gen(), 'type' => 'block', 'object_uuid' => (string) $b['uuid'], 'payload' => array('status' => $pb['status'] ?? 'missing', 'title' => $b['title'] ?? ''));
		}

		// Page step: skipped only when nothing at all changed.
		$page_unchanged = $exists && !empty($pp['unchanged']) && ($from === $to);
		$nothing = $page_unchanged && empty($steps);
		if (!$nothing) {
			$steps[] = array('step_id' => $uuid_gen(), 'type' => 'page', 'object_uuid' => (string) ($page['uuid'] ?? ''), 'payload' => array('action' => $exists ? 'update' : 'create'));
		}

		$mode = $push && $pull ? 'mixed' : ($pull ? 'pull' : 'push');
		return array(
			'steps'      => $steps,
			'blockers'   => $blockers,
			'warnings'   => self::dedupe($warnings),
			'media_mode' => $mode,
			'summary'    => array(
				'media_count'    => $push + $pull,
				'media_bytes'    => $bytes,
				'media_push'     => $push,
				'media_pull'     => $pull,
				'blocks_count'   => $blocks_count,
				'page_action'    => $nothing ? 'none' : ($exists ? 'update' : 'create'),
				'from_status'    => $from,
				'to_status'      => $to,
				'publishes'      => $to === 'publish' && $from !== 'publish',
				'unpublishes'    => $from === 'publish' && $to !== 'publish',
				'edited_on_prod' => $edited,
				'affected_pages' => \array_values($affected),
				'nothing_to_do'  => $nothing,
				'prod_permalink' => $pp['permalink'] ?? null,
			),
		);
	}

	public static function human(int $bytes): string {
		if ($bytes >= 1048576) {
			return \round($bytes / 1048576, 1) . ' MB';
		}
		if ($bytes >= 1024) {
			return \round($bytes / 1024) . ' KB';
		}
		return $bytes . ' B';
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
