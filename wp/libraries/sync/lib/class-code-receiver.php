<?php
/**
 * Prod-side business logic behind the /code/* routes: the code plan, the
 * idempotent writes of code posts (modules, app definitions, block
 * definitions, patterns) and ACF field groups, and the finalize step that
 * flushes caches after a deploy.
 *
 * Code identity is `post_type + post_name`; there is no UUID. The hash both
 * sides compare is Code_Item::hash(). After a write this site stores
 *   _awx_content_hash  = Code_Item::from_post(...)->hash() of what it saved
 *   _awx_source_hash   = the source's item hash at that write
 * so a later edit here is detectable (content hash drifts) and an unchanged
 * source can be skipped (source hash matches).
 *
 * The routes exist only when AWX_SYNC_ALLOW_CODE is true and every one of
 * them requires the `code` token scope (see Prod_Routes).
 */
namespace aw2\sync;

if (!\class_exists(__NAMESPACE__ . '\\Code_Item')) {
	require_once __DIR__ . '/class-code-item.php';
}

class Code_Receiver {

	const SLUG_RE      = '/^[a-z0-9_.\-]+$/';
	const POST_TYPE_RE = '/^[a-z0-9_\-]+$/';
	const ACF_POST_TYPE = 'acf-field-group';
	const STEP_TYPE_CODE     = 'code';
	const STEP_TYPE_ACF      = 'acf';
	const STEP_TYPE_FINALIZE = 'finalize';

	/* ================================================================== */
	/* Configuration                                                       */
	/* ================================================================== */

	/** Code routes are an explicit opt-in on the target. */
	public static function allowed(): bool {
		return \defined('AWX_SYNC_ALLOW_CODE') && (bool) AWX_SYNC_ALLOW_CODE;
	}

	public static function acf_active(): bool {
		return \function_exists('acf_import_field_group');
	}

	/** Kinds accepted by /code/modules (everything but ACF groups). */
	public static function post_kinds(): array {
		return \array_values(\array_diff(Code_Item::KINDS, array('acf')));
	}

	/* ================================================================== */
	/* Plan                                                                */
	/* ================================================================== */

	/**
	 * @param array $m {items:[{kind,post_type,slug,hash}], collections:[{kind,post_type}], apps:[slug], acf_keys:[key]}
	 */
	public function plan(array $m): array {
		$out = array(
			'items'        => array(),
			'acf'          => array(),
			'only_on_prod' => array(),
			'apps'         => array(),
			'acf_active'   => self::acf_active(),
			'allow_code'   => self::allowed(),
			'post_types'   => \array_values(\get_post_types(array(), 'names')),
		);

		$seen = array(); // post_type => [slug => true], for only_on_prod
		foreach ((array) ($m['items'] ?? array()) as $it) {
			if (!\is_array($it)) {
				continue;
			}
			$kind      = (string) ($it['kind'] ?? '');
			$post_type = (string) ($it['post_type'] ?? '');
			$slug      = (string) ($it['slug'] ?? '');
			$hash      = isset($it['hash']) ? (string) $it['hash'] : '';
			if ($post_type === '' || $slug === '' || !\in_array($kind, self::post_kinds(), true)) {
				continue;
			}
			$seen[$post_type][$slug] = true;
			$out['items'][$post_type . ':' . $slug] = $this->plan_item($kind, $post_type, $slug, $hash);
		}

		// acf_keys: ["group_x", ...] or [{key, hash}, ...]; a hash lets the plan say "unchanged".
		foreach ((array) ($m['acf_keys'] ?? array()) as $entry) {
			$key  = \is_array($entry) ? (string) ($entry['key'] ?? '') : (string) $entry;
			$hash = \is_array($entry) ? (string) ($entry['hash'] ?? '') : (string) ($m['acf_hashes'][$key] ?? '');
			if ($key === '') {
				continue;
			}
			$out['acf'][$key] = $this->plan_acf($key, $hash);
		}

		foreach ((array) ($m['collections'] ?? array()) as $c) {
			$post_type = \is_array($c) ? (string) ($c['post_type'] ?? '') : (string) $c;
			if ($post_type === '' || !\preg_match(self::POST_TYPE_RE, $post_type)) {
				continue;
			}
			$out['only_on_prod'][$post_type] = array();
			foreach (self::live_posts_of_type($post_type) as $row) {
				if (empty($seen[$post_type][$row['post_name']])) {
					$out['only_on_prod'][$post_type][] = array('slug' => (string) $row['post_name'], 'title' => (string) $row['post_title']);
				}
			}
		}

		foreach ((array) ($m['apps'] ?? array()) as $slug) {
			$slug = (string) $slug;
			if ($slug === '') {
				continue;
			}
			$out['apps'][$slug] = self::find_post('aw2_app', $slug) !== null;
		}

		return $out;
	}

