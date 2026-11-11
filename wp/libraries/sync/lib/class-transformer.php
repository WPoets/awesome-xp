<?php
/**
 * Block transformer: neutralise on the source site, resolve on the target.
 *
 * Both passes walk the parsed block tree (attributes, innerHTML/innerContent
 * and innerBlocks together) and finish with serialize_blocks(). No regex is
 * ever run over the raw serialised string.
 *
 * Tokens (a token never looks like an id, so Dev 5 is never mistaken for Prod 5):
 *   awx-media://<uuid>            attachment id
 *   awx-media://<uuid>/<size>     attachment URL at a size ('full', 'large', ...)
 *   awx-post://<uuid>             post id, or its permalink in a string context
 *   awx-block://<uuid>            reusable block (wp_block) id
 *   awx-term://<taxonomy>/<slug>  term id
 *   awx-site://<path>             any other own-site URL -> same path on the target
 */
namespace aw2\sync;

class Transformer {

	const T_MEDIA = 'awx-media://';
	const T_POST  = 'awx-post://';
	const T_TERM  = 'awx-term://';
	const T_BLOCK = 'awx-block://';
	const T_SITE  = 'awx-site://';

	const UUID_RE  = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
	const TOKEN_RE = '#awx-(media|post|block|term|site)://([^\s"\'<>\\\\)\]]*)#';

	/** @var Site_Resolver */
	private $site;
	/** @var Reference_Registry */
	private $registry;
	/** @var string 'neutralise'|'resolve' */
	private $mode = 'neutralise';
	/** @var Transform_Result */
	private $result;
	/** @var string */
	private $block_name = '';
	/** @var array<string,array> term catalogue available while resolving ('taxonomy:slug' => info) */
	private $term_catalog = array();
	/** @var string|null */
	private $url_regex = null;

	public function __construct(Site_Resolver $site, Reference_Registry $registry) {
		$this->site     = $site;
		$this->registry = $registry;
		$this->result   = new Transform_Result();
	}

	/* ================================================================== */
	/* Public API                                                          */
	/* ================================================================== */

	/** Source side: every reference becomes a token; refs are collected. */
	public function neutralise(string $content): Transform_Result {
		return $this->run($content, 'neutralise', array());
	}

	/**
	 * Target side: every token becomes a local id or URL.
	 *
	 * @param array $terms Term catalogue from the payload ('taxonomy:slug' => ['taxonomy','slug','name','parent']),
	 *                     used to create terms that do not exist yet.
	 */
	public function resolve(string $content, array $terms = array()): Transform_Result {
		return $this->run($content, 'resolve', $terms);
	}

	/** Tokens still present in a string (the saved content must have none). */
	public static function leftover_tokens(string $content): array {
		$found = array();
		if (\preg_match_all(self::TOKEN_RE, $content, $m)) {
			$found = \array_values(\array_unique($m[0]));
		}
		if (\preg_match_all('#wp-image-awx-media-(' . self::UUID_RE . ')#', $content, $m2)) {
			foreach ($m2[0] as $t) {
				$found[] = $t;
			}
		}
		return \array_values(\array_unique($found));
	}

	/** Does the content still mention one of the given host names? */
	public static function contains_host(string $content, array $hosts): bool {
		foreach ($hosts as $host) {
			if ($host !== '' && false !== \stripos($content, '//' . $host)) {
				return true;
			}
		}
		return false;
	}

	/* ================================================================== */
	/* Tree walk                                                           */
	/* ================================================================== */

	private function run(string $content, string $mode, array $terms): Transform_Result {
		$this->mode         = $mode;
		$this->term_catalog = $terms;
		$this->result       = new Transform_Result();
		$this->url_regex    = null;

		$blocks = Block_Serializer::parse($content);
		$blocks = $this->walk_blocks($blocks);
		$out    = Block_Serializer::serialize($blocks);

		if ($mode === 'neutralise') {
			$this->scan_shortcodes($content);
		}

		$this->result->content = $out;
		$this->result->changed = ($out !== $content);
		return $this->result;
	}

	private function walk_blocks(array $blocks): array {
		foreach ($blocks as $i => $block) {
			if (\is_array($block)) {
				$blocks[$i] = $this->walk_block($block);
			}
		}
		return $blocks;
	}

