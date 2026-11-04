<?php
namespace aw2\abilities\operations\wal;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/wal-decompile
 *
 * Decompiles a published WordPress Page post_content into a clean,
 * structured Website Assembly Language (WAL) document for agent editing.
 */
class WAL_Decompile extends Operation_Base
{
    public function execute(array $params = [])
    {
        if (!class_exists('WAL_Decompiler')) {
            $wal_file = dirname(dirname(dirname(__DIR__))) . '/libraries/gutenberg/lib/class-wal-engine.php';
            if (file_exists($wal_file)) {
                require_once $wal_file;
            }
        }

        if (!class_exists('WAL_Decompiler')) {
            return $this->error(
                'WAL Engine is unavailable.',
                'Ensure the Gutenberg library and WAL_Decompiler are loaded.',
                'wal_unavailable'
            );
        }

        $post_id = isset($params['post_id']) ? (int)$params['post_id'] : 0;

        if ($post_id <= 0) {
            return $this->error(
                "Invalid 'post_id' parameter.",
                "Provide a positive integer post ID to decompile: {\"post_id\": 104}",
                'invalid_post_id'
            );
        }

        try {
            $wal_doc = \WAL_Decompiler::decompile($post_id);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'Check that the post exists and is accessible.', 'post_not_found');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 'Review post_content for valid block comments.', 'decompilation_error');
        }

        $this->audit_log('awesome/wal-decompile', 'page', (string)$post_id, 'success');

        return $this->success(
            $wal_doc,
            sprintf("Page ID %d successfully decompiled to WAL.", $post_id)
        );
    }
}
