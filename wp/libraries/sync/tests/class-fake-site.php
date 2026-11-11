<?php
/**
 * In-memory Site_Resolver for tests.
 */
namespace aw2\sync\tests;

use aw2\sync\Site_Resolver;

class Fake_Site implements Site_Resolver {

	public $home;
	public $hosts;
	public $uploads = '/wp-content/uploads';

	/** id => ['uuid','url','sizes' => [size => url],'mime','alt','title','file','sha256','bytes'] */
	public $media = array();
	/** id => ['uuid','type','slug','url','title','status','content','thumbnail_id','terms','parent','author_login'] */
	public $posts = array();
	/** id => ['uuid','slug','title','content','pattern_slug'] */
	public $blocks = array();
	/** id => ['taxonomy','slug','name','parent'] */
	public $terms = array();
	/** slug => config */
	public $dgb = array();
	/** slug => version */
	public $dgb_versions = array();
	public $allow_create_terms = true;
	public $created_terms = array();
	private $next_term_id = 9000;

	public function __construct(string $home, array $extra_hosts = array()) {
		$this->home  = rtrim($home, '/');
		$this->hosts = array_merge(array(parse_url($home, PHP_URL_HOST)), $extra_hosts);
	}

	public function home_url(): string { return $this->home; }
	public function own_hosts(): array { return $this->hosts; }
	public function uploads_path(): string { return $this->uploads; }

	private function find_by_uuid(array $table, string $uuid): ?int {
		foreach ($table as $id => $row) {
			if (isset($row['uuid']) && $row['uuid'] === $uuid) {
				return (int) $id;
			}
		}
		return null;
	}

	public function media_uuid(int $id): ?string { return isset($this->media[$id]) ? $this->media[$id]['uuid'] : null; }
	public function media_id_by_uuid(string $uuid): ?int { return $this->find_by_uuid($this->media, $uuid); }
	/** Like WP_Site_Resolver: alternate hosts (CDN) are normalised, so compare paths only. */
	public function media_id_by_url(string $url): ?int {
		$path = (string) parse_url($url, PHP_URL_PATH);
		foreach ($this->media as $id => $m) {
			if ((string) parse_url($m['url'], PHP_URL_PATH) === $path) return (int) $id;
			foreach ($m['sizes'] ?? array() as $u) {
				if ((string) parse_url($u, PHP_URL_PATH) === $path) return (int) $id;
			}
		}
		return null;
	}
	public function media_size_for_url(int $id, string $url): string {
		if (!isset($this->media[$id])) return 'full';
		foreach ($this->media[$id]['sizes'] ?? array() as $size => $u) {
			if (basename($u) === basename($url)) return (string) $size;
		}
		return 'full';
	}
	public function media_url(int $id, string $size = 'full'): ?string {
		if (!isset($this->media[$id])) return null;
		if ($size !== 'full' && isset($this->media[$id]['sizes'][$size])) return $this->media[$id]['sizes'][$size];
		return $this->media[$id]['url'];
	}
	public function media_info(int $id): ?array {
		if (!isset($this->media[$id])) return null;
		$m = $this->media[$id];
		return array(
			'sha256' => $m['sha256'] ?? str_repeat('a', 64), 'mime' => $m['mime'] ?? 'image/jpeg', 'bytes' => $m['bytes'] ?? 1000,
			'alt' => $m['alt'] ?? '', 'title' => $m['title'] ?? '', 'file' => basename($m['url']), 'url' => $m['url'], 'date' => '2026-01-01 00:00:00',
		);
	}

	public function post_uuid(int $id): ?string { return isset($this->posts[$id]) ? $this->posts[$id]['uuid'] : null; }
	public function post_id_by_uuid(string $uuid): ?int { return $this->find_by_uuid($this->posts, $uuid); }
	public function post_id_by_url(string $url): ?int {
		$path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
		foreach ($this->posts as $id => $p) {
			if (rtrim((string) parse_url($p['url'], PHP_URL_PATH), '/') === $path) return (int) $id;
		}
		return null;
	}
	public function post_permalink(int $id): ?string { return isset($this->posts[$id]) ? $this->posts[$id]['url'] : null; }
	public function post_info(int $id): ?array {
		if (!isset($this->posts[$id])) return null;
		$p = $this->posts[$id];
		return array(
			'id' => $id, 'uuid' => $p['uuid'], 'type' => $p['type'] ?? 'page', 'slug' => $p['slug'] ?? 'slug', 'title' => $p['title'] ?? '',
			'status' => $p['status'] ?? 'publish', 'date' => '2026-01-01 00:00:00', 'parent' => $p['parent'] ?? 0,
			'author_login' => $p['author_login'] ?? 'dev', 'template' => '', 'content' => $p['content'] ?? '',
			'thumbnail_id' => $p['thumbnail_id'] ?? 0, 'terms' => $p['terms'] ?? array(),
		);
	}

	public function block_uuid(int $id): ?string { return isset($this->blocks[$id]) ? $this->blocks[$id]['uuid'] : null; }
	public function block_id_by_uuid(string $uuid): ?int { return $this->find_by_uuid($this->blocks, $uuid); }
	public function block_content(int $id): ?string { return isset($this->blocks[$id]) ? $this->blocks[$id]['content'] : null; }
	public function block_info(int $id): ?array {
		if (!isset($this->blocks[$id])) return null;
		$b = $this->blocks[$id];
		return array('id' => $id, 'uuid' => $b['uuid'], 'slug' => $b['slug'] ?? 'block', 'title' => $b['title'] ?? '', 'hash' => hash('sha256', $b['content']), 'pattern_slug' => $b['pattern_slug'] ?? null);
	}

	public function term_info(int $id, ?string $taxonomy = null): ?array {
		if (!isset($this->terms[$id])) return null;
		if ($taxonomy !== null && $this->terms[$id]['taxonomy'] !== $taxonomy) return null;
		return $this->terms[$id];
	}
	public function term_id_by_slug(string $taxonomy, string $slug): ?int {
		foreach ($this->terms as $id => $t) {
			if ($t['taxonomy'] === $taxonomy && $t['slug'] === $slug) return (int) $id;
		}
		return null;
	}
	public function ensure_term(array $term): ?int {
		$existing = $this->term_id_by_slug($term['taxonomy'], $term['slug']);
		if ($existing !== null) return $existing;
		if (!$this->allow_create_terms) return null;
		$id = $this->next_term_id++;
		$this->terms[$id] = array('taxonomy' => $term['taxonomy'], 'slug' => $term['slug'], 'name' => $term['name'] ?? $term['slug'], 'parent' => $term['parent'] ?? null);
		$this->created_terms[] = $id;
		return $id;
	}

	public function dgb_registry(): array { return $this->dgb; }
	public function dgb_block_version(string $slug): ?string { return $this->dgb_versions[$slug] ?? (isset($this->dgb[$slug]) ? '1' : null); }
}
