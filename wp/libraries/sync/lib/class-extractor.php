<?php
/**
 * Extractor (source side): turns one post into a portable payload plus the
 * ordered list of its dependencies.
 *
 * Reusable blocks are followed recursively (leaves first); a cycle fails the
 * preflight with Sync_Exception.
 */
namespace aw2\sync;

class Sync_Exception extends \RuntimeException {
	/** @var string */
	public $code_slug;
	/** @var array */
	public $details;

	public function __construct(string $code_slug, string $message, array $details = array()) {
		parent::__construct($message);
		$this->code_slug = $code_slug;
		$this->details   = $details;
	}
}

class Extractor {

	/** @var Site_Resolver */
	private $site;
	/** @var Transformer */
	private $transformer;

	public function __construct(Site_Resolver $site, Transformer $transformer) {
		$this->site        = $site;
		$this->transformer = $transformer;
	}

	/**
	 * @return array {
	 *   protocol:int,
	 *   page: {uuid,id,type,slug,title,status,date,parent_uuid,author_login,template,content,hash,featured_media,terms[]},
	 *   media:  [uuid => {uuid,id,sha256,mime,bytes,alt,title,file}],
	 *   blocks: [ {uuid,id,slug,title,hash,pattern_slug,content} ] in dependency order (leaves first),
	 *   posts:  [uuid => {uuid,id,type,slug}],
	 *   terms:  [key  => {taxonomy,slug,name,parent}],
	 *   dgb_blocks: [slug => version|null],
	 *   modules: [slug],
	 *   post_types: [name],
	 *   warnings: [...]
	 * }
	 * @throws Sync_Exception
	 */
	public function analyze(int $post_id): array {
		$info = $this->site->post_info($post_id);
		if ($info === null) {
			throw new Sync_Exception('post_not_found', "Post #{$post_id} does not exist.");
		}
		if (isset($info['status']) && $info['status'] === 'trash') {
			throw new Sync_Exception('post_trashed', "Post #{$post_id} is in the trash and cannot be synced.");
		}
		if (empty($info['uuid'])) {
			throw new Sync_Exception('post_without_uuid', "Post #{$post_id} has no sync UUID.");
		}

		$content = isset($info['content']) ? (string) $info['content'] : '';
		$page    = $this->transformer->neutralise($content);

		$agg = new Transform_Result();
		$agg->merge($page);

		// Reusable blocks, leaves first.
		$ordered = array();
		$visited = array();
		foreach (\array_keys($page->refs['blocks']) as $block_id) {
			$this->visit_block((int) $block_id, array(), $visited, $ordered, $agg);
		}

		// Featured image.
		$featured = null;
		if (!empty($info['thumbnail_id'])) {
			$fid  = (int) $info['thumbnail_id'];
			$uuid = $this->site->media_uuid($fid);
			if ($uuid !== null) {
				$featured = $uuid;
				$agg->refs['media'][$fid] = $uuid;
			} else {
				$agg->warn('media_missing', "Featured image #{$fid} does not exist on this site.");
			}
		}

		// Term assignments of the post itself.
		$assigned = array();
		if (!empty($info['terms']) && \is_array($info['terms'])) {
			foreach ($info['terms'] as $t) {
				$key = $t['taxonomy'] . ':' . $t['slug'];
				$assigned[] = $key;
				$agg->refs['terms'][$key] = $t;
			}
		}

		// Media manifest.
		$media = array();
		foreach ($agg->refs['media'] as $id => $uuid) {
			$m = $this->site->media_info((int) $id);
			if ($m === null) {
				$agg->warn('media_missing', "Attachment #{$id} has no file on this site.");
				continue;
			}
			$m['uuid'] = $uuid;
			$m['id']   = (int) $id;
			$media[$uuid] = $m;
		}

		// Linked posts (resolved on the target, never written).
		$posts = array();
		foreach ($agg->refs['posts'] as $id => $uuid) {
			if ((int) $id === $post_id) {
				continue;
			}
			$p = $this->site->post_info((int) $id);
			$posts[$uuid] = array(
				'uuid' => $uuid,
				'id'   => (int) $id,
				'type' => $p ? $p['type'] : null,
				'slug' => $p ? $p['slug'] : null,
			);
		}

		// dgb block definitions to verify on the target.
		$dgb = array();
		foreach (\array_keys($agg->refs['dgb']) as $slug) {
			$dgb[$slug] = $this->site->dgb_block_version((string) $slug);
			if ($dgb[$slug] === null) {
				$agg->warn('dgb_block_unknown', "Block dgb/{$slug} is not defined on this site.");
			}
		}

		$parent_uuid = null;
		if (!empty($info['parent'])) {
			$parent_uuid = $this->site->post_uuid((int) $info['parent']);
			if ($parent_uuid === null) {
				$agg->warn('parent_without_uuid', "Parent post #{$info['parent']} has no sync UUID; the target page is created at the top level.");
			}
		}

		return array(
			'protocol' => \defined('AWX_SYNC_PROTOCOL') ? AWX_SYNC_PROTOCOL : 1,
			'page'     => array(
				'uuid'           => $info['uuid'],
				'id'             => $post_id,
				'type'           => $info['type'],
				'slug'           => $info['slug'],
				'title'          => isset($info['title']) ? $info['title'] : '',
				'status'         => $info['status'],
				'date'           => isset($info['date']) ? $info['date'] : null,
				'parent_uuid'    => $parent_uuid,
				'author_login'   => isset($info['author_login']) ? $info['author_login'] : null,
				'template'       => isset($info['template']) ? $info['template'] : null,
				'content'        => $page->content,
				'hash'           => \hash('sha256', $content),
				'featured_media' => $featured,
				'terms'          => $assigned,
				'meta'           => array(),
			),
			'media'      => $media,
			'blocks'     => $ordered,
			'posts'      => $posts,
			'terms'      => $agg->refs['terms'],
			'dgb_blocks' => $dgb,
			'modules'    => \array_keys($agg->refs['modules']),
			'post_types' => \array_keys($agg->refs['post_types']),
			'warnings'   => $agg->warnings,
		);
	}

	/**
	 * Depth-first visit of a reusable block and its own block references.
	 */
	private function visit_block(int $block_id, array $stack, array &$visited, array &$ordered, Transform_Result $agg): void {
		if (\in_array($block_id, $stack, true)) {
			$chain = \array_merge($stack, array($block_id));
			throw new Sync_Exception(
				'block_cycle',
				'Reusable blocks reference each other in a cycle: ' . \implode(' -> ', \array_map(function ($id) { return '#' . $id; }, $chain)),
				array('chain' => $chain)
			);
		}
		if (isset($visited[$block_id])) {
			return;
		}
		$info = $this->site->block_info($block_id);
		if ($info === null) {
			$agg->warn('block_missing', "Reusable block #{$block_id} does not exist on this site.");
			$visited[$block_id] = true;
			return;
		}
		$content = (string) $this->site->block_content($block_id);
		$r = $this->transformer->neutralise($content);
		$stack[] = $block_id;
		foreach (\array_keys($r->refs['blocks']) as $child) {
			$this->visit_block((int) $child, $stack, $visited, $ordered, $agg);
		}
		$agg->merge($r);
		$visited[$block_id] = true;
		$ordered[] = array(
			'uuid'         => $info['uuid'],
			'id'           => $block_id,
			'slug'         => $info['slug'],
			'title'        => $info['title'],
			'hash'         => \hash('sha256', $content),
			'pattern_slug' => isset($info['pattern_slug']) ? $info['pattern_slug'] : null,
			'content'      => $r->content,
		);
	}
}
