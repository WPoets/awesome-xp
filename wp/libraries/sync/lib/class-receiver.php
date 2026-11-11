<?php
/**
 * Prod-side business logic behind the REST routes: handshake, plan, and the
 * idempotent writes of reusable blocks and pages. Media lives in Media_Handler.
 */
namespace aw2\sync;

class Sync_Http_Exception extends Sync_Exception {
	/** @var int */
	public $status;
	/** @var bool */
	public $retryable;

	public function __construct(string $code_slug, string $message, int $status = 400, array $details = array(), bool $retryable = false) {
		parent::__construct($code_slug, $message, $details);
		$this->status    = $status;
		$this->retryable = $retryable;
	}
}

class Receiver {

	const STATUSES = array('publish', 'draft', 'pending', 'private', 'future');

	/**
	 * Target-side markers. _awx_content_hash (Identity::META_CONTENT_HASH) is the
	 * SHA-256 of the content THIS site saved at the last sync, so a later edit here
	 * is detectable. _awx_source_hash is the source's hash at that sync, so an
	 * unchanged source can be skipped.
	 */
	const META_SOURCE_HASH = '_awx_source_hash';

	/** @var WP_Site_Resolver */
	private $site;
	/** @var Transformer */
	private $transformer;

	public function __construct(?Site_Resolver $site = null, ?Transformer $transformer = null) {
		$this->site        = $site ?: new WP_Site_Resolver(false);
		$this->transformer = $transformer ?: new Transformer($this->site, Reference_Registry::instance());
	}

	/* ================================================================== */
	/* Configuration                                                       */
	/* ================================================================== */

	public static function allowed_post_types(): array {
		$types = \get_post_types(array('public' => true), 'names');
		unset($types['attachment']);
		return \array_values((array) \apply_filters('awx_sync_allowed_post_types', \array_values($types)));
	}

	public static function push_allowed(): bool {
		return !\defined('AWX_SYNC_ALLOW_PUSH') || (bool) AWX_SYNC_ALLOW_PUSH;
	}

	public static function source_url(): ?string {
		if (!\defined('AWX_SYNC_SOURCE_URL') || !AWX_SYNC_SOURCE_URL) {
			return null;
		}
		return \untrailingslashit((string) AWX_SYNC_SOURCE_URL);
	}

	/** Scheme and host of $url must equal the configured source. */
	public static function url_is_source(string $url): bool {
		$src = self::source_url();
		if ($src === null) {
			return false;
		}
		$a = \parse_url($src);
		$b = \parse_url($url);
		if (!$a || !$b || empty($a['host']) || empty($b['host'])) {
			return false;
		}
		$port_a = isset($a['port']) ? (int) $a['port'] : null;
		$port_b = isset($b['port']) ? (int) $b['port'] : null;
		return \strtolower($a['scheme'] ?? 'https') === \strtolower($b['scheme'] ?? '')
			&& \strtolower($a['host']) === \strtolower($b['host'])
			&& $port_a === $port_b;
	}

	/** Extra request args for fetching from the source (basic auth, no redirects). */
	public static function source_request_args(array $args = array()): array {
		$args['redirection'] = 0;
		$args['timeout']     = isset($args['timeout']) ? $args['timeout'] : 300;
		if (\defined('AWX_SYNC_SOURCE_AUTH') && AWX_SYNC_SOURCE_AUTH) {
			$args['headers'] = isset($args['headers']) ? $args['headers'] : array();
			$args['headers']['Authorization'] = 'Basic ' . \base64_encode((string) AWX_SYNC_SOURCE_AUTH);
		}
		return $args;
	}

	/* ================================================================== */
	/* Handshake                                                           */
	/* ================================================================== */