	private function walk_block(array $block): array {
		$name  = isset($block['blockName']) ? $block['blockName'] : null;
		$prev  = $this->block_name;
		$this->block_name = $name ?: 'core/freeform';
		$entry = $this->registry->entry_for($name);
		$attrs = isset($block['attrs']) && \is_array($block['attrs']) ? $block['attrs'] : array();

		if ($name !== null && 0 === \strpos($name, 'dgb/')) {
			$slug = \substr($name, 4);
			$this->result->refs['dgb'][$slug] = true;
			if ($entry === null) {
				$this->warn('dgb_block_unknown', "Block {$name} is not defined on this site; only URLs inside its attributes were rewritten.");
			}
			foreach (array('content.post_type', 'post_type') as $k) {
				if (!empty($attrs[$k]) && \is_string($attrs[$k])) {
					$this->result->refs['post_types'][$attrs[$k]] = true;
				}
			}
		}

		if ($entry !== null) {
			$this->emit_entry_warnings($name, $entry, $attrs);
			$attrs = $this->apply_attr_descriptors($attrs, $entry['attrs']);
			$attrs = $this->apply_field_rules($attrs, $entry['fields']);
		} elseif ($name !== null) {
			$this->warn_unregistered($name, $attrs);
		}

		if ($name !== null && 0 === \strpos($name, 'acf/')) {
			$this->warn_acf($name, $attrs);
		}

		$attrs = $this->walk_generic($attrs);
		$block['attrs'] = $attrs;

		$html_rules = $entry !== null ? $entry['html'] : array();
		if (isset($block['innerHTML']) && \is_string($block['innerHTML'])) {
			$block['innerHTML'] = $this->transform_html($block['innerHTML'], $html_rules);
		}
		if (!empty($block['innerContent']) && \is_array($block['innerContent'])) {
			foreach ($block['innerContent'] as $k => $chunk) {
				if (\is_string($chunk)) {
					$block['innerContent'][$k] = $this->transform_html($chunk, $html_rules);
				}
			}
		}
		if (!empty($block['innerBlocks']) && \is_array($block['innerBlocks'])) {
			$block['innerBlocks'] = $this->walk_blocks($block['innerBlocks']);
		}

		$this->block_name = $prev;
		return $block;
	}

	private function emit_entry_warnings(?string $name, array $entry, array $attrs): void {
		if (empty($entry['warn'])) {
			return;
		}
		if ($name === 'core/query' && empty($attrs['query']['taxQuery'])) {
			return;
		}
		if ($name === 'core/navigation' && !isset($attrs['ref'])) {
			return;
		}
		foreach ($entry['warn'] as $code => $message) {
			$this->warn((string) $code, (string) $message);
		}
	}

	private function warn_unregistered(string $name, array $attrs): void {
		$hits = array();
		$this->find_id_like($attrs, '', $hits);
		if (!empty($hits)) {
			$this->warn('unregistered_id_attribute', "Block {$name} is not in the reference registry but has id-like attributes (" . \implode(', ', $hits) . "); they are copied unchanged.");
		}
	}

	private function find_id_like($value, string $path, array &$hits): void {
		if (!\is_array($value)) {
			return;
		}
		foreach ($value as $k => $v) {
			$p = $path === '' ? (string) $k : $path . '.' . $k;
			if (\is_array($v)) {
				$this->find_id_like($v, $p, $hits);
			} elseif (\is_string($k) && (\is_int($v) || (\is_string($v) && \ctype_digit($v))) && (int) $v > 0 && \preg_match('/(^id$|Id$|_id$|^ids$|Ids$|_ids$|^ref$)/', $k)) {
				$hits[] = $p;
			}
		}
	}

	private function warn_acf(string $name, array $attrs): void {
		if (empty($attrs['data']) || !\is_array($attrs['data'])) {
			return;
		}
		foreach ($attrs['data'] as $k => $v) {
			if (\is_string($k) && $k !== '' && $k[0] === '_') {
				continue;
			}
			$numeric = (\is_int($v) && $v > 0) || (\is_string($v) && \ctype_digit($v) && (int) $v > 0);
			if ($numeric || (\is_array($v) && !empty($v) && \count(\array_filter($v, 'is_numeric')) === \count($v))) {
				$this->warn('acf_block_unverified', "ACF block {$name} holds numeric field values that may be attachment or post ids; they are copied unchanged.");
				return;
			}
		}
	}

