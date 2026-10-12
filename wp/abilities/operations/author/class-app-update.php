<?php
namespace aw2\abilities\operations\author;

use aw2\abilities\operations\Operation_Base;
use aw2\abilities\guardrails\Validation_Token;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/app-update
 *
 * Saves changes to an app manifest as a new draft version.
 */
class App_Update extends Operation_Base
{
    public function execute(array $params = [])
    {
        $slug = isset($params['slug']) ? sanitize_title(trim($params['slug'])) : '';
        $manifest = isset($params['manifest']) && is_array($params['manifest']) ? $params['manifest'] : [];
        $validation_token = isset($params['validation_token']) ? (string)$params['validation_token'] : '';
        $if_version = isset($params['if_version']) ? (int)$params['if_version'] : null;

        if (empty($slug)) {
            return $this->error('App slug is required.', 'Provide {"slug": "..."}', 'missing_slug');
        }

        $post_type = defined('AWESOME_APPS_POST_TYPE') ? AWESOME_APPS_POST_TYPE : 'aw2_app';
        $post = get_page_by_path($slug, OBJECT, $post_type);
        if (!$post) {
            return $this->error("App '{$slug}' not found.", "Call awesome/app-create first.", 'app_not_found');
        }

        $current_version = (int)get_post_meta($post->ID, '_aw_version', true) ?: 1;
        if ($if_version !== null && $if_version !== $current_version) {
            return $this->error(
                "Version mismatch: current is {$current_version}, provided was {$if_version}.",
                "Call awesome/app-get to inspect the latest state before updating.",
                'version_conflict'
            );
        }

        $next_version = $current_version + 1;

        // Check if open draft fork exists
        $existing_drafts = get_posts([
            'post_type'   => $post_type,
            'meta_key'    => '_aw_forked_from',
            'meta_value'  => $slug,
            'post_status' => 'draft'
        ]);

        $content = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!empty($existing_drafts)) {
            $draft_id = $existing_drafts[0]->ID;
            wp_update_post([
                'ID'           => $draft_id,
                'post_content' => $content,
                'post_title'   => $manifest['name'] ?? $post->post_title
            ]);
        } else {
            $suffix = function_exists('wp_generate_password') ? wp_generate_password(6, false) : substr(md5(uniqid()), 0, 6);
            $draft_id = wp_insert_post([
                'post_title'   => ($manifest['name'] ?? $post->post_title) . ' (Draft)',
                'post_name'    => "{$slug}-draft-{$suffix}",
                'post_type'    => $post_type,
                'post_status'  => 'draft',
                'post_content' => $content
            ]);
            update_post_meta($draft_id, '_aw_forked_from', $slug);
            update_post_meta($draft_id, '_aw_is_draft_fork', 1);
            update_post_meta($draft_id, '_aw_source_post_id', $post->ID);
        }

        update_post_meta($draft_id, '_aw_target_version', $next_version);

        $this->audit_log('awesome/app-update', 'app', $slug, 'success', ['draft_id' => $draft_id]);

        return $this->success([
            'slug'          => $slug,
            'draft_id'      => $draft_id,
            'draft_version' => $next_version,
            'status'        => 'draft',
            'message'       => "App '{$slug}' updated as draft version {$next_version}. Call awesome/version-publish to promote to live."
        ]);
    }
}