	/** Status of one code post on this site, by the shared rules. */
	private function plan_item(string $kind, string $post_type, string $slug, string $hash): array {
		$post = self::find_post($post_type, $slug);
		$row  = array(
			'status'           => 'new',
			'prod_id'          => null,
			'prod_hash'        => null,
			'last_synced_hash' => null,
			'never_synced'     => false,
			'has_revisions'    => \post_type_exists($post_type) && \post_type_supports($post_type, 'revisions'),
			'title'            => null,
		);
		if ($post === null) {
			return $row;
		}
		$prod_hash = Code_Item::from_post($kind, $post, self::meta_skipped($kind, $slug))->hash();
		$last = (string) \get_post_meta((int) $post->ID, Identity::META_CONTENT_HASH, true);
		$src  = (string) \get_post_meta((int) $post->ID, Receiver::META_SOURCE_HASH, true);
		$row['prod_id']          = (int) $post->ID;
		$row['prod_hash']        = $prod_hash;
		$row['last_synced_hash'] = $last !== '' ? $last : null;
		$row['title']            = (string) $post->post_title;
		$row['status']           = self::classify($prod_hash, $last, $src, $hash, $row['never_synced']);
		return $row;
	}

	/**
	 * The status rules shared by code posts and ACF groups.
	 *   last set, prod != last      -> prod_edited
	 *   last set, src == item hash  -> unchanged
	 *   last set otherwise          -> changed
	 *   never synced                -> unchanged when prod == item hash, else changed (+never_synced)
	 */
	public static function classify(string $prod_hash, string $last, string $src, string $item_hash, bool &$never_synced): string {
		$never_synced = false;
		if ($last !== '') {
			if ($prod_hash !== $last) {
				return 'prod_edited';
			}
			return ($item_hash !== '' && \hash_equals($src, $item_hash)) ? 'unchanged' : 'changed';
		}
		if ($item_hash !== '' && \hash_equals($prod_hash, $item_hash)) {
			return 'unchanged';
		}
		$never_synced = true;
		return 'changed';
	}

	private function plan_acf(string $key, string $hash): array {
		if (!self::acf_active()) {
			return array('status' => 'acf_inactive', 'prod_id' => null, 'prod_hash' => null);
		}
		$group = self::acf_group($key);
		if ($group === null) {
			return array('status' => 'new', 'prod_id' => null, 'prod_hash' => null, 'last_synced_hash' => null, 'never_synced' => false, 'has_revisions' => false, 'title' => null);
		}
		$post_id   = (int) $group['ID'];
		$prod_hash = self::acf_hash($group);
		$last = (string) \get_post_meta($post_id, Identity::META_CONTENT_HASH, true);
		$src  = (string) \get_post_meta($post_id, Receiver::META_SOURCE_HASH, true);
		$never = false;
		$status = self::classify($prod_hash, $last, $src, $hash, $never);
		return array(
			'status'           => $status,
			'prod_id'          => $post_id,
			'prod_hash'        => $prod_hash,
			'last_synced_hash' => $last !== '' ? $last : null,
			'never_synced'     => $never,
			'has_revisions'    => false,
			'title'            => (string) ($group['title'] ?? ''),
		);
	}

	/* ================================================================== */
	/* Code posts                                                          */
	/* ================================================================== */

