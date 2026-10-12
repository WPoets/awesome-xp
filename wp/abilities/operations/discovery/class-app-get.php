<?php
namespace aw2\abilities\operations\discovery;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/app-get
 *
 * Returns an app's full manifest, including the services, modules, and blocks it uses.
 * Secrets and credentials are fully redacted.
 */
class App_Get extends Operation_Base
{
    public function execute(array $params = [])
    {
        $slug = isset($params['slug']) ? sanitize_title(trim($params['slug'])) : '';
        if (empty($slug)) {
            return $this->error('The app slug parameter is required.', 'Provide slug in inputs, e.g. {"slug": "my-app"}', 'missing_slug');
        }

        $manifest = null;
        $status = 'publish';
        $post_id = 0;

        // Check memory registered apps
        $registered_apps =& \aw2_library::get_array_ref('apps');
        if (isset($registered_apps[$slug])) {
            $manifest = $registered_apps[$slug];
        }

        // Check CPT
        $post = get_page_by_path($slug, OBJECT, defined('AWESOME_APPS_POST_TYPE') ? AWESOME_APPS_POST_TYPE : 'aw2_app');
        if ($post) {
            $post_id = $post->ID;
            $status = $post->post_status;
            if (!$manifest) {
                $decoded = json_decode($post->post_content, true);
                $manifest = is_array($decoded) ? $decoded : ['content' => $post->post_content];
                $manifest['name'] = $post->post_title;
                $manifest['slug'] = $post->post_name;
            }
        }

        if (!$manifest) {
            return $this->error(
                "App with slug '{$slug}' not found.",
                "Call awesome/app-list to see all available apps.",
                'app_not_found'
            );
        }

        // Redact any secrets or credentials
        $clean_manifest = $this->redact_credentials($manifest);

        // Find drafts if any
        $drafts = [];
        if ($post_id) {
            $draft_posts = get_posts([
                'post_type'   => defined('AWESOME_APPS_POST_TYPE') ? AWESOME_APPS_POST_TYPE : 'aw2_app',
                'meta_key'    => '_aw_forked_from',
                'meta_value'  => $slug,
                'post_status' => 'draft'
            ]);
            foreach ($draft_posts as $dp) {
                $drafts[] = [
                    'id'            => $dp->ID,
                    'title'         => $dp->post_title,
                    'modified_date' => $dp->post_modified
                ];
            }
        }

        return $this->success([
            'slug'            => $slug,
            'status'          => $status,
            'manifest'        => $clean_manifest,
            'has_open_draft'  => !empty($drafts),
            'drafts'          => $drafts
        ]);
    }

    private function redact_credentials(array $data)
    {
        $sensitive_keys = ['password', 'secret', 'token', 'api_key', 'key', 'auth', 'credentials'];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->redact_credentials($v);
            } elseif (is_string($k)) {
                foreach ($sensitive_keys as $s) {
                    if (stripos($k, $s) !== false) {
                        $data[$k] = '***REDACTED***';
                        break;
                    }
                }
            }
        }
        return $data;
    }
}
