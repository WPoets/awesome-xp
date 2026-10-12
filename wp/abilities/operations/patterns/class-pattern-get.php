<?php
namespace aw2\abilities\operations\patterns;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/pattern-get
 *
 * Returns a pattern's block markup, blocks used, synced status, and revisions.
 */
class Pattern_Get extends Operation_Base
{
    public function execute(array $params = [])
    {
        $id = isset($params['id']) ? (int)$params['id'] : 0;
        if (!$id) {
            return $this->error('Pattern ID is required.', 'Pass {"id": 123} in parameters.', 'missing_id');
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== 'wp_block') {
            return $this->error(
                "Block pattern with ID {$id} not found.",
                "Call awesome/pattern-list to find available patterns.",
                'pattern_not_found'
            );
        }

        $markup = $post->post_content;

        // Extract blocks used in this pattern markup
        $blocks_used = [];
        if (preg_match_all('/<!--\s+wp:([a-zA-Z0-9_\-\/]+)/', $markup, $matches)) {
            $blocks_used = array_values(array_unique($matches[1]));
        }

        $is_synced = !has_term('wp_pattern_sync_type_unsynced', 'wp_pattern_sync_type', $post->ID);
        $revisions = wp_get_post_revisions($post->ID);

        return $this->success([
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'markup'      => $markup,
            'blocks_used' => $blocks_used,
            'synced'      => $is_synced,
            'status'      => $post->post_status,
            'revisions'   => count($revisions)
        ]);
    }
}