	/**
	 * @param array $payload {item:{...Code_Item::to_payload()}, expected_prod_hash|null, overwrite:bool}
	 */
	public function write_module(array $payload, string $run_id, string $step_id): array {
		$replay = Step_Store::replay($run_id, $step_id);
		if ($replay !== null) {
			return $replay + array('replayed' => true);
		}
		$raw = isset($payload['item']) && \is_array($payload['item']) ? $payload['item'] : null;
		if ($raw === null) {
			throw new Sync_Http_Exception('invalid_payload', 'Missing field item.', 400);
		}
		$kind = (string) ($raw['kind'] ?? '');
		if (!\in_array($kind, self::post_kinds(), true)) {
			throw new Sync_Http_Exception('invalid_payload', 'Unknown or unsupported code item kind: ' . $kind, 400);
		}
		$slug      = (string) ($raw['slug'] ?? '');
		$post_type = (string) ($raw['post_type'] ?? '');
		if (!\preg_match(self::SLUG_RE, $slug)) {
			throw new Sync_Http_Exception('invalid_payload', 'Invalid slug.', 400, array('slug' => $slug));
		}
		if (!\preg_match(self::POST_TYPE_RE, $post_type)) {
			throw new Sync_Http_Exception('invalid_payload', 'Invalid post type.', 400, array('post_type' => $post_type));
		}
		if (!isset($raw['content'])) {
			throw new Sync_Http_Exception('invalid_payload', 'Missing field item.content.', 400);
		}
		$status = (string) ($raw['status'] ?? 'publish');
		if (!\in_array($status, Receiver::STATUSES, true)) {
			throw new Sync_Http_Exception('status_not_allowed', "Status {$status} cannot be synced.", 422);
		}
		$item      = Code_Item::from_payload($raw);
		$item_hash = isset($raw['hash']) && $raw['hash'] !== '' ? (string) $raw['hash'] : $item->hash();
		$key       = $item->key();

		$existing  = self::find_post($post_type, $slug);
		$unchanged = false;
		if ($existing !== null) {
			$prod_hash = Code_Item::from_post($kind, $existing, $item->meta_skipped)->hash();
			$expected  = isset($payload['expected_prod_hash']) && $payload['expected_prod_hash'] !== '' ? (string) $payload['expected_prod_hash'] : null;
			if ($expected !== null && !\hash_equals($prod_hash, $expected) && empty($payload['overwrite'])) {
				throw new Sync_Http_Exception('edited_on_prod', "{$key} was edited on the target since the plan was made. Re-plan or confirm overwrite.", 409, array('prod_hash' => $prod_hash, 'expected_prod_hash' => $expected, 'key' => $key));
			}
			$unchanged = \hash_equals($prod_hash, $item_hash);
		}

		if ($unchanged) {
			// Identical already: only make sure the markers are in place, no new revision.
			$id = (int) $existing->ID;
			self::stamp($id, $kind, $item->meta_skipped, $item_hash);
			$result = array('action' => 'unchanged', 'post_id' => $id, 'revision_id' => Receiver::latest_revision($id), 'key' => $key, 'kind' => $kind, 'post_type' => $post_type, 'slug' => $slug, 'warnings' => array());
			Step_Store::record($run_id, $step_id, self::STEP_TYPE_CODE, $key, 'done', $result);
			return $result;
		}

		$result = Service_User::run(function () use ($item, $item_hash, $existing, $kind, $post_type, $slug, $status, $key, $raw) {
			$warnings = array();
			$parent   = 0;
			if ($item->parent_slug !== null) {
				$p = self::find_post($post_type, $item->parent_slug);
				if ($p !== null && ($existing === null || (int) $p->ID !== (int) $existing->ID)) {
					$parent = (int) $p->ID;
				} else {
					$warnings[] = array('code' => 'parent_missing', 'message' => "Parent {$post_type}:{$item->parent_slug} is not on the target; {$key} was saved at the top level.");
				}
			}
			$postarr = array(
				'post_type'    => $post_type,
				'post_title'   => $item->title,
				'post_name'    => $slug,
				'post_status'  => $status,
				'post_content' => $item->content,
				'post_parent'  => $parent,
			);
			if ($existing !== null) {
				$postarr['ID'] = (int) $existing->ID;
				$id = \wp_update_post(\wp_slash($postarr), true);
			} else {
				$id = \wp_insert_post(\wp_slash($postarr), true);
			}
			if (\is_wp_error($id)) {
				throw new Sync_Http_Exception('write_failed', $id->get_error_message(), 500, array('key' => $key), true);
			}
			$id = (int) $id;
			self::force_slug($id, $slug, $warnings);

			if (!$item->meta_skipped) {
				self::replace_meta($id, $item->meta);
			}
			self::stamp($id, $kind, $item->meta_skipped, $item_hash);
			\do_action('awx_sync_after_code_write', $id, $raw);

			$action = $existing === null ? 'created' : 'updated';
			return array(
				'action'      => $action,
				'post_id'     => $id,
				'revision_id' => Receiver::latest_revision($id),
				'key'         => $key,
				'kind'        => $kind,
				'post_type'   => $post_type,
				'slug'        => $slug,
				'warnings'    => $warnings,
			);
		});
		Step_Store::record($run_id, $step_id, self::STEP_TYPE_CODE, $key, 'done', $result);
		return $result;
	}

