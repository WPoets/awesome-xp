<?php
/**
 * Source-side catalog of deployable code: what the "Deploy Code" tree shows
 * and how a selection becomes the list of Code_Items for a run.
 *
 * Mirrors what Tools > Export Awesome Apps offers: Core, each service post
 * type, each app with its config/modules (and pages/posts as content),
 * block definitions, ACF (registration module + field groups) and managed
 * patterns.
 */
namespace aw2\sync;

class Code_Catalog {

	const META_LAST_SYNC = '_awx_code_last_sync';
	const ACF_MODULE     = 'gutenberg-blocks';

	/* ------------------------------------------------------------------ */
	/* Post types                                                          */
	/* ------------------------------------------------------------------ */

	public static function core_post_type(): string {
		return \defined('AWESOME_CORE_POST_TYPE') ? (string) AWESOME_CORE_POST_TYPE : 'awesome_core';
	}

	public static function apps_post_type(): string {
		return \defined('AWESOME_APPS_POST_TYPE') ? (string) AWESOME_APPS_POST_TYPE : 'aw2_app';
	}

	public static function dgb_post_type(): string {
		return 'aw_gb_blocks';
	}

	/** [post_type => label] of every service handler that stores modules in posts. */
	public static function service_post_types(): array {
		$out = array();
		if (!\class_exists('\aw2_library')) {
			return $out;
		}
		$handlers = &\aw2_library::get_array_ref('handlers');
		foreach ((array) $handlers as $key => $h) {
			if (!\is_array($h) || empty($h['post_type']) || $h['post_type'] === self::core_post_type()) {
				continue;
			}
			$is_service = (isset($h['service']) && \strtolower((string) $h['service']) === 'yes') || (isset($h['@service']) && $h['@service'] === true);
			if (!$is_service) {
				continue;
			}
			$out[(string) $h['post_type']] = (string) ($h['service_label'] ?? $key);
		}
		\ksort($out);
		return $out;
	}

	/** [slug => ['name','post_id','collections' => ['config'=>pt,'modules'=>pt,'pages'=>pt,'posts'=>pt]]] */
	public static function apps(): array {
		$out = array();
		if (!\class_exists('\aw2_library')) {
			return $out;
		}
		$apps = &\aw2_library::get_array_ref('apps');
		foreach ((array) $apps as $slug => $app) {
			$cols = array();
			foreach (array('config', 'modules', 'pages', 'posts') as $c) {
				if (!empty($app['collection'][$c]['post_type'])) {
					$cols[$c] = (string) $app['collection'][$c]['post_type'];
				}
			}
			$out[(string) $slug] = array(
				'slug'        => (string) $slug,
				'name'        => (string) ($app['name'] ?? $slug),
				'post_id'     => (int) ($app['post_id'] ?? 0),
				'collections' => $cols,
			);
		}
		\ksort($out);
		return $out;
	}

	/** Post types of every app's pages/posts collections: content, synced through the page pipeline. */
	public static function content_post_types(): array {
		$out = array();
		foreach (self::apps() as $app) {
			foreach (array('pages', 'posts') as $c) {
				if (!empty($app['collections'][$c])) {
					$out[] = $app['collections'][$c];
				}
			}
		}
		return \array_values(\array_unique($out));
	}

	public static function acf_active(): bool {
		return \function_exists('acf_get_field_groups') && \function_exists('acf_prepare_field_group_for_export');
	}

	/* ------------------------------------------------------------------ */
	/* Tree                                                                */
	/* ------------------------------------------------------------------ */

