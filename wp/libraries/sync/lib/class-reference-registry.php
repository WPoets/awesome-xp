<?php
/**
 * Reference registry: tells both sites where references to other objects sit
 * inside each block type.
 *
 * Two kinds of entries:
 *  - Static entries for core blocks and third-party blocks, registered with
 *    register(). Attribute descriptors are short strings:
 *      'media'           integer attachment id
 *      'media[]'         list of attachment ids
 *      'media_object'    {id,url,alt,title} | id | url (also lists of these)
 *      'post'            integer post id
 *      'post_object'     {id,title,url} (also lists)
 *      'term'            integer term id (taxonomy via ':taxonomy', e.g. 'term:category')
 *      'term_objects'    list of {id,name,slug} (taxonomy via ':taxonomy')
 *      'block'           wp_block post id (core/block ref)
 *      'media_url'       string that may be a numeric attachment id or a URL
 *    URLs in plain strings need no declaration: every string attribute and
 *    the inner HTML of every block is scanned for own-site URLs.
 *  - Dynamic entries for dgb/* blocks, derived from the compiled AWX block
 *    registry (field types image/file/post-select/filtered-post-select/
 *    taxonomy-select/row_repeater) at run time, so block authors never have
 *    to register anything.
 *
 * hash() feeds the handshake so both sites can prove they agree on the rules.
 */
namespace aw2\sync;

class Reference_Registry {

	/** @var Reference_Registry|null */
	private static $instance = null;

	/** @var array<string,array> normalised static entries by block name */
	private $entries = array();

	/** @var array<string,array|null> cache of dgb entries by slug */
	private $dgb_cache = array();

	/** @var callable|null returns the dgb editor registry (slug => config) */
	private $dgb_loader = null;

	public static function instance(): Reference_Registry {
		if (self::$instance === null) {
			self::$instance = new self();
			self::$instance->register_core_entries();
		}
		return self::$instance;
	}

	/** Fresh instance for tests. */
	public static function create(?callable $dgb_loader = null): Reference_Registry {
		$r = new self();
		$r->register_core_entries();
		$r->dgb_loader = $dgb_loader;
		return $r;
	}

	public function set_dgb_loader(callable $loader): void {
		$this->dgb_loader = $loader;
		$this->dgb_cache  = array();
	}

	/* ------------------------------------------------------------------ */

	public function register(string $block_name, array $entry): void {
		$this->entries[$block_name] = $this->normalise_entry($entry);
	}

	public function has(string $block_name): bool {
		return isset($this->entries[$block_name]) || 0 === \strpos($block_name, 'dgb/');
	}

	/**
	 * Entry for a block, or null when the block is unknown to the registry.
	 *
	 * @return array|null ['attrs' => [key => descriptor], 'fields' => [...dgb field rules], 'html' => [...], 'warn' => [code => message]]
	 */
	public function entry_for(?string $block_name): ?array {
		if ($block_name === null || $block_name === '') {
			return $this->entries['core/freeform'];
		}
		if (isset($this->entries[$block_name])) {
			return $this->entries[$block_name];
		}
		if (0 === \strpos($block_name, 'dgb/')) {
			return $this->dgb_entry(\substr($block_name, 4));
		}
		return null;
	}

	/** Static entries only (the dgb part is site data, not rules). */
	public function all(): array {
		return $this->entries;
	}

	/** Stable hash of the static rules, compared in the handshake. */
	public function hash(): string {
		$entries = $this->entries;
		\ksort($entries);
		return \hash('sha256', \json_encode($entries));
	}

	/* ------------------------------------------------------------------ */

	private function normalise_entry(array $entry): array {
		$out = array('attrs' => array(), 'fields' => array(), 'html' => array(), 'warn' => array());
		if (!empty($entry['attrs']) && \is_array($entry['attrs'])) {
			foreach ($entry['attrs'] as $key => $desc) {
				$out['attrs'][$key] = \is_array($desc) ? $desc : self::parse_descriptor((string) $desc);
			}
		}
		if (!empty($entry['html']) && \is_array($entry['html'])) {
			$out['html'] = \array_values($entry['html']);
		}
		if (!empty($entry['warn']) && \is_array($entry['warn'])) {
			$out['warn'] = $entry['warn'];
		}
		if (!empty($entry['fields']) && \is_array($entry['fields'])) {
			$out['fields'] = $entry['fields'];
		}
		return $out;
	}

	/**
	 * 'media[]' => ['type' => 'media', 'list' => true]
	 * 'term_objects:category' => ['type' => 'term_objects', 'list' => false, 'taxonomy' => 'category']
	 */
	public static function parse_descriptor(string $desc): array {
		$list = false;
		$taxonomy = null;
		if (\substr($desc, -2) === '[]') {
			$list = true;
			$desc = \substr($desc, 0, -2);
		}
		if (false !== \strpos($desc, ':')) {
			list($desc, $taxonomy) = \explode(':', $desc, 2);
		}
		return array('type' => $desc, 'list' => $list, 'taxonomy' => $taxonomy);
	}

	/* ------------------------------------------------------------------ */