	/* ================================================================== */
	/* ACF field groups                                                    */
	/* ================================================================== */

	/**
	 * @param array $payload {key, group:{export array}, hash, expected_prod_hash|null, overwrite}
	 */
	public function write_acf_group(array $payload, string $run_id, string $step_id): array {
		$replay = Step_Store::replay($run_id, $step_id);
		if ($replay !== null) {
			return $replay + array('replayed' => true);
		}
		if (!self::acf_active()) {
			throw new Sync_Http_Exception('acf_inactive', 'Advanced Custom Fields is not active on the target; field groups cannot be imported.', 422);
		}
		$group = isset($payload['group']) && \is_array($payload['group']) ? $payload['group'] : null;
		$key   = (string) ($payload['key'] ?? ($group['key'] ?? ''));
		if ($group === null || $key === '' || !\preg_match('/^[A-Za-z0-9_\-]+$/', $key)) {
			throw new Sync_Http_Exception('invalid_payload', 'Missing or invalid key/group.', 400);
		}
		if (empty($group['key'])) {
			$group['key'] = $key;
		} elseif ((string) $group['key'] !== $key) {
			throw new Sync_Http_Exception('invalid_payload', 'The group key does not match the key field.', 400, array('key' => $key, 'group_key' => $group['key']));
		}
		$item_hash = isset($payload['hash']) && $payload['hash'] !== '' ? (string) $payload['hash'] : self::acf_hash($group);
		$audit_key = self::ACF_POST_TYPE . ':' . $key;

		$existing  = self::acf_group($key);
		$prod_hash = null;
		if ($existing !== null) {
			$prod_hash = self::acf_hash($existing);
			$expected  = isset($payload['expected_prod_hash']) && $payload['expected_prod_hash'] !== '' ? (string) $payload['expected_prod_hash'] : null;
			if ($expected !== null && !\hash_equals($prod_hash, $expected) && empty($payload['overwrite'])) {
				throw new Sync_Http_Exception('edited_on_prod', "Field group {$key} was edited on the target since the plan was made. Re-plan or confirm overwrite.", 409, array('prod_hash' => $prod_hash, 'expected_prod_hash' => $expected, 'key' => $key));
			}
		}

		$result = Service_User::run(function () use ($group, $key, $item_hash, $existing, $audit_key) {
			unset($group['ID']);
			$imported = \acf_import_field_group($group);
			$post_id  = \is_array($imported) && !empty($imported['ID']) ? (int) $imported['ID'] : 0;
			if ($post_id === 0) {
				$again   = self::acf_group($key);
				$post_id = $again !== null ? (int) $again['ID'] : 0;
			}
			if ($post_id === 0) {
				throw new Sync_Http_Exception('write_failed', "ACF did not return a post for field group {$key}.", 500, array('key' => $key), true);
			}
			$fresh = self::acf_group($key);
			\update_post_meta($post_id, Identity::META_CONTENT_HASH, $fresh !== null ? self::acf_hash($fresh) : $item_hash);
			\update_post_meta($post_id, Receiver::META_SOURCE_HASH, $item_hash);
			\do_action('awx_sync_after_code_write', $post_id, array('kind' => 'acf', 'post_type' => self::ACF_POST_TYPE, 'slug' => $key, 'group' => $group, 'hash' => $item_hash));
			return array(
				'action'  => $existing === null ? 'created' : 'updated',
				'post_id' => $post_id,
				'key'     => $key,
				'kind'    => 'acf',
			);
		});
		if ($prod_hash !== null && \hash_equals($prod_hash, $item_hash)) {
			$result['action'] = 'unchanged';
		}
		Step_Store::record($run_id, $step_id, self::STEP_TYPE_ACF, $audit_key, 'done', $result);
		return $result;
	}

