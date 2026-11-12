<?php
/**
 * Saved code-deploy selections ("deployment sets"), so a developer names a
 * selection once and re-runs it from the screen or `wp awx sync deploy --set`.
 */
namespace aw2\sync;

class Deploy_Sets {

	public static function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results('SELECT * FROM ' . Schema::table('sets') . ' ORDER BY name ASC', ARRAY_A) ?: array();
		return \array_map(array(__CLASS__, 'decode'), $rows);
	}

	public static function get(string $name): ?array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('sets') . ' WHERE name = %s', $name), ARRAY_A);
		return $row ? self::decode($row) : null;
	}

	/** Create or replace by name. Returns the stored set. */
	public static function save(string $name, array $selection, int $user_id): array {
		global $wpdb;
		$name = self::clean_name($name);
		if ($name === '') {
			throw new Sync_Exception('invalid_set_name', 'A set needs a name (letters, numbers, dashes).');
		}
		$now = \current_time('mysql', true);
		$existing = self::get($name);
		$data = array('selection' => \wp_json_encode($selection), 'user_id' => $user_id, 'updated_at' => $now);
		if ($existing) {
			$wpdb->update(Schema::table('sets'), $data, array('name' => $name));
		} else {
			$wpdb->insert(Schema::table('sets'), $data + array('name' => $name, 'created_at' => $now));
		}
		return self::get($name);
	}

	public static function delete(string $name): bool {
		global $wpdb;
		return (bool) $wpdb->delete(Schema::table('sets'), array('name' => self::clean_name($name)));
	}

	public static function touch(string $name, string $run_id): void {
		global $wpdb;
		$wpdb->update(Schema::table('sets'), array('last_run_id' => $run_id, 'updated_at' => \current_time('mysql', true)), array('name' => self::clean_name($name)));
	}

	public static function clean_name(string $name): string {
		return \mb_substr(\preg_replace('/[^a-z0-9_.\-]+/', '-', \strtolower(\trim($name))), 0, 100);
	}

	private static function decode(array $row): array {
		$row['selection'] = \json_decode((string) $row['selection'], true) ?: array();
		$row['id'] = (int) $row['id'];
		$row['user_id'] = (int) $row['user_id'];
		return $row;
	}
}