	/* ================================================================== */
	/* Attribute descriptors (static entries)                              */
	/* ================================================================== */

	private function apply_attr_descriptors(array $attrs, array $descriptors): array {
		foreach ($descriptors as $key => $desc) {
			if (!\array_key_exists($key, $attrs)) {
				continue;
			}
			$attrs[$key] = $this->apply_descriptor($attrs[$key], $desc, (string) $key);
		}
		return $attrs;
	}

	private function apply_descriptor($value, array $desc, string $path) {
		$type = $desc['type'];
		$list = !empty($desc['list']);
		$tax  = isset($desc['taxonomy']) ? $desc['taxonomy'] : null;

		if ($list) {
			if (!\is_array($value)) {
				return $value;
			}
			foreach ($value as $i => $v) {
				$value[$i] = $this->apply_descriptor($v, array('type' => $type, 'list' => false, 'taxonomy' => $tax), $path . '.' . $i);
			}
			return $value;
		}

		switch ($type) {
			case 'media':
				return $this->media_id($value);
			case 'media_object':
				return $this->media_object($value);
			case 'media_url':
				return $this->media_url_value($value);
			case 'post':
				return $this->post_id($value);
			case 'post_object':
				return $this->post_object($value);
			case 'term':
				return $this->term_id($value, $tax);
			case 'term_objects':
				return $this->term_objects($value, $tax);
			case 'block':
				return $this->block_id($value);
		}
		return $value;
	}

	/* ================================================================== */
	/* dgb field rules                                                     */
	/* ================================================================== */

	private function apply_field_rules(array $attrs, array $rules): array {
		foreach ($rules as $rule) {
			$paths = array(array($rule['attr']), array('_data', $rule['attr']));
			if ($rule['name'] !== '' && $rule['name'] !== $rule['attr']) {
				$paths[] = array('_data', $rule['name']);
			}
			if (false !== \strpos($rule['attr'], '.')) {
				$paths[] = \explode('.', $rule['attr']);
			}
			foreach ($paths as $path) {
				if (!self::path_exists($attrs, $path)) {
					continue;
				}
				$v  = self::path_get($attrs, $path);
				$nv = $this->apply_field($v, $rule);
				if ($nv !== $v) {
					self::path_set($attrs, $path, $nv);
				}
			}
		}
		return $attrs;
	}

	private function apply_field($value, array $rule) {
		switch ($rule['type']) {
			case 'image':
			case 'media':
			case 'file':
				return $this->media_object($value);
			case 'post-select':
				return $this->post_object($value);
			case 'filtered-post-select':
				return $this->filtered_post($value, $rule);
			case 'taxonomy-select':
				return $this->term_objects($value, $rule['taxonomy']);
			case 'row_repeater':
			case 'repeater':
			case 'custom-repeater':
			case 'attributes-repeater':
				if (!\is_array($value)) {
					return $value;
				}
				foreach ($value as $i => $row) {
					if (!\is_array($row)) {
						continue;
					}
					foreach ($rule['sub'] as $name => $sub) {
						if (\array_key_exists($name, $row)) {
							$row[$name] = $this->apply_field($row[$name], $sub);
						}
					}
					$value[$i] = $row;
				}
				return $value;
			default:
				// A bare number in an attribute named like a URL or video is an attachment id (see Block_Library::self_heal_data_attributes).
				if ((self::is_id($value) || self::is_bare_media_token($value))
					&& (false !== \stripos($rule['attr'], 'url') || false !== \stripos($rule['attr'], 'video'))) {
					return $this->media_id($value);
				}
				return $value;
		}
	}

	private function filtered_post($value, array $rule) {
		if (!\is_array($value)) {
			return $value;
		}
		if (isset($value['post'])) {
			$value['post'] = $this->post_object($value['post']);
		}
		if (isset($value['posts']) && \is_array($value['posts'])) {
			$value['posts'] = $this->post_object($value['posts']);
		}
		if (isset($value['filters']) && \is_array($value['filters'])) {
			foreach ($value['filters'] as $k => $v) {
				$tax = $rule['taxonomy'] ?: self::guess_taxonomy((string) $k);
				if (\is_array($v)) {
					foreach ($v as $i => $vv) {
						$v[$i] = $this->term_id($vv, $tax);
					}
					$value['filters'][$k] = $v;
				} else {
					$value['filters'][$k] = $this->term_id($v, $tax);
				}
			}
		}
		return $value;
	}

