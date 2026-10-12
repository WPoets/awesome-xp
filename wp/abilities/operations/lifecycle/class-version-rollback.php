<?php
namespace aw2\abilities\operations\lifecycle;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/version-rollback
 *
 * Restores a previous version / revision as live.
 */
class Version_Rollback extends Operation_Base
{
    public function execute(array $params = [])
    {
        $artifact_type = isset($params['artifact_type']) ? sanitize_key($params['artifact_type']) : 'block';
        $slug = isset($params['slug']) ? sanitize_title(trim($params['slug'])) : '';
        $version = isset($params['version']) ? (int)$params['version'] : 0;

        if (empty($slug)) {
            return $this->error('Slug is required.', 'Provide {"artifact_type": "block", "slug": "my-block", "version": 2}', 'missing_slug');
        }

        $post_type = 'aw_gb_blocks';
        if ($artifact_type === 'app') {
            $post_type = defined('AWESOME_APPS_POST_TYPE') ? AWESOME_APPS_POST_TYPE : 'aw2_app';
        } elseif ($artifact_type === 'pattern') {
            $post_type = 'wp_block';
        }

        $live_post = get_page_by_path($slug, OBJECT, $post_type);
        if (!$live_post) {
            return $this->error("Artifact '{$slug}' not found.", 'Check awesome/version-list to inspect existing versions.', 'artifact_not_found');
        }

        $revisions = wp_get_post_revisions($live_post->ID);
        if (empty($revisions)) {
            return $this->error("No previous revisions exist for '{$slug}'.", 'Rollback requires at least one previous revision.', 'no_revisions');
        }

        // Locate target revision
        $target_revision_id = 0;
        if ($version > 0) {
            // Check if $version is directly a revision ID
            if (isset($revisions[$version])) {
                $target_revision_id = $version;
            } else {
                // Find revision matching version index
                $idx = count($revisions);
                foreach ($revisions as $rev) {
                    if ($idx === $version || $rev->ID === $version) {
                        $target_revision_id = $rev->ID;
                        break;
                    }
                    $idx--;
                }
            }
        }

        if (!$target_revision_id) {
            // Default to most recent previous revision
            $latest_rev = reset($revisions);
            $target_revision_id = $latest_rev->ID;
        }

        // Perform restore using WordPress native revision restore
        $restored = wp_restore_post_revision($target_revision_id);
        if (!$restored) {
            return $this->error("Failed to restore revision {$target_revision_id}.", 'Check permissions and try again.', 'restore_failed');
        }

        $this->audit_log('awesome/version-rollback', $artifact_type, $slug, 'success', ['restored_revision_id' => $target_revision_id]);

        return $this->success([
            'artifact_type'        => $artifact_type,
            'slug'                 => $slug,
            'restored_revision_id' => $target_revision_id,
            'status'               => 'publish',
            'message'              => "Successfully rolled back '{$slug}' to revision {$target_revision_id}."
        ]);
    }
}