	public function handshake(array $params): array {
		$pull = array('configured' => self::source_url() !== null, 'probe_ok' => null, 'error' => null);
		if ($pull['configured'] && !empty($params['probe_url'])) {
			$probe = (string) $params['probe_url'];
			if (!self::url_is_source($probe)) {
				$pull['probe_ok'] = false;
				$pull['error']    = 'probe_url_not_source';
			} else {
				$res = \wp_safe_remote_get($probe, self::source_request_args(array('timeout' => 15)));
				if (\is_wp_error($res)) {
					$pull['probe_ok'] = false;
					$pull['error']    = $res->get_error_message();
				} elseif ((int) \wp_remote_retrieve_response_code($res) !== 200 || \trim((string) \wp_remote_retrieve_body($res)) !== 'awx-sync-probe-ok') {
					$pull['probe_ok'] = false;
					$pull['error']    = 'probe_http_' . \wp_remote_retrieve_response_code($res);
				} else {
					$pull['probe_ok'] = true;
				}
			}
		}
		$upload_max = (int) \wp_max_upload_size();
		return array(
			'plugin_version'  => \defined('AWE_VERSION') ? AWE_VERSION : null,
			'sync_version'    => AWX_SYNC_VERSION,
			'protocol'        => AWX_SYNC_PROTOCOL,
			'registry_hash'   => Reference_Registry::instance()->hash(),
			'post_types'      => self::allowed_post_types(),
			'server_time'     => \time(),
			'home_url'        => \untrailingslashit(\home_url()),
			'theme'           => \get_stylesheet(),
			'limits'          => array('upload_max_bytes' => $upload_max),
			'push_allowed'    => self::push_allowed(),
			'pull'            => $pull,
			'unfiltered_html' => Service_User::has_unfiltered_html(),
			'mime_allowlist'  => Media_Handler::allowed_mimes(),
		);
	}

	/* ================================================================== */
	/* Plan                                                                */
	/* ================================================================== */

