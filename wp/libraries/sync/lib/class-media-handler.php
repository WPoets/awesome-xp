<?php
/**
 * Prod-side media receiver for both transports.
 *
 * push: Dev uploads the file in one multipart request.
 * pull: Prod downloads the file from a signed Dev URL whose scheme and host
 *       must equal AWX_SYNC_SOURCE_URL; redirects are not followed.
 * Either way the file's SHA-256 and MIME type are checked before an
 * attachment is created or replaced, and image sub-sizes are generated here.
 */
namespace aw2\sync;

class Media_Handler {

	/** extension => mime, the only types accepted. SVG only through the awx_sync_sanitize_svg filter. */
	public static function allowed_mimes(): array {
		$list = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			'pdf'  => 'application/pdf',
			'mp4'  => 'video/mp4',
		);
		if (\has_filter('awx_sync_sanitize_svg')) {
			$list['svg'] = 'image/svg+xml';
		}
		return (array) \apply_filters('awx_sync_allowed_mimes', $list);
	}

	private static function includes(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Push transport.
	 *
	 * @param array $file  one entry of $_FILES
	 * @param array $meta  uuid, sha256, mime, file (basename), alt, title
	 */
	public function receive_push(array $file, array $meta, string $run_id, string $step_id): array {
		$replay = Step_Store::replay($run_id, $step_id);
		if ($replay !== null) {
			return $replay + array('replayed' => true);
		}
		if (empty($file['tmp_name']) || !\is_uploaded_file($file['tmp_name'])) {
			$code = isset($file['error']) ? (int) $file['error'] : -1;
			if (\in_array($code, array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
				throw new Sync_Http_Exception('file_too_large', 'The file exceeds the upload limit of this site.', 413, array('upload_max_bytes' => \wp_max_upload_size()));
			}
			throw new Sync_Http_Exception('no_file', 'No file was received.', 400, array('php_error' => $code));
		}
		$tmp = (string) $file['tmp_name'];
		$result = $this->store($tmp, $meta, true);
		Step_Store::record($run_id, $step_id, 'media', (string) ($meta['uuid'] ?? ''), 'done', $result);
		return $result;
	}

	/**
	 * Pull transport.
	 */
	public function receive_pull(string $fetch_url, array $meta, string $run_id, string $step_id): array {
		$replay = Step_Store::replay($run_id, $step_id);
		if ($replay !== null) {
			return $replay + array('replayed' => true);
		}
		if (Receiver::source_url() === null) {
			throw new Sync_Http_Exception('pull_not_configured', 'AWX_SYNC_SOURCE_URL is not set on this site.', 422);
		}
		if (!Receiver::url_is_source($fetch_url)) {
			throw new Sync_Http_Exception('fetch_url_not_source', 'The fetch URL does not belong to the configured source site.', 422);
		}
		self::includes();
		$args_filter = function ($args) {
			return Receiver::source_request_args($args);
		};
		\add_filter('http_request_args', $args_filter, 99);
		try {
			$tmp = \download_url($fetch_url, 300);
		} finally {
			\remove_filter('http_request_args', $args_filter, 99);
		}
		if (\is_wp_error($tmp)) {
			$data = $tmp->get_error_data();
			$status = \is_array($data) && isset($data['code']) ? (int) $data['code'] : 0;
			$retryable = $status === 0 || $status >= 500 || $status === 408 || $status === 429 || $status === 410 /* expired signed url */;
			throw new Sync_Http_Exception('fetch_failed', 'Could not fetch the file from the source: ' . $tmp->get_error_message(), 502, array('source_status' => $status), $retryable);
		}
		try {
			$result = $this->store($tmp, $meta, false);
		} finally {
			if (\file_exists($tmp)) {
				@\unlink($tmp);
			}
		}
		Step_Store::record($run_id, $step_id, 'media', (string) ($meta['uuid'] ?? ''), 'done', $result);
		return $result;
	}

	/**
	 * Verify a received file and create or replace the attachment that carries $meta['uuid'].
	 */
	public function store(string $tmp, array $meta, bool $is_upload): array {
		self::includes();
		foreach (array('uuid', 'sha256', 'file') as $k) {
			if (empty($meta[$k])) {
				throw new Sync_Http_Exception('invalid_payload', "Missing media field {$k}.", 400);
			}
		}
		$uuid = (string) $meta['uuid'];
		if (!\preg_match('/^' . Transformer::UUID_RE . '$/', $uuid)) {
			throw new Sync_Http_Exception('invalid_payload', 'Malformed media uuid.', 400);
		}
		$name = \sanitize_file_name(\basename((string) $meta['file']));
		if ($name === '') {
			throw new Sync_Http_Exception('invalid_payload', 'Malformed file name.', 400);
		}

		$hash = \hash_file('sha256', $tmp);
		if (!\hash_equals(\strtolower((string) $meta['sha256']), (string) $hash)) {
			throw new Sync_Http_Exception('hash_mismatch', 'The received file does not match the declared SHA-256.', 422);
		}

		$allowed = self::allowed_mimes();
		$check = \wp_check_filetype_and_ext($tmp, $name, $allowed);
		$ext  = isset($check['ext']) ? (string) $check['ext'] : '';
		$mime = isset($check['type']) ? (string) $check['type'] : '';
		if ($ext === '' || $mime === '' || !isset($allowed[$ext]) || $allowed[$ext] !== $mime) {
			throw new Sync_Http_Exception('mime_blocked', 'The file type is not allowed by the sync MIME allowlist.', 422, array('detected' => $check));
		}
		if ($mime === 'image/svg+xml') {
			$clean = \apply_filters('awx_sync_sanitize_svg', null, (string) \file_get_contents($tmp));
			if (!\is_string($clean) || $clean === '') {
				throw new Sync_Http_Exception('mime_blocked', 'SVG was rejected by the sanitiser.', 422);
			}
			\file_put_contents($tmp, $clean);
		}

		$existing = (new WP_Site_Resolver(false))->media_id_by_uuid($uuid);
		$bytes    = (int) \filesize($tmp);

		return Service_User::run(function () use ($tmp, $name, $mime, $uuid, $meta, $existing, $hash, $bytes, $is_upload) {
			$file_array = array('name' => $name, 'tmp_name' => $tmp, 'type' => $mime, 'error' => 0, 'size' => $bytes);
			$overrides  = array('test_form' => false, 'test_size' => false, 'mimes' => self::allowed_mimes(), 'action' => 'awx_sync');
			$moved = $is_upload ? \wp_handle_upload($file_array, $overrides) : \wp_handle_sideload($file_array, $overrides);
			if (!\is_array($moved) || isset($moved['error'])) {
				throw new Sync_Http_Exception('store_failed', 'Could not store the file: ' . (\is_array($moved) ? $moved['error'] : 'unknown error'), 500, array(), true);
			}
			$path = $moved['file'];

			if ($existing !== null) {
				$old_file = (string) \get_attached_file($existing);
				$old_meta = \wp_get_attachment_metadata($existing);
				\update_attached_file($existing, $path);
				\wp_update_post(array('ID' => $existing, 'post_mime_type' => $mime, 'post_title' => isset($meta['title']) ? (string) $meta['title'] : \get_the_title($existing)));
				\wp_update_attachment_metadata($existing, \wp_generate_attachment_metadata($existing, $path));
				if ($old_file !== '' && $old_file !== $path && \function_exists('wp_delete_attachment_files')) {
					\wp_delete_attachment_files($existing, \is_array($old_meta) ? $old_meta : array(), array(), $old_file);
				}
				$id = $existing;
			} else {
				$id = \wp_insert_attachment(array(
					'post_mime_type' => $mime,
					'post_title'     => isset($meta['title']) && $meta['title'] !== '' ? (string) $meta['title'] : \preg_replace('/\.[^.]+$/', '', $name),
					'post_content'   => '',
					'post_status'    => 'inherit',
					'guid'           => $moved['url'],
				), $path, 0, true);
				if (\is_wp_error($id)) {
					@\unlink($path);
					throw new Sync_Http_Exception('store_failed', $id->get_error_message(), 500, array(), true);
				}
				\wp_update_attachment_metadata($id, \wp_generate_attachment_metadata($id, $path));
			}
			\update_post_meta($id, Identity::META_UUID, $uuid);
			\update_post_meta($id, Identity::META_FILE_HASH, $hash);
			if (isset($meta['alt'])) {
				\update_post_meta($id, '_wp_attachment_image_alt', \sanitize_text_field((string) $meta['alt']));
			}
			\do_action('awx_sync_after_write', (int) $id, 'media');
			return array('id' => (int) $id, 'created' => $existing === null, 'url' => \wp_get_attachment_url($id), 'bytes' => $bytes, 'mime' => $mime);
		});
	}
}
