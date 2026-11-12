<?php
/**
 * AWX Sync — database schema.
 *
 * Owns the five prefixed tables used by the Dev→Prod sync:
 *   awx_sync_runs    Dev manifest header (one row per sync run)
 *   awx_sync_steps   Dev progress / Prod idempotency store (one row per step)
 *   awx_sync_tokens  Prod scoped sync tokens (SHA-256 hash only)
 *   awx_sync_log     Prod audit log of every authenticated call
 *   awx_sync_nonces  Prod replay store for request nonces
 *   awx_sync_sets    Dev saved code-deploy selections ("deployment sets")
 *
 * Tables are created with dbDelta and gated by the `awx_sync_db_version`
 * option checked on `init`, so existing installs pick them up without a
 * plugin reactivation. Everything is per-blog via `$wpdb->prefix`, so
 * `install()` also works inside `switch_to_blog()`.
 *
 * @package aw2\sync
 */

namespace aw2\sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schema {

	/** Bump when the SQL below changes; `maybe_upgrade()` re-runs dbDelta. */
	const DB_VERSION = 3;

	/** Option holding the installed schema version. */
	const OPTION = 'awx_sync_db_version';

	/** Daily cron hook that purges old nonces and log rows. */
	const CRON_HOOK = 'awx_sync_daily_cleanup';

	/** Option row used as a short atomic lock around `install()`. */
	const LOCK_OPTION = 'awx_sync_db_upgrade_lock';

	/** Short name => table suffix (prefix is added at runtime). */
	const TABLES = array(
		'runs'   => 'awx_sync_runs',
		'steps'  => 'awx_sync_steps',
		'tokens' => 'awx_sync_tokens',
		'log'    => 'awx_sync_log',
		'nonces' => 'awx_sync_nonces',
		'sets'   => 'awx_sync_sets',
	);

	/**
	 * Wire the upgrade check and the daily cleanup cron.
	 * Call once from the loader (sync-init.php).
	 */
	public static function init(): void {
		\add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
		\add_action( 'init', array( __CLASS__, 'maybe_schedule_cleanup' ), 6 );
		\add_action( self::CRON_HOOK, array( __CLASS__, 'daily_cleanup' ) );
	}

	/**
	 * Fully prefixed table name for a short key (runs|steps|tokens|log|nonces).
	 *
	 * @throws \InvalidArgumentException For an unknown short name.
	 */
	public static function table( string $short ): string {
		global $wpdb;
		if ( ! isset( self::TABLES[ $short ] ) ) {
			throw new \InvalidArgumentException( 'Unknown AWX Sync table: ' . $short );
		}
		return $wpdb->prefix . self::TABLES[ $short ];
	}

	/**
	 * Create or upgrade the tables when the stored version is missing or lower
	 * than DB_VERSION. Guarded by an option-row lock so two concurrent requests on a
	 * fresh install do not both run dbDelta.
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) \get_option( self::OPTION, 0 );
		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		if ( ! self::acquire_lock() ) {
			return; // another request is installing; it will set the option.
		}

		try {
			// Re-check under the lock; the other request may have finished.
			$installed = (int) \get_option( self::OPTION, 0 );
			if ( $installed >= self::DB_VERSION ) {
				return;
			}
			self::install();
			\update_option( self::OPTION, self::DB_VERSION, false );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Run dbDelta for all tables (v3 widens object_uuid columns and adds runs.kind/label and the sets table). Idempotent; safe to call from an activation
	 * hook, a CLI command, or inside switch_to_blog(). Does not touch the
	 * version option — callers that want the gate updated use maybe_upgrade().
	 */
	public static function install(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		\dbDelta( self::sql() );
	}

	/**
	 * Which of the six tables currently exist, as [short => bool].
	 */
	public static function tables_exist(): array {
		global $wpdb;
		$out = array();
		foreach ( self::TABLES as $short => $suffix ) {
			$name          = $wpdb->prefix . $suffix;
			$found         = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
			$out[ $short ] = ( $found === $name );
		}
		return $out;
	}