	public function plan(array $m): array {
		global $wpdb;
		$out = array('page' => null, 'media' => array(), 'blocks' => array(), 'posts' => array(), 'terms' => array(), 'dgb_blocks' => array(), 'modules' => array(), 'post_types' => array(), 'warnings' => array());

		// Page
		$page = isset($m['page']) && \is_array($m['page']) ? $m['page'] : array();
		$allowed = self::allowed_post_types();
		$p = array('status' => 'missing', 'type_allowed' => !empty($page['type']) && \in_array($page['type'], $allowed, true));
		if (!empty($page['uuid'])) {
			$id = $this->site->post_id_by_uuid((string) $page['uuid']);
			if ($id !== null) {
				$post = \get_post($id);
				$prod_hash = \hash('sha256', (string) $post->post_content);
				$last = (string) \get_post_meta($id, Identity::META_CONTENT_HASH, true);
				$src  = (string) \get_post_meta($id, self::META_SOURCE_HASH, true);
				$p = \array_merge($p, array(
					'status'           => 'exists',
					'prod_id'          => $id,
					'prod_type'        => $post->post_type,
					'prod_status'      => $post->post_status,
					'title'            => $post->post_title,
					'permalink'        => \get_permalink($id),
					'prod_hash'        => $prod_hash,
					'last_synced_hash' => $last !== '' ? $last : null,
					'edited_on_prod'   => $last !== '' ? ($prod_hash !== $last) : null,
					'unchanged'        => !empty($page['hash']) && $src !== '' && \hash_equals($src, (string) $page['hash']) && $last !== '' && $prod_hash === $last,
				));
				if (!empty($page['type']) && $post->post_type !== $page['type']) {
					$out['warnings'][] = array('code' => 'post_type_differs', 'message' => "The target post #{$id} is a {$post->post_type}, the source is a {$page['type']}; the type is not changed.");
				}
			}
		}
		$out['page'] = $p;

		// Media
		foreach ((array) ($m['media'] ?? array()) as $uuid => $info) {
			$id = $this->site->media_id_by_uuid((string) $uuid);
			if ($id === null) {
				$out['media'][$uuid] = array('status' => 'missing');
				continue;
			}
			$hash = (string) \get_post_meta($id, Identity::META_FILE_HASH, true);
			if ($hash === '' && \class_exists(__NAMESPACE__ . '\\Identity')) {
				$hash = (string) Identity::file_hash($id);
			}
			$same = !empty($info['sha256']) && $hash !== '' && \hash_equals($hash, (string) $info['sha256']);
			$out['media'][$uuid] = array('status' => $same ? 'exists_unchanged' : 'exists_changed', 'prod_id' => $id);
		}

		// Reusable blocks
		foreach ((array) ($m['blocks'] ?? array()) as $b) {
			if (empty($b['uuid'])) {
				continue;
			}
			$id = $this->site->block_id_by_uuid((string) $b['uuid']);
			if ($id === null) {
				$out['blocks'][$b['uuid']] = array('status' => 'missing', 'affected' => array());
				continue;
			}
			$src  = (string) \get_post_meta($id, self::META_SOURCE_HASH, true);
			$same = !empty($b['hash']) && $src !== '' && \hash_equals($src, (string) $b['hash']);
			$entry = array('status' => $same ? 'exists_unchanged' : 'exists_changed', 'prod_id' => $id, 'affected' => array());
			if (!$same) {
				$like = '%' . $wpdb->esc_like('"ref":' . $id) . '%';
				$types = "'" . \implode("','", \array_map('esc_sql', $allowed)) . "'";
				$rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_type IN ({$types}) AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s LIMIT 50", $like), ARRAY_A);
				foreach ((array) $rows as $r) {
					if (!empty($page['uuid']) && isset($p['prod_id']) && (int) $r['ID'] === (int) $p['prod_id']) {
						continue;
					}
					$entry['affected'][] = array('id' => (int) $r['ID'], 'title' => $r['post_title'], 'type' => $r['post_type'], 'permalink' => \get_permalink((int) $r['ID']));
				}
			}
			$out['blocks'][$b['uuid']] = $entry;
		}

		// Linked posts
		foreach ((array) ($m['posts'] ?? array()) as $uuid => $info) {
			$key = \is_array($info) && !empty($info['uuid']) ? $info['uuid'] : (string) $uuid;
			$id  = $this->site->post_id_by_uuid((string) $key);
			$out['posts'][$key] = array('status' => $id !== null ? 'exists' : 'missing', 'prod_id' => $id);
			if ($id === null) {
				$out['warnings'][] = array('code' => 'linked_post_missing', 'message' => 'A linked post (' . ($info['slug'] ?? $key) . ') has no counterpart on the target; the link will point at the same path on the target domain.');
			}
		}

		// Terms
		foreach ((array) ($m['terms'] ?? array()) as $key => $t) {
			$exists = !empty($t['taxonomy']) && !empty($t['slug']) ? $this->site->term_id_by_slug((string) $t['taxonomy'], (string) $t['slug']) : null;
			$tax_ok = !empty($t['taxonomy']) && \taxonomy_exists((string) $t['taxonomy']);
			$out['terms'][$key] = array('status' => $exists !== null ? 'exists' : ($tax_ok ? 'will_create' : 'taxonomy_missing'), 'prod_id' => $exists);
			if (!$tax_ok) {
				$out['warnings'][] = array('code' => 'taxonomy_missing', 'message' => "Taxonomy " . ($t['taxonomy'] ?? '?') . " does not exist on the target; term {$key} cannot be created.");
			}
		}

		// dgb block definitions
		foreach ((array) ($m['dgb_blocks'] ?? array()) as $slug => $version) {
			$prod_version = $this->site->dgb_block_version((string) $slug);
			if ($prod_version === null) {
				$out['dgb_blocks'][$slug] = array('status' => 'missing');
			} elseif ($version !== null && (string) $version !== (string) $prod_version) {
				$out['dgb_blocks'][$slug] = array('status' => 'version_mismatch', 'version' => $prod_version);
			} else {
				$out['dgb_blocks'][$slug] = array('status' => 'ok', 'version' => $prod_version);
			}
		}

		// Code modules and post types: warn only
		foreach ((array) ($m['modules'] ?? array()) as $slug) {
			$exists = (bool) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s LIMIT 1", \defined('AWESOME_CORE_POST_TYPE') ? AWESOME_CORE_POST_TYPE : 'awesome_core', $slug));
			$out['modules'][$slug] = array('status' => $exists ? 'exists' : 'missing');
			if (!$exists) {
				$out['warnings'][] = array('code' => 'module_missing', 'message' => "Code module {$slug} is not on the target; the shortcode will render nothing until it is deployed.");
			}
		}
		foreach ((array) ($m['post_types'] ?? array()) as $pt) {
			$exists = \post_type_exists((string) $pt);
			$out['post_types'][$pt] = array('status' => $exists ? 'exists' : 'missing');
			if (!$exists) {
				$out['warnings'][] = array('code' => 'post_type_missing', 'message' => "Post type {$pt} queried by a block does not exist on the target.");
			}
		}

		$out['unfiltered_html'] = Service_User::has_unfiltered_html();
		return $out;
	}

