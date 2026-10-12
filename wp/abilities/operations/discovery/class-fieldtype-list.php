<?php
namespace aw2\abilities\operations\discovery;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/fieldtype-list
 *
 * Lists Dynamic Gutenberg Block (DGB) field types with their config options.
 * Use when defining block fields.
 */
class Fieldtype_List extends Operation_Base
{
    public function execute(array $params = [])
    {
        $field_types = [
            'text' => [
                'type'        => 'text',
                'description' => 'Single-line text input',
                'options'     => ['name', 'label', 'attr_name', 'default', 'placeholder'],
                'example'     => ['type' => 'text', 'name' => 'heading', 'label' => 'Heading', 'attr_name' => 'content.title']
            ],
            'textarea' => [
                'type'        => 'textarea',
                'description' => 'Multi-line text input for paragraphs or excerpts',
                'options'     => ['name', 'label', 'attr_name', 'default', 'placeholder', 'rows'],
                'example'     => ['type' => 'textarea', 'name' => 'lead_text', 'label' => 'Lead Text', 'attr_name' => 'content.text']
            ],
            'number' => [
                'type'        => 'number',
                'description' => 'Numeric input',
                'options'     => ['name', 'label', 'attr_name', 'default', 'min', 'max', 'step'],
                'example'     => ['type' => 'number', 'name' => 'columns', 'label' => 'Columns', 'attr_name' => 'settings.columns', 'default' => 3]
            ],
            'select' => [
                'type'        => 'select',
                'description' => 'Dropdown single-selection menu',
                'options'     => ['name', 'label', 'attr_name', 'default', 'options'],
                'example'     => [
                    'type'      => 'select',
                    'name'      => 'layout',
                    'label'     => 'Layout',
                    'attr_name' => 'style.layout',
                    'options'   => [
                        ['label' => 'Standard', 'value' => 'standard'],
                        ['label' => 'Card', 'value' => 'card'],
                        ['label' => 'Minimal', 'value' => 'minimal']
                    ]
                ]
            ],
            'toggle' => [
                'type'        => 'toggle',
                'description' => 'Boolean switch toggle',
                'options'     => ['name', 'label', 'attr_name', 'default'],
                'example'     => ['type' => 'toggle', 'name' => 'show_badge', 'label' => 'Show Badge', 'attr_name' => 'settings.showBadge', 'default' => true]
            ],
            'image' => [
                'type'        => 'image',
                'description' => 'WordPress Media Library image selector with url, alt, and id',
                'options'     => ['name', 'label', 'attr_name'],
                'example'     => ['type' => 'image', 'name' => 'card_image', 'label' => 'Card Image', 'attr_name' => 'content.image']
            ],
            'date' => [
                'type'        => 'date',
                'description' => 'Date picker',
                'options'     => ['name', 'label', 'attr_name', 'default'],
                'example'     => ['type' => 'date', 'name' => 'event_date', 'label' => 'Event Date', 'attr_name' => 'content.date']
            ],
            'row_repeater' => [
                'type'        => 'row_repeater',
                'description' => 'Repeatable rows containing defined sub-fields',
                'options'     => ['name', 'label', 'attr_name', 'repeater_fields'],
                'example'     => [
                    'type'            => 'row_repeater',
                    'name'            => 'features',
                    'label'           => 'Features List',
                    'attr_name'       => 'content.items',
                    'repeater_fields' => [
                        ['type' => 'text', 'name' => 'item_title', 'label' => 'Feature Title'],
                        ['type' => 'toggle', 'name' => 'included', 'label' => 'Included', 'default' => true]
                    ]
                ]
            ],
            'attributes-repeater' => [
                'type'        => 'attributes-repeater',
                'description' => 'Dynamic key-value metadata attributes',
                'options'     => ['name', 'label', 'attr_name'],
                'example'     => ['type' => 'attributes-repeater', 'name' => 'custom_attrs', 'label' => 'Data Attributes', 'attr_name' => 'advanced.dataAttrs']
            ],
            'innerblocks' => [
                'type'        => 'innerblocks',
                'description' => 'Nested block container allowing arbitrary or allowed inner Gutenberg blocks',
                'options'     => ['name', 'label', 'allowed_blocks', 'template'],
                'example'     => ['type' => 'innerblocks', 'name' => 'content_blocks', 'label' => 'Inner Blocks']
            ]
        ];

        return $this->success($field_types);
    }
}
