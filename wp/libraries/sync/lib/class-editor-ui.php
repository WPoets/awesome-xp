<?php
/**
 * Source-side editor integration: enqueues the "Sync to Production" sidebar
 * for developers on syncable post types.
 */
namespace aw2\sync;

class Editor_UI {

	public static function init(): void {
		\add_action('enqueue_block_editor_assets', array(__CLASS__, 'enqueue'));
	}

	public static function enqueue(): void {
		if (!is_source() || !\current_user_can('develop_for_awesomeui')) {
			return;
		}
		$post = \get_post();
		if (!$post || !\current_user_can('edit_post', $post->ID)) {
			return;
		}
		$public = \get_post_types(array('public' => true), 'names');
		unset($public['attachment']);
		if (!isset($public[$post->post_type])) {
			return;
		}
		$configured = (\defined('AWX_SYNC_TARGET_URL') || \getenv('AWX_SYNC_TARGET_URL')) && (\defined('AWX_SYNC_TOKEN') || \getenv('AWX_SYNC_TOKEN'));
		$file = AWX_SYNC_DIR . 'assets/js/sync-editor.js';
		\wp_enqueue_script(
			'awx-sync-editor',
			\plugins_url('assets/js/sync-editor.js', AWX_SYNC_DIR . 'sync-init.php'),
			array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-notices'),
			\file_exists($file) ? (string) \filemtime($file) : AWX_SYNC_VERSION,
			true
		);
		\wp_localize_script('awx-sync-editor', 'awxSync', array(
			'postId'     => (int) $post->ID,
			'postType'   => $post->post_type,
			'configured' => $configured,
			'targetUrl'  => \defined('AWX_SYNC_TARGET_URL') ? (string) AWX_SYNC_TARGET_URL : (string) \getenv('AWX_SYNC_TARGET_URL'),
			'abilities'  => 'awesome-xp/v1/abilities/',
		));
	}
}