	/* ================================================================== */
	/* Finalize                                                            */
	/* ================================================================== */

	/**
	 * Flush caches after the run's writes. What was written is read back from
	 * the run's step rows, so nothing else has to be stored per run.
	 */
	public function finalize(string $run_id, string $step_id): array {
		$replay = Step_Store::replay($run_id, $step_id);
		if ($replay !== null) {
			return $replay + array('replayed' => true);
		}
		$written = self::written_in_run($run_id);

		if (\function_exists('\\aw2\\global_cache\\flush')) {
			\aw2\global_cache\flush(null, null, null);
		}

		$recompiled = array();
		if (\function_exists('\\aw2\\gutenberg_blocks\\dgb')) {
			$dgb = \aw2\gutenberg_blocks\dgb();
			if (\is_object($dgb) && \method_exists($dgb, 'invalidate_registry')) {
				$dgb->invalidate_registry();
			}
			if (\is_object($dgb) && \method_exists($dgb, 'recompile_single_block_assets')) {
				foreach ($written['blocks'] as $slug) {
					$dgb->recompile_single_block_assets($slug);
					$recompiled[] = $slug;
				}
			}
		}

		$rewrite = false;
		if ($written['app_created']) {
			\flush_rewrite_rules();
			$rewrite = true;
		}

		\do_action('awx_sync_after_code_deploy', $run_id, $written);

		$result = array(
			'flushed'            => true,
			'blocks_recompiled'  => $recompiled,
			'rewrite_flushed'    => $rewrite,
			'written'            => \count($written['items']),
		);
		Step_Store::record($run_id, $step_id, self::STEP_TYPE_FINALIZE, null, 'done', $result);
		return $result;
	}

	/**
	 * What the run has written so far, from its done step rows.
	 *
	 * @return array {items:[{key,kind,post_type,slug,action,post_id}], blocks:[slug], app_created:bool}
	 */
	public static function written_in_run(string $run_id): array {
		$out = array('items' => array(), 'blocks' => array(), 'app_created' => false);
		foreach (Step_Store::for_run($run_id) as $row) {
			if ($row['status'] !== 'done' || !\is_array($row['response'])) {
				continue;
			}
			if (!\in_array($row['type'], array(self::STEP_TYPE_CODE, self::STEP_TYPE_ACF), true)) {
				continue;
			}
			$r = $row['response'];
			$kind = (string) ($r['kind'] ?? '');
			$out['items'][] = array(
				'key'       => (string) ($r['key'] ?? $row['object_uuid']),
				'kind'      => $kind,
				'post_type' => (string) ($r['post_type'] ?? ($kind === 'acf' ? self::ACF_POST_TYPE : '')),
				'slug'      => (string) ($r['slug'] ?? ($r['key'] ?? '')),
				'action'    => (string) ($r['action'] ?? ''),
				'post_id'   => (int) ($r['post_id'] ?? 0),
			);
			if ($kind === 'dgb' && !empty($r['slug']) && !\in_array($r['slug'], $out['blocks'], true)) {
				$out['blocks'][] = (string) $r['slug'];
			}
			if ($kind === 'app' && ($r['action'] ?? '') === 'created') {
				$out['app_created'] = true;
			}
		}
		return $out;
	}