	/** The full tree the Deploy Code screen renders. */
	public function build(): array {
		$core_pt = self::core_post_type();
		$tree = array(
			'core'     => array('post_type' => $core_pt, 'label' => 'Core', 'modules' => $this->modules('core', $core_pt)),
			'services' => array(),
			'apps'     => array(),
			'blocks'   => array(
				'dgb' => array('post_type' => self::dgb_post_type(), 'label' => 'Gutenberg blocks', 'modules' => $this->modules('dgb', self::dgb_post_type())),
				'acf' => array('active' => self::acf_active(), 'module' => null, 'groups' => array()),
			),
			'patterns' => $this->patterns(),
			'generated_at' => \time(),
		);
		foreach (self::service_post_types() as $pt => $label) {
			$tree['services'][] = array('post_type' => $pt, 'label' => $label, 'modules' => $this->modules('service', $pt));
		}
		foreach (self::apps() as $slug => $app) {
			$node = array('slug' => $slug, 'name' => $app['name'], 'post_id' => $app['post_id'], 'definition' => null, 'collections' => array());
			$def = $app['post_id'] ? \get_post($app['post_id']) : null;
			if ($def) {
				$node['definition'] = $this->row(Code_Item::from_post('app', $def));
			}
			foreach ($app['collections'] as $c => $pt) {
				if ($c === 'config' || $c === 'modules') {
					$node['collections'][$c] = array('post_type' => $pt, 'modules' => $this->modules($c, $pt));
				} else {
					$node['collections'][$c] = array('post_type' => $pt, 'count' => $this->count_content($pt));
				}
			}
			$tree['apps'][] = $node;
		}
		// ACF: the registration module lives in core; the field groups in ACF.
		$acf_module = \get_page_by_path(self::ACF_MODULE, OBJECT, $core_pt);
		if ($acf_module) {
			$tree['blocks']['acf']['module'] = $this->row(Code_Item::from_post('core', $acf_module));
		}
		if (self::acf_active()) {
			foreach ($this->acf_items() as $item) {
				$tree['blocks']['acf']['groups'][] = $this->row($item);
			}
		}
		return $tree;
	}

	/** Rows for every published post of a code post type, parents first. */
	private function modules(string $kind, string $post_type): array {
		$rows = array();
		foreach ($this->items_of($kind, $post_type) as $item) {
			$rows[] = $this->row($item);
		}
		return $rows;
	}

	private function row(Code_Item $item): array {
		$r = array(
			'kind'        => $item->kind,
			'post_type'   => $item->post_type,
			'slug'        => $item->slug,
			'key'         => $item->key(),
			'title'       => $item->title,
			'id'          => $item->id,
			'parent_slug' => $item->parent_slug,
			'hash'        => $item->hash(),
			'meta_skipped' => $item->meta_skipped,
			'last_sync'   => $item->id ? (\get_post_meta($item->id, self::META_LAST_SYNC, true) ?: null) : null,
		);
		if ($item->kind === 'dgb') {
			$r['version'] = isset($item->meta['_aw_version'][0]) ? $item->meta['_aw_version'][0] : null;
		}
		return $r;
	}

	private function count_content(string $post_type): int {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')", $post_type));
	}

