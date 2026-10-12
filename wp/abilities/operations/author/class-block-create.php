<?php
namespace aw2\abilities\operations\author;

use aw2\abilities\operations\Operation_Base;
use aw2\abilities\guardrails\Validation_Token;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/block-create
 *
 * Creates a new Gutenberg block as a draft.
 * Only use after block-list confirms no existing block or variation fits.
 */
class Block_Create extends Operation_Base
{
    public function execute(array $params = [])
    {
        $config = isset($params['config']) && is_array($params['config']) ? $params['config'] : [];
        $template = isset($params['template']) ? (string)$params['template'] : '';
        $validation_token = isset($params['validation_token']) ? (string)$params['validation_token'] : '';

        // 1. Validate required fields
        if (empty($config) || empty($config['name']) || empty($config['title'])) {
            return $this->error(
                "Block config must include 'name' and 'title'.",
                "Ensure your config payload defines 'name' (slug) and 'title'.",
                'invalid_config'
            );
        }

        $slug = sanitize_title($config['name']);
        $title = sanitize_text_field($config['title']);

        // 2. Enforce Validation Token Gate
        if (empty($validation_token)) {
            return $this->error(
                'Missing validation token.',
                'Call awesome/template-validate with your template code first to receive a validation token.',
                'missing_validation_token'
            );
        }

        if (!Validation_Token::verify($validation_token, $template, 'block')) {
            $this->audit_log('awesome/block-create', 'block', $slug, 'rejected', ['reason' => 'invalid_token']);
            return $this->error(
                'Validation token does not match the submitted template or has expired.',
                'Call awesome/template-validate with the exact template code being saved.',
                'invalid_validation_token'
            );
        }

        // 3. Slug collision check: Slug collision is an error, not an overwrite!
        $existing = null;
        $existing_posts = get_posts([
            'name'           => $slug,
            'post_type'      => 'aw_gb_blocks',
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 1
        ]);
        if (!empty($existing_posts)) {
            $existing = $existing_posts[0];
        }
        if (!$existing && function_exists('get_page_by_path')) {
            $existing = get_page_by_path($slug, OBJECT, 'aw_gb_blocks');
        }
        if ($existing) {
            return $this->error(
                "A block with slug '{$slug}' already exists.",
                "Call awesome/block-update to modify an existing block, or choose a new slug.",
                'slug_exists'
            );
        }

        // 4. Validate DGB fields against Universal Dictionary recommendations
        $dict_file = dirname(dirname(__DIR__)) . '/data/universal-dictionary.json';
        $dictionary = file_exists($dict_file) ? json_decode(file_get_contents($dict_file), true)['fields'] ?? [] : [];

        // 5. Store block definition in aw_gb_blocks as draft
        if (preg_match('/^\s*\[(templates\.add|gt_blocks|config|flow)/', $template)) {
            $post_content = $template;
        } else {
            $payload = [
                'config'   => $config,
                'template' => $template
            ];
            $post_content = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $post_data = [
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_type'    => 'aw_gb_blocks',
            'post_status'  => 'draft',
            'post_content' => $post_content,
            'post_excerpt' => $config['description'] ?? ''
        ];

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            return $this->error(
                $post_id->get_error_message(),
                'Check database write permissions and try again.',
                'db_insert_failed'
            );
        }

        // Tag with initial version meta
        update_post_meta($post_id, '_aw_version', 1);

        $this->audit_log('awesome/block-create', 'block', $slug, 'success', ['post_id' => $post_id]);

        return $this->success([
            'slug'    => $slug,
            'post_id' => $post_id,
            'version' => 1,
            'status'  => 'draft',
            'message' => "Block '{$slug}' created as draft. Test with awesome/template-preview and promote to live with awesome/version-publish when ready."
        ]);
    }
}
