<?php
namespace aw2\abilities\operations\lifecycle;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/blocks-flush
 *
 * Flushes the cached Gutenberg block registry and optionally triggers recompilation of all block CSS/JS assets.
 */
class Blocks_Flush extends Operation_Base
{
    public function execute(array $params = [])
    {
        $recompile = !empty($params['recompile']);
        $slug      = isset($params['slug']) ? sanitize_title(trim($params['slug'])) : '';

        if (!function_exists('aw2\gutenberg_blocks\dgb')) {
            return $this->error(
                'Gutenberg blocks subsystem is not active.',
                'Ensure the aw2 gutenberg library is enabled.',
                'subsystem_inactive'
            );
        }

        $dgb = \aw2\gutenberg_blocks\dgb();
        $dgb->invalidate_registry();

        $data = [
            'flushed'   => true,
            'timestamp' => (string) microtime(true),
        ];

        if (!empty($slug)) {
            $compiled_assets = $dgb->recompile_single_block_assets($slug);
            $has_assets = !empty($compiled_assets['css']) || !empty($compiled_assets['js']);
            $data['targeted_slug'] = $slug;
            $data['recompiled']    = $has_assets;
            $data['assets']        = $compiled_assets;

            if ($has_assets) {
                $this->audit_log('awesome/blocks-flush', 'block', $slug, 'success', ['recompiled' => true]);
                return $this->success($data, "Block registry cache flushed and assets recompiled for '{$slug}'.");
            } else {
                $this->audit_log('awesome/blocks-flush', 'block', $slug, 'warning', ['recompiled' => false]);
                return $this->success($data, "Block registry cache flushed. Block '{$slug}' had no compilable styles/scripts or was not found.");
            }
        }

        if ($recompile) {
            $built = $dgb->build_registry();
            $count = is_array($built) ? count($built) : 0;
            $data['recompiled_count'] = $count;
            $this->audit_log('awesome/blocks-flush', 'block', 'all', 'success', ['count' => $count]);
            return $this->success($data, "Block registry flushed and recompiled {$count} block(s).");
        }

        $this->audit_log('awesome/blocks-flush', 'block', 'all', 'success', ['recompiled' => false]);
        return $this->success($data, 'Block registry cache successfully invalidated. Stamp bumped.');
    }
}
