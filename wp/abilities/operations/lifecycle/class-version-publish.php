<?php
namespace aw2\abilities\operations\lifecycle;

use aw2\abilities\operations\Operation_Base;
use aw2\abilities\guardrails\Template_Linter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/version-publish
 *
 * Makes a draft version live. Affects every page using this artifact.
 * Merges the draft fork into the live post and purges the draft copy.
 */
class Version_Publish extends Operation_Base
{
    public function execute(array $params = [])
    {
        global $wpdb;

        $artifact_type = isset($params['artifact_type']) ? sanitize_key($params['artifact_type']) : 'block';
        $slug = isset($params['slug']) ? sanitize_title(trim($params['slug'])) : '';

        if (empty($slug)) {
            return $this->error('Slug is required.', 'Provide {"artifact_type": "block", "slug": "my-block"}', 'missing_slug');
        }

        // Custom services publish
        if ($artifact_type === 'service') {
            $name = strpos($slug, 'custom.') !== 0 ? 'custom.' . $slug : $slug;
            $custom_drafts = get_option('awx_custom_service_drafts', []);
            if (!isset($custom_drafts[$name])) {
                return $this->error("Custom service '{$name}' not found.", 'Create the service first with awesome/service-create.', 'service_not_found');
            }

            // Register into live handlers
            $svc = $custom_drafts[$name];
            $svc['status'] = 'publish';
            $custom_drafts[$name] = $svc;
            update_option('awx_custom_service_drafts', $custom_drafts, false);

            \aw2_library::add_service($name, $svc['description'] ?? 'Custom Service', ['@custom' => true]);
            $this->audit_log('awesome/version-publish', 'service', $name, 'success');

            return $this->success([
                'artifact_type' => 'service',
                'slug'          => $name,
                'live_version'  => $svc['version'] ?? 1,
                'status'        => 'publish',
                'message'       => "Service '{$name}' is now live."
            ]);
        }

        $post_type = 'aw_gb_blocks';
        if ($artifact_type === 'app') {
            $post_type = defined('AWESOME_APPS_POST_TYPE') ? AWESOME_APPS_POST_TYPE : 'aw2_app';
        } elseif ($artifact_type === 'pattern') {
            $post_type = 'wp_block';
        }

        // 1. Locate live post or newly created initial draft
        $live_post = get_page_by_path($slug, OBJECT, $post_type);

        // Check if there is an open draft fork
        $draft_posts = get_posts([
            'post_type'   => $post_type,
            'meta_key'    => '_aw_forked_from',
            'meta_value'  => $slug,
            'post_status' => 'draft'
        ]);

        $new_content = '';
        $new_title = '';
        $new_excerpt = '';
        $draft_to_delete = null;

        if (!empty($draft_posts)) {
            $draft_post = $draft_posts[0];
            $new_content = $draft_post->post_content;
            $new_title = str_replace(' (Draft)', '', $draft_post->post_title);
            $new_excerpt = $draft_post->post_excerpt;
            $draft_to_delete = $draft_post->ID;
        } elseif ($live_post && $live_post->post_status === 'draft') {
            // Initial draft being published directly
            $new_content = $live_post->post_content;
            $new_title = $live_post->post_title;
            $new_excerpt = $live_post->post_excerpt;
        } elseif ($live_post && $live_post->post_status === 'publish') {
            return $this->error(
                "Block '{$slug}' is already published and has no open draft fork.",
                "Call awesome/block-update first to create a draft fork before publishing.",
                'no_draft_fork'
            );
        } else {
            return $this->error("Artifact '{$slug}' was not found.", "Check awesome/{$artifact_type}-list.", 'artifact_not_found');
        }

        // 2. Re-validate template code on publish (Safety barrier)
        if ($artifact_type === 'block') {
            $decoded = json_decode($new_content, true);
            $template_to_check = is_array($decoded) ? ($decoded['template'] ?? '') : $new_content;
            $lint = Template_Linter::lint($template_to_check, 'block');
            if (!$lint['valid']) {
                $this->audit_log('awesome/version-publish', 'block', $slug, 'rejected', ['errors' => $lint['errors']]);
                return $this->error(
                    'Re-validation failed before publish.',
                    'Fix the reported errors in template code and re-test with awesome/template-validate.',
                    'publish_validation_failed',
                    ['errors' => $lint['errors']]
                );
            }
        }

        // 3. Update the live post
        $current_version = (int)get_post_meta($live_post->ID, '_aw_version', true) ?: 1;
        $next_version = $current_version + 1;

        wp_update_post([
            'ID'           => $live_post->ID,
            'post_title'   => $new_title,
            'post_content' => $new_content,
            'post_excerpt' => $new_excerpt,
            'post_status'  => 'publish'
        ]);

        update_post_meta($live_post->ID, '_aw_version', $next_version);

        // 4. Safely clean up the draft fork post
        if ($draft_to_delete) {
            wp_delete_post($draft_to_delete, true);
        }

        // 5. Count affected pages
        $affected_count = 0;
        if ($wpdb && $artifact_type === 'block') {
            $tag = "%wp:dgb/{$slug}%";
            $affected_count = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s",
                $tag
            ));
        }

        $this->audit_log('awesome/version-publish', $artifact_type, $slug, 'success', [
            'version'        => $next_version,
            'affected_count' => $affected_count
        ]);

        return $this->success([
            'artifact_type'       => $artifact_type,
            'slug'                => $slug,
            'live_version'        => $next_version,
            'affected_page_count' => $affected_count,
            'status'              => 'publish',
            'message'             => "Version {$next_version} of '{$slug}' is now live across {$affected_count} pages."
        ]);
    }
}
