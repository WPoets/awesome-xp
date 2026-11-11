<?php
/**
 * Prod-side permission callback: validates signature, timestamp, nonce,
 * token expiry/revocation and scope, and writes an audit row on rejection.
 */
namespace aw2\sync;

class Request_Auth {

	/**
	 * @return array|\WP_Error ['token_id' => ..., 'scopes' => [...]] or a WP_Error with status 401/403
	 */
	/**
	 * @param string|null $body_override Signed body when the raw body is not available
	 *                                  (multipart uploads sign their JSON `meta` field instead).
	 */
	public static function authenticate(\WP_REST_Request $request, string $required_scope = 'sync', ?string $body_override = null) {
		$headers = array();
		foreach ($request->get_headers() as $k => $v) {
			$headers[\str_replace('_', '-', \strtolower($k))] = \is_array($v) ? \reset($v) : $v;
		}
		$token_id = isset($headers['x-awx-token-id']) ? (string) $headers['x-awx-token-id'] : '';
		$ip       = self::client_ip();
		$route    = $request->get_route();
		$method   = $request->get_method();

		$deny = function (string $code, string $message, int $status) use ($token_id, $ip, $route, $method) {
			Audit_Log::record(array('token_id' => $token_id ?: null, 'route' => $route, 'method' => $method, 'result' => 'denied', 'http_status' => $status, 'message' => $code . ': ' . $message, 'ip' => $ip));
			return new \WP_Error('awx_sync_' . $code, $message, array('status' => $status, 'retryable' => false));
		};

		if (!\is_ssl() && !self::allow_insecure()) {
			return $deny('https_required', 'Sync requests must use HTTPS.', 403);
		}
		if ($token_id === '' || !\preg_match('/^[A-Za-z0-9]{16}$/', $token_id)) {
			return $deny('missing_headers', 'Missing or malformed token id.', 401);
		}
		$row = Token_Store::find($token_id);
		if (!$row) {
			return $deny('unknown_token', 'Unknown token.', 401);
		}
		if (!empty($row['revoked_at'])) {
			return $deny('token_revoked', 'This token has been revoked.', 401);
		}
		if (!empty($row['expires_at']) && \strtotime($row['expires_at'] . ' UTC') < \time()) {
			return $deny('token_expired', 'This token has expired.', 401);
		}
		$allowlist = self::ip_allowlist();
		if (!empty($allowlist) && !\in_array($ip, $allowlist, true)) {
			return $deny('ip_not_allowed', 'This address is not allowed to use the sync API.', 403);
		}

		// The secret is stored encrypted with a key derived from AUTH_KEY (see Token_Store),
		// so a database dump alone cannot sign requests.
		$key = Token_Store::secret($row);
		if ($key === null) {
			return $deny('token_unreadable', 'The token secret cannot be decrypted on this site (AUTH_KEY changed?). Create a new token.', 401);
		}
		$path  = Signer::normalise_path(\rest_get_url_prefix() . $route);
		$body  = $body_override !== null ? $body_override : (string) $request->get_body();
		$check = Signer::verify($key, $headers, $method, $path, $body);
		if ($check !== true) {
			$status = ($check === 'bad_signature') ? 401 : 400;
			return $deny($check, 'Request signature check failed (' . $check . ').', $status);
		}
		if (!Nonce_Store::claim($token_id, (string) $headers['x-awx-nonce'])) {
			return $deny('replayed_nonce', 'This request was already processed.', 409);
		}
		$scopes = Token_Store::scopes($row);
		if (!\in_array($required_scope, $scopes, true) && !\in_array('*', $scopes, true)) {
			return $deny('scope_denied', 'Token lacks the ' . $required_scope . ' scope.', 403);
		}
		Token_Store::touch($token_id, $ip);
		return array('token_id' => $token_id, 'scopes' => $scopes, 'ip' => $ip);
	}

	public static function client_ip(): string {
		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return (string) \apply_filters('awx_sync_client_ip', $ip);
	}

	private static function ip_allowlist(): array {
		if (!\defined('AWX_SYNC_IP_ALLOWLIST') || !AWX_SYNC_IP_ALLOWLIST) {
			return array();
		}
		return \array_values(\array_filter(\array_map('trim', \explode(',', (string) AWX_SYNC_IP_ALLOWLIST))));
	}

	private static function allow_insecure(): bool {
		return \defined('AWX_SYNC_ALLOW_HTTP') && AWX_SYNC_ALLOW_HTTP;
	}
}
