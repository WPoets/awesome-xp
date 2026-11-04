<?php
namespace aw2\abilities\operations\patterns;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/pattern-import
 *
 * Imports a pattern bundle manifest idempotently into wp_block posts
 * with unsynced status, tracking metadata, and taxonomy categories.
 */
class Pattern_Import extends Operation_Base
{
    public function execute(array $params = [])
    {
        if (!class_exists('AWX_Pattern_Bridge')) {
            $bridge_file = dirname(dirname(dirname(__DIR__))) . '/libraries/gutenberg/lib/class-pattern-bridge.php';
            if (file_exists($bridge_file)) {
                require_once $bridge_file;
            }
        }

        if (!class_exists('AWX_Pattern_Bridge')) {
            return $this->error(
                'Pattern Bridge engine is unavailable.',
                'Ensure the Gutenberg library and AWX_Pattern_Bridge are loaded.',
                'bridge_unavailable'
            );
        }

        $manifest = [];

        // In-memory array payload
        if (isset($params['manifest']) && is_array($params['manifest'])) {
            $manifest = $params['manifest'];
        } elseif (!empty($params['path'])) {
            // Read from filesystem path
            $path = sanitize_text_field(trim($params['path']));
            if (!file_exists($path)) {
                return $this->error(
                    "Pattern manifest file not found: {$path}",
                    "Verify the file path exists and is readable.",
                    'file_not_found',
                    ['path' => $path]
                );
            }
            $raw = file_get_contents($path);
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return $this->error(
                    "Invalid JSON format in: {$path}",
                    "Ensure the file contains a valid JSON array of pattern definitions.",
                    'invalid_json',
                    ['json_error' => json_last_error_msg()]
                );
            }
            $manifest = $decoded;
        } else {
            return $this->error(
                'Missing pattern manifest.',
                'Provide either a "manifest" array of pattern objects or a "path" to a JSON file.',
                'missing_manifest'
            );
        }

        if (empty($manifest)) {
            return $this->error(
                'Pattern manifest is empty.',
                'Provide a non-empty array of pattern definitions with "slug", "title", and "content".',
                'empty_manifest'
            );
        }

        $results = \AWX_Pattern_Bridge::import_bundle($manifest);

        $this->audit_log('awesome/pattern-import', 'pattern', 'bundle', 'success', [
            'created' => $results['created'],
            'updated' => $results['updated'],
            'errors'  => count($results['errors'])
        ]);

        return $this->success(
            $results,
            sprintf(
                'Import complete: %d created, %d updated, %d error(s).',
                $results['created'],
                $results['updated'],
                count($results['errors'])
            )
        );
    }
}
