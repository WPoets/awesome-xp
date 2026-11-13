<?php
/**
 * Sync Audit & Release History screen under Awesome Enterprise:
 * Displays releases (what was synced, by whom, when, and itemized diff).
 *
 * Authoritative history lives on Prod; Dev environments query Prod with
 * local fallback to prevent multi-dev blindspots.
 */
namespace aw2\sync;

class Audit_Page {

	const SLUG = 'awx-sync-audit';
	const CAP  = 'develop_for_awesomeui';

	public static function init(): void {
		\add_action('admin_menu', array(__CLASS__, 'menu'), 25);
		\add_action('admin_post_awx_sync_export_releases', array(__CLASS__, 'handle_export'));
	}

	public static function menu(): void {
		$parent = isset($GLOBALS['admin_page_hooks']['awesome-enterprise']) ? 'awesome-enterprise' : 'tools.php';
		$hook = \add_submenu_page($parent, 'Sync Audit & Releases', 'Sync Audit', self::CAP, self::SLUG, array(__CLASS__, 'render'));
		if ($hook) {
			\add_action('load-' . $hook, function () {
				\add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
			});
		}
	}

	public static function enqueue(): void {
		\wp_enqueue_style('wp-components');
	}

	public static function handle_export(): void {
		if (!\current_user_can(self::CAP) && !\current_user_can('manage_options')) {
			\wp_die('Not allowed.');
		}
		\check_admin_referer('awx_sync_export_releases');
		$releases = Release_Store::recent(1000, array(), false);

		\nocache_headers();
		\header('Content-Type: text/csv; charset=utf-8');
		\header('Content-Disposition: attachment; filename="awx-sync-releases-' . \gmdate('Ymd-His') . '.csv"');

		$out = \fopen('php://output', 'w');
		\fputcsv($out, array('Date (UTC)', 'Release ID', 'Kind', 'Status', 'Label', 'Author Name', 'Author Email', 'Client IP', 'Source URL', 'Target URL', 'Token ID', 'Duration (s)', 'Total Items'));
		foreach ($releases as $r) {
			\fputcsv($out, array(
				$r['created_at'],
				$r['release_id'],
				$r['kind'],
				$r['status'],
				$r['label'],
				$r['author_name'],
				$r['author_email'],
				$r['client_ip'],
				$r['source_url'],
				$r['target_url'],
				$r['token_id'],
				$r['duration_s'],
				$r['items_count'],
			));
		}
		\fclose($out);
		exit;
	}

