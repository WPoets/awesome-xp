<?php
/**
 * Release store for sync audits: records top-level release events on both
 * Dev and Prod, provides OOM-safe paginated listings, and automated pruning.
 */
namespace aw2\sync;

class Release_Store {

	const RETAIN_RELEASES_DEFAULT = 100;
	const RETAIN_DAYS_DEFAULT     = 90;
	const PRUNE_ITEMS_DAYS_DEFAULT = 30;

	private static function table(): string {
		global $wpdb;
		if (\class_exists(__NAMESPACE__ . '\\Schema') && \method_exists(__NAMESPACE__ . '\\Schema', 'table')) {
			return Schema::table('releases');
		}
		return $wpdb->prefix . 'awx_sync_releases';
	}

	/**
	 * Insert a new release record (or update if release_id exists).
	 */
	public static function record(array $data): bool {
		global $wpdb;
		$table = self::table();
		$release_id = isset($data['release_id']) ? (string) $data['release_id'] : '';
		if ($release_id === '' || !\preg_match('/^[0-9a-fA-F-]{36}$/', $release_id)) {
			return false;
		}

		$existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE release_id = %s", $release_id));

		$row = array();
		if (isset($data['kind'])) {
			$row['kind'] = \mb_substr((string) $data['kind'], 0, 20);
		}
		if (isset($data['label'])) {
			$row['label'] = \mb_substr((string) $data['label'], 0, 100);
		}
		if (isset($data['status'])) {
			$row['status'] = \mb_substr((string) $data['status'], 0, 20);
		}
		if (isset($data['source_url'])) {
			$row['source_url'] = \mb_substr((string) $data['source_url'], 0, 255);
		}
		if (isset($data['target_url'])) {
			$row['target_url'] = \mb_substr((string) $data['target_url'], 0, 255);
		}
		if (isset($data['author_name'])) {
			$row['author_name'] = \mb_substr((string) $data['author_name'], 0, 100);
		}
		if (isset($data['author_email'])) {
			$row['author_email'] = \mb_substr((string) $data['author_email'], 0, 100);
		}
		if (\array_key_exists('token_id', $data)) {
			$row['token_id'] = $data['token_id'] !== null ? \mb_substr((string) $data['token_id'], 0, 16) : null;
		}
		if (\array_key_exists('ip', $data)) {
			$row['ip'] = $data['ip'] !== null ? \mb_substr((string) $data['ip'], 0, 45) : null;
		}
		if (isset($data['summary'])) {
			$s = \is_string($data['summary']) ? (\json_decode($data['summary'], true) ?: array()) : (array) $data['summary'];
			if (isset($data['items_count'])) {
				$s['items_count'] = (int) $data['items_count'];
			}
			$row['summary'] = \json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} elseif (isset($data['items_count'])) {
			if ($existing) {
				$curr_summary = $wpdb->get_var($wpdb->prepare("SELECT summary FROM {$table} WHERE id = %d", (int) $existing));
				$s = $curr_summary ? (\json_decode($curr_summary, true) ?: array()) : array();
				$s['items_count'] = (int) $data['items_count'];
				$row['summary'] = \json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			} else {
				$row['summary'] = \json_encode(array('items_count' => (int) $data['items_count']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
		}
		if (\array_key_exists('items', $data)) {
			$row['items'] = $data['items'] === null ? null : (\is_string($data['items']) ? $data['items'] : \json_encode($data['items'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}
		if (isset($data['error'])) {
			$row['error'] = (string) $data['error'];
		}
		if (isset($data['completed_at'])) {
			$row['completed_at'] = (string) $data['completed_at'];
		}
		if (isset($data['duration_seconds'])) {
			$row['duration_seconds'] = (int) $data['duration_seconds'];
		}

		if ($existing) {
			if (empty($row)) {
				return true;
			}
			$res = $wpdb->update($table, $row, array('id' => (int) $existing));
			return $res !== false;
		}

		$row['release_id']   = $release_id;
		$row['kind']         = $row['kind'] ?? 'code';
		$row['label']        = $row['label'] ?? '';
		$row['status']       = $row['status'] ?? 'running';
		$row['source_url']   = $row['source_url'] ?? '';
		$row['target_url']   = $row['target_url'] ?? '';
		$row['author_name']  = $row['author_name'] ?? '';
		$row['author_email'] = $row['author_email'] ?? '';
		$row['token_id']     = $row['token_id'] ?? null;
		$row['ip']           = $row['ip'] ?? null;
		$row['created_at']   = isset($data['created_at']) ? (string) $data['created_at'] : \current_time('mysql', true);
		$res = $wpdb->insert($table, $row);
		return $res !== false;
	}

	/**
	 * Start a release (creates a 'running' record).
	 */
	public static function start(string $release_id, string $kind = 'code', $label = '', string $source_url = '', string $target_url = '', string $author_name = '', string $author_email = '', ?string $token_id = null): bool {
		$client_ip = \class_exists(__NAMESPACE__ . '\\Request_Auth') && \method_exists(__NAMESPACE__ . '\\Request_Auth', 'client_ip') ? Request_Auth::client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
		if (\is_array($label)) {
			$meta         = $label;
			$label        = (string) ($meta['label'] ?? '');
			$source_url   = (string) ($meta['source_url'] ?? '');
			$target_url   = (string) ($meta['target_url'] ?? '');
			$author_name  = (string) ($meta['author_name'] ?? '');
			$author_email = (string) ($meta['author_email'] ?? '');
			$token_id     = isset($meta['token_id']) ? (string) $meta['token_id'] : $token_id;
			$client_ip    = !empty($meta['client_ip']) ? (string) $meta['client_ip'] : $client_ip;
		}
		return self::record(array(
			'release_id'   => $release_id,
			'kind'         => $kind,
			'label'        => $label,
			'status'       => 'running',
			'source_url'   => $source_url,
			'target_url'   => $target_url,
			'author_name'  => $author_name,
			'author_email' => $author_email,
			'token_id'     => $token_id,
			'ip'           => $client_ip,
			'created_at'   => \current_time('mysql', true),
		));
	}

	/**
	 * Mark a release complete (or failed).
	 */
	public static function complete(string $release_id, string $status, array $summary, ?array $items = null, ?string $error = null): bool {
		global $wpdb;
		$table = self::table();
		$created_at = $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$table} WHERE release_id = %s", $release_id));

		$completed_at = \current_time('mysql', true);
		$duration = 0;
		if ($created_at) {
			$duration = \max(0, \strtotime($completed_at . ' UTC') - \strtotime($created_at . ' UTC'));
		}

		if ($items !== null) {
			if (!isset($summary['items_count'])) {
				$summary['items_count'] = \count($items);
			}
		}

		$data = array(
			'release_id'       => $release_id,
			'status'           => $status,
			'summary'          => $summary,
			'error'            => $error,
			'completed_at'     => $completed_at,
			'duration_seconds' => $duration,
		);
		if ($items !== null) {
			$data['items'] = $items;
		}
		return self::record($data);
	}

	/**
	 * Retrieve a single release row with its items decoded.
	 */
	public static function get(string $release_id, bool $include_items = true): ?array {
		global $wpdb;
		$table = self::table();
		$cols = self::columns($include_items);
		$row = $wpdb->get_row($wpdb->prepare("SELECT {$cols} FROM {$table} WHERE release_id = %s", $release_id), ARRAY_A);
		if (!$row) {
			return null;
		}
		return self::format_row($row);
	}

	/**
	 * OOM-Safe listing of recent releases. `items` column is excluded by default!
	 */
	public static function recent(int $limit = 50, array $filters = array(), bool $include_items = false): array {
		global $wpdb;
		$table = self::table();
		$cols = self::columns($include_items);

		$where = array('1=1');
		$args  = array();

		foreach (array('kind', 'status', 'token_id') as $f) {
			if (!empty($filters[$f])) {
				$where[] = "{$f} = %s";
				$args[]  = (string) $filters[$f];
			}
		}
		if (!empty($filters['target_url'])) {
			$where[] = "target_url LIKE %s";
			$args[]  = '%' . $wpdb->esc_like((string) $filters['target_url']) . '%';
		}
		if (!empty($filters['search'])) {
			$s = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
			$where[] = "(label LIKE %s OR author_name LIKE %s OR author_email LIKE %s OR release_id LIKE %s)";
			$args[] = $s;
			$args[] = $s;
			$args[] = $s;
			$args[] = $s;
		}

		$sql = "SELECT {$cols} FROM {$table} WHERE " . \implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d';
		$args[] = \max(1, \min(500, $limit));

		$rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) ?: array();

		// Batch-resolve token labels for display if any tokens are present
		$token_labels = self::token_labels();
		$out = array();
		foreach ($rows as $r) {
			$formatted = self::format_row($r);
			if (!empty($formatted['token_id']) && isset($token_labels[$formatted['token_id']])) {
				$formatted['token_label'] = $token_labels[$formatted['token_id']];
			}
			$out[] = $formatted;
		}
		return $out;
	}

	/**
	 * Prune old releases and heavy items payloads to prevent bloat.
	 */
	public static function purge(?int $keep_days = null, ?int $keep_count = null, ?int $prune_items_days = null): array {
		global $wpdb;
		$table = self::table();

		$keep_days        = $keep_days !== null ? $keep_days : (int) \apply_filters('awx_sync_retain_release_days', \defined('AWX_SYNC_RETAIN_DAYS') ? AWX_SYNC_RETAIN_DAYS : self::RETAIN_DAYS_DEFAULT);
		$keep_count       = $keep_count !== null ? $keep_count : (int) \apply_filters('awx_sync_retain_release_count', \defined('AWX_SYNC_RETAIN_RELEASES') ? AWX_SYNC_RETAIN_RELEASES : self::RETAIN_RELEASES_DEFAULT);
		$prune_items_days = $prune_items_days !== null ? $prune_items_days : (int) \apply_filters('awx_sync_prune_items_days', self::PRUNE_ITEMS_DAYS_DEFAULT);

		$now = \time();
		$day = \defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
		$pruned_items = 0;
		$deleted_days = 0;
		$deleted_count = 0;

		// Tier 1: Nullify heavy items column for releases older than $prune_items_days
		if ($prune_items_days > 0) {
			$cutoff_items = \gmdate('Y-m-d H:i:s', $now - ($prune_items_days * $day));
			$res = $wpdb->query($wpdb->prepare(
				"UPDATE {$table} SET items = NULL WHERE created_at < %s AND items IS NOT NULL",
				$cutoff_items
			));
			$pruned_items = \is_int($res) ? $res : 0;
		}

		// Tier 2: Delete releases older than $keep_days
		if ($keep_days > 0) {
			$cutoff_date = \gmdate('Y-m-d H:i:s', $now - ($keep_days * $day));
			$res = $wpdb->query($wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				$cutoff_date
			));
			$deleted_days = \is_int($res) ? $res : 0;
		}

		// Tier 3: Enforce maximum count limit (delete records beyond the last $keep_count)
		if ($keep_count > 0) {
			$cutoff_id = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
				\max(1, $keep_count)
			));
			if ($cutoff_id > 0) {
				$res = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id <= %d", $cutoff_id));
				$deleted_count = \is_int($res) ? $res : 0;
			}
		}

		return array(
			'items_pruned'  => $pruned_items,
			'deleted_days'  => $deleted_days,
			'deleted_count' => $deleted_count,
			'rows_deleted'  => $deleted_days + $deleted_count,
		);
	}

	/* ---- Helpers ---- */

	private static function columns(bool $include_items): string {
		$cols = array(
			'id', 'release_id', 'kind', 'label', 'status', 'source_url', 'target_url',
			'author_name', 'author_email', 'token_id', 'ip', 'summary', 'error',
			'created_at', 'completed_at', 'duration_seconds'
		);
		if ($include_items) {
			$cols[] = 'items';
		}
		return \implode(', ', $cols);
	}

	private static function format_row(array $row): array {
		if (isset($row['summary']) && \is_string($row['summary'])) {
			$decoded = \json_decode($row['summary'], true);
			$row['summary'] = \is_array($decoded) ? $decoded : array();
		}
		if (isset($row['items']) && \is_string($row['items'])) {
			$decoded = \json_decode($row['items'], true);
			$row['items'] = \is_array($decoded) ? $decoded : array();
		}
		$summary = $row['summary'] ?? array();
		$row['items_count'] = isset($summary['items_count'])
			? (int) $summary['items_count']
			: (isset($row['items']) && \is_array($row['items']) ? \count($row['items']) : (int) ($summary['total'] ?? 0));
		$row['client_ip'] = $row['ip'] ?? '';
		$row['duration_s'] = (float) ($row['duration_seconds'] ?? 0);
		return $row;
	}

	private static function token_labels(): array {
		if (!\class_exists(__NAMESPACE__ . '\\Token_Store')) {
			return array();
		}
		$tokens = Token_Store::all();
		$map = array();
		foreach ($tokens as $t) {
			if (!empty($t['token_id'])) {
				$map[$t['token_id']] = !empty($t['label']) ? (string) $t['label'] : $t['token_id'];
			}
		}
		return $map;
	}
}