	private static function guess_taxonomy(string $key): string {
		$map = array('category' => 'category', 'categories' => 'category', 'cat' => 'category', 'tag' => 'post_tag', 'tags' => 'post_tag', 'post_tag' => 'post_tag');
		return isset($map[$key]) ? $map[$key] : $key;
	}

	/* ================================================================== */
	/* Value transforms (mode aware)                                       */
	/* ================================================================== */

	private static function is_id($value): bool {
		return (\is_int($value) && $value > 0) || (\is_string($value) && \ctype_digit($value) && (int) $value > 0);
	}

	private static function is_list(array $a): bool {
		return $a === array() || \array_keys($a) === \range(0, \count($a) - 1);
	}

	private static function token_body(string $value, string $prefix): ?string {
		return 0 === \strpos($value, $prefix) ? \substr($value, \strlen($prefix)) : null;
	}

	/** integer attachment id <-> awx-media://uuid */
	private function media_id($value) {
		if ($this->mode === 'neutralise') {
			if (!self::is_id($value)) {
				return $value;
			}
			$id   = (int) $value;
			$uuid = $this->site->media_uuid($id);
			if ($uuid === null) {
				$this->warn('media_missing', "Attachment #{$id} used by {$this->block_name} does not exist on this site; the id is copied unchanged.");
				return $value;
			}
			$this->result->refs['media'][$id] = $uuid;
			return self::T_MEDIA . $uuid;
		}
		if (\is_string($value) && null !== ($body = self::token_body($value, self::T_MEDIA))) {
			$uuid = \explode('/', $body)[0];
			$id   = $this->site->media_id_by_uuid($uuid);
			if ($id === null) {
				$this->result->miss('media', $uuid, $this->block_name);
				return $value;
			}
			return $id;
		}
		return $value;
	}

	/** {id,url,alt,title} | id | url | list of these */
	private function media_object($value) {
		if (\is_array($value)) {
			if (self::is_list($value)) {
				foreach ($value as $i => $v) {
					$value[$i] = $this->media_object($v);
				}
				return $value;
			}
			$id_known = null;
			if (\array_key_exists('id', $value)) {
				if ($this->mode === 'neutralise' && self::is_id($value['id'])) {
					$id_known = (int) $value['id'];
				}
				$value['id'] = $this->media_id($value['id']);
				if ($this->mode === 'resolve' && \is_int($value['id'])) {
					$id_known = $value['id'];
				}
			}
			if (isset($value['url']) && \is_string($value['url']) && $value['url'] !== '') {
				$value['url'] = $this->media_url_for($value['url'], $id_known);
			}
			return $value;
		}
		if (self::is_id($value) || self::is_bare_media_token($value)) {
			return $this->media_id($value); // bare id (or its token) -> stays an id
		}
		if (\is_string($value)) {
			return $this->mode === 'neutralise' ? $this->neutralise_string($value) : $this->media_url_for($value, null);
		}
		return $value;
	}

	/** awx-media://<uuid> with no size part stands for an attachment id, not a URL. */
	private static function is_bare_media_token($value): bool {
		return \is_string($value) && 0 === \strpos($value, self::T_MEDIA) && false === \strpos(\substr($value, \strlen(self::T_MEDIA)), '/');
	}

	/** URL of an attachment whose id is known (neutralise) or token (resolve). */
	private function media_url_for(string $url, ?int $id) {
		if ($this->mode === 'neutralise') {
			if (0 === \strpos($url, 'awx-')) {
				return $url;
			}
			if ($id !== null) {
				$uuid = $this->site->media_uuid($id);
				if ($uuid !== null) {
					$this->result->refs['media'][$id] = $uuid;
					return self::T_MEDIA . $uuid . '/' . $this->site->media_size_for_url($id, $url);
				}
			}
			return $this->neutralise_string($url);
		}
		return $this->resolve_string($url);
	}

	/** media_url descriptor: numeric id or URL string. */
	private function media_url_value($value) {
		if (self::is_id($value) || self::is_bare_media_token($value)) {
			return $this->media_id($value);
		}
		if (\is_string($value)) {
			return $this->media_url_for($value, null);
		}
		return $value;
	}

