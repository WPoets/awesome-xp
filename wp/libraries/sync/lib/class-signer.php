<?php
/**
 * HMAC request signing shared by the Dev client and the Prod verifier.
 *
 *   X-AWX-Token-Id:  <token id>
 *   X-AWX-Timestamp: <unix seconds>
 *   X-AWX-Nonce:     <16 random bytes, hex>
 *   X-AWX-Signature: HMAC-SHA256(secret, METHOD \n path \n timestamp \n nonce \n sha256(body))
 *
 * No WordPress dependency, so it is unit-testable on its own.
 */
namespace aw2\sync;

class Signer {

	const H_TOKEN_ID  = 'X-AWX-Token-Id';
	const H_TIMESTAMP = 'X-AWX-Timestamp';
	const H_NONCE     = 'X-AWX-Nonce';
	const H_SIGNATURE = 'X-AWX-Signature';
	const H_PROTOCOL  = 'X-AWX-Protocol';

	/** Seconds a timestamp may differ from the receiver's clock. */
	const WINDOW = 300;

	public static function canonical(string $method, string $path, int $timestamp, string $nonce, string $body): string {
		return \strtoupper($method) . "\n" . self::normalise_path($path) . "\n" . $timestamp . "\n" . $nonce . "\n" . \hash('sha256', $body);
	}

	/** Path only (no host, no query), with one leading slash and no trailing slash. */
	public static function normalise_path(string $path): string {
		if (false !== \strpos($path, '://')) {
			$path = (string) \parse_url($path, PHP_URL_PATH);
		}
		$q = \strpos($path, '?');
		if ($q !== false) {
			$path = \substr($path, 0, $q);
		}
		$path = '/' . \trim($path, '/');
		return $path;
	}

	public static function signature(string $secret, string $method, string $path, int $timestamp, string $nonce, string $body): string {
		return \hash_hmac('sha256', self::canonical($method, $path, $timestamp, $nonce, $body), $secret);
	}

	public static function nonce(): string {
		return \bin2hex(\random_bytes(16));
	}

	/**
	 * Headers for an outgoing request.
	 */
	public static function sign(string $token_id, string $secret, string $method, string $path, string $body, ?int $timestamp = null, ?string $nonce = null): array {
		$timestamp = $timestamp ?? \time();
		$nonce     = $nonce ?? self::nonce();
		return array(
			self::H_TOKEN_ID  => $token_id,
			self::H_TIMESTAMP => (string) $timestamp,
			self::H_NONCE     => $nonce,
			self::H_SIGNATURE => self::signature($secret, $method, $path, $timestamp, $nonce, $body),
			self::H_PROTOCOL  => (string) (\defined('AWX_SYNC_PROTOCOL') ? AWX_SYNC_PROTOCOL : 1),
		);
	}

	/**
	 * Verify incoming headers against a known secret.
	 *
	 * @param array $headers lower-case header name => value
	 * @return true|string  true, or an error code: missing_headers | stale_timestamp | bad_nonce | bad_signature
	 */
	public static function verify(string $secret, array $headers, string $method, string $path, string $body, ?int $now = null, int $window = self::WINDOW) {
		$h = array();
		foreach ($headers as $k => $v) {
			$h[\strtolower((string) $k)] = \is_array($v) ? (string) \reset($v) : (string) $v;
		}
		$need = array(\strtolower(self::H_TOKEN_ID), \strtolower(self::H_TIMESTAMP), \strtolower(self::H_NONCE), \strtolower(self::H_SIGNATURE));
		foreach ($need as $k) {
			if (!isset($h[$k]) || $h[$k] === '') {
				return 'missing_headers';
			}
		}
		$ts = $h[\strtolower(self::H_TIMESTAMP)];
		if (!\ctype_digit($ts)) {
			return 'stale_timestamp';
		}
		$now = $now ?? \time();
		if (\abs($now - (int) $ts) > $window) {
			return 'stale_timestamp';
		}
		$nonce = $h[\strtolower(self::H_NONCE)];
		if (!\preg_match('/^[0-9a-f]{32}$/', $nonce)) {
			return 'bad_nonce';
		}
		$expected = self::signature($secret, $method, $path, (int) $ts, $nonce, $body);
		if (!\hash_equals($expected, \strtolower($h[\strtolower(self::H_SIGNATURE)]))) {
			return 'bad_signature';
		}
		return true;
	}

	/* ---- Token format: "<token_id>.<secret>" ---- */

	public static function split_token(string $token): ?array {
		$token = \trim($token);
		if (!\preg_match('/^([A-Za-z0-9]{16})\.([A-Za-z0-9_\-]{40,})$/', $token, $m)) {
			return null;
		}
		return array('token_id' => $m[1], 'secret' => $m[2]);
	}

	public static function hash_secret(string $secret): string {
		return \hash('sha256', $secret);
	}

	public static function new_token_id(): string {
		$alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$out = '';
		for ($i = 0; $i < 16; $i++) {
			$out .= $alphabet[\random_int(0, \strlen($alphabet) - 1)];
		}
		return $out;
	}

	public static function new_secret(): string {
		return \rtrim(\strtr(\base64_encode(\random_bytes(32)), '+/', '-_'), '=');
	}
}
