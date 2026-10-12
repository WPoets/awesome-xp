<?php
namespace aw2\abilities\operations\discovery;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/app-list
 *
 * Lists registered apps with name, slug, status, and a one-line purpose.
 * Use before creating an app to check whether one already exists.
 */
class App_List extends Operation_Base
{
    public function execute(array $params = [])
    {
        $status_filter = isset($params['status']) ? sanitize_text_field($params['status']) : '';
        $apps_summary = [];

        // 1. Fetch apps registered in memory
        $registered_apps =& \aw2_library::get_array_ref('apps');
        if (is_array($registered_apps)) {
            foreach ($registered_apps as $slug => $app) {
                $status = $app['status'] ?? 'publish';
                if (!empty($status_filter) && $status !== $status_filter) {
                    continue;
                }
                $apps_summary[$slug] = [
                    'name'        => $app['name'] ?? $slug,
                    'slug'        => $slug,
                    'status'      => $status,
                    'purpose'     => $app['purpose'] ?? ($app['description'] ?? 'No purpose defined.'),
                    'post_type'   => $app['collection']['pages']['post_type'] ?? ''
                ];
            }
        }

        // 2. Query aw2_app post type
        $posts = get_posts([
            'post_type'      => defined('AWESOME_APPS_POST_TYPE') ? AWESOME_APPS_POST_TYPE : 'aw2_app',
            'post_status'    => !empty($status_filter) ? $status_filter : ['publish', 'draft'],
            'posts_per_page' => 100
        ]);

        foreach ($posts as $post) {
            $slug = $post->post_name;
            if (!isset($apps_summary[$slug])) {
                $apps_summary[$slug] = [
                    'name'    => $post->post_title,
                    'slug'    => $slug,
                    'status'  => $post->post_status,
                    'purpose' => !empty($post->post_excerpt) ? $post->post_excerpt : wp_trim_words($post->post_content, 15)
                ];
            }
        }

        return $this->success(array_values($apps_summary));
    }
}