	/** integer post id <-> awx-post://uuid */
	private function post_id($value) {
		if ($this->mode === 'neutralise') {
			if (!self::is_id($value)) {
				return $value;
			}
			$id   = (int) $value;
			$uuid = $this->site->post_uuid($id);
			if ($uuid === null) {
				$this->warn('post_missing', "Post #{$id} referenced by {$this->block_name} does not exist on this site; the id is copied unchanged.");
				return $value;
			}
			$this->result->refs['posts'][$id] = $uuid;
			return self::T_POST . $uuid;
		}
		if (\is_string($value) && null !== ($uuid = self::token_body($value, self::T_POST))) {
			$id = $this->site->post_id_by_uuid($uuid);
			if ($id === null) {
				$this->result->miss('post', $uuid, $this->block_name);
				return $value;
			}
			return $id;
		}
		return $value;
	}

	/** {id,title,url} | list */
	private function post_object($value) {
		if (!\is_array($value)) {
			return self::is_id($value) ? $this->post_id($value) : $value;
		}
		if (self::is_list($value)) {
			foreach ($value as $i => $v) {
				$value[$i] = $this->post_object($v);
			}
			return $value;
		}
		$id_known = null;
		if (\array_key_exists('id', $value)) {
			if ($this->mode === 'neutralise' && self::is_id($value['id'])) {
				$id_known = (int) $value['id'];
			}
			$value['id'] = $this->post_id($value['id']);
		}
		if (isset($value['url']) && \is_string($value['url']) && $value['url'] !== '') {
			if ($this->mode === 'neutralise') {
				if ($id_known !== null && isset($this->result->refs['posts'][$id_known])) {
					$value['url'] = self::T_POST . $this->result->refs['posts'][$id_known];
				} else {
					$value['url'] = $this->neutralise_string($value['url']);
				}
			} else {
				$value['url'] = $this->resolve_string($value['url']);
			}
		}
		return $value;
	}

	/** integer term id <-> awx-term://taxonomy/slug */
	private function term_id($value, ?string $taxonomy) {
		if ($this->mode === 'neutralise') {
			if (!self::is_id($value)) {
				return $value;
			}
			$id   = (int) $value;
			$info = $this->site->term_info($id, $taxonomy);
			if ($info === null) {
				$this->warn('term_missing', "Term #{$id} referenced by {$this->block_name} does not exist on this site; the id is copied unchanged.");
				return $value;
			}
			$key = $info['taxonomy'] . ':' . $info['slug'];
			$this->result->refs['terms'][$key] = $info;
			return self::T_TERM . $info['taxonomy'] . '/' . $info['slug'];
		}
		if (\is_string($value) && null !== ($body = self::token_body($value, self::T_TERM))) {
			$parts = \explode('/', $body, 2);
			if (\count($parts) !== 2) {
				return $value;
			}
			list($tax, $slug) = $parts;
			$id = $this->site->term_id_by_slug($tax, $slug);
			if ($id === null && isset($this->term_catalog[$tax . ':' . $slug])) {
				$id = $this->site->ensure_term($this->term_catalog[$tax . ':' . $slug]);
			}
			if ($id === null) {
				$this->result->miss('term', $tax . ':' . $slug, $this->block_name);
				return $value;
			}
			return $id;
		}
		return $value;
	}

	/** list of {id,name,slug} (or a single one) */
	private function term_objects($value, ?string $taxonomy) {
		if (!\is_array($value)) {
			return self::is_id($value) ? $this->term_id($value, $taxonomy) : $value;
		}
		if (self::is_list($value)) {
			foreach ($value as $i => $v) {
				$value[$i] = $this->term_objects($v, $taxonomy);
			}
			return $value;
		}
		if (\array_key_exists('id', $value)) {
			$tax = $taxonomy;
			if ($tax === null && isset($value['taxonomy']) && \is_string($value['taxonomy'])) {
				$tax = $value['taxonomy'];
			}
			$value['id'] = $this->term_id($value['id'], $tax);
		}
		return $value;
	}