	public static function render(): void {
		if (!\current_user_can(self::CAP) && !\current_user_can('manage_options')) {
			\wp_die('Not allowed.');
		}

		$status_filter = !empty($_GET['status']) ? \sanitize_key((string) $_GET['status']) : '';
		$kind_filter   = !empty($_GET['kind']) ? \sanitize_key((string) $_GET['kind']) : '';
		$source_mode   = !empty($_GET['history_source']) ? \sanitize_key((string) $_GET['history_source']) : 'auto';

		$filters = array();
		if ($status_filter) {
			$filters['status'] = $status_filter;
		}
		if ($kind_filter) {
			$filters['kind'] = $kind_filter;
		}

		$releases = array();
		$active_source = 'local';
		$target_url = '';
		$fetch_error = '';

		if ($source_mode !== 'local' && is_source()) {
			try {
				$client = Sync_Client::from_config();
				$target_url = $client->target_url();
				$qs = array('limit' => 100);
				if ($status_filter) { $qs['status'] = $status_filter; }
				if ($kind_filter) { $qs['kind'] = $kind_filter; }
				$res = $client->get('/code/releases?' . \http_build_query($qs));
				if (!\is_wp_error($res) && isset($res['releases'])) {
					$releases = $res['releases'];
					$active_source = 'remote';
				} else {
					$fetch_error = \is_wp_error($res) ? $res->get_error_message() : 'Could not retrieve releases from target.';
				}
			} catch (\Exception $e) {
				$fetch_error = $e->getMessage();
			}
		}

		if (empty($releases) && $active_source === 'local') {
			$releases = Release_Store::recent(100, $filters, false);
		}

		// Map active tokens if on target for quick revocation
		$active_tokens = array();
		if (is_target() && \class_exists(__NAMESPACE__ . '\\Token_Store')) {
			foreach (Token_Store::all() as $t) {
				if (empty($t['revoked_at'])) {
					$active_tokens[$t['token_id']] = $t['label'] ?: $t['token_id'];
				}
			}
		}
		?>
		<div class="wrap awx-sync-audit-wrap">
			<h1 class="wp-heading-inline">Sync Audit & Releases</h1>
			<form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left:12px;">
				<?php \wp_nonce_field('awx_sync_export_releases'); ?>
				<input type="hidden" name="action" value="awx_sync_export_releases">
				<?php \submit_button('Export CSV', 'secondary small', 'submit', false); ?>
			</form>
			<hr class="wp-header-end">

			<p class="description">
				Audit log of all code deploys and content syncs. Each entry records what was synced, by whom, when, and the complete itemized manifest.
			</p>

			<?php if (is_source()) : ?>
				<div class="nav-tab-wrapper" style="margin-bottom:16px;">
					<a href="<?php echo \esc_url(\add_query_arg(array('history_source' => 'remote', 'page' => self::SLUG), \admin_url('admin.php'))); ?>" class="nav-tab <?php echo $source_mode !== 'local' ? 'nav-tab-active' : ''; ?>">
						Production Releases (Authoritative) <?php if ($target_url) : ?><span style="font-weight:normal;opacity:0.8;">— <?php echo \esc_html($target_url); ?></span><?php endif; ?>
					</a>
					<a href="<?php echo \esc_url(\add_query_arg(array('history_source' => 'local', 'page' => self::SLUG), \admin_url('admin.php'))); ?>" class="nav-tab <?php echo $source_mode === 'local' ? 'nav-tab-active' : ''; ?>">
						Local Releases (This Machine)
					</a>
				</div>
			<?php endif; ?>

			<?php if ($fetch_error) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><strong>Note:</strong> Could not reach target environment (<em><?php echo \esc_html($fetch_error); ?></em>). Showing local machine history instead.</p>
				</div>
			<?php endif; ?>

			<!-- Filter Bar -->
			<div class="tablenav top" style="margin-top:0;">
				<div class="alignleft actions">
					<form method="get" action="<?php echo \esc_url(\admin_url('admin.php')); ?>" style="display:flex;gap:8px;align-items:center;">
						<input type="hidden" name="page" value="<?php echo \esc_attr(self::SLUG); ?>">
						<?php if ($source_mode) : ?>
							<input type="hidden" name="history_source" value="<?php echo \esc_attr($source_mode); ?>">
						<?php endif; ?>

						<select name="kind">
							<option value="">All Types</option>
							<option value="code" <?php \selected($kind_filter, 'code'); ?>>Code Deploys</option>
							<option value="page" <?php \selected($kind_filter, 'page'); ?>>Page Syncs</option>
						</select>

						<select name="status">
							<option value="">All Statuses</option>
							<option value="success" <?php \selected($status_filter, 'success'); ?>>Success</option>
							<option value="failed" <?php \selected($status_filter, 'failed'); ?>>Failed</option>
							<option value="running" <?php \selected($status_filter, 'running'); ?>>Running / Pending</option>
						</select>

						<?php \submit_button('Filter', 'button', 'filter_action', false); ?>
						<?php if ($status_filter || $kind_filter) : ?>
							<a href="<?php echo \esc_url(\remove_query_arg(array('status', 'kind'))); ?>" class="button">Reset</a>
						<?php endif; ?>
					</form>
				</div>
				<div class="tablenav-pages">
					<span class="displaying-num"><?php echo \count($releases); ?> release(s)</span>
				</div>
			</div>

			<!-- Releases Table -->
			<div class="awx-releases-scroll">
			<table class="wp-list-table widefat striped fixed awx-releases-table">
				<thead>
					<tr>
						<th class="awx-col-date">Date & Time (UTC)</th>
						<th class="awx-col-id">Run ID</th>
						<th class="awx-col-release">Release</th>
						<th class="awx-col-by">Deployed By</th>
						<th class="awx-col-token">Auth / Token</th>
						<th class="awx-col-status">Status</th>
						<th class="awx-col-summary">Summary</th>
						<th class="awx-col-actions">Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php if (empty($releases)) : ?>
					<tr>
						<td colspan="8" style="text-align:center;padding:24px;color:#666;">No sync releases found.</td>
					</tr>
				<?php endif; ?>

				<?php foreach ($releases as $r) :
					$created = !empty($r['created_at']) ? \strtotime($r['created_at'] . ' UTC') : 0;
					$ago = $created ? \human_time_diff($created, \time()) . ' ago' : '–';
					$summary = !empty($r['summary']) && \is_array($r['summary']) ? $r['summary'] : array();
					$by_kind = $summary['by_kind'] ?? array();
					$status = $r['status'] ?? 'unknown';
					$token_id = $r['token_id'] ?? '';
					$can_revoke = is_target() && !empty($active_tokens[$token_id]);
				?>
					<tr id="release-row-<?php echo \esc_attr($r['release_id']); ?>">
						<td>
							<strong class="awx-nowrap"><?php echo \esc_html($r['created_at']); ?></strong><br>
							<span class="description" style="font-size:11px;"><?php echo \esc_html($ago); ?></span>
						</td>
						<td>
							<code style="font-size:11px;" title="<?php echo \esc_attr($r['release_id']); ?>">
								<?php echo \esc_html(\substr($r['release_id'], 0, 8) . '…'); ?>
							</code>
						</td>
						<td>
							<span class="awx-badge awx-badge-<?php echo \esc_attr($r['kind']); ?>">
								<?php echo \esc_html(\strtoupper($r['kind'])); ?>
							</span>
							<strong><?php echo \esc_html($r['label'] ?: '–'); ?></strong>
							<?php if (!empty($r['source_url'])) : ?>
								<br><span class="description" style="font-size:11px;">from: <?php echo \esc_html($r['source_url']); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if (!empty($r['author_name']) || !empty($r['author_email'])) : ?>
								<strong><?php echo \esc_html($r['author_name'] ?: 'Developer'); ?></strong>
								<?php if (!empty($r['author_email'])) : ?>
									<br><a href="mailto:<?php echo \esc_attr($r['author_email']); ?>" style="font-size:11px;color:#555;"><?php echo \esc_html($r['author_email']); ?></a>
								<?php endif; ?>
							<?php else : ?>
								<span class="description">System</span>
							<?php endif; ?>
							<?php if (!empty($r['client_ip'])) : ?>
								<br><code style="font-size:10px;color:#777;"><?php echo \esc_html($r['client_ip']); ?></code>
							<?php endif; ?>
						</td>
						<td>
							<?php if ($token_id) : ?>
								<code style="font-size:11px;" title="Token ID: <?php echo \esc_attr($token_id); ?>">
									<?php echo \esc_html(\substr($token_id, 0, 8) . '…'); ?>
								</code>
								<?php if ($can_revoke) : ?>
									<form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>" style="display:inline;margin-left:4px;" onsubmit="return confirm('Revoke this deployment token immediately?');">
										<?php \wp_nonce_field('awx_sync_revoke_' . $token_id); ?>
										<input type="hidden" name="action" value="awx_sync_revoke_token">
										<input type="hidden" name="token_id" value="<?php echo \esc_attr($token_id); ?>">
										<button type="submit" class="button-link" style="color:#d63638;font-size:11px;">Revoke</button>
									</form>
								<?php endif; ?>
							<?php else : ?>
								<span class="description">–</span>
							<?php endif; ?>
						</td>
						<td>
							<span class="awx-status-pill awx-status-<?php echo \esc_attr($status); ?>">
								<?php echo \esc_html($status); ?>
							</span>
						</td>
						<td>
							<strong><?php echo (int) ($r['items_count'] ?? 0); ?> item(s)</strong>
							<?php if (!empty($r['duration_s'])) : ?>
								<span class="description" style="font-size:11px;">in <?php echo \esc_html($r['duration_s']); ?>s</span>
							<?php endif; ?>
							<?php if (!empty($by_kind)) :
								$kinds_str = array();
								foreach ($by_kind as $k => $c) {
									$kinds_str[] = "{$c} {$k}";
								}
								?>
								<br><span class="description" style="font-size:11px;"><?php echo \esc_html(\implode(', ', $kinds_str)); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<button type="button" class="button button-small awx-details-toggle" data-id="<?php echo \esc_attr($r['release_id']); ?>" data-source="<?php echo \esc_attr($active_source); ?>">
								View Items
							</button>
						</td>
					</tr>
					<!-- Drawer Container Row (Hidden by default) -->
					<tr id="drawer-<?php echo \esc_attr($r['release_id']); ?>" class="awx-drawer-row" style="display:none;background:#f8f9fa;">
						<td colspan="8" style="padding:16px 20px;">
							<div class="awx-drawer-content" id="drawer-content-<?php echo \esc_attr($r['release_id']); ?>">
								<p><em>Loading itemized breakdown…</em></p>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>

		<style>
			.awx-releases-scroll { overflow-x:auto; }
			.awx-releases-table { min-width:960px; }
			.awx-releases-table th, .awx-releases-table td { vertical-align:top; overflow-wrap:anywhere; }
			.awx-releases-table th { white-space:nowrap; }
			.awx-releases-table .awx-col-date { width:150px; }
			.awx-releases-table .awx-col-id { width:95px; }
			.awx-releases-table .awx-col-by { width:175px; }
			.awx-releases-table .awx-col-token { width:105px; }
			.awx-releases-table .awx-col-status { width:90px; }
			.awx-releases-table .awx-col-summary { width:120px; }
			.awx-releases-table .awx-col-actions { width:95px; }
			.awx-releases-table .awx-nowrap, .awx-releases-table .awx-status-pill, .awx-releases-table .awx-badge { white-space:nowrap; }
			.awx-badge { display:inline-block; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:700; letter-spacing:0.5px; }
			.awx-badge-code { background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; }
			.awx-badge-page { background:#ccfbf1; color:#0f766e; border:1px solid #99f6e4; }
			.awx-status-pill { display:inline-block; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:capitalize; }
			.awx-status-success { background:#dcfce7; color:#15803d; }
			.awx-status-failed { background:#fee2e2; color:#b91c1c; }
			.awx-status-running { background:#fef3c7; color:#b45309; }
			.awx-status-unknown_pending_reconcile { background:#ffedd5; color:#c2410c; }
			.awx-items-table { width:100%; border-collapse:collapse; margin-top:10px; background:#fff; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,0.04); }
			.awx-items-table th { background:#f1f1f1; text-align:left; padding:8px 10px; font-size:12px; border-bottom:1px solid #ccd0d4; }
			.awx-items-table td { padding:8px 10px; font-size:12px; border-bottom:1px solid #e5e5e5; }
			.awx-action-created { color:#15803d; font-weight:600; }
			.awx-action-updated { color:#0369a1; font-weight:600; }
			.awx-action-unchanged { color:#6b7280; }
			.awx-action-deleted { color:#b91c1c; }
		</style>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var buttons = document.querySelectorAll('.awx-details-toggle');
			buttons.forEach(function(btn) {
				btn.addEventListener('click', function() {
					var id = this.getAttribute('data-id');
					var source = this.getAttribute('data-source');
					var drawerRow = document.getElementById('drawer-' + id);
					var drawerContent = document.getElementById('drawer-content-' + id);

					if (drawerRow.style.display === 'table-row') {
						drawerRow.style.display = 'none';
						this.textContent = 'View Items';
						return;
					}

					drawerRow.style.display = 'table-row';
					this.textContent = 'Hide Items';

					if (drawerContent.getAttribute('data-loaded')) {
						return;
					}

					// Fetch release details with items via REST
					var endpoint = '<?php echo \esc_url_raw(\rest_url(Prod_Routes::NS . '/code/releases/')); ?>' + encodeURIComponent(id);
					fetch(endpoint, {
						headers: { 'X-WP-Nonce': '<?php echo \wp_create_nonce('wp_rest'); ?>' }
					})
					.then(function(res) { return res.json(); })
					.then(function(data) {
						var r = data.release;
						if (!r) {
							drawerContent.innerHTML = '<p class="error">Could not load release details.</p>';
							return;
						}
						var items = r.items || [];
						var html = '<div style="margin-bottom:8px;"><strong>Itemized Manifest (' + items.length + ' items)</strong>:</div>';
						if (!items.length) {
							html += '<p class="description">No itemized details recorded for this release.</p>';
						} else {
							html += '<div style="margin-bottom:8px;"><input type="text" placeholder="Filter items by key or title…" class="regular-text awx-item-filter" style="width:300px;font-size:12px;"></div>';
							html += '<div style="max-height:360px;overflow-y:auto;"><table class="awx-items-table"><thead><tr><th>Key</th><th>Kind</th><th>Title / Slug</th><th>Action</th></tr></thead><tbody>';
							items.forEach(function(item) {
								var act = item.action || 'updated';
								html += '<tr class="awx-item-row" data-search="' + (item.key + ' ' + (item.title || '') + ' ' + (item.kind || '')).toLowerCase() + '">';
								html += '<td><code>' + (item.key || '–') + '</code></td>';
								html += '<td><span class="awx-badge awx-badge-code">' + (item.kind || 'item') + '</span></td>';
								html += '<td>' + (item.title || item.slug || '–') + '</td>';
								html += '<td><span class="awx-action-' + act + '">' + act + '</span></td>';
								html += '</tr>';
							});
							html += '</tbody></table></div>';
						}
						drawerContent.innerHTML = html;
						drawerContent.setAttribute('data-loaded', '1');

						var filterInput = drawerContent.querySelector('.awx-item-filter');
						if (filterInput) {
							filterInput.addEventListener('input', function() {
								var q = this.value.toLowerCase().trim();
								var rows = drawerContent.querySelectorAll('.awx-item-row');
								rows.forEach(function(row) {
									var text = row.getAttribute('data-search') || '';
									row.style.display = (q === '' || text.indexOf(q) !== -1) ? '' : 'none';
								});
							});
						}
					})
					.catch(function(err) {
						drawerContent.innerHTML = '<p class="error">Error loading release: ' + err.message + '</p>';
					});
				});
			});
		});
		</script>
		<?php
	}
}
