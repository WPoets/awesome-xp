<?php
/**
 * Source-side "Deploy Code" screen under Awesome Enterprise: select → plan
 * (diff) → deploy → summary. The page is a wp.element app driving the
 * awesome/sync-code-* abilities; this class only registers the menu and
 * enqueues it.
 */
namespace aw2\sync;

class Deploy_Page {

	const SLUG = 'awx-sync-deploy';
	const CAP  = 'develop_for_awesomeui';

	public static function init(): void {
		\add_action('admin_menu', array(__CLASS__, 'menu'), 20);
	}

	public static function menu(): void {
		$parent = isset($GLOBALS['admin_page_hooks']['awesome-enterprise']) ? 'awesome-enterprise' : 'tools.php';
		$hook = \add_submenu_page($parent, 'Deploy Code to Production', 'Deploy Code', self::CAP, self::SLUG, array(__CLASS__, 'render'));
		if ($hook) {
			\add_action('load-' . $hook, function () {
				\add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
			});
		}
	}

	public static function enqueue(): void {
		$js  = AWX_SYNC_DIR . 'assets/js/sync-deploy.js';
		$css = AWX_SYNC_DIR . 'assets/css/sync-deploy.css';
		\wp_enqueue_script(
			'awx-sync-deploy',
			\plugins_url('assets/js/sync-deploy.js', AWX_SYNC_DIR . 'sync-init.php'),
			array('wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'),
			\file_exists($js) ? (string) \filemtime($js) : AWX_SYNC_VERSION,
			true
		);
		\wp_enqueue_style('wp-components');
		\wp_enqueue_style(
			'awx-sync-deploy',
			\plugins_url('assets/css/sync-deploy.css', AWX_SYNC_DIR . 'sync-init.php'),
			array('wp-components'),
			\file_exists($css) ? (string) \filemtime($css) : AWX_SYNC_VERSION
		);
		$configured = (\defined('AWX_SYNC_TARGET_URL') || \getenv('AWX_SYNC_TARGET_URL')) && (\defined('AWX_SYNC_TOKEN') || \getenv('AWX_SYNC_TOKEN'));
		\wp_localize_script('awx-sync-deploy', 'awxSyncDeploy', array(
			'configured' => $configured,
			'targetUrl'  => \defined('AWX_SYNC_TARGET_URL') ? (string) AWX_SYNC_TARGET_URL : (string) \getenv('AWX_SYNC_TARGET_URL'),
			'abilities'  => 'awesome-xp/v1/abilities/',
			'user'       => \get_current_user_id(),
		));
	}

	public static function render(): void {
		if (!\current_user_can(self::CAP) && !\current_user_can('manage_options')) {
			\wp_die('Not allowed.');
		}
		echo '<div class="wrap awx-sync-deploy"><h1>Deploy Code to Production</h1>';
		if (!is_source()) {
			echo '<div class="notice notice-warning"><p>This site is not a sync source. Add <code>define(\'AWX_SYNC_ROLE\', \'source\');</code> to wp-config.php.</p></div>';
		}
		echo '<div id="awx-sync-deploy-app"><p>Loading…</p></div></div>';
	}
}
