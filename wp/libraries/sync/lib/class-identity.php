<?php
namespace aw2\sync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AWX Sync — Identity.
 *
 * Gives every syncable object (public post types, wp_block reusable blocks,
 * media attachments) a stable UUID that is identical on Dev and Prod, so the
 * two sites never need matching post IDs. Also owns the hashing helpers the
 * sync uses to detect changes.
 *
 * Meta keys:
 *   _awx_sync_uuid     UUIDv4, assigned once, never overwritten by this class.
 *   _awx_content_hash  SHA-256 of post_content at last sync. Only the sync
 *                      writes it; Identity merely exposes content_hash().
 *   _awx_file_hash     SHA-256 of the attachment's original file.
 *
 * All lookups are scoped to the current blog via $wpdb->postmeta / $wpdb->posts.
 */
class Identity
{
    const META_UUID         = '_awx_sync_uuid';
    const META_CONTENT_HASH = '_awx_content_hash';
    const META_FILE_HASH    = '_awx_file_hash';

    /** Default backfill batch size. */
    const BATCH_SIZE = 200;

    /** Guard so hooks are only registered once. */
    private static $hooked = false;

    /**
     * Register save_post / add_attachment hooks.
     */
    public static function init(): void
    {
        if (self::$hooked) {
            return;
        }
        self::$hooked = true;

        add_action('save_post', [__CLASS__, 'on_save_post'], 20, 3);
        add_action('add_attachment', [__CLASS__, 'on_add_attachment'], 20, 1);
    }

    /* ------------------------------------------------------------------ */
    /* UUID                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Return the post's UUID, assigning a fresh UUIDv4 if it has none.
     * Never overwrites an existing UUID.
     */
    public static function ensure_uuid(int $post_id): string
    {
        $existing = self::get_uuid($post_id);
        if ($existing !== null) {
            return $existing;
        }

        $uuid = wp_generate_uuid4();
        // add_post_meta with $unique=true: if a concurrent writer beat us to it
        // the add fails and we re-read instead of clobbering.
        $added = add_post_meta($post_id, self::META_UUID, $uuid, true);
        if ($added === false) {
            $again = self::get_uuid($post_id);
            if ($again !== null) {
                return $again;
            }
            update_post_meta($post_id, self::META_UUID, $uuid);
        }

        return $uuid;
    }

    /**
     * Return the post's UUID or null when none is stored.
     *
     * @param int|\WP_Post $post_id
     */
    public static function get_uuid($post_id): ?string
    {
        $post_id = self::to_id($post_id);
        if ($post_id <= 0) {
            return null;
        }
        $uuid = get_post_meta($post_id, self::META_UUID, true);
        if (!is_string($uuid) || $uuid === '') {
            return null;
        }
        return $uuid;
    }

    /**
     * Find the single post carrying $uuid on the current blog.
     *
     * Returns null when no post matches, or when more than one does
     * (a warning is logged in that case — duplicates break the sync).
     *
     * @param string   $uuid
     * @param string[] $post_types Optional restriction; empty = any type.
     */
    public static function find_by_uuid(string $uuid, array $post_types = []): ?int
    {
        global $wpdb;

        $uuid = trim($uuid);
        if ($uuid === '') {
            return null;
        }

        $sql = "SELECT pm.post_id
                  FROM {$wpdb->postmeta} pm
                  INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND pm.meta_value = %s";
        $params = [self::META_UUID, $uuid];

        $post_types = array_values(array_filter(array_map('strval', $post_types)));
        if (!empty($post_types)) {
            $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
            $sql .= " AND p.post_type IN ({$placeholders})";
            $params = array_merge($params, $post_types);
        }

        // Fetch two rows so we can detect duplicates without counting everything.
        $sql .= ' ORDER BY pm.post_id ASC LIMIT 2';

        $ids = $wpdb->get_col($wpdb->prepare($sql, $params));
        if (empty($ids)) {
            return null;
        }
        if (count($ids) > 1) {
            self::log(sprintf(
                'Duplicate sync UUID %s on posts %s (first two shown).',
                $uuid,
                implode(', ', array_map('intval', $ids))
            ));
            return null;
        }

        return (int) $ids[0];
    }

