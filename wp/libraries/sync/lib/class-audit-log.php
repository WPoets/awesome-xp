<?php
/**
 * Prod-side audit log: one row per authenticated (or rejected) sync call.
 */
namespace aw2\sync;

class Audit_Log {

	public static function record(array $entry): void {
		global $wpdb;
		$row = array(
			'token_id'    => isset($entry['token_id']) ? \mb_substr((string) $entry['token_id'], 0, 16) : null,
			'run_id'      => isset($entry['run_id']) ? \mb_substr((string) $entry['run_id'], 0, 36) : null,
			'route'       => \mb_substr((string) ($entry['route'] ?? ''), 0, 100),
			'method'      => \mb_substr((string) ($entry['method'] ?? ''), 0, 8),
			'object_uuid' => isset($entry['object_uuid']) ? \mb_substr((string) $entry['object_uuid'], 0, 191) : null,
			'result'      => \mb_substr((string) ($entry['result'] ?? 'ok'), 0, 20),
			'http_status' => (int) ($entry['http_status'] ?? 200),
			'message'     => isset($entry['message']) ? \mb_substr((string) $entry['message'], 0, 2000) : null,
			'ip'          => isset($entry['ip']) ? \mb_substr((string) $entry['ip'], 0, 45) : null,
			'created_at'  => \current_time('mysql', true),
		);
		$wpdb->insert(Schema::table('log'), $row);
	}

	public static function recent(int $limit = 200, array $filters = array()): array {
		global $wpdb;
		$where = array('1=1');
		$args  = array();
		foreach (array('token_id', 'run_id', 'result') as $col) {
			if (!empty($filters[$col])) {
				$where[] = "{$col} = %s";
				$args[]  = $filters[$col];
			}
		}
		$sql = 'SELECT * FROM ' . Schema::table('log') . ' WHERE ' . \implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d';
		$args[] = \max(1, \min(5000, $limit));
		return $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) ?: array();
	}

	public static function csv(array $rows): string {
		$fh = \fopen('php://temp', 'r+');
		\fputcsv($fh, array('created_at', 'token_id', 'run_id', 'route', 'method', 'object_uuid', 'result', 'http_status', 'ip', 'message'));
		foreach ($rows as $r) {
			\fputcsv($fh, array($r['created_at'], $r['token_id'], $r['run_id'], $r['route'], $r['method'], $r['object_uuid'], $r['result'], $r['http_status'], $r['ip'], $r['message']));
		}
		\rewind($fh);
		$out = (string) \stream_get_contents($fh);
		\fclose($fh);
		return $out;
	}
}