	/* ================================================================== */
	/* Helpers                                                             */
	/* ================================================================== */

	/** The `settings` module of a config collection never carries its meta. */
	public static function meta_skipped(string $kind, string $slug): bool {
		return $kind === 'config' && $slug === 'settings';
	}

	/** The live post with this type and slug (trash and auto-drafts ignored). */
	public static function find_post(string $post_type, string $slug): ?\WP_Post {
		global $wpdb;
		$id = $wpdb->get_var($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT 1",
			$post_type,
			$slug
		));
		if (!$id) {
			return null;
		}
		$post = \get_post((int) $id);
		return $post instanceof \WP_Post ? $post : null;
	}

	/** @return array<int, array{post_name:string, post_title:string}> */
	private static function live_posts_of_type(string $post_type): array {
		global $wpdb;
		return (array) $wpdb->get_results($wpdb->prepare(
			"SELECT post_name, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') ORDER BY post_name ASC",
			$post_type
		), ARRAY_A);
	}

	/**
	 * WordPress may rewrite a slug on save (dots, case, uniqueness). The slug
	 * is the identity both sites agree on, so it is restored when that happens.
	 */
	private static function force_slug(int $post_id, string $slug, array &$warnings): void {
		global $wpdb;
		$saved = \get_post($post_id);
		if (!$saved || $saved->post_name === $slug) {
			return;
		}
		$wpdb->update($wpdb->posts, array('post_name' => $slug), array('ID' => $post_id));
		\clean_post_cache($post_id);
		$warnings[] = array('code' => 'slug_restored', 'message' => "WordPress saved the slug as {$saved->post_name}; it was restored to {$slug}.");
	}

	/**
	 * Replace the synced meta: drop every key the sync owns, then add the
	 * payload's values. Values travel as the stored strings; a serialized
	 * string is unserialized first (as the WordPress importer does) because
	 * add_post_meta() would otherwise wrap it in a second serialization.
	 */
	private static function replace_meta(int $post_id, array $meta): void {
		$current = \get_post_custom($post_id);
		foreach ((array) $current as $k => $vals) {
			if (Code_Item::meta_key_allowed((string) $k)) {
				\delete_post_meta($post_id, (string) $k);
			}
		}
		foreach ($meta as $k => $vals) {
			$k = (string) $k;
			if (!Code_Item::meta_key_allowed($k)) {
				continue;
			}
			foreach ((array) $vals as $v) {
				\add_post_meta($post_id, \wp_slash($k), \wp_slash(\maybe_unserialize((string) $v)));
			}
		}
		\wp_cache_delete($post_id, 'post_meta');
	}

	/** Record what this site saved (Code_Item hash) and which source version it came from. */
	public static function stamp(int $post_id, string $kind, bool $meta_skipped, string $source_hash): void {
		\clean_post_cache($post_id);
		$saved = \get_post($post_id);
		$hash  = $saved ? Code_Item::from_post($kind, $saved, $meta_skipped)->hash() : '';
		\update_post_meta($post_id, Identity::META_CONTENT_HASH, $hash);
		\update_post_meta($post_id, Receiver::META_SOURCE_HASH, $source_hash);
	}

	/** The ACF group with its fields loaded, or null. */
	public static function acf_group(string $key): ?array {
		if (!\function_exists('acf_get_field_group')) {
			return null;
		}
		$group = \acf_get_field_group($key);
		if (!\is_array($group) || empty($group['ID'])) {
			return null;
		}
		if (\function_exists('acf_get_fields')) {
			$group['fields'] = \acf_get_fields($group);
		}
		return $group;
	}

	/** Hash of a group as ACF would export it. */
	public static function acf_hash(array $group): string {
		if (\function_exists('acf_prepare_field_group_for_export')) {
			if (!isset($group['fields']) && !empty($group['ID']) && \function_exists('acf_get_fields')) {
				$group['fields'] = \acf_get_fields($group);
			}
			$group = \acf_prepare_field_group_for_export($group);
		}
		unset($group['ID']);
		return Code_Item::hash_parts('acf', '', '', null, '', array(), \is_array($group) ? $group : array());
	}
}
