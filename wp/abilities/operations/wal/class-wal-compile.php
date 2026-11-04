<?php
namespace aw2\abilities\operations\wal;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/wal-compile
 *
 * Compiles a declarative Website Assembly Language (WAL) document directly
 * into a published WordPress Page with native Gutenberg block AST.
 */
class WAL_Compile extends Operation_Base
{
    public function execute(array $params = [])
    {
        if (!class_exists('WAL_Transpiler')) {
            $wal_file = dirname(dirname(dirname(__DIR__))) . '/libraries/gutenberg/lib/class-wal-engine.php';
            if (file_exists($wal_file)) {
                require_once $wal_file;
            }
        }

        if (!class_exists('WAL_Transpiler')) {
            return $this->error(
                'WAL Engine is unavailable.',
                'Ensure the Gutenberg library and WAL_Transpiler are loaded.',
                'wal_unavailable'
            );
        }

        $wal = $params['wal'] ?? [];

        if (empty($wal) || !is_array($wal)) {
            return $this->error(
                "Missing 'wal' parameter.",
                "Provide a structured 'wal' object with a 'page' definition: {\"wal\": {\"page\": { ... }}}",
                'missing_wal'
            );
        }

        if (empty($wal['page']) || !is_array($wal['page'])) {
            return $this->error(
                "Invalid WAL structure: missing 'page' root key.",
                "Ensure your WAL document contains a root 'page' object with 'title', 'slug', and 'sections'.",
                'invalid_wal_structure'
            );
        }

        try {
            $post_id = \WAL_Transpiler::compile_and_save($wal);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'Review WAL structure and section attributes.', 'invalid_wal_argument');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 'Verify database write permissions and try again.', 'compilation_failed');
        }

        $page_url = function_exists('get_permalink') ? get_permalink($post_id) : '';
        $edit_url = function_exists('get_edit_post_link') ? get_edit_post_link($post_id, 'raw') : '';

        $title  = $wal['page']['title'] ?? '';
        $slug   = $wal['page']['slug'] ?? '';
        $status = $wal['page']['status'] ?? 'draft';

        $this->audit_log('awesome/wal-compile', 'page', $slug ?: (string)$post_id, 'success', [
            'post_id' => $post_id,
            'status'  => $status
        ]);

        return $this->success([
            'post_id'  => $post_id,
            'title'    => $title,
            'slug'     => $slug,
            'status'   => $status,
            'url'      => $page_url,
            'edit_url' => $edit_url,
        ], sprintf("Page '%s' successfully compiled and saved (ID: %d).", $title ?: $slug, $post_id));
    }
}
