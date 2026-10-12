<?php
namespace aw2\abilities\operations\discovery;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/dictionary-get
 *
 * Returns Universal Dictionary parameter names (title, text, items, image, url, etc.)
 * and their meaning by context. Use these names for all block fields.
 */
class Dictionary_Get extends Operation_Base
{
    public function execute(array $params = [])
    {
        $json_file = dirname(dirname(__DIR__)) . '/data/universal-dictionary.json';
        if (file_exists($json_file)) {
            $data = json_decode(file_get_contents($json_file), true);
            if (is_array($data)) {
                return $this->success($data);
            }
        }

        // Fallback schema if file not readable
        return $this->success([
            'description' => 'Universal Dictionary standard field names for Gutenberg block fields.',
            'fields' => [
                'title'       => ['type' => 'string', 'control' => 'title', 'purpose' => 'Headline or entity title'],
                'text'        => ['type' => 'string', 'control' => 'textarea', 'purpose' => 'Main body text copy'],
                'description' => ['type' => 'string', 'control' => 'textarea', 'purpose' => 'Secondary summary / excerpt'],
                'items'       => ['type' => 'array',  'control' => 'row_repeater', 'purpose' => 'Repeater list of items'],
                'image'       => ['type' => 'object', 'control' => 'image', 'purpose' => 'Media library image asset with url/alt'],
                'icon'        => ['type' => 'string', 'control' => 'select', 'purpose' => 'Icon name or symbol'],
                'url'         => ['type' => 'string', 'control' => 'text', 'purpose' => 'Link destination'],
                'button_text' => ['type' => 'string', 'control' => 'text', 'purpose' => 'CTA button label'],
                'layout'      => ['type' => 'string', 'control' => 'select', 'purpose' => 'Structural layout variation'],
                'style'       => ['type' => 'string', 'control' => 'select', 'purpose' => 'Visual style modifier class'],
                'category'    => ['type' => 'string', 'control' => 'text', 'purpose' => 'Taxonomy tag or label'],
                'date'        => ['type' => 'string', 'control' => 'date', 'purpose' => 'Date or schedule stamp']
            ]
        ]);
    }
}
