<?php
/**
 * Minimal WordPress stubs for running test-identity.php outside WordPress.
 *
 * Posts and postmeta live in an in-memory SQLite database behind a tiny
 * $wpdb look-alike, so Identity's real SQL (joins, IN lists, LIMIT paging)
 * is exercised rather than mocked away. Only loaded when no WordPress is
 * bootstrapped; never loaded inside a real site.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

class WP_Error
{
    private $code;
    private $message;
    public function __construct($code = '', $message = '')
    {
        $this->code    = $code;
        $this->message = $message;
    }
    public function get_error_code()    { return $this->code; }
    public function get_error_message() { return $this->message; }
}

class WP_Post
{
    public $ID;
    public $post_type;
    public $post_status;
    public $post_title;
    public $post_content;
    public function __construct(array $row)
    {
        $this->ID           = (int) $row['ID'];
        $this->post_type    = $row['post_type'];
        $this->post_status  = $row['post_status'];
        $this->post_title   = $row['post_title'];
        $this->post_content = $row['post_content'];
    }
}

/**
 * SQLite-backed stand-in for the handful of $wpdb methods Identity uses.
 */
class AWX_Stub_WPDB
{
    public $prefix   = 'wp_';
    public $posts    = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $insert_id = 0;
    /** @var PDO */
    public $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE wp_posts (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            post_type TEXT, post_status TEXT, post_title TEXT, post_content TEXT, post_mime_type TEXT
        )');
        $this->pdo->exec('CREATE TABLE wp_postmeta (
            meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER, meta_key TEXT, meta_value TEXT
        )');
    }

    /** Mimics $wpdb->prepare(): %s quoted, %d int, %f float, %% literal. */
    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%(%|[sdf])/', function ($m) use (&$i, $args) {
            if ($m[1] === '%') {
                return '%';
            }
            $v = $args[$i++] ?? null;
            switch ($m[1]) {
                case 'd': return (string) (int) $v;
                case 'f': return (string) (float) $v;
                default:  return $this->pdo->quote((string) $v);
            }
        }, $query);
    }

    public function query($sql)
    {
        $n = $this->pdo->exec($sql);
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return $n;
    }

    public function get_results($sql, $output = OBJECT)
    {
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if ($output === ARRAY_A) {
            return $rows;
        }
        return array_map(function ($r) { return (object) $r; }, $rows);
    }

    public function get_row($sql, $output = OBJECT)
    {
        $rows = $this->get_results($sql, $output);
        return $rows ? $rows[0] : null;
    }

    public function get_col($sql)
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public function get_var($sql)
    {
        $v = $this->pdo->query($sql)->fetchColumn(0);
        return $v === false ? null : $v;
    }
}

define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['wpdb'] = new AWX_Stub_WPDB();
$GLOBALS['awx_stub_hooks'] = [];

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['awx_stub_hooks'][$hook][] = [$callback, (int) $accepted_args];
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
    add_action($hook, $callback, $priority, $accepted_args);
}
function do_action($hook, ...$args)
{
    foreach ($GLOBALS['awx_stub_hooks'][$hook] ?? [] as $entry) {
        call_user_func_array($entry[0], array_slice($args, 0, $entry[1]));
    }
}
function apply_filters($hook, $value, ...$args)
{
    return $value;
}

function wp_generate_uuid4()
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function get_post($post_id)
{
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM wp_posts WHERE ID = %d', $post_id), ARRAY_A);
    return $row ? new WP_Post($row) : null;
}

function get_post_types($args = [], $output = 'names')
{
    return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment'];
}

function get_post_meta($post_id, $key, $single = false)
{
    global $wpdb;
    $vals = $wpdb->get_col($wpdb->prepare(
        'SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC',
        $post_id, $key
    ));
    if ($single) {
        return $vals ? $vals[0] : '';
    }
    return $vals;
}

function add_post_meta($post_id, $key, $value, $unique = false)
{
    global $wpdb;
    if ($unique && get_post_meta($post_id, $key, true) !== '') {
        return false;
    }
    $wpdb->query($wpdb->prepare(
        'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (%d, %s, %s)',
        $post_id, $key, $value
    ));
    return $wpdb->insert_id;
}

function update_post_meta($post_id, $key, $value)
{
    global $wpdb;
    $existing = $wpdb->get_col($wpdb->prepare(
        'SELECT meta_id FROM wp_postmeta WHERE post_id = %d AND meta_key = %s', $post_id, $key
    ));
    if ($existing) {
        $wpdb->query($wpdb->prepare(
            'UPDATE wp_postmeta SET meta_value = %s WHERE post_id = %d AND meta_key = %s',
            $value, $post_id, $key
        ));
        return true;
    }
    return (bool) add_post_meta($post_id, $key, $value);
}

function delete_post_meta($post_id, $key)
{
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM wp_postmeta WHERE post_id = %d AND meta_key = %s', $post_id, $key));
    return true;
}

function wp_is_post_autosave($post_id) { return false; }
function wp_is_post_revision($post_id) { return false; }

function wp_insert_post(array $data, $wp_error = false)
{
    global $wpdb;
    $update = !empty($data['ID']);
    if ($update) {
        $cur = get_post($data['ID']);
        $wpdb->query($wpdb->prepare(
            'UPDATE wp_posts SET post_type=%s, post_status=%s, post_title=%s, post_content=%s WHERE ID=%d',
            $data['post_type'] ?? $cur->post_type,
            $data['post_status'] ?? $cur->post_status,
            $data['post_title'] ?? $cur->post_title,
            $data['post_content'] ?? $cur->post_content,
            $data['ID']
        ));
        $id = (int) $data['ID'];
    } else {
        $wpdb->query($wpdb->prepare(
            'INSERT INTO wp_posts (post_type, post_status, post_title, post_content, post_mime_type) VALUES (%s, %s, %s, %s, %s)',
            $data['post_type'] ?? 'post',
            $data['post_status'] ?? 'draft',
            $data['post_title'] ?? '',
            $data['post_content'] ?? '',
            $data['post_mime_type'] ?? ''
        ));
        $id = $wpdb->insert_id;
    }
    $post = get_post($id);
    do_action('save_post', $id, $post, $update);
    return $id;
}

function wp_update_post(array $data, $wp_error = false)
{
    return wp_insert_post($data, $wp_error);
}

function wp_insert_attachment(array $data, $file = false)
{
    $data['post_type']   = 'attachment';
    $data['post_status'] = $data['post_status'] ?? 'inherit';
    $id = wp_insert_post($data);
    if ($file) {
        update_post_meta($id, '_wp_attached_file', $file);
    }
    do_action('add_attachment', $id);
    return $id;
}

function get_attached_file($attachment_id, $unfiltered = false)
{
    $f = get_post_meta($attachment_id, '_wp_attached_file', true);
    return $f === '' ? false : $f;
}

function wp_delete_post($post_id, $force = false)
{
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM wp_postmeta WHERE post_id = %d', $post_id));
    $wpdb->query($wpdb->prepare('DELETE FROM wp_posts WHERE ID = %d', $post_id));
    return true;
}
