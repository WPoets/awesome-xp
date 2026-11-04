<?php
/**
 * Website Assembly Language (WAL) Engine
 *
 * Provides deterministic compilation from declarative WAL YAML/JSON
 * into native Gutenberg AST / serialized block markup, and decompilation
 * from published post_content back into clean WAL representation.
 *
 * @package DynamicGutenbergBlocks
 * @version 1.0.0
 */

namespace aw2\gutenberg_blocks;

if (!defined('ABSPATH')) {
    exit;
}

final class WAL_Transpiler
{
    /**
     * Compiles a validated WAL document directly into a WordPress Page.
     *
     * @param array $wal Structured WAL document containing 'page' => [ ... ]
     * @return int Created or updated post ID
     * @throws \InvalidArgumentException When WAL document is malformed
     * @throws \RuntimeException When database save fails
     */
    public static function compile_and_save(array $wal): int
    {
        if (empty($wal['page']) || !is_array($wal['page'])) {
            throw new \InvalidArgumentException("Invalid WAL payload: missing 'page' root definition.");
        }

        $page = $wal['page'];
        $sections = isset($page['sections']) && is_array($page['sections']) ? $page['sections'] : [];
        $block_nodes = self::transpile_nodes($sections);

        $serialized_content = function_exists('serialize_blocks')
            ? serialize_blocks($block_nodes)
            : '';

        $post_data = [
            'post_type'    => 'page',
            'post_status'  => isset($page['status']) ? sanitize_key($page['status']) : 'draft',
            'post_title'   => sanitize_text_field($page['title'] ?? ''),
            'post_content' => function_exists('wp_slash') ? wp_slash($serialized_content) : $serialized_content,
        ];

        if (!empty($page['slug'])) {
            $post_data['post_name'] = sanitize_title($page['slug']);
        }

        if (!empty($page['id']) && is_numeric($page['id'])) {
            $post_data['ID'] = (int)$page['id'];
            $post_id = wp_update_post($post_data, true);
        } else {
            $post_id = wp_insert_post($post_data, true);
        }

        if (is_wp_error($post_id)) {
            throw new \RuntimeException("WAL Compilation failed: " . $post_id->get_error_message());
        }

        return (int)$post_id;
    }

    /**
     * Recursively maps WAL nodes to native Gutenberg AST array blocks.
     *
     * @param array $nodes Array of section, block, or pattern nodes
     * @return array Gutenberg AST block array nodes
     */
    public static function transpile_nodes(array $nodes): array
    {
        $ast = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            // Pattern expansion path
            if (!empty($node['pattern'])) {
                $overrides = isset($node['overrides']) && is_array($node['overrides']) ? $node['overrides'] : [];
                $pattern_blocks = self::resolve_pattern((string)$node['pattern'], $overrides);
                foreach ($pattern_blocks as $pb) {
                    $ast[] = $pb;
                }
                continue;
            }

            if (empty($node['block'])) {
                continue;
            }

            $block_name = (string)$node['block'];
            $has_slash = function_exists('str_contains')
                ? str_contains($block_name, '/')
                : (strpos($block_name, '/') !== false);

            if (!$has_slash) {
                $block_name = 'dgb/' . $block_name;
            }

            $inner_nodes = !empty($node['inner']) && is_array($node['inner']) ? $node['inner'] : [];
            $inner_blocks = !empty($inner_nodes) ? self::transpile_nodes($inner_nodes) : [];

            $ast[] = [
                'blockName'    => $block_name,
                'attrs'        => isset($node['attrs']) && is_array($node['attrs']) ? $node['attrs'] : [],
                'innerBlocks'  => $inner_blocks,
                'innerHTML'    => '',
                'innerContent' => array_fill(0, count($inner_blocks), null),
            ];
        }

        return $ast;
    }

    /**
     * Retrieves an unsynced pattern and parses it into block nodes.
     *
     * @param string $slug Pattern slug identifier
     * @param array $overrides Attribute overrides for root block node
     * @return array Parsed block nodes
     */
    public static function resolve_pattern(string $slug, array $overrides = []): array
    {
        $meta_slug_key = class_exists('AWX_Pattern_Bridge')
            ? AWX_Pattern_Bridge::META_SLUG
            : '_awx_pattern_slug';

        $query = new \WP_Query([
            'post_type'      => 'wp_block',
            'posts_per_page' => 1,
            'meta_query'     => [
                ['key' => $meta_slug_key, 'value' => sanitize_key($slug)]
            ],
        ]);

        if (!$query->have_posts()) {
            return [];
        }

        $content = $query->posts[0]->post_content;
        $parsed = function_exists('parse_blocks') ? parse_blocks($content) : [];

        // Apply attribute overrides to root pattern block if provided
        if (!empty($overrides) && !empty($parsed[0])) {
            $current_attrs = isset($parsed[0]['attrs']) && is_array($parsed[0]['attrs'])
                ? $parsed[0]['attrs']
                : [];
            $parsed[0]['attrs'] = array_merge($current_attrs, $overrides);
        }

        return $parsed;
    }
}

final class WAL_Decompiler
{
    /**
     * Decompiles a published WordPress page back into a structured WAL document.
     *
     * @param int $post_id WordPress post ID
     * @return array Structured WAL document [page => [ ... ]]
     * @throws \InvalidArgumentException When post does not exist
     */
    public static function decompile(int $post_id): array
    {
        $post = function_exists('get_post') ? get_post($post_id) : null;
        if (!$post) {
            throw new \InvalidArgumentException("Post not found with ID: " . $post_id);
        }

        $blocks = function_exists('parse_blocks') ? parse_blocks($post->post_content) : [];

        return [
            'page' => [
                'title'    => $post->post_title,
                'slug'     => $post->post_name,
                'status'   => $post->post_status,
                'sections' => self::ast_to_wal($blocks),
            ]
        ];
    }

    /**
     * Recursively converts Gutenberg AST block nodes into clean WAL intermediate representation.
     *
     * @param array $blocks Parsed Gutenberg AST blocks
     * @return array Clean WAL section/block nodes
     */
    public static function ast_to_wal(array $blocks): array
    {
        $wal_nodes = [];

        foreach ($blocks as $b) {
            if (empty($b['blockName'])) {
                continue;
            }

            $block_name = str_replace('dgb/', '', $b['blockName']);
            $inner = !empty($b['innerBlocks']) && is_array($b['innerBlocks'])
                ? self::ast_to_wal($b['innerBlocks'])
                : [];

            $node = [
                'block' => $block_name,
                'attrs' => isset($b['attrs']) && is_array($b['attrs']) ? $b['attrs'] : [],
            ];

            if (!empty($inner)) {
                $node['inner'] = $inner;
            }

            $wal_nodes[] = $node;
        }

        return $wal_nodes;
    }
}

// Global class aliases for root-namespace access
if (!class_exists('WAL_Transpiler', false)) {
    class_alias('aw2\gutenberg_blocks\WAL_Transpiler', 'WAL_Transpiler');
}
if (!class_exists('WAL_Decompiler', false)) {
    class_alias('aw2\gutenberg_blocks\WAL_Decompiler', 'WAL_Decompiler');
}
