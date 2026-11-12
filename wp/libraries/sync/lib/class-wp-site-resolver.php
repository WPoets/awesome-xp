<?php
/**
 * Site_Resolver backed by WordPress. Used on both the source and the target.
 */
namespace aw2\sync;

class WP_Site_Resolver implements Site_Resolver {

	const META_UUID      = '_awx_sync_uuid';
	const META_FILE_HASH = '_awx_file_hash';

	/** @var bool assign a UUID to objects that have none (source side) */
	private $assign_missing;

	/** @var array<string,int|null> */
	private $uuid_cache = array();

	public function __construct(bool $assign_missing = true) {
		$this->assign_missing = $assign_missing;
	}

	/* ---- Site identity ---- */

	public function home_url(): string {
		return \untrailingslashit(\home_url());
	}

	public function own_hosts(): array {
		$urls = array(\home_url(), \site_url(), \content_url());
		$up = \wp_upload_dir(null, false);
		if (!empty($up['baseurl'])) {
			$urls[] = $up['baseurl'];
		}
		$hosts = array();
		foreach ($urls as $u) {
			$h = \strtolower((string) \parse_url($u, PHP_URL_HOST));
			if ($h === '') {
				continue;
			}
			$hosts[] = $h;
			$hosts[] = (0 === \strpos($h, 'www.')) ? \substr($h, 4) : 'www.' . $h;
		}
		return \array_values(\array_unique(\apply_filters('awx_sync_own_hosts', $hosts)));
	}

	public function uploads_path(): string {
		$up = \wp_upload_dir(null, false);
		$path = !empty($up['baseurl']) ? (string) \parse_url($up['baseurl'], PHP_URL_PATH) : '';
		return $path !== '' ? \rtrim($path, '/') : '/wp-content/uploads';
	}

	/* ---- UUID plumbing ---- */

	private function uuid_for(int $post_id, array $allowed_types): ?string {
		$post = \get_post($post_id);
		if (!$post || !\in_array($post->post_type, $allowed_types, true)) {
			return null;
		}
		if (\class_exists(__NAMESPACE__ . '\\Identity') && \method_exists(__NAMESPACE__ . '\\Identity', 'get_uuid')) {
			$uuid = Identity::get_uuid($post_id);
			if (!$uuid && $this->assign_missing && \method_exists(__NAMESPACE__ . '\\Identity', 'ensure_uuid')) {
				$uuid = Identity::ensure_uuid($post_id);
			}
			return $uuid ? (string) $uuid : null;
		}
		$uuid = (string) \get_post_meta($post_id, self::META_UUID, true);
		if ($uuid === '' && $this->assign_missing) {
			$uuid = \wp_generate_uuid4();
			\update_post_meta($post_id, self::META_UUID, $uuid);
		}
		return $uuid !== '' ? $uuid : null;
	}