    /**
     * Stamp $uuid onto $post_id (used when pairing a Prod post with its Dev twin).
     *
     * @return true|\WP_Error
     */
    public static function pair(int $post_id, string $uuid)
    {
        $uuid = strtolower(trim($uuid));

        if (!self::is_valid_uuid($uuid)) {
            return new \WP_Error('awx_sync_invalid_uuid', sprintf('"%s" is not a valid UUIDv4.', $uuid));
        }

        $post = get_post($post_id);
        if (!$post) {
            return new \WP_Error('awx_sync_no_post', sprintf('Post %d does not exist.', $post_id));
        }

        $holder = self::find_by_uuid($uuid);
        if ($holder !== null && $holder !== $post_id) {
            return new \WP_Error(
                'awx_sync_uuid_taken',
                sprintf('UUID %s is already held by post %d.', $uuid, $holder)
            );
        }

        $current = self::get_uuid($post_id);
        if ($current === $uuid) {
            return true;
        }

        update_post_meta($post_id, self::META_UUID, $uuid);
        return true;
    }

    /**
     * Map AWX-managed pattern slug => wp_block post ID for the current blog.
     *
     * Used by the pairing CLI: Dev exports [slug => uuid], Prod resolves each
     * slug to its local wp_block via this map and stamps the Dev UUID.
     *
     * @return array<string,int>
     */
    public static function pair_patterns(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT p.ID AS post_id, p.post_name, pm.meta_value AS meta_slug
               FROM {$wpdb->posts} p
               LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_awx_pattern_slug'
              WHERE p.post_type = 'wp_block'
                AND p.post_status <> 'auto-draft'
              ORDER BY p.ID ASC",
            ARRAY_A
        );