	private function register_core_entries(): void {
		$core = array(
			'core/freeform'        => array('attrs' => array()),
			'core/html'            => array('attrs' => array()),
			'core/image'           => array('attrs' => array('id' => 'media'), 'html' => array('wp-image', 'data-id')),
			'core/gallery'         => array('attrs' => array('ids' => 'media[]', 'images' => 'media_object[]'), 'html' => array('wp-image', 'data-id')),
			'core/cover'           => array('attrs' => array('id' => 'media'), 'html' => array('wp-image')),
			'core/media-text'      => array('attrs' => array('mediaId' => 'media'), 'html' => array('wp-image')),
			'core/file'            => array('attrs' => array('id' => 'media')),
			'core/video'           => array('attrs' => array('id' => 'media')),
			'core/audio'           => array('attrs' => array('id' => 'media')),
			'core/post-featured-image' => array('attrs' => array()),
			'core/block'           => array('attrs' => array('ref' => 'block')),
			'core/navigation-link' => array('attrs' => array('id' => 'post')),
			'core/latest-posts'    => array('attrs' => array('categories' => 'term_objects:category')),
			'core/button'          => array('attrs' => array()),
			'core/buttons'         => array('attrs' => array()),
			'core/embed'           => array('attrs' => array()),
			'core/navigation'      => array(
				'attrs' => array(),
				'warn'  => array('navigation_ref_unsupported' => 'core/navigation menus are not synced; the target keeps its own menu.'),
			),
			'core/query'           => array(
				'attrs' => array(),
				'warn'  => array('query_tax_unsupported' => 'core/query taxonomy filters are copied as-is; term ids may differ on the target.'),
			),
			'core/paragraph'       => array('attrs' => array()),
			'core/heading'         => array('attrs' => array()),
			'core/list'            => array('attrs' => array()),
			'core/list-item'       => array('attrs' => array()),
			'core/quote'           => array('attrs' => array()),
			'core/group'           => array('attrs' => array()),
			'core/columns'         => array('attrs' => array()),
			'core/column'          => array('attrs' => array()),
			'core/spacer'          => array('attrs' => array()),
			'core/separator'       => array('attrs' => array()),
			'core/table'           => array('attrs' => array()),
			'core/code'            => array('attrs' => array()),
			'core/preformatted'    => array('attrs' => array()),
			'core/details'         => array('attrs' => array()),
			'core/shortcode'       => array('attrs' => array()),
			'core/pattern'         => array('attrs' => array()),
			'core/missing'         => array('attrs' => array()),
		);
		foreach ($core as $name => $entry) {
			$this->register($name, $entry);
		}
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Build the entry for a dgb block from its compiled field definitions.
	 *
	 * Result 'fields' is a flat list of rules:
	 *   ['attr' => 'content.image', 'name' => 'image', 'type' => 'image', 'taxonomy' => ?, 'multiple' => bool, 'sub' => [name => rule]]
	 */
	private function dgb_entry(string $slug): ?array {
		if (\array_key_exists($slug, $this->dgb_cache)) {
			return $this->dgb_cache[$slug];
		}
		$registry = $this->load_dgb_registry();
		if (!isset($registry[$slug])) {
			$this->dgb_cache[$slug] = null;
			return null;
		}
		$config = $registry[$slug];
		$fields = isset($config['fields']) && \is_array($config['fields']) ? $config['fields'] : array();
		if (!empty($config['tabs']) && \is_array($config['tabs'])) {
			foreach ($config['tabs'] as $tab) {
				if (!empty($tab['fields']) && \is_array($tab['fields'])) {
					$fields = \array_merge($fields, $tab['fields']);
				}
			}
		}
		$rules = array();
		foreach ($fields as $f) {
			$rule = self::field_rule($f);
			if ($rule !== null) {
				$rules[] = $rule;
			}
		}
		$entry = array('attrs' => array(), 'fields' => $rules, 'html' => array(), 'warn' => array());
		$this->dgb_cache[$slug] = $entry;
		return $entry;
	}

	public static function field_rule(array $f): ?array {
		$name = isset($f['name']) ? (string) $f['name'] : '';
		$attr = isset($f['attr_name']) && $f['attr_name'] !== '' ? (string) $f['attr_name'] : $name;
		$type = isset($f['type']) ? (string) $f['type'] : 'text';
		if ($attr === '' || $type === 'innerblocks') {
			return null;
		}
		$rule = array(
			'attr'     => $attr,
			'name'     => $name,
			'type'     => $type,
			'taxonomy' => isset($f['taxonomy']) ? (string) $f['taxonomy'] : null,
			'multiple' => !empty($f['multiple']),
			'sub'      => array(),
		);
		if (\in_array($type, array('row_repeater', 'repeater', 'custom-repeater', 'attributes-repeater'), true)) {
			$subs = isset($f['repeater_fields']) && \is_array($f['repeater_fields']) ? $f['repeater_fields'] : (isset($f['fields']) && \is_array($f['fields']) ? $f['fields'] : array());
			foreach ($subs as $sf) {
				$sr = self::field_rule($sf);
				if ($sr !== null && $sr['name'] !== '') {
					$rule['sub'][$sr['name']] = $sr;
				}
			}
		}
		return $rule;
	}

	private function load_dgb_registry(): array {
		if ($this->dgb_loader !== null) {
			$r = \call_user_func($this->dgb_loader);
			return \is_array($r) ? $r : array();
		}
		if (\function_exists('aw2\gutenberg_blocks\dgb')) {
			$dgb = \aw2\gutenberg_blocks\dgb();
			if ($dgb && \method_exists($dgb, 'load_registry')) {
				$r = $dgb->load_registry(true); // editor registry carries fields and tabs
				return \is_array($r) ? $r : array();
			}
		}
		return array();
	}
}
