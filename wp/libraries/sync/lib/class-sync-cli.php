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
     * Runs as the user given with WP-CLI's global --user=<login> flag, or the
     * first administrator when it is omitted. That user must be able to edit
     * the post.
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
        $this->ensure_user(false);
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

    /**
     * Deploy Awesome code (core, services, apps, block definitions, ACF, patterns) to the target site.
     *
     * Prints the plan (one row per item with its status on the target). Without --yes nothing is written.
     *
     * Runs as the user given with WP-CLI's global --user=<login> flag, or the
     * first administrator when it is omitted. That user must be an
     * administrator or have Awesome developer access (develop_for_awesomeui).
     *
     * ## OPTIONS
     *
     * [--core[=<slugs>]]
     * : Core modules. All, or a comma-separated list of slugs.
     *
     * [--services[=<post_types>]]
     * : Service modules. All service post types, or a comma-separated list of post types (append :slug1|slug2 to limit modules).
     *
     * [--apps[=<slugs>]]
     * : Apps: definition, config and modules. All apps, or a comma-separated list of app slugs.
     *
     * [--with-pages]
     * : Also include the pages collection of the selected apps.
     *
     * [--with-posts]
     * : Also include the posts collection of the selected apps.
     *
     * [--blocks[=<slugs>]]
     * : Gutenberg block definitions (aw_gb_blocks). All, or a comma-separated list of slugs.
     *
     * [--acf[=<keys>]]
     * : ACF field groups (plus the gutenberg-blocks core module). All, or a comma-separated list of group keys.
     *
     * [--patterns[=<slugs>]]
     * : Block patterns (wp_block). All, or a comma-separated list of pattern slugs.
     *
     * [--templates[=<slugs>]]
     * : Site templates and template parts (wp_template, wp_template_part). All, or a comma-separated list of slugs.
     *
     * [--menus[=<slugs>]]
     * : Navigation menus (nav_menu). All, or a comma-separated list of menu slugs.
     *
     * [--set=<name>]
     * : Use a saved deployment set instead of the flags above.
     *
     * [--include=<keys>]
     * : Deploy only these plan rows (comma-separated keys such as awesome_core:header,page:12). Default: the rows the plan ticks.
     *
     * [--exclude=<keys>]
     * : Untick these plan rows.
     *
     * [--all]
     * : Tick every selectable row, including app pages/posts of apps that already exist on the target.
     *
     * [--yes]
     * : Execute the plan instead of only printing it.
     *
     * [--overwrite]
     * : Replace items that were edited on the target since the last deploy.
     *
     * [--dry-run]
     * : Same as omitting --yes.
     *
     * [--format=<format>]
     * : table (default) or json for the plan rows.
     *
     * ## EXAMPLES
     *
     *     $ wp awx sync deploy --apps=crm --dry-run
     *     $ wp awx sync deploy --core --services --yes
     *     $ wp awx sync deploy --set=release --yes --overwrite
     *     $ wp awx sync deploy --blocks=hero,cta --acf --yes
     */
    public function deploy($args, $assoc_args)
    {
        $this->ensure_user();
        $run = $this->ability_runner();

        $input = [];
        if (!empty($assoc_args['set'])) {
            $input['set'] = (string) $assoc_args['set'];
        } else {
            $input['selection'] = self::selection_from_flags($assoc_args);
            if (empty($input['selection'])) {
                \WP_CLI::error('Nothing selected. Pass --core, --services, --apps, --blocks, --acf, --patterns, --templates, --styles, --tokens, --menus or --set=<name>.');
            }
        }
        $view = $run('awesome/sync-code-analyze', $input);
        $plan = $view['plan'] ?? [];
        $rows = $plan['rows'] ?? [];

        $format = $assoc_args['format'] ?? 'table';
        if ($format === 'json') {
            \WP_CLI::line(json_encode(['run_id' => $view['run_id'], 'status' => $view['status'], 'plan' => $plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $items = [];
            foreach ($rows as $r) {
                $items[] = [
                    'key'      => $r['key'],
                    'kind'     => $r['kind'],
                    'title'    => $r['title'],
                    'status'   => $r['status'] . (!empty($r['never_synced']) ? ' (never synced)' : ''),
                    'ticked'   => !empty($r['selected']) ? 'yes' : ($r['selectable'] ? '' : '-'),
                    'warnings' => implode('; ', array_map(function ($w) { return $w['code']; }, $r['warnings'] ?? [])),
                ];
            }
            \WP_CLI\Utils\format_items('table', $items, ['key', 'kind', 'title', 'status', 'ticked', 'warnings']);
            $s = $plan['summary']['by_status'] ?? [];
            \WP_CLI::line(sprintf('Run %s: %d new, %d changed, %d edited on target, %d unchanged, %d only on target, %d blocked.',
                $view['run_id'], $s['new'] ?? 0, $s['changed'] ?? 0, $s['prod_edited'] ?? 0, $s['unchanged'] ?? 0, $s['only_on_prod'] ?? 0, $s['blocked'] ?? 0));
        }
        foreach ($plan['warnings'] ?? [] as $w) {
            \WP_CLI::warning($w['code'] . ': ' . $w['message']);
        }
        foreach ($plan['blockers'] ?? [] as $b) {
            \WP_CLI::line('BLOCKED ' . $b['code'] . ': ' . $b['message']);
        }
        if ($view['status'] === 'blocked') {
            \WP_CLI::error('The run is blocked; fix the items above and retry.');
        }
        if ($view['status'] === 'done') {
            \WP_CLI::success('Nothing to do: the target already matches.');
            return;
        }

        // Which rows to deploy.
        $selected = [];
        if (!empty($assoc_args['include'])) {
            $selected = array_filter(array_map('trim', explode(',', (string) $assoc_args['include'])));
        } else {
            foreach ($rows as $r) {
                if (!empty($assoc_args['all']) ? !empty($r['selectable']) && $r['status'] !== 'unchanged' : !empty($r['selected'])) {
                    $selected[] = $r['key'];
                }
            }
        }
        if (!empty($assoc_args['exclude'])) {
            $ex = array_filter(array_map('trim', explode(',', (string) $assoc_args['exclude'])));
            $selected = array_values(array_diff($selected, $ex));
        }
        \WP_CLI::line(count($selected) . ' row(s) will be deployed.');
        if (empty($assoc_args['yes']) || !empty($assoc_args['dry-run'])) {
            \WP_CLI::line('Dry run only. Re-run with --yes to execute.');
            return;
        }
        $run('awesome/sync-code-confirm', ['run_id' => $view['run_id'], 'selected_keys' => $selected, 'overwrite' => !empty($assoc_args['overwrite'])]);

        $status = $run('awesome/sync-code-status', ['run_id' => $view['run_id']]);
        $bar = \WP_CLI\Utils\make_progress_bar('Deploying', count($status['steps'] ?? []));
        while (true) {
            $r = $run('awesome/sync-code-step', ['run_id' => $view['run_id']]);
            if (!empty($r['error'])) {
                $bar->finish();
                if (!empty($r['error']['retryable'])) {
                    \WP_CLI::warning('Retrying: ' . $r['error']['message']);
                    continue;
                }
                \WP_CLI::error($r['error']['code'] . ': ' . $r['error']['message'] . ' (resume from the Deploy Code screen or run again)');
            }
            if (!empty($r['step'])) {
                $bar->tick(1, sprintf('%s %s', $r['step']['type'], (string) ($r['step']['object_uuid'] ?? '')));
            }
            if ($r['status'] === 'done') {
                $bar->finish();
                \WP_CLI::success(sprintf('Deployed %d step(s) to %s.', $r['progress']['done'] ?? 0, $r['target_url'] ?? ''));
                return;
            }
            if ($r['status'] !== 'running') {
                $bar->finish();
                \WP_CLI::error('Run stopped with status ' . $r['status'] . ': ' . ($r['error'] ?? ''));
            }
        }
    }

    /**
     * Manage saved deployment sets.
     *
     * ## OPTIONS
     *
     * <action>
     * : list, save, delete or show
     *
     * [<name>]
     * : Set name (save, delete, show).
     *
     * [--core[=<slugs>]] [--services[=<post_types>]] [--apps[=<slugs>]] [--with-pages] [--with-posts] [--blocks[=<slugs>]] [--acf[=<keys>]] [--patterns[=<slugs>]] [--templates[=<slugs>]] [--menus[=<slugs>]]
     * : Selection flags for save (same as `deploy`).
     *
     * ## EXAMPLES
     *
     *     $ wp awx sync sets save release --core --apps=crm,shop --blocks
     *     $ wp awx sync sets list
     *     $ wp awx sync sets delete release
     */
    public function sets($args, $assoc_args)
    {
        $this->ensure_user();
        $run = $this->ability_runner();
        $action = $args[0] ?? 'list';
        $name = $args[1] ?? '';
        switch ($action) {
            case 'list':
                $sets = $run('awesome/sync-set-list', [])['sets'];
                if (empty($sets)) {
                    \WP_CLI::line('No deployment sets.');
                    return;
                }
                \WP_CLI\Utils\format_items('table', array_map(function ($s) {
                    return ['name' => $s['name'], 'selection' => json_encode($s['selection'], JSON_UNESCAPED_SLASHES), 'updated_at' => $s['updated_at'], 'last_run' => $s['last_run_id'] ?: '-'];
                }, $sets), ['name', 'selection', 'updated_at', 'last_run']);
                return;
            case 'show':
                $set = \aw2\sync\Deploy_Sets::get($name);
                if (!$set) {
                    \WP_CLI::error('Unknown set.');
                }
                \WP_CLI::line(json_encode($set['selection'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return;
            case 'save':
                $sel = self::selection_from_flags($assoc_args);
                if ($name === '' || empty($sel)) {
                    \WP_CLI::error('Usage: wp awx sync sets save <name> --core|--services|--apps|--blocks|--acf|--patterns|--templates|--styles|--tokens|--menus');
                }
                $res = $run('awesome/sync-set-save', ['name' => $name, 'selection' => $sel]);
                \WP_CLI::success('Saved set ' . $res['set']['name'] . '.');
                return;
            case 'delete':
                $res = $run('awesome/sync-set-delete', ['name' => $name]);
                \WP_CLI::success(($res['deleted'] ? 'Deleted' : 'No such set') . ' ' . $res['name'] . '.');
                return;
            default:
                \WP_CLI::error('Unknown action ' . $action);
        }
    }

    /** Translate deploy flags into the selection object the abilities accept. */
    public static function selection_from_flags(array $a): array
    {
        $list = function ($v) {
            if ($v === true || $v === '' || $v === '*' || $v === 'all') {
                return '*';
            }
            return array_values(array_filter(array_map('trim', explode(',', (string) $v)), 'strlen'));
        };
        $sel = [];
        if (isset($a['core'])) {
            $sel['core'] = $list($a['core']);
        }
        if (isset($a['services'])) {
            if ($a['services'] === true || $a['services'] === '' || $a['services'] === '*') {
                foreach (array_keys(\aw2\sync\Code_Catalog::service_post_types()) as $pt) {
                    $sel['services'][$pt] = '*';
                }
            } else {
                foreach (explode(',', (string) $a['services']) as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    $bits = explode(':', $part, 2);
                    $sel['services'][$bits[0]] = isset($bits[1]) ? array_values(array_filter(explode('|', $bits[1]))) : '*';
                }
            }
        }
        if (isset($a['apps'])) {
            $slugs = ($a['apps'] === true || $a['apps'] === '' || $a['apps'] === '*') ? array_keys(\aw2\sync\Code_Catalog::apps()) : $list($a['apps']);
            foreach ((array) $slugs as $slug) {
                $parts = ['definition' => true, 'config' => '*', 'modules' => '*'];
                if (!empty($a['with-pages'])) {
                    $parts['pages'] = '*';
                }
                if (!empty($a['with-posts'])) {
                    $parts['posts'] = '*';
                }
                $sel['apps'][$slug] = $parts;
            }
        }
        if (isset($a['blocks'])) {
            $sel['dgb'] = $list($a['blocks']);
        }
        if (isset($a['acf'])) {
            $sel['acf'] = $list($a['acf']);
        }
        if (isset($a['patterns'])) {
            $sel['patterns'] = $list($a['patterns']);
        }
        if (isset($a['templates'])) {
            $sel['templates'] = $list($a['templates']);
        }
        if (isset($a['styles'])) {
            $sel['styles'] = true;
        }
        if (isset($a['tokens'])) {
            $sel['tokens'] = true;
        }
        if (isset($a['menus'])) {
            $sel['menus'] = $list($a['menus']);
        }
        return $sel;
    }

    /**
     * Settle which WordPress user the command runs as.
     *
     * `--user` is a WP-CLI global flag: WP-CLI consumes it and sets the current
     * user before the command starts, so it never reaches $assoc_args. Without
     * it the first administrator is used. With $need_developer the user must
     * pass the same gate as the code-deploy abilities, checked here so the
     * message can name the user and say how to grant access.
     */
    private function ensure_user(bool $need_developer = true): void
    {
        $explicit = get_current_user_id() > 0;
        if (!$explicit) {
            $user = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
            if (!$user) {
                \WP_CLI::error('This site has no administrator to run as; pass --user=<login>.');
            }
            wp_set_current_user($user->ID);
        }
        if (!$need_developer || \aw2\sync\abilities\Code_Operation::is_developer()) {
            return;
        }
        $user  = wp_get_current_user();
        $login = $user->user_login;
        $roles = $user->roles ? implode(', ', $user->roles) : 'none';
        $grant = "wp user add-cap {$login} develop_for_awesomeui";
        if (is_multisite()) {
            $grant .= " && wp user meta update {$login} develop_for_awesomeui yes";
        }
        \WP_CLI::error_multi_line([
            sprintf(
                'Only Awesome developers can run this command. It ran as "%s" (ID %d, roles: %s)%s, who has neither manage_options nor Awesome developer access.',
                $login,
                $user->ID,
                $roles,
                $explicit ? ', the user given with --user' : ', the first user with the administrator role'
            ),
            'Fix it one of two ways:',
            '  1. Run as an administrator or developer: add --user=<login> to the command.',
            "  2. Grant \"{$login}\" developer access: {$grant}",
            '     (or tick "Grant Developer Access" on the user\'s profile screen).',
        ]);
        \WP_CLI::halt(1);
    }

    private function ability_runner(): callable
    {
        return function ($ability, $input) {
            $res = \aw2\abilities\awx_execute_ability($ability, $input);
            if (empty($res['success'])) {
                $e = $res['error'] ?? [];
                \WP_CLI::error(sprintf('%s: %s %s', $e['code'] ?? 'error', $e['message'] ?? '', $e['next_step'] ?? ''));
            }
            return $res['data'];
        };
    }

    /**
     * Inspect deployment releases and sync audit records.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : 'list' (default) or 'show'.
     *
     * [<release-id>]
     * : UUID of the release (required for 'show').
     *
     * [--limit=<n>]
     * : Maximum number of releases to list.
     * ---
     * default: 20
     * ---
     *
     * [--status=<status>]
     * : Filter by status ('success', 'failed', 'running').
     *
     * [--kind=<kind>]
     * : Filter by kind ('code', 'page').
     *
     * [--target=<target>]
     * : 'remote', 'local', or 'auto'.
     * ---
     * default: auto
     * ---
     *
     * [--format=<format>]
     * : Output format ('table', 'json', 'csv', 'yaml').
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     # List recent sync releases
     *     $ wp awx sync releases
     *
     *     # Show only failed code deploys
     *     $ wp awx sync releases list --kind=code --status=failed
     *
     *     # Show itemized details of a release
     *     $ wp awx sync releases show c036d07e-1111-2222-3333-444455556666
     */
    public function releases($args, $assoc_args)
    {
        $this->ensure_user();
        $action = $args[0] ?? 'list';
        $format = $assoc_args['format'] ?? 'table';

        if ($action === 'list') {
            $limit  = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 20;
            $target = $assoc_args['target'] ?? 'auto';
            $status = !empty($assoc_args['status']) ? sanitize_key($assoc_args['status']) : null;
            $kind   = !empty($assoc_args['kind']) ? sanitize_key($assoc_args['kind']) : null;

            $releases = [];
            $source = 'local';

            if ($target !== 'local' && is_source()) {
                try {
                    $client = Sync_Client::from_config();
                    $qs = ['limit' => $limit];
                    if ($status) { $qs['status'] = $status; }
                    if ($kind) { $qs['kind'] = $kind; }
                    $res = $client->get('/code/releases?' . http_build_query($qs));
                    if (!is_wp_error($res) && isset($res['releases'])) {
                        $releases = $res['releases'];
                        $source = 'remote';
                    }
                } catch (\Exception $e) {
                    // Fall back to local
                }
            }

            if (empty($releases) && $source === 'local') {
                $filters = [];
                if ($status) { $filters['status'] = $status; }
                if ($kind) { $filters['kind'] = $kind; }
                $releases = Release_Store::recent($limit, $filters, false);
            }

            if (empty($releases)) {
                \WP_CLI::line('No sync releases found.');
                return;
            }

            if ($format === 'json') {
                \WP_CLI::line(json_encode(['source' => $source, 'releases' => $releases], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return;
            }

            $rows = array_map(function ($r) {
                $author = $r['author_name'] ?: ($r['author_email'] ?: 'system');
                return [
                    'release_id' => substr($r['release_id'], 0, 13) . '...',
                    'created_at' => $r['created_at'],
                    'kind'       => $r['kind'],
                    'status'     => $r['status'],
                    'author'     => $author,
                    'label'      => $r['label'] ?: '-',
                    'items'      => (int) ($r['items_count'] ?? 0),
                    'duration_s' => $r['duration_s'] ? $r['duration_s'] . 's' : '-',
                ];
            }, $releases);

            \WP_CLI\Utils\format_items($format, $rows, ['release_id', 'created_at', 'kind', 'status', 'author', 'label', 'items', 'duration_s']);
            if ($source === 'remote') {
                \WP_CLI::line('(Authoritative history from target)');
            }
            return;
        }

        if ($action === 'show') {
            $id = $args[1] ?? '';
            if ($id === '') {
                \WP_CLI::error('Usage: wp awx sync releases show <release-id>');
            }

            $release = null;
            $target = $assoc_args['target'] ?? 'auto';
            if ($target !== 'local' && is_source()) {
                try {
                    $client = Sync_Client::from_config();
                    $res = $client->get('/code/releases/' . rawurlencode($id));
                    if (!is_wp_error($res) && isset($res['release'])) {
                        $release = $res['release'];
                    }
                } catch (\Exception $e) {
                    // Fall back to local
                }
            }

            if (!$release) {
                $release = Release_Store::get($id, true);
            }

            if (!$release) {
                \WP_CLI::error("Release '{$id}' not found.");
            }

            if ($format === 'json') {
                \WP_CLI::line(json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return;
            }

            \WP_CLI::line('--------------------------------------------------');
            \WP_CLI::line("Release ID:   {$release['release_id']}");
            \WP_CLI::line("Created:      {$release['created_at']} UTC");
            \WP_CLI::line("Completed:    " . ($release['completed_at'] ?: '-') . ' UTC');
            \WP_CLI::line("Duration:     " . ($release['duration_s'] ? $release['duration_s'] . 's' : '-'));
            \WP_CLI::line("Kind:         {$release['kind']}");
            \WP_CLI::line("Status:       {$release['status']}");
            \WP_CLI::line("Label:        " . ($release['label'] ?: '-'));
            \WP_CLI::line("Author:       " . ($release['author_name'] ?: '-') . ' <' . ($release['author_email'] ?: '-') . '>');
            \WP_CLI::line("Client IP:    " . ($release['client_ip'] ?: '-'));
            \WP_CLI::line("Source URL:   " . ($release['source_url'] ?: '-'));
            \WP_CLI::line("Token ID:     " . ($release['token_id'] ?: '-'));
            \WP_CLI::line("Total Items:  {$release['items_count']}");
            \WP_CLI::line('--------------------------------------------------');

            $items = $release['items'] ?? [];
            if (!empty($items)) {
                \WP_CLI::line('Itemized manifest:');
                $item_rows = array_map(function ($it) {
                    return [
                        'key'    => $it['key'] ?? '-',
                        'kind'   => $it['kind'] ?? '-',
                        'title'  => $it['title'] ?? ($it['slug'] ?? '-'),
                        'action' => $it['action'] ?? 'updated',
                    ];
                }, $items);
                \WP_CLI\Utils\format_items($format, $item_rows, ['key', 'kind', 'title', 'action']);
            }
            return;
        }

        \WP_CLI::error("Unknown action '{$action}'. Valid actions: list, show");
    }
}
