<?php
/**
 * Dev-side run records (awx_sync_runs): the manifest, the plan and the status
 * of one sync of one post. Steps live in Step_Store.
 */
namespace aw2\sync;

class Manifest_Store {

	const OPEN_STATUSES = array('planning', 'awaiting_confirm', 'running', 'failed');

	public static function create(int $post_id, string $post_uuid, array $manifest, string $target_url, int $user_id, string $media_mode = 'push', string $kind = 'page', string $label = ''): string {
		global $wpdb;
		$run_id = \wp_generate_uuid4();
		$now = \current_time('mysql', true);
		$wpdb->insert(Schema::table('runs'), array(
			'run_id'     => $run_id,
			'post_id'    => $post_id,
			'post_uuid'  => $post_uuid,
			'kind'       => $kind,
			'label'      => \mb_substr($label, 0, 100),
			'target_url' => \mb_substr($target_url, 0, 255),
			'media_mode' => $media_mode,
			'status'     => 'planning',
			'manifest'   => \wp_json_encode($manifest),
			'user_id'    => $user_id,
			'created_at' => $now,
			'updated_at' => $now,
		));
		return $run_id;
	}

	public static function get(string $run_id): ?array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('runs') . ' WHERE run_id = %s', $run_id), ARRAY_A);
		return $row ? self::decode($row) : null;
	}

	public static function update(string $run_id, array $fields): void {
		global $wpdb;
		foreach (array('manifest', 'plan') as $k) {
			if (isset($fields[$k]) && \is_array($fields[$k])) {
				$fields[$k] = \wp_json_encode($fields[$k]);
			}
		}
		$fields['updated_at'] = \current_time('mysql', true);
		$wpdb->update(Schema::table('runs'), $fields, array('run_id' => $run_id));
	}

	/** Most recent run for a post that is not finished, or null. */
	public static function open_for_post(int $post_id): ?array {
		global $wpdb;
		$in  = "'" . \implode("','", self::OPEN_STATUSES) . "'";
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('runs') . " WHERE post_id = %d AND status IN ({$in}) ORDER BY id DESC LIMIT 1", $post_id), ARRAY_A);
		return $row ? self::decode($row) : null;
	}

	public static function recent_for_post(int $post_id, int $limit = 10): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::table('runs') . ' WHERE post_id = %d ORDER BY id DESC LIMIT %d', $post_id, $limit), ARRAY_A) ?: array();
		return \array_map(array(__CLASS__, 'decode'), $rows);
	}

	/** Mark every open run of a post as superseded (a new analyze starts). */
	public static function supersede(int $post_id): void {
		global $wpdb;
		$in = "'" . \implode("','", self::OPEN_STATUSES) . "'";
		$wpdb->query($wpdb->prepare('UPDATE ' . Schema::table('runs') . " SET status = 'superseded', updated_at = %s WHERE post_id = %d AND status IN ({$in})", \current_time('mysql', true), $post_id));
	}

	/** Most recent code-deploy runs (any status). */
	public static function recent_code_runs(int $limit = 10): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::table('runs') . " WHERE kind = 'code' ORDER BY id DESC LIMIT %d", $limit), ARRAY_A) ?: array();
		return \array_map(array(__CLASS__, 'decode'), $rows);
	}

	/** The open code-deploy run of a user, or null. */
	public static function open_code_run(int $user_id): ?array {
		global $wpdb;
		$in  = "'" . \implode("','", self::OPEN_STATUSES) . "'";
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('runs') . " WHERE kind = 'code' AND user_id = %d AND status IN ({$in}) ORDER BY id DESC LIMIT 1", $user_id), ARRAY_A);
		return $row ? self::decode($row) : null;
	}

	private static function decode(array $row): array {
		$row['manifest'] = $row['manifest'] !== null && $row['manifest'] !== '' ? \json_decode($row['manifest'], true) : null;
		$row['plan']     = $row['plan'] !== null && $row['plan'] !== '' ? \json_decode($row['plan'], true) : null;
		return $row;
	}
}
