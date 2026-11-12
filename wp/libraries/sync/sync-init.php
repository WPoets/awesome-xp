<?php
/**
 * AWX Sync bootstrap.
 *
 * Moves one saved Gutenberg page (plus the media, reusable blocks and terms it
 * depends on) from a source site to a target site as a series of small,
 * idempotent REST calls. See docs in the architecture specification v2.
 *
 * Every site sets AWX_SYNC_ROLE in wp-config.php to 'source', 'target' or
 * 'none' (the default). Source sites show the Sync button and hold the token;
 * target sites expose the receiving routes; 'none' does neither.
 */
namespace aw2\sync;

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('AWX_SYNC_VERSION')) {
	\define('AWX_SYNC_VERSION', '0.1.0');
}
if (!defined('AWX_SYNC_PROTOCOL')) {
	\define('AWX_SYNC_PROTOCOL', 1);
}
if (!defined('AWX_SYNC_ROLE')) {
	\define('AWX_SYNC_ROLE', 'none');
}
if (!defined('AWX_SYNC_DIR')) {
	\define('AWX_SYNC_DIR', __DIR__ . '/');
}

// Core transform layer (always loaded: the registry hash is part of the handshake on both roles).
require_once __DIR__ . '/lib/interface-site-resolver.php';
require_once __DIR__ . '/lib/class-block-serializer.php';
require_once __DIR__ . '/lib/class-reference-registry.php';
require_once __DIR__ . '/lib/class-transform-result.php';
require_once __DIR__ . '/lib/class-transformer.php';
require_once __DIR__ . '/lib/class-extractor.php';
require_once __DIR__ . '/lib/class-wp-site-resolver.php';
require_once __DIR__ . '/lib/class-signer.php';
require_once __DIR__ . '/lib/class-planner.php';
require_once __DIR__ . '/lib/class-token-store.php';
require_once __DIR__ . '/lib/class-nonce-store.php';
require_once __DIR__ . '/lib/class-audit-log.php';
require_once __DIR__ . '/lib/class-step-store.php';
require_once __DIR__ . '/lib/class-manifest-store.php';
require_once __DIR__ . '/lib/class-request-auth.php';
require_once __DIR__ . '/lib/class-service-user.php';
require_once __DIR__ . '/lib/class-receiver.php';
require_once __DIR__ . '/lib/class-code-receiver.php';
require_once __DIR__ . '/lib/class-media-handler.php';
require_once __DIR__ . '/lib/class-prod-routes.php';
require_once __DIR__ . '/lib/class-sync-client.php';
require_once __DIR__ . '/lib/class-export-routes.php';
require_once __DIR__ . '/lib/class-orchestrator.php';
require_once __DIR__ . '/lib/class-code-item.php';
require_once __DIR__ . '/lib/class-code-catalog.php';
require_once __DIR__ . '/lib/class-code-planner.php';
require_once __DIR__ . '/lib/class-code-orchestrator.php';
require_once __DIR__ . '/lib/class-deploy-sets.php';
require_once __DIR__ . '/admin/class-tokens-page.php';
require_once __DIR__ . '/admin/class-deploy-page.php';

// Components delivered by other work streams; loaded when present so the
// plugin keeps working while the feature is assembled.
foreach (array('class-schema.php', 'class-identity.php', 'class-sync-cli.php') as $optional) {
	if (is_readable(__DIR__ . '/lib/' . $optional)) {
		require_once __DIR__ . '/lib/' . $optional;
	}
}

/**
 * Role helpers.
 */
function role(): string {
	$role = strtolower((string) AWX_SYNC_ROLE);
	return in_array($role, array('source', 'target'), true) ? $role : 'none';
}
function is_source(): bool {
	return role() === 'source';
}
function is_target(): bool {
	return role() === 'target';
}

/**
 * Public registration API for blocks that live outside this plugin.
 *
 * @param string $block_name e.g. 'acme/hero'
 * @param array  $entry      ['attrs' => ['imageId' => 'media', 'galleryIds' => 'media[]', 'linkPostId' => 'post'], 'html' => ['wp-image']]
 */
function register_reference(string $block_name, array $entry): void {
	Reference_Registry::instance()->register($block_name, $entry);
}

/**
 * Wire-up.
 */
function init(): void {
	if (class_exists(__NAMESPACE__ . '\\Schema') && method_exists(__NAMESPACE__ . '\\Schema', 'init')) {
		Schema::init();
	}
	if (class_exists(__NAMESPACE__ . '\\Identity') && method_exists(__NAMESPACE__ . '\\Identity', 'init')) {
		Identity::init();
	}
	if (defined('WP_CLI') && WP_CLI && class_exists(__NAMESPACE__ . '\\Sync_CLI_Command') && method_exists(__NAMESPACE__ . '\\Sync_CLI_Command', 'register')) {
		Sync_CLI_Command::register();
	}
	// Target side: signed receiving routes, service user and the tokens screen.
	Prod_Routes::init();
	if (\is_admin()) {
		Tokens_Page::init();
	}
	// Source side: signed export URLs for pull mode, abilities and the editor UI.
	Export_Routes::init();
	if (is_source() && \class_exists('\\aw2\\abilities\\operations\\Operation_Base')) {
		require_once __DIR__ . '/abilities/class-sync-abilities.php';
		abilities\Sync_Abilities::register();
	}
	if (is_source() && \is_readable(__DIR__ . '/lib/class-editor-ui.php')) {
		require_once __DIR__ . '/lib/class-editor-ui.php';
		Editor_UI::init();
	}
	if (is_source() && \is_admin()) {
		Deploy_Page::init();
	}
	// App pages/posts collections and site templates are content: let the page pipeline carry them on both roles.
	$content_types = function (array $types) {
		$template_types = \class_exists(__NAMESPACE__ . '\\Code_Catalog') && \method_exists(__NAMESPACE__ . '\\Code_Catalog', 'template_post_types')
			? Code_Catalog::template_post_types()
			: array('wp_template', 'wp_template_part');
		return \array_values(\array_unique(\array_merge($types, Code_Catalog::content_post_types(), $template_types)));
	};
	\add_filter('awx_sync_allowed_post_types', $content_types);
	\add_filter('awx_sync_tracked_post_types', $content_types);
	// Let third-party code add registry entries once everything is loaded.
	\do_action('awx_sync_register_references', Reference_Registry::instance());
}
\add_action('plugins_loaded', __NAMESPACE__ . '\\init', 20);
