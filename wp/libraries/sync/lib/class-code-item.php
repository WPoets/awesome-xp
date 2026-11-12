<?php
/**
 * One deployable code object (a module, an app definition, a block
 * definition, a pattern or an ACF field group) and the hash both sites use
 * to decide whether it changed.
 *
 * Code identity is `post_type + post_name`; there is no UUID. The hash covers
 * title, status, parent slug, content and the synced subset of postmeta, so a
 * change to any of those shows up as "changed" in the plan.
 *
 * Kinds:
 *   core      awesome_core modules
 *   service   modules of a service handler post type
 *   app       the aw2_app definition post (content + *_collection meta)
 *   config    modules of an app's config collection (`settings` meta is skipped)
 *   module    modules of an app's modules collection
 *   dgb       aw_gb_blocks block definitions
 *   acf       ACF field groups (keyed by the ACF group key, not a post slug)
 *   pattern   managed wp_block patterns (keyed by _awx_pattern_slug)
 */
namespace aw2\sync;

class Code_Item {

	const KINDS = array('core', 'service', 'app', 'config', 'module', 'dgb', 'acf', 'pattern');

	/** Meta keys never carried across for any kind. */
	const META_SKIP = array('_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_pingme', '_encloseme');

	/** @var string one of KINDS */
	public $kind;
	/** @var string */
	public $post_type;
	/** @var string post_name (or ACF group key for acf) */
	public $slug;
	/** @var string */
	public $title = '';
	/** @var string */
	public $status = 'publish';
	/** @var string|null parent module slug (hierarchical post types) */
	public $parent_slug = null;
	/** @var string */
	public $content = '';
	/** @var array<string, string[]> meta_key => values (already filtered) */
	public $meta = array();
	/** @var bool true when postmeta was deliberately left out (settings module) */
	public $meta_skipped = false;
	/** @var int|null local post id (source side only; never sent) */
	public $id = null;
	/** @var array|null ACF export array for kind=acf */
	public $acf_group = null;

	public function __construct(string $kind, string $post_type, string $slug) {
		$this->kind      = $kind;
		$this->post_type = $post_type;
		$this->slug      = $slug;
	}

	/** Stable identity used in step rows, plan rows and the audit log. */
	public function key(): string {
		return $this->post_type . ':' . $this->slug;
	}

	/** Whether a meta key is carried across for this item. */
	public static function meta_key_allowed(string $key): bool {
		if (\in_array($key, self::META_SKIP, true)) {
			return false;
		}
		if (0 === \strpos($key, '_awx_')) {
			return false;
		}
		return true;
	}

	/**
	 * Filter raw postmeta (get_post_custom() shape: key => string[]) down to
	 * the synced subset. Values are kept as the stored strings so serialized
	 * arrays round-trip unchanged.
	 */
	public static function filter_meta(array $raw): array {
		$out = array();
		foreach ($raw as $k => $vals) {
			if (!self::meta_key_allowed((string) $k)) {
				continue;
			}
			$vals = \is_array($vals) ? \array_values(\array_map('strval', $vals)) : array((string) $vals);
			$out[(string) $k] = $vals;
		}
		\ksort($out, SORT_STRING);
		return $out;
	}

	/**
	 * The hash both sides compute. Built from a canonical JSON document so
	 * key order and whitespace outside the content cannot change it.
	 */
	public function hash(): string {
		return self::hash_parts($this->kind, $this->title, $this->status, $this->parent_slug, $this->content, $this->meta, $this->acf_group);
	}

	public static function hash_parts(string $kind, string $title, string $status, ?string $parent_slug, string $content, array $meta, ?array $acf_group = null): string {
		if ($kind === 'acf') {
			$doc = array('kind' => 'acf', 'group' => self::canonical($acf_group ?? array()));
		} else {
			$meta = self::filter_meta($meta);
			$doc = array(
				'kind'    => $kind,
				'title'   => $title,
				'status'  => $status,
				'parent'  => $parent_slug !== null && $parent_slug !== '' ? $parent_slug : null,
				'content' => \str_replace("\r\n", "\n", $content),
				'meta'    => $meta,
			);
		}
		return \hash('sha256', \json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** Recursively sort associative arrays so json_encode is order-independent. */
	public static function canonical($value) {
		if (!\is_array($value)) {
			return $value;
		}
		$is_list = \array_keys($value) === \range(0, \count($value) - 1);
		$out = array();
		foreach ($value as $k => $v) {
			$out[$k] = self::canonical($v);
		}
		if (!$is_list) {
			\ksort($out, SORT_STRING);
		}
		return $out;
	}

	/** Wire shape sent to the target (never includes the local id). */
	public function to_payload(): array {
		$p = array(
			'kind'         => $this->kind,
			'post_type'    => $this->post_type,
			'slug'         => $this->slug,
			'title'        => $this->title,
			'status'       => $this->status,
			'parent_slug'  => $this->parent_slug,
			'content'      => $this->content,
			'meta'         => $this->meta,
			'meta_skipped' => $this->meta_skipped,
			'hash'         => $this->hash(),
		);
		if ($this->kind === 'acf') {
			$p['group'] = $this->acf_group;
		}
		return $p;
	}

	public static function from_payload(array $p): Code_Item {
		$kind = (string) ($p['kind'] ?? '');
		if (!\in_array($kind, self::KINDS, true)) {
			throw new \InvalidArgumentException('Unknown code item kind: ' . $kind);
		}
		$item = new self($kind, (string) ($p['post_type'] ?? ''), (string) ($p['slug'] ?? ''));
		$item->title        = (string) ($p['title'] ?? '');
		$item->status       = (string) ($p['status'] ?? 'publish');
		$item->parent_slug  = isset($p['parent_slug']) && $p['parent_slug'] !== '' ? (string) $p['parent_slug'] : null;
		$item->content      = (string) ($p['content'] ?? '');
		$item->meta         = self::filter_meta(\is_array($p['meta'] ?? null) ? $p['meta'] : array());
		$item->meta_skipped = !empty($p['meta_skipped']);
		$item->acf_group    = isset($p['group']) && \is_array($p['group']) ? $p['group'] : null;
		return $item;
	}

	/**
	 * Build the item for an existing post on either site. $meta_skipped
	 * drops the postmeta (the `settings` module of a config collection).
	 */
	public static function from_post(string $kind, \WP_Post $post, bool $meta_skipped = false): Code_Item {
		$item = new self($kind, $post->post_type, $post->post_name);
		$item->id     = (int) $post->ID;
		$item->title  = (string) $post->post_title;
		$item->status = (string) $post->post_status;
		$item->content = (string) $post->post_content;
		if ((int) $post->post_parent > 0) {
			$parent = \get_post((int) $post->post_parent);
			$item->parent_slug = $parent ? (string) $parent->post_name : null;
		}
		$item->meta_skipped = $meta_skipped;
		if (!$meta_skipped) {
			$raw = \get_post_custom((int) $post->ID);
			$item->meta = self::filter_meta(\is_array($raw) ? $raw : array());
		}
		return $item;
	}
}