	private function id_by_uuid(string $uuid, array $allowed_types): ?int {
		global $wpdb;
		$key = \implode(',', $allowed_types) . '|' . $uuid;
		if (\array_key_exists($key, $this->uuid_cache)) {
			return $this->uuid_cache[$key];
		}
		if (!\preg_match('/^' . Transformer::UUID_RE . '$/', $uuid)) {
			return $this->uuid_cache[$key] = null;
		}
		$placeholders = \implode(',', \array_fill(0, \count($allowed_types), '%s'));
		$sql = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type IN ({$placeholders}) AND p.post_status <> 'trash' LIMIT 2",
			\array_merge(array(self::META_UUID, $uuid), $allowed_types)
		);
		$ids = $wpdb->get_col($sql);
		if (\count($ids) !== 1) {
			return $this->uuid_cache[$key] = null;
		}
		return $this->uuid_cache[$key] = (int) $ids[0];
	}

	private function public_types(): array {
		$types = \get_post_types(array('public' => true), 'names');
		unset($types['attachment']);
		return \array_values(\array_unique(\apply_filters('awx_sync_allowed_post_types', \array_values($types))));
	}

	/* ---- Media ---- */

	public function media_uuid(int $attachment_id): ?string {
		return $this->uuid_for($attachment_id, array('attachment'));
	}

	public function media_id_by_uuid(string $uuid): ?int {
		return $this->id_by_uuid($uuid, array('attachment'));
	}

	public function media_id_by_url(string $url): ?int {
		$url = $this->absolutise($url);
		// Normalise CDN / alternate hosts onto the uploads base URL.
		$up = \wp_upload_dir(null, false);
		if (!empty($up['baseurl'])) {
			$base_host = \parse_url($up['baseurl'], PHP_URL_HOST);
			$url_host  = \parse_url($url, PHP_URL_HOST);
			if ($base_host && $url_host && \strcasecmp($base_host, $url_host) !== 0) {
				$path = (string) \parse_url($url, PHP_URL_PATH);
				$url  = \parse_url($up['baseurl'], PHP_URL_SCHEME) . '://' . $base_host . $path;
			}
		}
		$url = \preg_replace('/\?.*$/', '', $url);
		$candidates = array($url);
		$stripped = \preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url);
		if ($stripped !== $url) {
			$candidates[] = $stripped;
			$candidates[] = \preg_replace('/(\.[a-z0-9]+)$/i', '-scaled$1', $stripped);
		}
		foreach ($candidates as $c) {
			$id = (int) \attachment_url_to_postid($c);
			if ($id > 0) {
				return $id;
			}
		}
		return null;
	}

	public function media_size_for_url(int $attachment_id, string $url): string {
		$basename = \basename((string) \parse_url($url, PHP_URL_PATH));
		$meta = \wp_get_attachment_metadata($attachment_id);
		if (\is_array($meta) && !empty($meta['sizes']) && \is_array($meta['sizes'])) {
			foreach ($meta['sizes'] as $size => $data) {
				if (!empty($data['file']) && $data['file'] === $basename) {
					return (string) $size;
				}
			}
		}
		return 'full';
	}

	public function media_url(int $attachment_id, string $size = 'full'): ?string {
		if (!\get_post($attachment_id)) {
			return null;
		}
		if ($size !== 'full' && \wp_attachment_is_image($attachment_id)) {
			$url = \wp_get_attachment_image_url($attachment_id, $size);
			if ($url) {
				return $url;
			}
		}
		$url = \wp_get_attachment_url($attachment_id);
		return $url ? $url : null;
	}

	public function media_info(int $attachment_id): ?array {
		$post = \get_post($attachment_id);
		if (!$post || $post->post_type !== 'attachment') {
			return null;
		}
		$path = '';
		if (\function_exists('wp_get_original_image_path') && \wp_attachment_is_image($attachment_id)) {
			$path = (string) \wp_get_original_image_path($attachment_id);
		}
		if ($path === '' || !\file_exists($path)) {
			$path = (string) \get_attached_file($attachment_id);
		}
		if ($path === '' || !\file_exists($path)) {
			return null;
		}
		$hash = '';
		if (\class_exists(__NAMESPACE__ . '\\Identity') && \method_exists(__NAMESPACE__ . '\\Identity', 'file_hash')) {
			$hash = (string) Identity::file_hash($attachment_id);
		}
		if ($hash === '') {
			$hash = (string) \get_post_meta($attachment_id, self::META_FILE_HASH, true);
			if ($hash === '') {
				$hash = (string) \hash_file('sha256', $path);
				\update_post_meta($attachment_id, self::META_FILE_HASH, $hash);
			}
		}
		return array(
			'sha256' => $hash,
			'mime'   => (string) \get_post_mime_type($attachment_id),
			'bytes'  => (int) \filesize($path),
			'alt'    => (string) \get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
			'title'  => (string) $post->post_title,
			'file'   => \basename($path),
			'url'    => (string) \wp_get_attachment_url($attachment_id),
			'date'   => $post->post_date_gmt,
		);
	}

	/* ---- Posts ---- */

	public function post_uuid(int $post_id): ?string {
		return $this->uuid_for($post_id, $this->public_types());
	}

	public function post_id_by_uuid(string $uuid): ?int {
		return $this->id_by_uuid($uuid, $this->public_types());
	}

	public function post_id_by_url(string $url): ?int {
		$url = $this->absolutise($url);
		$id = (int) \url_to_postid($url);
		return $id > 0 ? $id : null;
	}

	public function post_permalink(int $post_id): ?string {
		$url = \get_permalink($post_id);
		return $url ? $url : null;
	}

	public function post_info(int $post_id): ?array {
		$post = \get_post($post_id);
		if (!$post || $post->post_type === 'attachment' || $post->post_type === 'revision') {
			return null;
		}
		$terms = array();
		foreach (\get_object_taxonomies($post->post_type) as $tax) {
			$list = \wp_get_object_terms($post_id, $tax);
			if (\is_wp_error($list)) {
				continue;
			}
			foreach ($list as $t) {
				$info = $this->term_to_info($t);
				if ($info) {
					$terms[] = $info;
				}
			}
		}
		$author = \get_userdata((int) $post->post_author);
		return array(
			'id'           => $post_id,
			'uuid'         => $this->post_uuid($post_id),
			'type'         => $post->post_type,
			'slug'         => $post->post_name,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'date'         => $post->post_date_gmt,
			'parent'       => (int) $post->post_parent,
			'author_login' => $author ? $author->user_login : null,
			'template'     => (string) \get_page_template_slug($post_id),
			'content'      => (string) $post->post_content,
			'thumbnail_id' => (int) \get_post_thumbnail_id($post_id),
			'terms'        => $terms,
		);
	}

	/* ---- Reusable blocks ---- */

	public function block_uuid(int $block_id): ?string {
		return $this->uuid_for($block_id, array('wp_block'));
	}

	public function block_id_by_uuid(string $uuid): ?int {
		return $this->id_by_uuid($uuid, array('wp_block'));
	}

	public function block_content(int $block_id): ?string {
		$post = \get_post($block_id);
		return ($post && $post->post_type === 'wp_block') ? (string) $post->post_content : null;
	}

	public function block_info(int $block_id): ?array {
		$post = \get_post($block_id);
		if (!$post || $post->post_type !== 'wp_block') {
			return null;
		}
		return array(
			'id'           => $block_id,
			'uuid'         => $this->block_uuid($block_id),
			'slug'         => $post->post_name,
			'title'        => $post->post_title,
			'hash'         => \hash('sha256', (string) $post->post_content),
			'pattern_slug' => (string) (\get_post_meta($block_id, '_awx_pattern_slug', true) ?: $post->post_name),
		);
	}

	/* ---- Terms ---- */

	private function term_to_info($term): ?array {
		if (!($term instanceof \WP_Term)) {
			return null;
		}
		$parent = null;
		if ($term->parent) {
			$p = \get_term($term->parent, $term->taxonomy);
			if ($p instanceof \WP_Term) {
				$parent = $p->slug;
			}
		}
		return array('taxonomy' => $term->taxonomy, 'slug' => $term->slug, 'name' => $term->name, 'parent' => $parent);
	}

	public function term_info(int $term_id, ?string $taxonomy = null): ?array {
		$term = \get_term($term_id, $taxonomy ?: '');
		if (!($term instanceof \WP_Term)) {
			return null;
		}
		return $this->term_to_info($term);
	}

	public function term_id_by_slug(string $taxonomy, string $slug): ?int {
		if (!\taxonomy_exists($taxonomy)) {
			return null;
		}
		$term = \get_term_by('slug', $slug, $taxonomy);
		return ($term instanceof \WP_Term) ? (int) $term->term_id : null;
	}

	public function ensure_term(array $term): ?int {
		$tax  = isset($term['taxonomy']) ? (string) $term['taxonomy'] : '';
		$slug = isset($term['slug']) ? (string) $term['slug'] : '';
		if ($tax === '' || $slug === '' || !\taxonomy_exists($tax)) {
			return null;
		}
		$existing = $this->term_id_by_slug($tax, $slug);
		if ($existing !== null) {
			return $existing;
		}
		$args = array('slug' => $slug);
		if (!empty($term['parent'])) {
			$pid = $this->term_id_by_slug($tax, (string) $term['parent']);
			if ($pid !== null) {
				$args['parent'] = $pid;
			}
		}
		$name = !empty($term['name']) ? (string) $term['name'] : $slug;
		$res  = \wp_insert_term($name, $tax, $args);
		if (\is_wp_error($res)) {
			$dup = $res->get_error_data('term_exists');
			return $dup ? (int) $dup : null;
		}
		return (int) $res['term_id'];
	}

	/* ---- AWX dynamic blocks ---- */

	public function dgb_registry(): array {
		if (\function_exists('aw2\gutenberg_blocks\dgb')) {
			$dgb = \aw2\gutenberg_blocks\dgb();
			if ($dgb && \method_exists($dgb, 'load_registry')) {
				$r = $dgb->load_registry(true);
				return \is_array($r) ? $r : array();
			}
		}
		return array();
	}

	public function dgb_block_version(string $slug): ?string {
		global $wpdb;
		$id = $wpdb->get_var($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'aw_gb_blocks' AND post_name = %s AND post_status = 'publish' LIMIT 1",
			$slug
		));
		if (!$id) {
			return null;
		}
		$v = (string) \get_post_meta((int) $id, '_aw_version', true);
		return $v !== '' ? $v : '1';
	}

	/* ---- Helpers ---- */

	private function absolutise(string $url): string {
		if (0 === \strpos($url, '//')) {
			return (\is_ssl() ? 'https:' : 'http:') . $url;
		}
		return $url;
	}
}
