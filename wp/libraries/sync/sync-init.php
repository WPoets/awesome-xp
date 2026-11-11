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
	// Let third-party code add registry entries once everything is loaded.
	\do_action('awx_sync_register_references', Reference_Registry::instance());
}
\add_action('plugins_loaded', __NAMESPACE__ . '\\init', 20);
