<?php
/**
 * Step records in the awx_sync_steps table.
 *
 * On Dev the rows are the manifest's progress; on Prod they make every write
 * idempotent: a repeated step_id returns the stored response instead of
 * writing again.
 */
namespace aw2\sync;

class Step_Store {

	public static function get(string $run_id, string $step_id): ?array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('steps') . ' WHERE run_id = %s AND step_id = %s', $run_id, $step_id), ARRAY_A);
		if (!$row) {
			return null;
		}
		$row['response'] = $row['response'] !== null ? \json_decode($row['response'], true) : null;
		return $row;
	}

	/** Stored response of a completed step, for replays. */
	public static function replay(string $run_id, string $step_id): ?array {
		$row = self::get($run_id, $step_id);
		return ($row && $row['status'] === 'done' && \is_array($row['response'])) ? $row['response'] : null;
	}

	public static function for_run(string $run_id): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::table('steps') . ' WHERE run_id = %s ORDER BY seq ASC, id ASC', $run_id), ARRAY_A) ?: array();
		foreach ($rows as &$r) {
			$r['response'] = $r['response'] !== null ? \json_decode($r['response'], true) : null;
		}
		return $rows;
	}

	/** Insert pending steps (Dev) in order. $steps: [['step_id','type','object_uuid','payload'?], ...] */
	public static function create(string $run_id, array $steps): void {
		global $wpdb;
		$now = \current_time('mysql', true);
		$seq = 0;
		foreach ($steps as $s) {
			$seq++;
			$wpdb->insert(Schema::table('steps'), array(
				'run_id'      => $run_id,
				'step_id'     => $s['step_id'],
				'seq'         => $seq,
				'type'        => $s['type'],
				'object_uuid' => isset($s['object_uuid']) ? $s['object_uuid'] : null,
				'status'      => 'pending',
				'response'    => isset($s['payload']) ? \wp_json_encode($s['payload']) : null,
				'created_at'  => $now,
				'updated_at'  => $now,
			));
		}
	}

	public static function delete_run(string $run_id): void {
		global $wpdb;
		$wpdb->delete(Schema::table('steps'), array('run_id' => $run_id));
	}

	/**
	 * Upsert the outcome of one step. Used by Prod (status done/failed with the
	 * response to replay) and by Dev (progress).
	 */
	public static function record(string $run_id, string $step_id, string $type, ?string $object_uuid, string $status, ?array $response = null, ?string $error = null, ?string $request_hash = null): void {
		global $wpdb;
		$now   = \current_time('mysql', true);
		$table = Schema::table('steps');
		$exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE run_id = %s AND step_id = %s", $run_id, $step_id));
		$data = array(
			'status'     => $status,
			'response'   => $response !== null ? \wp_json_encode($response) : null,
			'error'      => $error,
			'updated_at' => $now,
		);
		if ($request_hash !== null) {
			$data['request_hash'] = $request_hash;
		}
		if ($exists) {
			$wpdb->query($wpdb->prepare("UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", (int) $exists));
			$wpdb->update($table, $data, array('id' => (int) $exists));
			return;
		}
		$seq = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(seq),0) + 1 FROM {$table} WHERE run_id = %s", $run_id));
		$wpdb->insert($table, \array_merge($data, array(
			'run_id'      => $run_id,
			'step_id'     => $step_id,
			'seq'         => $seq,
			'type'        => $type,
			'object_uuid' => $object_uuid,
			'attempts'    => 1,
			'created_at'  => $now,
		)));
	}

	public static function summary(string $run_id): array {
		$rows = self::for_run($run_id);
		$out  = array('total' => \count($rows), 'done' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0);
		foreach ($rows as $r) {
			if (isset($out[$r['status']])) {
				$out[$r['status']]++;
			}
		}
		return $out;
	}
}