	/** integer wp_block id <-> awx-block://uuid */
	private function block_id($value) {
		if ($this->mode === 'neutralise') {
			if (!self::is_id($value)) {
				return $value;
			}
			$id   = (int) $value;
			$uuid = $this->site->block_uuid($id);
			if ($uuid === null) {
				$this->warn('block_missing', "Reusable block #{$id} referenced by {$this->block_name} does not exist on this site; the id is copied unchanged.");
				return $value;
			}
			$this->result->refs['blocks'][$id] = $uuid;
			return self::T_BLOCK . $uuid;
		}
		if (\is_string($value) && null !== ($uuid = self::token_body($value, self::T_BLOCK))) {
			$id = $this->site->block_id_by_uuid($uuid);
			if ($id === null) {
				$this->result->miss('block', $uuid, $this->block_name);
				return $value;
			}
			return $id;
		}
		return $value;
	}

	/* ================================================================== */
	/* Generic string pass                                                 */
	/* ================================================================== */

	private function walk_generic($value) {
		if (\is_array($value)) {
			foreach ($value as $k => $v) {
				$value[$k] = $this->walk_generic($v);
			}
			return $value;
		}
		if (\is_string($value)) {
			return $this->mode === 'neutralise' ? $this->neutralise_string($value) : $this->resolve_string($value);
		}
		return $value;
	}

	private function own_url_regex(): string {
		if ($this->url_regex === null) {
			$hosts = array();
			foreach ($this->site->own_hosts() as $h) {
				$h = \strtolower(\trim((string) $h));
				if ($h !== '') {
					$hosts[] = \preg_quote($h, '#');
				}
			}
			$hosts = \array_unique($hosts);
			if (empty($hosts)) {
				$this->url_regex = '#(?!x)x#'; // never matches
			} else {
				$this->url_regex = '#(?:https?:)?//(?:' . \implode('|', $hosts) . ')(?::\d+)?(?P<path>/[^\s"\'<>\\\\)\]]*)?#i';
			}
		}
		return $this->url_regex;
	}

	/** Replace own-site URLs in a string with tokens. */
	private function neutralise_string(string $s): string {
		if ($s === '' || false === \strpos($s, '//')) {
			return $s;
		}
		return \preg_replace_callback($this->own_url_regex(), function ($m) {
			$path = isset($m['path']) && $m['path'] !== '' ? $m['path'] : '/';
			return $this->neutralise_url($m[0], $path);
		}, $s);
	}

	private function neutralise_url(string $full, string $path): string {
		$uploads = \rtrim($this->site->uploads_path(), '/');
		if ($uploads !== '' && 0 === \strpos($path, $uploads . '/')) {
			$id = $this->site->media_id_by_url($full);
			if ($id !== null) {
				$uuid = $this->site->media_uuid($id);
				if ($uuid !== null) {
					$this->result->refs['media'][$id] = $uuid;
					return self::T_MEDIA . $uuid . '/' . $this->site->media_size_for_url($id, $full);
				}
			}
			$this->warn('unmapped_upload_url', "Upload URL {$path} matches no attachment; it will point at the same path on the target.", $this->block_name);
			return self::T_SITE . \ltrim($path, '/');
		}
		$pid = $this->site->post_id_by_url($full);
		if ($pid !== null) {
			$uuid = $this->site->post_uuid($pid);
			if ($uuid !== null) {
				$this->result->refs['posts'][$pid] = $uuid;
				return self::T_POST . $uuid;
			}
		}
		if ($path !== '/') {
			$this->warn('unmapped_dev_url', "Link {$path} has no synced counterpart; it will point at the same path on the target.", $this->block_name);
		}
		return self::T_SITE . \ltrim($path, '/');
	}

	/** Replace tokens in a string with target URLs. */
	private function resolve_string(string $s): string {
		if ($s === '' || false === \strpos($s, 'awx-')) {
			return $s;
		}
		return \preg_replace_callback(self::TOKEN_RE, function ($m) {
			$type = $m[1];
			$body = $m[2];
			switch ($type) {
				case 'media':
					$parts = \explode('/', $body, 2);
					$uuid  = $parts[0];
					$size  = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : 'full';
					$id    = $this->site->media_id_by_uuid($uuid);
					if ($id === null) {
						$this->result->miss('media', $uuid, $this->block_name);
						return $m[0];
					}
					$url = $this->site->media_url($id, $size);
					return $url !== null ? $url : $m[0];
				case 'post':
					$id = $this->site->post_id_by_uuid($body);
					if ($id === null) {
						$this->result->miss('post', $body, $this->block_name);
						return $m[0];
					}
					$url = $this->site->post_permalink($id);
					return $url !== null ? $url : $m[0];
				case 'site':
					return $body === '' ? $this->site->home_url() : $this->site->home_url() . '/' . $body;
				case 'block':
				case 'term':
				default:
					return $m[0]; // only meaningful as whole-value ids; handled by descriptors
			}
		}, $s);
	}