	/**
	 * Delete nonces older than N seconds. Returns rows deleted.
	 */
	public static function purge_nonces( int $older_than_seconds = 900 ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, $older_than_seconds ) );
		$table  = self::table( 'nonces' );
		$rows   = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE seen_at < %s", $cutoff ) );
		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * Delete audit log rows older than N days. Returns rows deleted.
	 */
	public static function purge_logs( int $keep_days = 90 ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, $keep_days ) * DAY_IN_SECONDS );
		$table  = self::table( 'log' );
		$rows   = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * Cron callback: purge nonces and logs with default retention.
	 */
	public static function daily_cleanup(): void {
		$exists = self::tables_exist();
		if ( ! empty( $exists['nonces'] ) ) {
			self::purge_nonces();
		}
		if ( ! empty( $exists['log'] ) ) {
			self::purge_logs();
		}
	}

	/**
	 * Schedule the daily cleanup event if it is not already scheduled.
	 */
	public static function maybe_schedule_cleanup(): void {
		if ( ! \wp_next_scheduled( self::CRON_HOOK ) ) {
			\wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Drop all tables, clear the cron event and delete the version option.
	 * Not hooked anywhere; intended for a future uninstall.php.
	 */
	public static function uninstall(): void {
		global $wpdb;
		foreach ( self::TABLES as $suffix ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$suffix}" );
		}
		\wp_clear_scheduled_hook( self::CRON_HOOK );
		\delete_option( self::OPTION );
		\delete_option( self::LOCK_OPTION );
	}

	/**
	 * dbDelta-formatted CREATE TABLE statements for all six tables.
	 *
	 * Formatting rules dbDelta relies on: one column/index per line, two
	 * spaces after PRIMARY KEY, KEY definitions named, no backticks around
	 * column names, lowercase types.
	 */
	public static function sql(): array {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$runs = "CREATE TABLE {$p}awx_sync_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id char(36) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_uuid char(36) NOT NULL DEFAULT '',
  kind varchar(10) NOT NULL DEFAULT 'page',
  label varchar(100) NOT NULL DEFAULT '',
  target_url varchar(255) NOT NULL DEFAULT '',
  media_mode enum('push','pull','mixed') NOT NULL DEFAULT 'push',
  status varchar(20) NOT NULL DEFAULT 'planning',
  manifest longtext NOT NULL,
  plan longtext NULL,
  error text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY run_id (run_id),
  KEY post_id (post_id),
  KEY status (status),
  KEY kind (kind)
) ENGINE=InnoDB {$charset};";

		$steps = "CREATE TABLE {$p}awx_sync_steps (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id char(36) NOT NULL,
  step_id char(36) NOT NULL,
  seq int(10) unsigned NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL DEFAULT '',
  object_uuid varchar(191) NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  request_hash char(64) NULL,
  response longtext NULL,
  error text NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY run_step (run_id,step_id),
  KEY run_seq (run_id,seq)
) ENGINE=InnoDB {$charset};";

		$tokens = "CREATE TABLE {$p}awx_sync_tokens (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_id char(16) NOT NULL,
  label varchar(100) NOT NULL DEFAULT '',
  token_hash char(64) NOT NULL,
  secret_enc text NULL,
  scopes text NOT NULL,
  expires_at datetime NULL,
  last_used_at datetime NULL,
  last_used_ip varchar(45) NULL,
  revoked_at datetime NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY token_id (token_id)
) ENGINE=InnoDB {$charset};";

		$log = "CREATE TABLE {$p}awx_sync_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_id char(16) NULL,
  run_id char(36) NULL,
  route varchar(100) NOT NULL DEFAULT '',
  method varchar(8) NOT NULL DEFAULT '',
  object_uuid varchar(191) NULL,
  result varchar(20) NOT NULL DEFAULT '',
  http_status smallint(5) unsigned NOT NULL DEFAULT 0,
  message text NULL,
  ip varchar(45) NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY token_id (token_id),
  KEY run_id (run_id),
  KEY created_at (created_at)
) ENGINE=InnoDB {$charset};";

		$nonces = "CREATE TABLE {$p}awx_sync_nonces (
  nonce char(32) NOT NULL,
  token_id char(16) NOT NULL,
  seen_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (nonce),
  KEY seen_at (seen_at)
) ENGINE=InnoDB {$charset};";

		$sets = "CREATE TABLE {$p}awx_sync_sets (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL DEFAULT '',
  selection longtext NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  last_run_id char(36) NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY name (name)
) ENGINE=InnoDB {$charset};";

		return array( $runs, $steps, $tokens, $log, $nonces, $sets );
	}

	// ------------------------------------------------------------------
	// Locking
	// ------------------------------------------------------------------

	/**
	 * Take a short-lived lock. Prefers an atomic option insert (add_option
	 * fails if the row exists) over set_transient, which is not atomic on
	 * installs without an object cache.
	 */
	private static function acquire_lock(): bool {
		global $wpdb;

		// Expire a stale lock left by a crashed install.
		$held_since = (int) \get_option( self::LOCK_OPTION, 0 );
		if ( $held_since && ( time() - $held_since ) > 60 ) {
			\delete_option( self::LOCK_OPTION );
		}

		// add_option() returns false if the option already exists, which is
		// the atomic check-and-set we need (INSERT IGNORE on wp_options).
		$prev = $wpdb->suppress_errors( true );
		$got = \add_option( self::LOCK_OPTION, time(), '', 'no' );
		$wpdb->suppress_errors( $prev );
		return (bool) $got;
	}

	private static function release_lock(): void {
		\delete_option( self::LOCK_OPTION );
	}
}
