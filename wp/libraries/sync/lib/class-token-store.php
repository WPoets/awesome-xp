<?php
/**
 * Prod-side token store.
 *
 * A token is "<token_id>.<secret>". The table keeps sha256(secret) for a fast
 * identity check and the secret itself encrypted (AES-256-GCM) with a key
 * derived from AUTH_KEY, because verifying an HMAC needs the key: a database
 * dump alone cannot sign requests, and the plain secret is shown once only.
 */
namespace aw2\sync;

class Token_Store {

	const DEFAULT_TTL_DAYS = 90;

	/** Known scopes: `sync` = content (pages, media, blocks); `code` = code deploy routes. */
	const SCOPES = array('sync', 'code');

	/**
	 * @return array ['token' => '<id>.<secret>' (shown once), 'token_id' => ..., 'expires_at' => ...]
	 * @throws \RuntimeException when the secret cannot be encrypted.
	 */
	public static function create(string $label, int $ttl_days = self::DEFAULT_TTL_DAYS, array $scopes = array('sync')): array {
		global $wpdb;
		$token_id = Signer::new_token_id();
		$secret   = Signer::new_secret();
		$enc      = self::encrypt($secret);
		if ($enc === null) {
			throw new \RuntimeException('Cannot encrypt the token secret: OpenSSL with AES-256-GCM is required.');
		}
		$expires = $ttl_days > 0 ? \gmdate('Y-m-d H:i:s', \time() + $ttl_days * DAY_IN_SECONDS) : null;
		$ok = $wpdb->insert(Schema::table('tokens'), array(
			'token_id'   => $token_id,
			'label'      => \mb_substr($label, 0, 100),
			'token_hash' => Signer::hash_secret($secret),
			'secret_enc' => $enc,
			'scopes'     => \wp_json_encode(\array_values($scopes)),
			'expires_at' => $expires,
			'created_by' => \get_current_user_id(),
			'created_at' => \current_time('mysql', true),
		));
		if (!$ok) {
			throw new \RuntimeException('Could not store the token: ' . $wpdb->last_error);
		}
		return array('token' => $token_id . '.' . $secret, 'token_id' => $token_id, 'expires_at' => $expires);
	}

	public static function find(string $token_id): ?array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('tokens') . ' WHERE token_id = %s', $token_id), ARRAY_A);
		return $row ?: null;
	}

	/** Decrypted secret for a row, or null when it cannot be read. */
	public static function secret(array $row): ?string {
		if (empty($row['secret_enc'])) {
			return null;
		}
		$secret = self::decrypt((string) $row['secret_enc']);
		if ($secret === null || !\hash_equals((string) $row['token_hash'], Signer::hash_secret($secret))) {
			return null;
		}
		return $secret;
	}

	public static function touch(string $token_id, string $ip): void {
		global $wpdb;
		$wpdb->update(Schema::table('tokens'), array('last_used_at' => \current_time('mysql', true), 'last_used_ip' => \mb_substr($ip, 0, 45)), array('token_id' => $token_id));
	}

	public static function revoke(string $token_id): bool {
		global $wpdb;
		return (bool) $wpdb->update(Schema::table('tokens'), array('revoked_at' => \current_time('mysql', true), 'secret_enc' => null), array('token_id' => $token_id));
	}

	public static function all(): array {
		global $wpdb;
		return $wpdb->get_results('SELECT token_id, label, scopes, expires_at, last_used_at, last_used_ip, revoked_at, created_by, created_at FROM ' . Schema::table('tokens') . ' ORDER BY created_at DESC', ARRAY_A) ?: array();
	}

	public static function scopes(array $row): array {
		$s = \json_decode((string) $row['scopes'], true);
		return \is_array($s) ? $s : array();
	}

	/* ---- at-rest encryption ---- */

	private static function key(): ?string {
		if (!\defined('AUTH_KEY') || \strlen((string) AUTH_KEY) < 32) {
			return null;
		}
		$salt = \defined('AUTH_SALT') ? (string) AUTH_SALT : '';
		return \hash('sha256', AUTH_KEY . '|' . $salt . '|awx-sync-token', true);
	}

	public static function encrypt(string $plain): ?string {
		$key = self::key();
		if ($key === null || !\function_exists('openssl_encrypt') || !\in_array('aes-256-gcm', \openssl_get_cipher_methods(), true)) {
			return null;
		}
		$iv  = \random_bytes(12);
		$tag = '';
		$ct  = \openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
		if ($ct === false) {
			return null;
		}
		return 'v1:' . \base64_encode($iv . $tag . $ct);
	}

	public static function decrypt(string $blob): ?string {
		$key = self::key();
		if ($key === null || 0 !== \strpos($blob, 'v1:')) {
			return null;
		}
		$raw = \base64_decode(\substr($blob, 3), true);
		if ($raw === false || \strlen($raw) < 29) {
			return null;
		}
		$iv  = \substr($raw, 0, 12);
		$tag = \substr($raw, 12, 16);
		$ct  = \substr($raw, 28);
		$pt  = \openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		return $pt === false ? null : $pt;
	}
}