	/* ================================================================== */
	/* HTML                                                                */
	/* ================================================================== */

	private function transform_html(string $html, array $rules): string {
		if ($html === '' || \trim($html) === '') {
			return $html;
		}
		$with_data_id = \in_array('data-id', $rules, true);

		if ($this->mode === 'neutralise') {
			$html = \preg_replace_callback('/\bwp-image-(\d+)\b/', function ($m) {
				$id   = (int) $m[1];
				$uuid = $this->site->media_uuid($id);
				if ($uuid === null) {
					$this->warn('media_missing', "Attachment #{$id} (wp-image class in {$this->block_name}) does not exist on this site.");
					return $m[0];
				}
				$this->result->refs['media'][$id] = $uuid;
				return 'wp-image-awx-media-' . $uuid;
			}, $html);
			if ($with_data_id) {
				$html = \preg_replace_callback('/\bdata-id="(\d+)"/', function ($m) {
					$id   = (int) $m[1];
					$uuid = $this->site->media_uuid($id);
					if ($uuid === null) {
						return $m[0];
					}
					$this->result->refs['media'][$id] = $uuid;
					return 'data-id="' . self::T_MEDIA . $uuid . '"';
				}, $html);
			}
			return $this->neutralise_string($html);
		}

		$html = \preg_replace_callback('/wp-image-awx-media-(' . self::UUID_RE . ')/', function ($m) {
			$id = $this->site->media_id_by_uuid($m[1]);
			if ($id === null) {
				$this->result->miss('media', $m[1], $this->block_name);
				return $m[0];
			}
			return 'wp-image-' . $id;
		}, $html);
		$html = \preg_replace_callback('/data-id="awx-media:\/\/(' . self::UUID_RE . ')"/', function ($m) {
			$id = $this->site->media_id_by_uuid($m[1]);
			if ($id === null) {
				$this->result->miss('media', $m[1], $this->block_name);
				return $m[0];
			}
			return 'data-id="' . $id . '"';
		}, $html);
		return $this->resolve_string($html);
	}

	/* ================================================================== */
	/* Shortcodes                                                          */
	/* ================================================================== */

	/** [aw2.module module="x" slug="y" ...] references code by slug; record it for the plan. */
	private function scan_shortcodes(string $content): void {
		if (false === \strpos($content, '[aw2.module')) {
			return;
		}
		if (\preg_match_all('/\[aw2\.module\b([^\]]*)\]/i', $content, $m)) {
			foreach ($m[1] as $attr_str) {
				$slug = null;
				if (\preg_match('/\bmodule=["\']?([^"\'\s\]]+)/i', $attr_str, $mm)) {
					$slug = $mm[1];
				} elseif (\preg_match('/\bslug=["\']?([^"\'\s\]]+)/i', $attr_str, $mm)) {
					$slug = $mm[1];
				}
				if ($slug !== null) {
					$this->result->refs['modules'][$slug] = true;
				}
			}
		}
	}

	/* ================================================================== */
	/* Helpers                                                             */
	/* ================================================================== */

	private function warn(string $code, string $message, ?string $block = null): void {
		$this->result->warn($code, $message, $block ?: $this->block_name);
	}

	public static function path_exists(array $a, array $path): bool {
		$cur = $a;
		foreach ($path as $seg) {
			if (!\is_array($cur) || !\array_key_exists($seg, $cur)) {
				return false;
			}
			$cur = $cur[$seg];
		}
		return true;
	}

	public static function path_get(array $a, array $path) {
		$cur = $a;
		foreach ($path as $seg) {
			$cur = $cur[$seg];
		}
		return $cur;
	}

	public static function path_set(array &$a, array $path, $value): void {
		$ref = &$a;
		foreach ($path as $seg) {
			if (!isset($ref[$seg]) || !\is_array($ref[$seg])) {
				if (!\array_key_exists($seg, $ref)) {
					$ref[$seg] = array();
				}
			}
			$ref = &$ref[$seg];
		}
		$ref = $value;
	}
}
