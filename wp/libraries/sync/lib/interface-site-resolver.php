<?php
/**
 * Everything the transformer needs to know about the site it runs on.
 *
 * The transformer never calls WordPress directly, so the same code can be
 * exercised against an in-memory site in tests and against WordPress in
 * production (see WP_Site_Resolver).
 */
namespace aw2\sync;

interface Site_Resolver {

	/* ---- Site identity ---- */

	/** Home URL without trailing slash, e.g. https://dev.example.com */
	public function home_url(): string;

	/** Every host name that content on this site may use for its own URLs (home, site, uploads/CDN). */
	public function own_hosts(): array;

	/** Path prefix (no host) under which uploads live, e.g. /wp-content/uploads */
	public function uploads_path(): string;

	/* ---- Media ---- */

	/** Sync UUID of an attachment; on a source site this may assign one. Null when the attachment does not exist. */
	public function media_uuid(int $attachment_id): ?string;

	public function media_id_by_uuid(string $uuid): ?int;

	/** Attachment id for a URL on this site (size suffixes such as -300x200 and -scaled are tolerated). */
	public function media_id_by_url(string $url): ?int;

	/** Size slug ('full', 'large', 'thumbnail', ...) that produced $url for this attachment; 'full' when unknown. */
	public function media_size_for_url(int $attachment_id, string $url): string;

	/** URL of an attachment at a given size; falls back to the original file. Null when the attachment does not exist. */
	public function media_url(int $attachment_id, string $size = 'full'): ?string;

	/** Metadata used in manifests: sha256, mime, bytes, alt, title, file (basename). Null when missing. */
	public function media_info(int $attachment_id): ?array;

	/* ---- Posts (any public post type, including the page being synced) ---- */

	public function post_uuid(int $post_id): ?string;

	public function post_id_by_uuid(string $uuid): ?int;

	/** Post id for a permalink on this site, or null. */
	public function post_id_by_url(string $url): ?int;

	public function post_permalink(int $post_id): ?string;

	/**
	 * Everything the extractor needs about a post, or null when missing:
	 * ['id','uuid','type','slug','title','status','date','parent' (id|0),'author_login','template',
	 *  'content' (raw post_content),'thumbnail_id' (int|0),'terms' => [term_info, ...]]
	 */
	public function post_info(int $post_id): ?array;

	/* ---- Reusable blocks (wp_block) ---- */

	public function block_uuid(int $block_id): ?string;

	public function block_id_by_uuid(string $uuid): ?int;

	public function block_content(int $block_id): ?string;

	/** ['id','uuid','slug','title','hash','pattern_slug'] for manifests; null when missing. */
	public function block_info(int $block_id): ?array;

	/* ---- Terms ---- */

	/** ['taxonomy','slug','name','parent' => slug|null] for a term id (taxonomy optional hint); null when missing. */
	public function term_info(int $term_id, ?string $taxonomy = null): ?array;

	public function term_id_by_slug(string $taxonomy, string $slug): ?int;

	/** Find or create a term from ['taxonomy','slug','name','parent']; returns the id or null when creation is not allowed/failed. */
	public function ensure_term(array $term): ?int;

	/* ---- AWX dynamic blocks ---- */

	/** Editor registry of dgb blocks: slug => ['fields' => [...], 'tabs' => [...]] */
	public function dgb_registry(): array;

	/** Version marker of a dgb block definition post, or null when the block is not defined on this site. */
	public function dgb_block_version(string $slug): ?string;
}