	/** Managed patterns: [{slug, title, id, uuid}] */
	private function patterns(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_name FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_awx_pattern_managed' AND m.meta_value = '1'
			 WHERE p.post_type = 'wp_block' AND p.post_status = 'publish' ORDER BY p.post_title ASC",
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ($rows as $r) {
			$slug = (string) (\get_post_meta((int) $r['ID'], '_awx_pattern_slug', true) ?: $r['post_name']);
			$out[] = array(
				'slug'      => $slug,
				'title'     => (string) $r['post_title'],
				'id'        => (int) $r['ID'],
				'uuid'      => (string) \get_post_meta((int) $r['ID'], Identity::META_UUID, true) ?: null,
				'hash'      => \hash('sha256', (string) \get_post_field('post_content', (int) $r['ID'])),
				'last_sync' => \get_post_meta((int) $r['ID'], self::META_LAST_SYNC, true) ?: null,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Items                                                               */
	/* ------------------------------------------------------------------ */

	/** Published posts of one code post type as Code_Items, parents before children. */
	public function items_of(string $kind, string $post_type, $slugs = '*'): array {
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY post_name ASC", $post_type));
		$items = array();
		foreach ($ids as $id) {
			$post = \get_post((int) $id);
			if (!$post || $post->post_name === '') {
				continue;
			}
			if (\is_array($slugs) && !\in_array($post->post_name, $slugs, true)) {
				continue;
			}
			$items[] = Code_Item::from_post($kind, $post, $kind === 'config' && $post->post_name === 'settings');
		}
		return self::parents_first($items);
	}

	/** ACF field groups stored in the database (local JSON/PHP groups ship with code already). */
	public function acf_items($keys = '*'): array {
		$out = array();
		if (!self::acf_active()) {
			return $out;
		}
		foreach ((array) \acf_get_field_groups() as $group) {
			if (!\is_array($group) || empty($group['key']) || !empty($group['local'])) {
				continue;
			}
			if (\is_array($keys) && !\in_array($group['key'], $keys, true)) {
				continue;
			}
			$group['fields'] = \function_exists('acf_get_fields') ? \acf_get_fields($group) : array();
			$export = \acf_prepare_field_group_for_export($group);
			$item = new Code_Item('acf', 'acf-field-group', (string) $group['key']);
			$item->id        = isset($group['ID']) ? (int) $group['ID'] : null;
			$item->title     = (string) ($group['title'] ?? $group['key']);
			$item->status    = !empty($group['active']) ? 'publish' : 'acf-disabled';
			$item->acf_group = $export;
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Expand a selection into the ordered item list plus the content items
	 * (app pages/posts, patterns) that travel through the page pipeline.
	 *
	 * Selection shape (every key optional):
	 *   core:     '*' | [slug]
	 *   services: { post_type: '*' | [slug] }
	 *   apps:     { slug: { definition: bool, config: '*'|[slug]|false, modules: '*'|[slug]|false, pages: '*'|[post_id]|false, posts: '*'|[post_id]|false } }
	 *   dgb:      '*' | [slug]
	 *   acf:      '*' | [key]            (the registration module is added automatically)
	 *   patterns: '*' | [pattern slug]
	 *
	 * @return array {items: Code_Item[] (dependency order), content: [...], collections: [{kind,post_type}], apps: [slug], notes: [...]}
	 */
	public function items_for(array $sel): array {
		$items = array('app' => array(), 'core' => array(), 'service' => array(), 'config' => array(), 'module' => array(), 'dgb' => array(), 'acf' => array());
		$content = $collections = $apps = $notes = array();
		$core_pt = self::core_post_type();

		if (!empty($sel['core'])) {
			$items['core'] = $this->items_of('core', $core_pt, $sel['core']);
			$collections[] = array('kind' => 'core', 'post_type' => $core_pt);
		}
		$services = self::service_post_types();
		foreach ((array) ($sel['services'] ?? array()) as $pt => $slugs) {
			if (empty($slugs) || !isset($services[$pt])) {
				continue;
			}
			$items['service'] = \array_merge($items['service'], $this->items_of('service', (string) $pt, $slugs));
			$collections[] = array('kind' => 'service', 'post_type' => (string) $pt);
		}
		$all_apps = self::apps();
		foreach ((array) ($sel['apps'] ?? array()) as $slug => $parts) {
			if (!isset($all_apps[$slug]) || !\is_array($parts)) {
				continue;
			}
			$app = $all_apps[$slug];
			$apps[] = (string) $slug;
			if (!empty($parts['definition']) && $app['post_id']) {
				$def = \get_post($app['post_id']);
				if ($def) {
					$items['app'][] = Code_Item::from_post('app', $def);
				}
			}
			foreach (array('config', 'modules') as $c) {
				if (empty($parts[$c]) || empty($app['collections'][$c])) {
					continue;
				}
				$kind = $c === 'config' ? 'config' : 'module';
				$items[$kind] = \array_merge($items[$kind], $this->items_of($kind, $app['collections'][$c], $parts[$c]));
				$collections[] = array('kind' => $kind, 'post_type' => $app['collections'][$c], 'app' => (string) $slug);
			}
			foreach (array('pages', 'posts') as $c) {
				if (empty($parts[$c]) || empty($app['collections'][$c])) {
					continue;
				}
				foreach ($this->content_ids($app['collections'][$c], $parts[$c]) as $pid) {
					$content[] = array('kind' => 'page', 'post_id' => (int) $pid, 'app' => (string) $slug, 'collection' => $c, 'post_type' => $app['collections'][$c]);
				}
			}
		}
		if (!empty($sel['dgb'])) {
			$items['dgb'] = $this->items_of('dgb', self::dgb_post_type(), $sel['dgb']);
			$collections[] = array('kind' => 'dgb', 'post_type' => self::dgb_post_type());
		}
		if (!empty($sel['acf'])) {
			$items['acf'] = $this->acf_items($sel['acf']);
			$have_module = false;
			foreach ($items['core'] as $it) {
				if ($it->slug === self::ACF_MODULE) {
					$have_module = true;
				}
			}
			if (!$have_module) {
				$mod = \get_page_by_path(self::ACF_MODULE, OBJECT, $core_pt);
				if ($mod && $mod->post_status === 'publish') {
					$items['core'][] = Code_Item::from_post('core', $mod);
					$notes[] = array('code' => 'acf_module_added', 'message' => 'The ACF registration module core/' . self::ACF_MODULE . ' is included with the field groups.');
				}
			}
			if (!self::acf_active()) {
				$notes[] = array('code' => 'acf_inactive_source', 'message' => 'ACF is not active on this site; no field groups can be read.');
			}
		}
		if (!empty($sel['patterns'])) {
			foreach ($this->patterns() as $p) {
				if (\is_array($sel['patterns']) && !\in_array($p['slug'], $sel['patterns'], true)) {
					continue;
				}
				$content[] = array('kind' => 'pattern', 'block_id' => (int) $p['id'], 'slug' => $p['slug'], 'title' => $p['title']);
			}
		}
		foreach ($items['config'] as $it) {
			if ($it->meta_skipped) {
				$notes[] = array('code' => 'settings_meta_skipped', 'message' => 'Settings of ' . $it->key() . ' (postmeta) are environment-specific and are not deployed; only the module content is.', 'key' => $it->key());
			}
		}

		// Dependency order: app definitions, core, services, config, modules, block definitions, ACF.
		$ordered = \array_merge($items['app'], $items['core'], $items['service'], $items['config'], $items['module'], $items['dgb'], $items['acf']);
		return array('items' => $ordered, 'content' => $this->order_content($content), 'collections' => $collections, 'apps' => \array_values(\array_unique($apps)), 'notes' => $notes);
	}

	private function content_ids(string $post_type, $which): array {
		global $wpdb;
		if (\is_array($which)) {
			return \array_map('intval', $which);
		}
		return \array_map('intval', $wpdb->get_col($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','private','future') ORDER BY post_parent ASC, menu_order ASC, ID ASC",
			$post_type
		)));
	}

	/** Pages parents-first (by depth), patterns last-independent. */
	private function order_content(array $content): array {
		$pages = \array_filter($content, function ($c) { return $c['kind'] === 'page'; });
		$rest  = \array_filter($content, function ($c) { return $c['kind'] !== 'page'; });
		$depth = function (int $id) {
			$d = 0;
			$p = \get_post($id);
			while ($p && (int) $p->post_parent > 0 && $d < 20) {
				$d++;
				$p = \get_post((int) $p->post_parent);
			}
			return $d;
		};
		$pages = \array_values($pages);
		\usort($pages, function ($a, $b) use ($depth) {
			return $depth($a['post_id']) <=> $depth($b['post_id']) ?: $a['post_id'] <=> $b['post_id'];
		});
		return \array_merge($rest, $pages);
	}

	/** Stable sort: items whose parent_slug is in the list come after that parent. */
	public static function parents_first(array $items): array {
		$by_slug = array();
		foreach ($items as $it) {
			$by_slug[$it->slug] = $it;
		}
		$depth = function (Code_Item $it) use ($by_slug) {
			$d = 0;
			$cur = $it;
			while ($cur->parent_slug !== null && isset($by_slug[$cur->parent_slug]) && $d < 20) {
				$d++;
				$cur = $by_slug[$cur->parent_slug];
			}
			return $d;
		};
		$indexed = array();
		foreach ($items as $i => $it) {
			$indexed[] = array($depth($it), $i, $it);
		}
		\usort($indexed, function ($a, $b) {
			return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
		});
		return \array_map(function ($x) { return $x[2]; }, $indexed);
	}

	/** Record on the source post that it was deployed (shown in the tree). */
	public static function mark_synced(?int $post_id, string $run_id, string $hash): void {
		if ($post_id) {
			\update_post_meta($post_id, self::META_LAST_SYNC, array('run_id' => $run_id, 'time' => \time(), 'hash' => $hash));
		}
	}
}
