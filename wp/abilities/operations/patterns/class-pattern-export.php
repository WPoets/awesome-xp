<?php
namespace aw2\abilities\operations\patterns;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/pattern-export
 *
 * Exports all AWX-managed block patterns into a portable JSON bundle.
 * Optionally writes the bundle directly to a local filesystem path.
 */
class Pattern_Export extends Operation_Base
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

        $bundle = \AWX_Pattern_Bridge::export_bundle();
        $count = count($bundle);
        $path = isset($params['path']) ? sanitize_text_field(trim($params['path'])) : '';

        $response_data = [
            'count'    => $count,
            'patterns' => $bundle
        ];

        if (!empty($path)) {
            $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $bytes = @file_put_contents($path, $json);

            if ($bytes === false) {
                return $this->error(
                    "Unable to write export bundle to path: {$path}",
                    "Verify file permissions or choose an alternate destination path.",
                    'write_failed',
                    ['path' => $path]
                );
            }

            $response_data['path'] = $path;
            $response_data['bytes'] = $bytes;
        }

        $this->audit_log('awesome/pattern-export', 'pattern', 'all', 'success', ['count' => $count]);

        return $this->success(
            $response_data,
            sprintf('Successfully exported %d AWX-managed pattern(s).', $count)
        );
    }
}
