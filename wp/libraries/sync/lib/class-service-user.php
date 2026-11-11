<?php
/**
 * The Prod-side service user that owns sync writes.
 *
 * Role `awx_sync` holds `unfiltered_html` so synced block markup is not
 * stripped by kses. The user has a random, never-shown password and cannot
 * be used to log in (see the authenticate filter below). Because the user is
 * powerful, the sync routes only ever write objects that carry a sync UUID.
 */
namespace aw2\sync;

class Service_User {

	const ROLE  = 'awx_sync';
	const LOGIN = 'awx-sync';

	public static function init(): void {
		\add_filter('authenticate', array(__CLASS__, 'block_login'), 99, 2);
		\add_filter('wp_pre_insert_user_data', array(__CLASS__, 'guard_role_assignment'), 10, 3);
	}

	public static function capabilities(): array {
		return array(
			'read'                   => true,
			'unfiltered_html'        => true,
			'upload_files'           => true,
			'edit_posts'             => true,
			'edit_others_posts'      => true,
			'edit_private_posts'     => true,
			'edit_published_posts'   => true,
			'publish_posts'          => true,
			'edit_pages'             => true,
			'edit_others_pages'      => true,
			'edit_private_pages'     => true,
			'edit_published_pages'   => true,
			'publish_pages'          => true,
			'manage_categories'      => true,
			'assign_terms'           => true,
			'edit_terms'             => true,
			'edit_theme_options'     => false,
		);
	}

	public static function ensure_role(): void {
		$role = \get_role(self::ROLE);
		if (!$role) {
			\add_role(self::ROLE, 'AWX Sync Service', self::capabilities());
			return;
		}
		foreach (self::capabilities() as $cap => $grant) {
			if ($grant && !$role->has_cap($cap)) {
				$role->add_cap($cap);
			}
		}
	}

	/** @return int user id (created on first use) */
	public static function ensure_user(): int {
		self::ensure_role();
		$user = \get_user_by('login', self::LOGIN);
		if ($user) {
			if (!\in_array(self::ROLE, (array) $user->roles, true)) {
				$user->set_role(self::ROLE);
			}
			return (int) $user->ID;
		}
		$id = \wp_insert_user(array(
			'user_login'   => self::LOGIN,
			'user_pass'    => \wp_generate_password(64, true, true),
			'user_email'   => self::LOGIN . '@' . \wp_parse_url(\home_url(), PHP_URL_HOST),
			'display_name' => 'AWX Sync',
			'role'         => self::ROLE,
			'show_admin_bar_front' => 'false',
		));
		if (\is_wp_error($id)) {
			throw new \RuntimeException('Cannot create the awx-sync service user: ' . $id->get_error_message());
		}
		\update_user_meta((int) $id, 'awx_sync_service_user', 1);
		return (int) $id;
	}

	/** Can this site write unfiltered HTML as the service user? */
	public static function has_unfiltered_html(): bool {
		if (\defined('DISALLOW_UNFILTERED_HTML') && DISALLOW_UNFILTERED_HTML) {
			return false;
		}
		self::ensure_role();
		$role = \get_role(self::ROLE);
		return $role ? (bool) $role->has_cap('unfiltered_html') : false;
	}

	/**
	 * Run $fn as the service user and restore the previous user afterwards.
	 */
	public static function run(callable $fn) {
		$previous = \get_current_user_id();
		$uid = self::ensure_user();
		\wp_set_current_user($uid);
		try {
			return $fn($uid);
		} finally {
			\wp_set_current_user($previous);
		}
	}

	/** The service user can never log in interactively. */
	public static function block_login($user, $username) {
		if ($username === self::LOGIN || ($user instanceof \WP_User && $user->user_login === self::LOGIN)) {
			return new \WP_Error('awx_sync_service_user', 'This account is reserved for AWX Sync and cannot log in.');
		}
		return $user;
	}

	/** Nobody else gets the role through the users screen. */
	public static function guard_role_assignment($data, $update, $user_id) {
		if (isset($_POST['role']) && $_POST['role'] === self::ROLE && (!$user_id || \get_user_by('id', (int) $user_id)->user_login !== self::LOGIN)) { // phpcs:ignore WordPress.Security.NonceVerification
			unset($_POST['role']);
		}
		return $data;
	}
}
