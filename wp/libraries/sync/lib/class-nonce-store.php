<?php
/**
 * Prod-side replay protection: a nonce may be used once inside the timestamp window.
 */
namespace aw2\sync;

class Nonce_Store {

	/** @return bool true when the nonce is new (and now recorded), false when it was seen before. */
	public static function claim(string $token_id, string $nonce): bool {
		global $wpdb;
		$table = Schema::table('nonces');
		$res = $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$table} (nonce, token_id, seen_at) VALUES (%s, %s, %s)",
			$nonce, $token_id, \current_time('mysql', true)
		));
		return $res === 1;
	}

	public static function purge(int $older_than_seconds = 900): int {
		global $wpdb;
		$table = Schema::table('nonces');
		return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE seen_at < %s", \gmdate('Y-m-d H:i:s', \time() - $older_than_seconds)));
	}
}
