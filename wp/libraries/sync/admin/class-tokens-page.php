<?php
/**
 * Settings > AWX Sync Tokens (target sites): create, revoke and inspect
 * tokens; view and export the audit log.
 */
namespace aw2\sync;

class Tokens_Page {

	const SLUG = 'awx-sync-tokens';
	const CAP  = 'manage_options';

	public static function init(): void {
		if (!is_target()) {
			return;
		}
		\add_action('admin_menu', array(__CLASS__, 'menu'), 30);
		\add_action('admin_post_awx_sync_create_token', array(__CLASS__, 'handle_create'));
		\add_action('admin_post_awx_sync_revoke_token', array(__CLASS__, 'handle_revoke'));
		\add_action('admin_post_awx_sync_export_log', array(__CLASS__, 'handle_export'));
	}

	public static function menu(): void {
		\add_options_page('AWX Sync Tokens', 'AWX Sync Tokens', self::CAP, self::SLUG, array(__CLASS__, 'render'));
	}

	private static function back(array $args = array()): void {
		\wp_safe_redirect(\add_query_arg($args, \admin_url('options-general.php?page=' . self::SLUG)));
		exit;
	}

	public static function handle_create(): void {
		if (!\current_user_can(self::CAP)) {
			\wp_die('Not allowed.');
		}
		\check_admin_referer('awx_sync_create_token');
		$label = \sanitize_text_field((string) ($_POST['label'] ?? ''));
		$days  = isset($_POST['ttl_days']) ? (int) $_POST['ttl_days'] : Token_Store::DEFAULT_TTL_DAYS;
		$days  = \max(0, \min(3650, $days));
		try {
			$t = Token_Store::create($label !== '' ? $label : 'Dev site', $days);
		} catch (\Throwable $e) {
			self::back(array('awx_error' => \rawurlencode($e->getMessage())));
		}
		\set_transient('awx_sync_new_token_' . \get_current_user_id(), $t['token'], 300);
		self::back(array('awx_created' => $t['token_id']));
	}

	public static function handle_revoke(): void {
		if (!\current_user_can(self::CAP)) {
			\wp_die('Not allowed.');
		}
		$id = \sanitize_text_field((string) ($_POST['token_id'] ?? ''));
		\check_admin_referer('awx_sync_revoke_' . $id);
		Token_Store::revoke($id);
		self::back(array('awx_revoked' => $id));
	}

