<?php
/**
 * Dev-side (source) routes used by pull mode:
 *
 *   GET /wp-json/awx-sync/v1/export/probe?exp=&sig=            answers "awx-sync-probe-ok"
 *   GET /wp-json/awx-sync/v1/export/media/{uuid}?run=&exp=&sig= streams one original file
 *
 * No login is needed: the URL carries an HMAC made with a Dev-only secret and
 * an expiry, and only attachments named in an open run can be served.
 */
namespace aw2\sync;

class Export_Routes {

	const TTL_MEDIA = 1800;
	const TTL_PROBE = 900;

	public static function init(): void {
		if (!is_source()) {
			return;
		}
		\add_action('rest_api_init', array(__CLASS__, 'register'));
	}

	public static function register(): void {
		\register_rest_route(Prod_Routes::NS, '/export/probe', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => array(__CLASS__, 'serve_probe'),
		));
		\register_rest_route(Prod_Routes::NS, '/export/media/(?P<uuid>[0-9a-fA-F-]{36})', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => array(__CLASS__, 'serve_media'),
		));
	}

	public static function secret(): string {
		if (\defined('AWX_SYNC_EXPORT_SECRET') && AWX_SYNC_EXPORT_SECRET) {
			return (string) AWX_SYNC_EXPORT_SECRET;
		}
		return \hash('sha256', (\defined('AUTH_KEY') ? AUTH_KEY : '') . '|' . (\defined('AUTH_SALT') ? AUTH_SALT : '') . '|awx-sync-export');
	}

	private static function sig(string $what): string {
		return \hash_hmac('sha256', $what, self::secret());
	}

	public static function probe_url(int $ttl = self::TTL_PROBE): string {
		$exp = \time() + $ttl;
		return \add_query_arg(array('exp' => $exp, 'sig' => self::sig("probe|{$exp}")), \rest_url(Prod_Routes::NS . '/export/probe'));
	}

	public static function signed_media_url(string $uuid, string $run_id, int $ttl = self::TTL_MEDIA): string {
		$exp = \time() + $ttl;
		return \add_query_arg(array('run' => $run_id, 'exp' => $exp, 'sig' => self::sig("media|{$uuid}|{$run_id}|{$exp}")), \rest_url(Prod_Routes::NS . '/export/media/' . $uuid));
	}

	public static function serve_probe(\WP_REST_Request $r) {
		$exp = (int) $r->get_param('exp');
		$sig = (string) $r->get_param('sig');
		if ($exp < \time()) {
			return new \WP_Error('awx_sync_expired', 'Probe link expired.', array('status' => 410));
		}
		if (!\hash_equals(self::sig("probe|{$exp}"), $sig)) {
			return new \WP_Error('awx_sync_bad_signature', 'Bad probe signature.', array('status' => 403));
		}
		$res = new \WP_REST_Response('awx-sync-probe-ok', 200);
		$res->header('Content-Type', 'text/plain');
		// Return the raw string: WP would JSON-encode it (quotes) otherwise.
		\add_filter('rest_pre_serve_request', function ($served, $result) {
			if ($result instanceof \WP_REST_Response && $result->get_data() === 'awx-sync-probe-ok') {
				echo 'awx-sync-probe-ok';
				return true;
			}
			return $served;
		}, 10, 2);
		return $res;
	}

	public static function serve_media(\WP_REST_Request $r) {
		$uuid = (string) $r->get_param('uuid');
		$run  = (string) $r->get_param('run');
		$exp  = (int) $r->get_param('exp');
		$sig  = (string) $r->get_param('sig');
		if ($exp < \time()) {
			return new \WP_Error('awx_sync_expired', 'Download link expired.', array('status' => 410));
		}
		if (!\preg_match('/^' . Transformer::UUID_RE . '$/', $run) || !\hash_equals(self::sig("media|{$uuid}|{$run}|{$exp}"), $sig)) {
			return new \WP_Error('awx_sync_bad_signature', 'Bad download signature.', array('status' => 403));
		}
		$run_row = Manifest_Store::get($run);
		if (!$run_row || !\in_array($run_row['status'], Manifest_Store::OPEN_STATUSES, true)) {
			return new \WP_Error('awx_sync_run_closed', 'This run is not open.', array('status' => 403));
		}
		if (empty($run_row['manifest']['media'][$uuid])) {
			return new \WP_Error('awx_sync_not_in_run', 'This file is not part of the run.', array('status' => 403));
		}
		$id = (new WP_Site_Resolver(false))->media_id_by_uuid($uuid);
		if ($id === null) {
			return new \WP_Error('awx_sync_missing', 'Attachment not found.', array('status' => 404));
		}
		$path = self::original_path($id);
		if ($path === null) {
			return new \WP_Error('awx_sync_missing', 'File not found.', array('status' => 404));
		}
		\nocache_headers();
		\header('Content-Type: ' . (\get_post_mime_type($id) ?: 'application/octet-stream'));
		\header('Content-Length: ' . \filesize($path));
		\header('Content-Disposition: attachment; filename="' . \basename($path) . '"');
		\header('X-AWX-Sha256: ' . \hash_file('sha256', $path));
		while (\ob_get_level()) {
			\ob_end_clean();
		}
		\readfile($path);
		exit;
	}

	/** The original file of an attachment (not the -scaled variant), or null. */
	public static function original_path(int $attachment_id): ?string {
		$path = '';
		if (\function_exists('wp_get_original_image_path') && \wp_attachment_is_image($attachment_id)) {
			$path = (string) \wp_get_original_image_path($attachment_id);
		}
		if ($path === '' || !\file_exists($path)) {
			$path = (string) \get_attached_file($attachment_id);
		}
		return ($path !== '' && \file_exists($path)) ? $path : null;
	}
}
