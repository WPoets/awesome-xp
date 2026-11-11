<?php
namespace aw2\sync;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * AWX Sync identity commands.
 *
 * This file does NOT call \WP_CLI::add_command itself. The loader
 * (wp/libraries/sync/sync-init.php) must require this file only when
 * WP_CLI is defined (the guard above returns early otherwise, so the class
 * would be missing) and then call:
 *
 *     \aw2\sync\Sync_CLI_Command::register();
 *
 * which registers both `wp awx sync ...` and `wp aw2 sync ...`.
 */
class Sync_CLI_Command extends \WP_CLI_Command
{
    /**
     * Register the `awx sync` and `aw2 sync` command namespaces.
     */
    public static function register(): void
    {
        \WP_CLI::add_command('awx sync', __CLASS__);
        \WP_CLI::add_command('aw2 sync', __CLASS__);
    }

    /**
     * Assign missing sync UUIDs and file hashes to attachments (and optionally posts).
     *
     * ## OPTIONS
     *
     * [--posts]
     * : Also process public post types and wp_block, not just attachments.
     *
     * [--dry-run]
     * : Report what would be written without changing anything.
     *
     * [--batch=<n>]
     * : Rows per database query.
     * ---
     * default: 200
     * ---
     *
     * ## EXAMPLES
     *
     *     # Stamp every attachment with a UUID and file hash
     *     $ wp awx sync backfill
     *
     *     # Preview a full backfill including pages/posts/blocks
     *     $ wp awx sync backfill --posts --dry-run
     */
    public function backfill($args, $assoc_args)
    {
        global $wpdb;

        $do_posts = !empty($assoc_args['posts']);
        $dry_run  = !empty($assoc_args['dry-run']);
        $batch    = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : Identity::BATCH_SIZE;

        $types = ['attachment'];
        if ($do_posts) {
            $types = array_values(array_unique(array_merge($types, Identity::tracked_post_types())));
        }
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_type IN ({$placeholders}) AND post_status NOT IN ('auto-draft')",
            $types
        ));

        if ($total === 0) {
            \WP_CLI::success('Nothing to backfill.');
            return;
        }

        $label    = $dry_run ? 'Scanning (dry run)' : 'Backfilling';
        $progress = \WP_CLI\Utils\make_progress_bar("{$label} {$total} object(s)", $total);

        $counts = Identity::backfill([
            'posts'    => $do_posts,
            'dry_run'  => $dry_run,
            'batch'    => $batch,
            'on_batch' => function ($n) use ($progress) {
                $progress->tick($n);
            },
        ]);
        $progress->finish();

        $items = [];
        foreach ($counts as $metric => $count) {
            $items[] = ['metric' => $metric, 'count' => $count];
        }
        \WP_CLI\Utils\format_items('table', $items, ['metric', 'count']);

        if ($dry_run) {
            \WP_CLI::success('Dry run complete. Nothing was written.');
        } else {
            \WP_CLI::success(sprintf(
                'Backfill complete: %d UUID(s) stamped, %d file hash(es) computed.',
                $counts['stamped'],
                $counts['hashed']
            ));
        }
    }

    /**
     * Pair a local post with a Dev UUID.
     *
     * Refuses invalid UUIDs and UUIDs already held by another post.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : Local post ID to stamp.
     *
     * <uuid>
     * : UUIDv4 exported from the Dev site.
     *
     * ## EXAMPLES
     *
     *     $ wp awx sync pair 42 6f1c2c0e-2a7b-4d4e-9b0a-3c1d9f8e7a65
     */
    public function pair($args, $assoc_args)
    {
        $post_id = (int) $args[0];
        $uuid    = (string) $args[1];

        $result = Identity::pair($post_id, $uuid);
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }

        \WP_CLI::success(sprintf('Post %d now carries UUID %s.', $post_id, strtolower(trim($uuid))));
    }

    /**
     * Export AWX-managed pattern slugs with their sync UUIDs (run on Dev).
     *
     * Every wp_block carrying _awx_pattern_slug is listed; blocks without a
     * UUID are assigned one first. Feed the JSON output to `pair-patterns` on Prod.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     *   - count
     * ---
     *
     * [--fields=<fields>]
     * : Comma-separated list of fields to display.
     * ---
     * default: slug,uuid
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp awx sync export-pattern-ids --format=json > pattern-ids.json
     *
     * @subcommand export-pattern-ids
     */
    public function export_pattern_ids($args, $assoc_args)
    {
        $map = Identity::pair_patterns();
        if (empty($map)) {
            \WP_CLI::warning('No wp_block posts carry _awx_pattern_slug.');
            if (($assoc_args['format'] ?? 'table') === 'json') {
                \WP_CLI::line('[]');
            }
            return;
        }

        $items = [];
        foreach ($map as $slug => $post_id) {
            $items[] = [
                'slug'    => $slug,
                'uuid'    => Identity::ensure_uuid($post_id),
                'post_id' => $post_id,
            ];
        }

        $fields = isset($assoc_args['fields'])
            ? array_map('trim', explode(',', $assoc_args['fields']))
            : ['slug', 'uuid'];
        $format = $assoc_args['format'] ?? 'table';

        \WP_CLI\Utils\format_items($format, $items, $fields);
    }

    /**
     * Pair local AWX-managed patterns with Dev UUIDs from an export file (run on Prod).
     *
     * ## OPTIONS
     *
     * --from=<file>
     * : JSON file produced by `export-pattern-ids --format=json`:
     *   [{"slug": "...", "uuid": "..."}, ...]
     *
     * [--dry-run]
     * : Show what would be paired without writing.
     *
     * ## EXAMPLES
     *
     *     $ wp awx sync pair-patterns --from=pattern-ids.json
     *
     * @subcommand pair-patterns
     */
    public function pair_patterns($args, $assoc_args)
    {
        $file    = isset($assoc_args['from']) ? (string) $assoc_args['from'] : '';
        $dry_run = !empty($assoc_args['dry-run']);

        if ($file === '' || !is_readable($file)) {
            \WP_CLI::error("Cannot read --from file: {$file}");
        }

        $entries = json_decode((string) file_get_contents($file), true);
        if (!is_array($entries)) {
            \WP_CLI::error('The --from file is not a JSON array: ' . json_last_error_msg());
        }

        $local = Identity::pair_patterns();

        $rows   = [];
        $counts = ['paired' => 0, 'already' => 0, 'not_found' => 0, 'failed' => 0];

        foreach ($entries as $i => $entry) {
            $slug = isset($entry['slug']) ? (string) $entry['slug'] : '';
            $uuid = isset($entry['uuid']) ? strtolower(trim((string) $entry['uuid'])) : '';

            if ($slug === '' || $uuid === '') {
                $counts['failed']++;
                $rows[] = ['slug' => $slug ?: "(entry {$i})", 'post_id' => '', 'uuid' => $uuid, 'result' => 'invalid entry'];
                continue;
            }

            if (!isset($local[$slug])) {
                $counts['not_found']++;
                $rows[] = ['slug' => $slug, 'post_id' => '', 'uuid' => $uuid, 'result' => 'no local pattern'];
                continue;
            }

            $post_id = $local[$slug];
            $current = Identity::get_uuid($post_id);

            if ($current === $uuid) {
                $counts['already']++;
                $rows[] = ['slug' => $slug, 'post_id' => $post_id, 'uuid' => $uuid, 'result' => 'already paired'];
                continue;
            }

            if ($dry_run) {
                // Validate without writing so the dry run surfaces conflicts.
                if (!Identity::is_valid_uuid($uuid)) {
                    $counts['failed']++;
                    $rows[] = ['slug' => $slug, 'post_id' => $post_id, 'uuid' => $uuid, 'result' => 'invalid uuid'];
                    continue;
                }
                $holder = Identity::find_by_uuid($uuid);
                if ($holder !== null && $holder !== $post_id) {
                    $counts['failed']++;
                    $rows[] = ['slug' => $slug, 'post_id' => $post_id, 'uuid' => $uuid, 'result' => "uuid held by post {$holder}"];
                    continue;
                }
                $counts['paired']++;
                $note = $current ? "would replace {$current}" : 'would pair';
                $rows[] = ['slug' => $slug, 'post_id' => $post_id, 'uuid' => $uuid, 'result' => $note];
                continue;
            }

            $result = Identity::pair($post_id, $uuid);
            if (is_wp_error($result)) {
                $counts['failed']++;
                $rows[] = ['slug' => $slug, 'post_id' => $post_id, 'uuid' => $uuid, 'result' => $result->get_error_message()];
                continue;
            }

            $counts['paired']++;
            $rows[] = ['slug' => $slug, 'post_id' => $post_id, 'uuid' => $uuid, 'result' => $current ? "replaced {$current}" : 'paired'];
        }

        if (!empty($rows)) {
            \WP_CLI\Utils\format_items('table', $rows, ['slug', 'post_id', 'uuid', 'result']);
        }

        $summary = sprintf(
            '%d paired, %d already paired, %d without a local pattern, %d failed.',
            $counts['paired'],
            $counts['already'],
            $counts['not_found'],
            $counts['failed']
        );

        if ($dry_run) {
            \WP_CLI::success('Dry run: ' . $summary . ' Nothing was written.');
        } elseif ($counts['failed'] > 0) {
            \WP_CLI::warning($summary);
        } else {
            \WP_CLI::success($summary);
        }
    }

    /**
     * Print a post's sync UUID, assigning one if it has none.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : Post ID.
     *
     * [--dry-run]
     * : Only print; never assign.
     *
     * ## EXAMPLES
     *
     *     $ wp awx sync uuid 42
     */
    public function uuid($args, $assoc_args)
    {
        $post_id = (int) $args[0];
        if ($post_id <= 0 || !get_post($post_id)) {
            \WP_CLI::error("Post {$post_id} does not exist.");
        }

        $uuid = Identity::get_uuid($post_id);
        if ($uuid !== null) {
            \WP_CLI::line($uuid);
            return;
        }

        if (!empty($assoc_args['dry-run'])) {
            \WP_CLI::warning("Post {$post_id} has no sync UUID.");
            return;
        }

        $uuid = Identity::ensure_uuid($post_id);
        \WP_CLI::line($uuid);
        \WP_CLI::success("Assigned a new UUID to post {$post_id}.");
    }

    /**
     * Create or upgrade the sync tables (runs, steps, tokens, log, nonces).
     *
     * ## EXAMPLES
     *
     *     $ wp awx sync install
     */
    public function install($args, $assoc_args)
    {
        Schema::install();
        update_option(Schema::OPTION, Schema::DB_VERSION, false);
        foreach (Schema::tables_exist() as $short => $ok) {
            \WP_CLI::line(sprintf('%-8s %s', $short, $ok ? 'ok' : 'MISSING'));
        }
        \WP_CLI::success('Sync tables are in place.');
    }

    /**
     * Sync one post to the configured target site (source sites only).
     *
     * Runs analyze, plan, confirm and every step in sequence. Without --yes the
     * dry run is printed and nothing is written on the target.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post to sync.
     *
     * [--yes]
     * : Execute the plan instead of only printing it.
     *
     * [--overwrite]
     * : Replace the target page even if it was edited there since the last sync.
     *
     * [--user=<login>]
     * : Run as this user (needed for capability checks). Defaults to the first administrator.
     *
     * ## EXAMPLES
     *
     *     $ wp awx sync push 123
     *     $ wp awx sync push 123 --yes
     *     $ wp awx sync push 123 --yes --overwrite
     */
    public function push($args, $assoc_args)
    {
        $post_id = isset($args[0]) ? (int) $args[0] : 0;
        if ($post_id <= 0 || !get_post($post_id)) {
            \WP_CLI::error("Post {$post_id} does not exist.");
        }
        if (!get_current_user_id()) {
            $login = $assoc_args['user'] ?? null;
            $user  = $login ? get_user_by('login', $login) : (get_users(['role' => 'administrator', 'number' => 1])[0] ?? null);
            if (!$user) {
                \WP_CLI::error('No user to run as; pass --user=<login>.');
            }
            wp_set_current_user($user->ID);
        }
        $run = function ($ability, $input) {
            $res = \aw2\abilities\awx_execute_ability($ability, $input);
            if (empty($res['success'])) {
                $e = $res['error'] ?? [];
                \WP_CLI::error(sprintf('%s: %s %s', $e['code'] ?? 'error', $e['message'] ?? '', $e['next_step'] ?? ''));
            }
            return $res['data'];
        };

        $a = $run('awesome/sync-analyze', ['post_id' => $post_id]);
        \WP_CLI::line(sprintf('Run %s for "%s" (%s, %s). Media %d, blocks %d, links %d, terms %d.', $a['run_id'], $a['page']['title'], $a['page']['type'], $a['page']['status'], $a['counts']['media'], $a['counts']['blocks'], $a['counts']['posts'], $a['counts']['terms']));

        $p = $run('awesome/sync-plan', ['run_id' => $a['run_id']]);
        $s = $p['plan']['summary'];
        \WP_CLI::line(sprintf('Plan: %s page (%s -> %s), %d media (%s, push %d / pull %d), %d reusable block(s), %d other page(s) affected.',
            $s['page_action'], $s['from_status'] ?? 'new', $s['to_status'], $s['media_count'], \aw2\sync\Planner::human((int) $s['media_bytes']), $s['media_push'], $s['media_pull'], $s['blocks_count'], count($s['affected_pages'])));
        foreach ($p['plan']['warnings'] as $w) {
            \WP_CLI::warning($w['code'] . ': ' . $w['message']);
        }
        foreach ($p['plan']['blockers'] as $b) {
            \WP_CLI::line('BLOCKED ' . $b['code'] . ': ' . $b['message']);
        }
        if ($p['status'] === 'blocked') {
            \WP_CLI::error('The run is blocked; fix the items above and retry.');
        }
        if ($p['status'] === 'done') {
            \WP_CLI::success('Nothing to do: the target already matches.');
            return;
        }
        if (empty($assoc_args['yes'])) {
            \WP_CLI::line('Dry run only. Re-run with --yes to execute.');
            return;
        }
        $run('awesome/sync-confirm', ['run_id' => $a['run_id'], 'overwrite' => !empty($assoc_args['overwrite'])]);

        $total = count($p['steps']);
        $bar = \WP_CLI\Utils\make_progress_bar('Syncing', $total);
        while (true) {
            $r = $run('awesome/sync-step', ['run_id' => $a['run_id']]);
            if (!empty($r['error'])) {
                $bar->finish();
                if (!empty($r['error']['retryable'])) {
                    \WP_CLI::warning('Retrying: ' . $r['error']['message']);
                    continue;
                }
                \WP_CLI::error($r['error']['code'] . ': ' . $r['error']['message'] . ' (resume with the editor banner or `wp awx sync push` again)');
            }
            if (!empty($r['step'])) {
                $bar->tick(1, sprintf('%s %s', $r['step']['type'], $r['step']['object_uuid'] ? substr($r['step']['object_uuid'], 0, 8) : ''));
            }
            if ($r['status'] === 'done') {
                $bar->finish();
                $last = $r['last_sync'] ?? [];
                \WP_CLI::success(sprintf('Synced. %s (revision %s)', $last['permalink'] ?? '', $last['revision_id'] ?? '-'));
                return;
            }
            if ($r['status'] !== 'running') {
                $bar->finish();
                \WP_CLI::error('Run stopped with status ' . $r['status'] . ': ' . ($r['error'] ?? ''));
            }
        }
    }
}
