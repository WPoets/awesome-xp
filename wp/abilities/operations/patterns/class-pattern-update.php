<?php
namespace aw2\abilities\operations\patterns;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/pattern-update
 *
 * Saves changes to a pattern as a new draft.
 */
class Pattern_Update extends Operation_Base
{
    public function execute(array $params = [])
    {
        $id = isset($params['id']) ? (int)$params['id'] : 0;
        $markup = isset($params['markup']) ? (string)$params['markup'] : '';
        $if_version = isset($params['if_version']) ? (int)$params['if_version'] : null;

        if (!$id || empty(trim($markup))) {
            return $this->error('Pattern ID and markup are required.', 'Provide {"id": 123, "markup": "..."}', 'missing_parameters');
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== 'wp_block') {
            return $this->error("Pattern with ID {$id} not found.", "Call awesome/pattern-list to find available patterns.", 'pattern_not_found');
        }

        $revisions = wp_get_post_revisions($id);
        $current_version = count($revisions) + 1;

        if ($if_version !== null && $if_version !== $current_version) {
            return $this->error(
                "Version mismatch: current is {$current_version}, provided was {$if_version}.",
                "Call awesome/pattern-get to check current markup before updating.",
                'version_conflict'
            );
        }

        $updated_id = wp_update_post([
            'ID'           => $id,
            'post_content' => $markup
        ], true);

        if (is_wp_error($updated_id)) {
            return $this->error($updated_id->get_error_message(), 'Retry with valid post markup.', 'db_error');
        }

        $new_revisions = wp_get_post_revisions($id);
        $next_version = count($new_revisions) + 1;

        $this->audit_log('awesome/pattern-update', 'pattern', (string)$id, 'success');

        return $this->success([
            'id'      => $id,
            'version' => $next_version,
            'status'  => 'draft',
            'message' => "Pattern {$id} updated. Synced patterns must be published via awesome/version-publish."
        ]);
    }
}