        $map = [];
        foreach ((array) $rows as $row) {
            $slug = !empty($row['meta_slug']) ? (string) $row['meta_slug'] : (string) ($row['post_name'] ?? '');
            if ($slug === '' || isset($map[$slug])) {
                continue; // first (lowest ID) wins on duplicate slugs
            }
            $map[$slug] = (int) $row['post_id'];
        }
        return $map;
    }

    /**
     * Strict UUIDv4 check (lowercase or uppercase hex accepted).
     */
    public static function is_valid_uuid(string $uuid): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    /* ------------------------------------------------------------------ */
    /* Hashes                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * SHA-256 of post_content (or any string). Pure; the sync stores the result.
     */
    public static function content_hash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * SHA-256 of the attachment's original file, cached in _awx_file_hash.
     *
     * Returns null when the attachment has no readable file.
     */
    public static function file_hash(int $attachment_id, bool $recompute = false): ?string
    {
        if (!$recompute) {
            $cached = get_post_meta($attachment_id, self::META_FILE_HASH, true);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $path = get_attached_file($attachment_id, true);
        if (!is_string($path) || $path === '' || !is_readable($path) || !is_file($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            return null;
        }

        update_post_meta($attachment_id, self::META_FILE_HASH, $hash);
        return $hash;
    }

    /* ------------------------------------------------------------------ */
    /* Hooks                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Post types that receive a UUID on save: all public types except
     * attachment (handled by add_attachment), plus wp_block.
     *
     * @return string[]
     */
    public static function tracked_post_types(): array
    {
        $types = get_post_types(['public' => true], 'names');
        $types = array_values(array_diff((array) $types, ['attachment']));
        $types[] = 'wp_block';
        $types = array_values(array_unique($types));

        /**
         * Filter the post types that receive sync UUIDs on save.
         *
         * @param string[] $types
         */
        return (array) apply_filters('awx_sync_tracked_post_types', $types);
    }

    /**
     * save_post: assign a UUID to new/updated tracked posts. Cheap: one meta read.
     *
     * @param int      $post_id
     * @param \WP_Post $post
     * @param bool     $update
     */
    public static function on_save_post($post_id, $post = null, $update = false): void
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }
        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id)) {
            return;
        }
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return;
        }
        if (!($post instanceof \WP_Post)) {
            $post = get_post($post_id);
            if (!$post) {
                return;
            }
        }
        if ($post->post_type === 'revision' || $post->post_status === 'auto-draft') {
            return;
        }
        if (!in_array($post->post_type, self::tracked_post_types(), true)) {
            return;
        }

        self::ensure_uuid($post_id);
    }

    /**
     * add_attachment: assign a UUID and compute the file hash.
     *
     * @param int $attachment_id
     */
    public static function on_add_attachment($attachment_id): void
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return;
        }
        self::ensure_uuid($attachment_id);
        self::file_hash($attachment_id);
    }

    /* ------------------------------------------------------------------ */
    /* Backfill                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Walk attachments (and optionally tracked posts) assigning missing UUIDs
     * and file hashes, in ID-ordered batches so memory stays flat.
     *
     * @param array $args {
     *   @type bool     $posts      Also process tracked post types. Default false.
     *   @type bool     $dry_run    Report only; write nothing. Default false.
     *   @type int      $batch      Rows per query. Default 200.
     *   @type callable $on_batch   Called with (int $rows_in_batch) after each batch.
     * }
     * @return array {scanned, stamped, hashed, skipped, missing_file}
     */
    public static function backfill(array $args = []): array
    {
        global $wpdb;

        $do_posts = !empty($args['posts']);
        $dry_run  = !empty($args['dry_run']);
        $batch    = isset($args['batch']) ? max(1, (int) $args['batch']) : self::BATCH_SIZE;
        $on_batch = isset($args['on_batch']) && is_callable($args['on_batch']) ? $args['on_batch'] : null;

        $counts = [
            'scanned'      => 0,
            'stamped'      => 0,
            'hashed'       => 0,
            'skipped'      => 0,
            'missing_file' => 0,
        ];

        $types = ['attachment'];
        if ($do_posts) {
            $types = array_merge($types, self::tracked_post_types());
        }
        $types = array_values(array_unique($types));
        $placeholders = implode(',', array_fill(0, count($types), '%s'));

        $last_id = 0;
        while (true) {
            $sql = "SELECT p.ID, p.post_type,
                           uuid.meta_value  AS uuid,
                           fhash.meta_value AS file_hash
                      FROM {$wpdb->posts} p
                      LEFT JOIN {$wpdb->postmeta} uuid
                             ON uuid.post_id = p.ID AND uuid.meta_key = %s
                      LEFT JOIN {$wpdb->postmeta} fhash
                             ON fhash.post_id = p.ID AND fhash.meta_key = %s
                     WHERE p.ID > %d
                       AND p.post_type IN ({$placeholders})
                       AND p.post_status NOT IN ('auto-draft')
                     ORDER BY p.ID ASC
                     LIMIT %d";
            $params = array_merge(
                [self::META_UUID, self::META_FILE_HASH, $last_id],
                $types,
                [$batch]
            );

            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $id      = (int) $row['ID'];
                $last_id = $id;
                $counts['scanned']++;

                $has_uuid  = is_string($row['uuid']) && $row['uuid'] !== '';
                $is_attach = $row['post_type'] === 'attachment';
                $has_hash  = is_string($row['file_hash']) && $row['file_hash'] !== '';

                $did_something = false;

                if (!$has_uuid) {
                    if (!$dry_run) {
                        self::ensure_uuid($id);
                    }
                    $counts['stamped']++;
                    $did_something = true;
                }

                if ($is_attach && !$has_hash) {
                    $path = get_attached_file($id, true);
                    if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
                        $counts['missing_file']++;
                        $did_something = true;
                    } else {
                        if (!$dry_run) {
                            self::file_hash($id);
                        }
                        $counts['hashed']++;
                        $did_something = true;
                    }
                }

                if (!$did_something) {
                    $counts['skipped']++;
                }
            }

            if ($on_batch) {
                call_user_func($on_batch, count($rows));
            }

            if (count($rows) < $batch) {
                break;
            }

            // Keep the object cache from ballooning on big libraries.
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
        }

        return $counts;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @param int|\WP_Post|mixed $post
     */
    private static function to_id($post): int
    {
        if ($post instanceof \WP_Post) {
            return (int) $post->ID;
        }
        return (int) $post;
    }

    private static function log(string $message): void
    {
        error_log('[awx-sync] ' . $message);
    }
}
