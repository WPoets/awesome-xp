<?php
/**
 * parse_blocks()/serialize_blocks() with a standalone fallback so the
 * transformer can run (and be tested) outside a full WordPress bootstrap.
 * The fallback mirrors wp-includes/blocks.php byte for byte.
 */
namespace aw2\sync;

class Block_Serializer {

	public static function parse(string $content): array {
		if (\function_exists('parse_blocks')) {
			return \parse_blocks($content);
		}
		if (!\class_exists('\WP_Block_Parser')) {
			throw new \RuntimeException('WP_Block_Parser is not available; load wp-includes/class-wp-block-parser.php first.');
		}
		$parser = new \WP_Block_Parser();
		return $parser->parse($content);
	}

	public static function serialize(array $blocks): string {
		if (\function_exists('serialize_blocks')) {
			return \serialize_blocks($blocks);
		}
		$out = '';
		foreach ($blocks as $block) {
			$out .= self::serialize_block($block);
		}
		return $out;
	}

	public static function serialize_block(array $block): string {
		$block_content = '';
		$index = 0;
		$inner = isset($block['innerContent']) && \is_array($block['innerContent']) ? $block['innerContent'] : array();
		foreach ($inner as $chunk) {
			if (\is_string($chunk)) {
				$block_content .= $chunk;
			} else {
				$block_content .= self::serialize_block($block['innerBlocks'][$index++]);
			}
		}
		$attrs = isset($block['attrs']) && \is_array($block['attrs']) ? $block['attrs'] : array();
		return self::comment_delimited($block['blockName'] ?? null, $attrs, $block_content);
	}

	private static function comment_delimited(?string $name, array $attrs, string $content): string {
		if ($name === null) {
			return $content;
		}
		$short = (0 === \strpos($name, 'core/')) ? \substr($name, 5) : $name;
		$serialized_attrs = empty($attrs) ? '' : self::serialize_attributes($attrs) . ' ';
		if ($content === '') {
			return \sprintf('<!-- wp:%s %s/-->', $short, $serialized_attrs);
		}
		return \sprintf('<!-- wp:%s %s-->%s<!-- /wp:%s -->', $short, $serialized_attrs, $content, $short);
	}

	public static function serialize_attributes(array $attrs): string {
		$encoded = \json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$encoded = \preg_replace('/--/', '\\u002d\\u002d', $encoded);
		$encoded = \preg_replace('/</', '\\u003c', $encoded);
		$encoded = \preg_replace('/>/', '\\u003e', $encoded);
		$encoded = \preg_replace('/&/', '\\u0026', $encoded);
		$encoded = \preg_replace('/\\\\"/', '\\u0022', $encoded);
		return $encoded;
	}

	/**
	 * Canonical form used by tests and by the content-hash comparison:
	 * parse then serialize, so attribute JSON formatting differences vanish.
	 */
	public static function canonical(string $content): string {
		return self::serialize(self::parse($content));
	}
}
