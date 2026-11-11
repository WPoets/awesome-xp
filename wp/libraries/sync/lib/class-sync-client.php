<?php
/**
 * Dev-side signing HTTP client for the target's /awx-sync/v1 routes.
 *
 * Retries only errors the target marks retryable (plus transport failures,
 * 408, 429 and 5xx), three attempts with backoff.
 */
namespace aw2\sync;

class Sync_Client {

	const ATTEMPTS = 3;

	/** @var string */
	private $base;
	/** @var string */
	private $token_id;
	/** @var string */
	private $secret;
	/** @var string */
	private $rest_prefix;
	/** @var callable|null test hook: fn(string $url, array $args): array|WP_Error */
	public $transport = null;
	/** @var callable|null test hook replacing sleep() */
	public $sleeper = null;

	public function __construct(string $target_url, string $token, string $rest_prefix = 'wp-json') {
		$parts = Signer::split_token($token);
		if ($parts === null) {
			throw new Sync_Exception('token_malformed', 'AWX_SYNC_TOKEN must look like <id>.<secret>.');
		}
		$this->base        = \untrailingslashit($target_url);
		$this->token_id    = $parts['token_id'];
		$this->secret      = $parts['secret'];
		$this->rest_prefix = \trim($rest_prefix, '/');
	}

	/** Build from wp-config constants (AWX_SYNC_TARGET_URL, AWX_SYNC_TOKEN, optional AWX_SYNC_TARGET_REST_PREFIX). */
	public static function from_config(): Sync_Client {
		$url   = \defined('AWX_SYNC_TARGET_URL') ? (string) AWX_SYNC_TARGET_URL : (string) \getenv('AWX_SYNC_TARGET_URL');
		$token = \defined('AWX_SYNC_TOKEN') ? (string) AWX_SYNC_TOKEN : (string) \getenv('AWX_SYNC_TOKEN');
		if ($url === '' || $token === '') {
			throw new Sync_Exception('not_configured', 'Set AWX_SYNC_TARGET_URL and AWX_SYNC_TOKEN in wp-config.php (or the environment) on this source site.');
		}
		$prefix = \defined('AWX_SYNC_TARGET_REST_PREFIX') ? (string) AWX_SYNC_TARGET_REST_PREFIX : 'wp-json';
		return new self($url, $token, $prefix);
	}

	public function target_url(): string {
		return $this->base;
	}

	public function token_id(): string {
		return $this->token_id;
	}

	private function path(string $route): string {
		return '/' . $this->rest_prefix . '/' . Prod_Routes::NS . '/' . \ltrim($route, '/');
	}

	/** POST JSON. */
	public function post(string $route, array $body) {
		$json = \wp_json_encode($body);
		$path = $this->path($route);
		return $this->send('POST', $path, $json, function () use ($path, $json) {
			return array(
				'method'  => 'POST',
				'timeout' => 120,
				'headers' => Signer::sign($this->token_id, $this->secret, 'POST', $path, $json) + array('Content-Type' => 'application/json; charset=utf-8', 'Accept' => 'application/json'),
				'body'    => $json,
			);
		});
	}

	public function get(string $route) {
		$path = $this->path($route);
		return $this->send('GET', $path, '', function () use ($path) {
			return array(
				'method'  => 'GET',
				'timeout' => 60,
				'headers' => Signer::sign($this->token_id, $this->secret, 'GET', $path, '') + array('Accept' => 'application/json'),
			);
		});
	}

	/** POST multipart: a JSON `meta` field (which is what gets signed) plus one `file`. */
	public function post_file(string $route, array $meta, string $file_path, string $filename, string $mime) {
		if (!\is_readable($file_path)) {
			return new \WP_Error('awx_sync_file_unreadable', "Cannot read {$file_path}.", array('status' => 0, 'retryable' => false));
		}
		$json     = \wp_json_encode($meta);
		$path     = $this->path($route);
		$boundary = 'awxsync' . \bin2hex(\random_bytes(12));
		$body  = "--{$boundary}\r\nContent-Disposition: form-data; name=\"meta\"\r\nContent-Type: application/json; charset=utf-8\r\n\r\n{$json}\r\n";
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . \str_replace('"', '', $filename) . "\"\r\nContent-Type: {$mime}\r\n\r\n";
		$body .= (string) \file_get_contents($file_path);
		$body .= "\r\n--{$boundary}--\r\n";
		return $this->send('POST', $path, $json, function () use ($path, $json, $body, $boundary) {
			return array(
				'method'  => 'POST',
				'timeout' => 600,
				'headers' => Signer::sign($this->token_id, $this->secret, 'POST', $path, $json) + array('Content-Type' => 'multipart/form-data; boundary=' . $boundary, 'Accept' => 'application/json'),
				'body'    => $body,
			);
		});
	}

	/* ---- transport with retries ---- */

	private function send(string $method, string $path, string $signed_body, callable $build_args) {
		$url  = $this->base . $path;
		$last = null;
		for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
			$args = $build_args(); // fresh timestamp and nonce every attempt
			$args['sslverify'] = !(\defined('AWX_SYNC_INSECURE_SSL') && AWX_SYNC_INSECURE_SSL);
			$raw = $this->transport ? \call_user_func($this->transport, $url, $args) : \wp_safe_remote_request($url, $args);
			$res = $this->normalise($raw, $method . ' ' . $path);
			if (!\is_wp_error($res)) {
				return $res;
			}
			$last = $res;
			$data = $res->get_error_data();
			$retryable = \is_array($data) && !empty($data['retryable']);
			if (!$retryable || $attempt === self::ATTEMPTS) {
				break;
			}
			$delay = $attempt === 1 ? 1 : 3;
			if ($this->sleeper) {
				\call_user_func($this->sleeper, $delay);
			} else {
				\sleep($delay);
			}
		}
		return $last;
	}

	/** @return array|\WP_Error decoded JSON on 2xx; WP_Error carrying status/retryable/details otherwise */
	private function normalise($raw, string $what) {
		if (\is_wp_error($raw)) {
			return new \WP_Error('awx_sync_transport', "Could not reach the target ({$what}): " . $raw->get_error_message(), array('status' => 0, 'retryable' => true, 'details' => array()));
		}
		$status = (int) \wp_remote_retrieve_response_code($raw);
		$body   = (string) \wp_remote_retrieve_body($raw);
		$json   = \json_decode($body, true);
		if ($status >= 200 && $status < 300) {
			return \is_array($json) ? $json : array('raw' => $body);
		}
		$code      = \is_array($json) && !empty($json['code']) ? (string) $json['code'] : 'http_' . $status;
		$message   = \is_array($json) && !empty($json['message']) ? (string) $json['message'] : "Target answered HTTP {$status} for {$what}.";
		$details   = \is_array($json) && isset($json['data']['details']) ? $json['data']['details'] : array();
		$retryable = \is_array($json) && isset($json['data']['retryable']) ? (bool) $json['data']['retryable'] : ($status >= 500 || $status === 408 || $status === 429);
		if ($status === 401 || $status === 403) {
			$retryable = false;
		}
		return new \WP_Error($code, $message, array('status' => $status, 'retryable' => $retryable, 'details' => $details));
	}
}
