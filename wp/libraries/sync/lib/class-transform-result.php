<?php
namespace aw2\sync;

/**
 * Outcome of one neutralise() or resolve() pass over a piece of content.
 */
class Transform_Result {

	/** @var string */
	public $content = '';

	/** @var bool */
	public $changed = false;

	/**
	 * References found while neutralising, keyed by local id (or term key).
	 * media:  [attachment_id => uuid]
	 * posts:  [post_id => uuid]
	 * blocks: [wp_block_id => uuid]
	 * terms:  ['taxonomy:slug' => ['taxonomy','slug','name','parent']]
	 * dgb:    [slug => true]
	 * modules: [slug => true]          ([aw2.module] shortcodes)
	 * post_types: [name => true]       (queried by dynamic blocks)
	 */
	public $refs = array(
		'media'      => array(),
		'posts'      => array(),
		'blocks'     => array(),
		'terms'      => array(),
		'dgb'        => array(),
		'modules'    => array(),
		'post_types' => array(),
	);

	/** @var array<int,array{code:string,message:string,block:?string,path:?string}> */
	public $warnings = array();

	/**
	 * Tokens that could not be resolved (resolve() only):
	 * [ ['type' => 'media'|'post'|'block'|'term', 'key' => uuid or 'taxonomy:slug', 'block' => blockName] ]
	 */
	public $missing = array();

	public function warn(string $code, string $message, ?string $block = null, ?string $path = null): void {
		foreach ($this->warnings as $w) {
			if ($w['code'] === $code && $w['message'] === $message) {
				return; // de-duplicate identical warnings
			}
		}
		$this->warnings[] = array('code' => $code, 'message' => $message, 'block' => $block, 'path' => $path);
	}

	public function miss(string $type, string $key, ?string $block = null): void {
		foreach ($this->missing as $m) {
			if ($m['type'] === $type && $m['key'] === $key) {
				return;
			}
		}
		$this->missing[] = array('type' => $type, 'key' => $key, 'block' => $block);
	}

	public function ok(): bool {
		return empty($this->missing);
	}

	public function merge(Transform_Result $other): void {
		foreach ($other->refs as $kind => $items) {
			foreach ($items as $k => $v) {
				$this->refs[$kind][$k] = $v;
			}
		}
		foreach ($other->warnings as $w) {
			$this->warn($w['code'], $w['message'], $w['block'], $w['path']);
		}
		foreach ($other->missing as $m) {
			$this->miss($m['type'], $m['key'], $m['block']);
		}
		$this->changed = $this->changed || $other->changed;
	}
}
