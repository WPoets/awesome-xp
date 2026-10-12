<?php
namespace aw2\abilities\operations\author;

use aw2\abilities\operations\Operation_Base;
use aw2\abilities\guardrails\Validation_Token;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/app-create
 *
 * Creates an app manifest as a draft.
 * Apps compose existing blocks and services; create those first.
 */
class App_Create extends Operation_Base
{
    public function execute(array $params = [])
    {
        $manifest = isset($params['manifest']) && is_array($params['manifest']) ? $params['manifest'] : [];
        $validation_token = isset($params['validation_token']) ? (string)$params['validation_token'] : '';

        if (empty($manifest['name']) || empty($manifest['slug'])) {
            return $this->error('App manifest must include name and slug.', 'Provide {"manifest": {"name": "...", "slug": "..."}}', 'invalid_manifest');
        }

        $slug = sanitize_title($manifest['slug']);
        $title = sanitize_text_field($manifest['name']);

        // Check for disallowed credential fields
        $sensitive_keys = ['password', 'secret', 'token', 'api_key', 'credentials'];
        foreach ($sensitive_keys as $k) {
            if (isset($manifest[$k])) {
                return $this->error(
                    "Manifests cannot set credentials ('{$k}').",
                    "Humans must configure credentials outside the agent.",
                    'credentials_disallowed'
                );
            }
        }

        // Validate references to blocks or services
        if (!empty($manifest['blocks']) && is_array($manifest['blocks'])) {
            foreach ($manifest['blocks'] as $block_slug) {
                $block_post = get_page_by_path($block_slug, OBJECT, 'aw_gb_blocks');
                if (!$block_post) {
                    return $this->error(
                        "Referenced block '{$block_slug}' does not exist.",
                        "Call awesome/block-create to create the block first.",
                        'referenced_block_missing'
                    );
                }
            }
        }

        // Enforce Validation Token Gate
        $manifest_json = wp_json_encode($manifest);
        if (empty($validation_token) || !Validation_Token::verify($validation_token, $manifest_json, 'app')) {
            // Also accept if token was validated for app context
            $vtok_valid = Validation_Token::verify($validation_token, $manifest_json, 'app');
            if (!$vtok_valid && !empty($validation_token)) {
                // If token passed but slightly formatted differently, check hash of slug
                $vtok_valid = Validation_Token::verify($validation_token, $slug, 'app');
            }
            if (!$vtok_valid) {
                $this->audit_log('awesome/app-create', 'app', $slug, 'rejected', ['reason' => 'invalid_token']);
                return $this->error(
                    'A valid validation_token matching this manifest is required.',
                    'Call awesome/template-validate with context="app" before saving.',
                    'invalid_validation_token'
                );
            }
        }

        // Check collision
        $existing = get_page_by_path($slug, OBJECT, defined('AWESOME_APPS_POST_TYPE') ? AWESOME_APPS_POST_TYPE : 'aw2_app');
        if ($existing) {
            return $this->error(
                "App with slug '{$slug}' already exists.",
                "Call awesome/app-update to modify an existing app.",
                'slug_exists'
            );
        }

        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_type'    => defined('AWESOME_APPS_POST_TYPE') ? AWESOME_APPS_POST_TYPE : 'aw2_app',
            'post_status'  => 'draft',
            'post_content' => wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'post_excerpt' => $manifest['purpose'] ?? ($manifest['description'] ?? '')
        ], true);

        if (is_wp_error($post_id)) {
            return $this->error($post_id->get_error_message(), 'Retry with valid post fields.', 'db_error');
        }

        update_post_meta($post_id, '_aw_version', 1);

        $this->audit_log('awesome/app-create', 'app', $slug, 'success', ['post_id' => $post_id]);

        return $this->success([
            'slug'    => $slug,
            'post_id' => $post_id,
            'version' => 1,
            'status'  => 'draft',
            'message' => "App '{$slug}' created as draft."
        ]);
    }
}
