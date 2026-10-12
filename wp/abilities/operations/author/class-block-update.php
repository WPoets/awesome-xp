<?php
namespace aw2\abilities\operations\author;

use aw2\abilities\operations\Operation_Base;
use aw2\abilities\guardrails\Validation_Token;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/block-update
 *
 * Saves changes to a block as a new draft fork copy.
 * Does not touch or overwrite the live version.
 */
class Block_Update extends Operation_Base
{
    public function execute(array $params = [])
    {
        global $wpdb;

        $slug_input = isset($params['slug']) ? trim((string)$params['slug']) : '';
        $config = isset($params['config']) && is_array($params['config']) ? $params['config'] : null;
        $template = isset($params['template']) ? (string)$params['template'] : null;
        $validation_token = isset($params['validation_token']) ? (string)$params['validation_token'] : '';
        $if_version = isset($params['if_version']) ? (int)$params['if_version'] : null;

        if (empty($slug_input)) {
            return $this->error('Block slug or post ID is required.', 'Pass {"slug": "block-slug"} in parameters.', 'missing_slug');
        }

        // 1. Locate the block post by numeric ID, slug, title, or internal name across all statuses
        $post = null;
        if (is_numeric($slug_input)) {
            $p = get_post((int)$slug_input);
            if ($p && $p->post_type === 'aw_gb_blocks') {
                $post = $p;
            }
        }
        if (!$post) {
            $slug_clean = sanitize_title($slug_input);
            $posts = get_posts([
                'name'           => $slug_clean,
                'post_type'      => 'aw_gb_blocks',
                'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
                'posts_per_page' => 1
            ]);
            if (!empty($posts)) {
                $post = $posts[0];
            }
        }
        if (!$post && function_exists('get_page_by_path')) {
            $post = get_page_by_path($slug_input, OBJECT, 'aw_gb_blocks');
        }
        if (!$post) {
            $posts_by_title = get_posts([
                'title'          => $slug_input,
                'post_type'      => 'aw_gb_blocks',
                'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
                'posts_per_page' => 1
            ]);
            if (!empty($posts_by_title)) {
                $post = $posts_by_title[0];
            }
        }
        if (!$post && $wpdb) {
            $like_tag = '%[name]' . $wpdb->esc_like($slug_input) . '[/name]%';
            $like_json = '%"name"%' . $wpdb->esc_like($slug_input) . '%';
            $p_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'aw_gb_blocks' AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID DESC LIMIT 1",
                $like_tag,
                $like_json
            ));
            if ($p_id) {
                $post = get_post((int)$p_id);
            }
        }

        if (!$post) {
            return $this->error(
                "Block '{$slug_input}' does not exist.",
                "Call awesome/block-create to create a new block or call awesome/block-list to check existing blocks.",
                'block_not_found'
            );
        }

        $slug = $post->post_name;

        // 2. Optimistic locking / version check
        $current_version = (int)get_post_meta($post->ID, '_aw_version', true);
        if ($current_version <= 0) {
            $current_version = 1;
        }

        if ($if_version !== null && $if_version !== $current_version) {
            return $this->error(
                "Stale version detected. Current version is {$current_version}, but if_version was {$if_version}.",
                "Call awesome/block-get to fetch the latest version before retrying.",
                'version_conflict'
            );
        }

        // 3. Extract existing payload / template
        $existing_decoded = json_decode($post->post_content, true);
        $is_json_format = is_array($existing_decoded);

        if ($is_json_format) {
            $final_config = $config !== null ? $config : ($existing_decoded['config'] ?? []);
            $final_template = $template !== null ? $template : ($existing_decoded['template'] ?? '');
        } else {
            // Raw shortcode DSL block template
            $final_template = $template !== null ? $template : $post->post_content;
            $parsed_config = [];
            if (preg_match('/\[config\](.*?)\[\/config\]/s', $final_template, $cfg_match)) {
                if (preg_match('/\[name\](.*?)\[\/name\]/s', $cfg_match[1], $nm)) $parsed_config['name'] = trim($nm[1]);
                if (preg_match('/\[title\](.*?)\[\/title\]/s', $cfg_match[1], $ti)) $parsed_config['title'] = trim($ti[1]);
                if (preg_match('/\[category\](.*?)\[\/category\]/s', $cfg_match[1], $cat)) $parsed_config['category'] = trim($cat[1]);
            }
            $final_config = $config !== null ? $config : $parsed_config;
        }

        // 4. Enforce Validation Token Gate if template was provided or modified
        if ($template !== null) {
            if (empty($validation_token)) {
                return $this->error(
                    'Template was modified but validation_token was not provided.',
                    'Call awesome/template-validate with your template code to receive a validation token.',
                    'missing_validation_token'
                );
            }

            if (!Validation_Token::verify($validation_token, $final_template, 'block')) {
                $this->audit_log('awesome/block-update', 'block', $slug, 'rejected', ['reason' => 'invalid_token']);
                return $this->error(
                    'Validation token does not match the submitted template or has expired.',
                    'Call awesome/template-validate with the exact template code being saved.',
                    'invalid_validation_token'
                );
            }
        }

        // Determine content representation to save
        if (!$is_json_format || preg_match('/^\s*\[(templates\.add|gt_blocks|config|flow)/', $final_template)) {
            $content = $final_template;
        } else {
            $payload = [
                'config'   => $final_config,
                'template' => $final_template
            ];
            $content = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $new_title = $final_config['title'] ?? $post->post_title;
        $new_desc  = $final_config['description'] ?? $post->post_excerpt;

        // 5. If explicitly targeting by numeric post ID (e.g. patch draft #2229) or patch_draft flag:
        if ((is_numeric($slug_input) || !empty($params['patch_draft'])) && $post->post_status === 'draft') {
            wp_update_post([
                'ID'           => $post->ID,
                'post_title'   => $new_title,
                'post_content' => $content,
                'post_excerpt' => $new_desc
            ]);

            $this->audit_log('awesome/block-update', 'block', $slug, 'success', ['draft_id' => $post->ID]);

            return $this->success([
                'slug'          => $slug,
                'draft_id'      => $post->ID,
                'live_version'  => null,
                'draft_version' => $current_version,
                'status'        => 'draft',
                'message'       => "Successfully patched draft post #{$post->ID} ('{$slug}')."
            ]);
        }

        // 6. Otherwise manage linked draft fork copy (copy-on-write)
        $existing_drafts = get_posts([
            'post_type'   => 'aw_gb_blocks',
            'meta_key'    => '_aw_forked_from',
            'meta_value'  => $slug,
            'post_status' => 'draft'
        ]);

        if (!empty($existing_drafts)) {
            $draft_id = $existing_drafts[0]->ID;
            wp_update_post([
                'ID'           => $draft_id,
                'post_title'   => $new_title,
                'post_content' => $content,
                'post_excerpt' => $new_desc
            ]);
        } else {
            $suffix = function_exists('wp_generate_password') ? wp_generate_password(6, false) : substr(md5(uniqid()), 0, 6);
            $draft_id = wp_insert_post([
                'post_title'   => $new_title . ' (Draft)',
                'post_name'    => "{$slug}-draft-{$suffix}",
                'post_type'    => 'aw_gb_blocks',
                'post_status'  => 'draft',
                'post_content' => $content,
                'post_excerpt' => $new_desc
            ]);
            update_post_meta($draft_id, '_aw_forked_from', $slug);
            update_post_meta($draft_id, '_aw_is_draft_fork', 1);
            update_post_meta($draft_id, '_aw_source_post_id', $post->ID);
        }

        $next_version = $current_version + 1;
        update_post_meta($draft_id, '_aw_target_version', $next_version);

        $this->audit_log('awesome/block-update', 'block', $slug, 'success', ['draft_id' => $draft_id]);

        return $this->success([
            'slug'           => $slug,
            'draft_id'       => $draft_id,
            'live_version'   => $current_version,
            'draft_version'  => $next_version,
            'status'         => 'draft',
            'message'        => "Saved draft version {$next_version} for block '{$slug}'. Live artifact is untouched. Call awesome/version-publish to promote to live."
        ]);
    }
}
