<?php
namespace aw2\abilities\operations\lifecycle;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/version-list
 *
 * Lists versions of a block, service, app, or pattern with status, author, and date.
 * Use to find a draft to publish or a version to roll back to.
 */
class Version_List extends Operation_Base
{
    public function execute(array $params = [])
    {
        $artifact_type = isset($params['artifact_type']) ? sanitize_key($params['artifact_type']) : 'block';
        $slug = isset($params['slug']) ? sanitize_title(trim($params['slug'])) : '';

        if (empty($slug)) {
            return $this->error('Slug is required.', 'Provide {"artifact_type": "block", "slug": "my-block"}', 'missing_slug');
        }

        $post_type = 'aw_gb_blocks';
        if ($artifact_type === 'app') {
            $post_type = defined('AWESOME_APPS_POST_TYPE') ? AWESOME_APPS_POST_TYPE : 'aw2_app';
        } elseif ($artifact_type === 'pattern') {
            $post_type = 'wp_block';
        } elseif ($artifact_type === 'service') {
            // For custom services stored in options
            $custom_drafts = get_option('awx_custom_service_drafts', []);
            $name = strpos($slug, 'custom.') !== 0 ? 'custom.' . $slug : $slug;
            $svc = $custom_drafts[$name] ?? null;
            if (!$svc) {
                return $this->error("Service '{$name}' not found.", 'Call awesome/service-list to inspect services.', 'service_not_found');
            }
            return $this->success([
                'artifact_type' => 'service',
                'slug'          => $name,
                'live_version'  => $svc['version'] ?? 1,
                'versions'      => [
                    [
                        'version'  => $svc['version'] ?? 1,
                        'status'   => $svc['status'] ?? 'draft',
                        'modified' => $svc['updated_at'] ?? ($svc['created_at'] ?? '')
                    ]
                ]
            ]);
        }

        $live_post = null;
        if (is_numeric($slug)) {
            $p = get_post((int)$slug);
            if ($p && $p->post_type === $post_type) {
                $live_post = $p;
            }
        }
        if (!$live_post) {
            $posts = get_posts([
                'name'           => $slug,
                'post_type'      => $post_type,
                'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
                'posts_per_page' => 1
            ]);
            if (!empty($posts)) {
                $live_post = $posts[0];
            }
        }
        if (!$live_post && function_exists('get_page_by_path')) {
            $live_post = get_page_by_path($slug, OBJECT, $post_type);
        }

        if (!$live_post) {
            return $this->error(
                "Artifact '{$slug}' of type '{$artifact_type}' was not found.",
                "Verify the slug or call awesome/{$artifact_type}-list.",
                'artifact_not_found'
            );
        }

        $versions = [];
        $revisions = wp_get_post_revisions($live_post->ID);
        $is_published = ($live_post->post_status === 'publish');
        $current_status = $is_published ? 'live' : 'draft';

        // 1. Current version (live or draft)
        $current_v_num = (int)get_post_meta($live_post->ID, '_aw_version', true) ?: (count($revisions) + 1);
        $versions[] = [
            'version'   => $current_v_num,
            'status'    => $current_status,
            'post_id'   => $live_post->ID,
            'author'    => get_the_author_meta('display_name', $live_post->post_author),
            'date'      => $live_post->post_modified,
            'is_active' => $is_published
        ];

        // 2. Prior WordPress revisions
        $rev_idx = count($revisions);
        foreach ($revisions as $rev) {
            $versions[] = [
                'version'     => $rev_idx,
                'revision_id' => $rev->ID,
                'status'      => 'historical_revision',
                'author'      => get_the_author_meta('display_name', $rev->post_author),
                'date'        => $rev->post_modified,
                'is_active'   => false
            ];
            $rev_idx--;
        }

        // 3. Any open draft fork copy
        $draft_posts = get_posts([
            'post_type'   => $post_type,
            'meta_key'    => '_aw_forked_from',
            'meta_value'  => $slug,
            'post_status' => 'draft'
        ]);

        $open_draft = null;
        if (!empty($draft_posts)) {
            $dp = $draft_posts[0];
            $target_v = (int)get_post_meta($dp->ID, '_aw_target_version', true) ?: ($current_v_num + 1);
            $open_draft = [
                'version'  => $target_v,
                'status'   => 'draft_fork',
                'draft_id' => $dp->ID,
                'date'     => $dp->post_modified
            ];
            $versions[] = $open_draft;
        }

        return $this->success([
            'artifact_type'   => $artifact_type,
            'slug'            => $slug,
            'status'          => $live_post->post_status,
            'is_live'         => $is_published,
            'live_version'    => $is_published ? $current_v_num : null,
            'has_draft_fork'  => !empty($open_draft),
            'versions'        => $versions
        ]);
    }
}