	/* ================================================================== */
	/* Reusable block                                                      */
	/* ================================================================== */

	public function write_block(array $payload, string $run_id, string $step_id): array {
		$replay = Step_Store::replay($run_id, $step_id);
		if ($replay !== null) {
			return $replay + array('replayed' => true);
		}
		foreach (array('uuid', 'content', 'hash') as $k) {
			if (!isset($payload[$k])) {
				throw new Sync_Http_Exception('invalid_payload', "Missing field {$k}.", 400);
			}
		}
		$uuid = (string) $payload['uuid'];
		$res  = $this->transformer->resolve((string) $payload['content'], (array) ($payload['terms'] ?? array()));
		$this->assert_resolved($res, (array) ($payload['source_hosts'] ?? array()));

		$result = Service_User::run(function () use ($payload, $uuid, $res) {
			$existing = $this->site->block_id_by_uuid($uuid);
			$postarr = array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => $res->content,
			);
			if ($existing !== null) {
				$postarr['ID'] = $existing;
				$id = \wp_update_post(\wp_slash($postarr), true);
			} else {
				$postarr['post_title'] = isset($payload['title']) ? (string) $payload['title'] : 'Synced block';
				$postarr['post_name']  = isset($payload['slug']) ? (string) $payload['slug'] : '';
				$id = \wp_insert_post(\wp_slash($postarr), true);
			}
			if (\is_wp_error($id)) {
				throw new Sync_Http_Exception('write_failed', $id->get_error_message(), 500, array(), true);
			}
			\update_post_meta($id, Identity::META_UUID, $uuid);
			self::stamp_hashes((int) $id, (string) $payload['hash']);
			if (!empty($payload['pattern_slug']) && '' === (string) \get_post_meta($id, '_awx_pattern_slug', true)) {
				\update_post_meta($id, '_awx_pattern_slug', (string) $payload['pattern_slug']);
			}
			\do_action('awx_sync_after_write', (int) $id, 'block');
			return array('id' => (int) $id, 'created' => $existing === null, 'revision_id' => self::latest_revision((int) $id), 'warnings' => $res->warnings);
		});
		Step_Store::record($run_id, $step_id, 'block', $uuid, 'done', $result);
		return $result;
	}

	/* ================================================================== */
	/* Page                                                                */
	/* ================================================================== */

	public function write_page(array $payload, string $run_id, string $step_id): array {
		$replay = Step_Store::replay($run_id, $step_id);
		if ($replay !== null) {
			return $replay + array('replayed' => true);
		}
		foreach (array('uuid', 'type', 'content', 'hash', 'status') as $k) {
			if (!isset($payload[$k])) {
				throw new Sync_Http_Exception('invalid_payload', "Missing field {$k}.", 400);
			}
		}
		$uuid = (string) $payload['uuid'];
		$type = (string) $payload['type'];
		if (!\in_array($type, self::allowed_post_types(), true)) {
			throw new Sync_Http_Exception('post_type_not_allowed', "Post type {$type} is not accepted by this site.", 422);
		}
		$status = (string) $payload['status'];
		if (!\in_array($status, self::STATUSES, true)) {
			throw new Sync_Http_Exception('status_not_allowed', "Status {$status} cannot be synced.", 422);
		}

		$existing = $this->site->post_id_by_uuid($uuid);
		if ($existing !== null) {
			$post = \get_post($existing);
			$prod_hash = \hash('sha256', (string) $post->post_content);
			if (empty($payload['expected_prod_hash']) || !\hash_equals($prod_hash, (string) $payload['expected_prod_hash'])) {
				throw new Sync_Http_Exception('conflict', 'The target page changed since the plan was made. Re-plan and confirm again.', 409, array('prod_hash' => $prod_hash));
			}
			$last = (string) \get_post_meta($existing, Identity::META_CONTENT_HASH, true);
			$edited = ($last === '') || ($prod_hash !== $last);
			if ($edited && empty($payload['overwrite'])) {
				throw new Sync_Http_Exception('edited_on_prod', 'The target page was edited since the last sync. Confirm overwrite to replace it.', 409, array('prod_hash' => $prod_hash));
			}
		}

		$terms_catalog = (array) ($payload['terms_catalog'] ?? array());
		$res = $this->transformer->resolve((string) $payload['content'], $terms_catalog);
		$this->assert_resolved($res, (array) ($payload['source_hosts'] ?? array()));

		// Featured image and parent resolve before anything is written.
		$thumb = null;
		if (!empty($payload['featured_media'])) {
			$thumb = $this->site->media_id_by_uuid((string) $payload['featured_media']);
			if ($thumb === null) {
				throw new Sync_Http_Exception('unresolved_references', 'The featured image is not on the target yet.', 422, array('missing' => array(array('type' => 'media', 'key' => $payload['featured_media']))));
			}
		}
		$parent = 0;
		$warnings = $res->warnings;
		if ($existing === null && !empty($payload['parent_uuid'])) {
			$parent = (int) ($this->site->post_id_by_uuid((string) $payload['parent_uuid']) ?? 0);
			if ($parent === 0) {
				$warnings[] = array('code' => 'parent_missing', 'message' => 'The parent page is not on the target; the page was created at the top level.');
			}
		}

		$result = Service_User::run(function ($service_uid) use ($payload, $uuid, $type, $status, $existing, $res, $thumb, $parent, $terms_catalog, &$warnings) {
			$postarr = array(
				'post_type'    => $existing !== null ? \get_post($existing)->post_type : $type,
				'post_content' => $res->content,
				'post_status'  => $status,
			);
			if ($status === 'future' && !empty($payload['date'])) {
				$postarr['post_date_gmt'] = (string) $payload['date'];
				$postarr['post_date']     = \get_date_from_gmt((string) $payload['date']);
			}
			if ($existing !== null) {
				$postarr['ID'] = $existing;
				$id = \wp_update_post(\wp_slash($postarr), true);
			} else {
				$postarr['post_title']  = isset($payload['title']) ? (string) $payload['title'] : '';
				$postarr['post_name']   = isset($payload['slug']) ? (string) $payload['slug'] : '';
				$postarr['post_parent'] = $parent;
				$author = !empty($payload['author_login']) ? \get_user_by('login', (string) $payload['author_login']) : null;
				$postarr['post_author'] = $author ? (int) $author->ID : (int) $service_uid;
				$id = \wp_insert_post(\wp_slash($postarr), true);
				if (!\is_wp_error($id) && !empty($payload['template'])) {
					\update_post_meta($id, '_wp_page_template', (string) $payload['template']);
				}
			}
			if (\is_wp_error($id)) {
				throw new Sync_Http_Exception('write_failed', $id->get_error_message(), 500, array(), true);
			}
			\update_post_meta($id, Identity::META_UUID, $uuid);
			self::stamp_hashes((int) $id, (string) $payload['hash']);

			if ($thumb !== null) {
				\set_post_thumbnail($id, $thumb);
			} elseif (\array_key_exists('featured_media', $payload) && empty($payload['featured_media']) && $existing !== null) {
				\delete_post_thumbnail($id);
			}

			// Term assignments by slug, grouped per taxonomy; untouched taxonomies keep their terms.
			if (isset($payload['terms']) && \is_array($payload['terms'])) {
				$by_tax = array();
				foreach ($payload['terms'] as $key) {
					$info = $terms_catalog[$key] ?? null;
					if (!$info) {
						list($tax, $slug) = \array_pad(\explode(':', (string) $key, 2), 2, '');
						$info = array('taxonomy' => $tax, 'slug' => $slug, 'name' => $slug, 'parent' => null);
					}
					$tid = $this->site->ensure_term($info);
					if ($tid === null) {
						$warnings[] = array('code' => 'term_not_assigned', 'message' => "Term {$key} could not be created on the target.");
						continue;
					}
					$by_tax[$info['taxonomy']][] = $tid;
				}
				foreach ($by_tax as $tax => $ids) {
					\wp_set_object_terms($id, \array_map('intval', $ids), $tax, false);
				}
			}

			// Registered extra meta (declared via the awx_sync_page_meta_keys filter).
			$meta_keys = (array) \apply_filters('awx_sync_page_meta_keys', array());
			foreach ((array) ($payload['meta'] ?? array()) as $k => $v) {
				if (\in_array($k, $meta_keys, true)) {
					\update_post_meta($id, $k, $v);
				}
			}

			\do_action('awx_sync_after_write', (int) $id, 'page');
			return array(
				'id'          => (int) $id,
				'created'     => $existing === null,
				'status'      => $status,
				'permalink'   => \get_permalink((int) $id),
				'revision_id' => self::latest_revision((int) $id),
				'warnings'    => $warnings,
			);
		});
		Step_Store::record($run_id, $step_id, 'page', $uuid, 'done', $result);
		return $result;
	}

	/* ================================================================== */
	/* Helpers                                                             */
	/* ================================================================== */

	private function assert_resolved(Transform_Result $res, array $source_hosts): void {
		if (!$res->ok()) {
			throw new Sync_Http_Exception('unresolved_references', 'Some referenced objects are not on the target yet.', 422, array('missing' => $res->missing));
		}
		$left = Transformer::leftover_tokens($res->content);
		if (!empty($left)) {
			throw new Sync_Http_Exception('unresolved_references', 'Resolved content still contains sync tokens.', 422, array('tokens' => $left));
		}
		if (!empty($source_hosts) && Transformer::contains_host($res->content, $source_hosts)) {
			throw new Sync_Http_Exception('source_host_present', 'Resolved content still mentions the source site host.', 422);
		}
	}

	/** Record what this site saved and which source version it came from. */
	public static function stamp_hashes(int $post_id, string $source_hash): void {
		\clean_post_cache($post_id);
		$saved = \get_post($post_id);
		\update_post_meta($post_id, Identity::META_CONTENT_HASH, \hash('sha256', (string) ($saved ? $saved->post_content : '')));
		\update_post_meta($post_id, self::META_SOURCE_HASH, $source_hash);
	}

	public static function latest_revision(int $post_id): ?int {
		$revs = \wp_get_post_revisions($post_id, array('posts_per_page' => 1, 'fields' => 'ids'));
		if (empty($revs)) {
			return null;
		}
		$first = \reset($revs);
		return (int) (\is_object($first) ? $first->ID : $first);
	}

	public function run_status(string $run_id): array {
		return array('run_id' => $run_id, 'steps' => \array_map(function ($s) {
			return array('step_id' => $s['step_id'], 'type' => $s['type'], 'object_uuid' => $s['object_uuid'], 'status' => $s['status'], 'response' => $s['response'], 'updated_at' => $s['updated_at']);
		}, Step_Store::for_run($run_id)));
	}
}