	public static function handle_export(): void {
		if (!\current_user_can(self::CAP)) {
			\wp_die('Not allowed.');
		}
		\check_admin_referer('awx_sync_export_log');
		$rows = Audit_Log::recent(5000);
		\nocache_headers();
		\header('Content-Type: text/csv; charset=utf-8');
		\header('Content-Disposition: attachment; filename="awx-sync-audit-' . \gmdate('Ymd-His') . '.csv"');
		echo Audit_Log::csv($rows); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function render(): void {
		if (!\current_user_can(self::CAP)) {
			return;
		}
		$new = \get_transient('awx_sync_new_token_' . \get_current_user_id());
		if ($new) {
			\delete_transient('awx_sync_new_token_' . \get_current_user_id());
		}
		$tokens = Token_Store::all();
		$log    = Audit_Log::recent(200);
		$tables = Schema::tables_exist();
		?>
		<div class="wrap">
			<h1>AWX Sync Tokens</h1>
			<p>This site is a sync <strong>target</strong>. Tokens let a source site write synced pages, reusable blocks and media here, and nothing else.
				Source host for pull mode: <code><?php echo \esc_html(Receiver::source_url() ?: 'not set (AWX_SYNC_SOURCE_URL)'); ?></code>.
				Push uploads: <code><?php echo Receiver::push_allowed() ? 'allowed' : 'disabled (AWX_SYNC_ALLOW_PUSH)'; ?></code>.
				Unfiltered HTML for the service user: <code><?php echo Service_User::has_unfiltered_html() ? 'yes' : 'no (DISALLOW_UNFILTERED_HTML)'; ?></code>.
			</p>
			<?php if (\in_array(false, $tables, true)) : ?>
				<div class="notice notice-error"><p>Some sync tables are missing. Deactivate and reactivate the plugin, or run <code>wp awx sync install</code>.</p></div>
			<?php endif; ?>
			<?php if (!empty($_GET['awx_error'])) : ?>
				<div class="notice notice-error"><p><?php echo \esc_html(\sanitize_text_field(\wp_unslash($_GET['awx_error']))); ?></p></div>
			<?php endif; ?>
			<?php if ($new) : ?>
				<div class="notice notice-success">
					<p><strong>Copy this token now. It is shown once.</strong></p>
					<p><code style="font-size:14px;user-select:all"><?php echo \esc_html($new); ?></code></p>
					<p>On the source site add to <code>wp-config.php</code>:<br>
						<code>define('AWX_SYNC_ROLE', 'source');</code><br>
						<code>define('AWX_SYNC_TARGET_URL', '<?php echo \esc_html(\untrailingslashit(\home_url())); ?>');</code><br>
						<code>define('AWX_SYNC_TOKEN', '<?php echo \esc_html($new); ?>');</code></p>
				</div>
			<?php endif; ?>
			<?php if (!empty($_GET['awx_revoked'])) : ?>
				<div class="notice notice-info"><p>Token revoked.</p></div>
			<?php endif; ?>

			<h2>Create a token</h2>
			<form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
				<?php \wp_nonce_field('awx_sync_create_token'); ?>
				<input type="hidden" name="action" value="awx_sync_create_token">
				<table class="form-table">
					<tr><th><label for="awx-label">Label</label></th><td><input id="awx-label" name="label" type="text" class="regular-text" placeholder="dev.example.com"></td></tr>
					<tr><th><label for="awx-ttl">Expires after (days)</label></th><td><input id="awx-ttl" name="ttl_days" type="number" min="0" max="3650" value="<?php echo (int) Token_Store::DEFAULT_TTL_DAYS; ?>"> <span class="description">0 = never</span></td></tr>
				</table>
				<?php \submit_button('Create token'); ?>
			</form>

			<h2>Tokens</h2>
			<table class="widefat striped">
				<thead><tr><th>Label</th><th>Token id</th><th>Created</th><th>Expires</th><th>Last used</th><th>From</th><th>Status</th><th></th></tr></thead>
				<tbody>
				<?php if (empty($tokens)) : ?>
					<tr><td colspan="8">No tokens yet.</td></tr>
				<?php endif; ?>
				<?php foreach ($tokens as $t) :
					$expired = !empty($t['expires_at']) && \strtotime($t['expires_at'] . ' UTC') < \time();
					$state = !empty($t['revoked_at']) ? 'revoked' : ($expired ? 'expired' : 'active');
					?>
					<tr>
						<td><?php echo \esc_html($t['label']); ?></td>
						<td><code><?php echo \esc_html($t['token_id']); ?></code></td>
						<td><?php echo \esc_html($t['created_at']); ?></td>
						<td><?php echo \esc_html($t['expires_at'] ?: 'never'); ?></td>
						<td><?php echo \esc_html($t['last_used_at'] ?: '–'); ?></td>
						<td><?php echo \esc_html($t['last_used_ip'] ?: '–'); ?></td>
						<td><?php echo \esc_html($state); ?></td>
						<td>
							<?php if ($state === 'active') : ?>
							<form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" onsubmit="return confirm('Revoke this token? The source site will no longer be able to sync.');">
								<?php \wp_nonce_field('awx_sync_revoke_' . $t['token_id']); ?>
								<input type="hidden" name="action" value="awx_sync_revoke_token">
								<input type="hidden" name="token_id" value="<?php echo \esc_attr($t['token_id']); ?>">
								<?php \submit_button('Revoke', 'small', 'submit', false); ?>
							</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Audit log (last 200)</h2>
			<form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" style="margin-bottom:8px">
				<?php \wp_nonce_field('awx_sync_export_log'); ?>
				<input type="hidden" name="action" value="awx_sync_export_log">
				<?php \submit_button('Export CSV', 'secondary', 'submit', false); ?>
			</form>
			<table class="widefat striped">
				<thead><tr><th>Time (UTC)</th><th>Token</th><th>Run</th><th>Route</th><th>Object</th><th>Result</th><th>Status</th><th>IP</th><th>Message</th></tr></thead>
				<tbody>
				<?php if (empty($log)) : ?>
					<tr><td colspan="9">No calls yet.</td></tr>
				<?php endif; ?>
				<?php foreach ($log as $row) : ?>
					<tr>
						<td><?php echo \esc_html($row['created_at']); ?></td>
						<td><code><?php echo \esc_html($row['token_id'] ?: '–'); ?></code></td>
						<td><code><?php echo \esc_html($row['run_id'] ? \substr($row['run_id'], 0, 8) : '–'); ?></code></td>
						<td><?php echo \esc_html($row['method'] . ' ' . $row['route']); ?></td>
						<td><code><?php echo \esc_html($row['object_uuid'] ? \substr($row['object_uuid'], 0, 8) : '–'); ?></code></td>
						<td><?php echo \esc_html($row['result']); ?></td>
						<td><?php echo (int) $row['http_status']; ?></td>
						<td><?php echo \esc_html($row['ip'] ?: '–'); ?></td>
						<td><?php echo \esc_html($row['message'] ?: ''); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
