<?php
namespace aw2\abilities\operations\discovery;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/token-list
 *
 * Lists awx design tokens (role tokens by default, grouped by type).
 * Blocks must use these tokens rather than raw values.
 */
class Token_List extends Operation_Base
{
    public function execute(array $params = [])
    {
        $group_filter = isset($params['group']) ? sanitize_key($params['group']) : '';
        $include_primitives = !empty($params['include_primitives']);

        $token_defaults = function_exists('awx_get_token_defaults') ? awx_get_token_defaults() : null;
        $all_tokens = $token_defaults ? $token_defaults->get_all() : [];

        // Fallback standard design tokens if tokens module hasn't booted or is empty
        if (empty($all_tokens)) {
            $all_tokens = [
                'color' => [
                    'roles' => [
                        'brand-primary'   => ['value' => 'var(--awx-color-brand-primary)', 'purpose' => 'Primary brand color for buttons, key accents'],
                        'brand-secondary' => ['value' => 'var(--awx-color-brand-secondary)', 'purpose' => 'Secondary accents'],
                        'surface-base'    => ['value' => 'var(--awx-color-surface-base)', 'purpose' => 'Page background surface'],
                        'surface-card'    => ['value' => 'var(--awx-color-surface-card)', 'purpose' => 'Card background surface'],
                        'text-body'       => ['value' => 'var(--awx-color-text-body)', 'purpose' => 'Main paragraph text'],
                        'text-heading'    => ['value' => 'var(--awx-color-text-heading)', 'purpose' => 'Title and heading text'],
                        'text-muted'      => ['value' => 'var(--awx-color-text-muted)', 'purpose' => 'Subtle and caption text'],
                        'border-subtle'   => ['value' => 'var(--awx-color-border-subtle)', 'purpose' => 'Subtle card and divider borders']
                    ],
                    'primitives' => [
                        'brand' => ['500' => '#3b82f6', '600' => '#2563eb', '700' => '#1d4ed8'],
                        'gray'  => ['100' => '#f3f4f6', '200' => '#e5e7eb', '800' => '#1f2937', '900' => '#111827']
                    ]
                ],
                'space' => [
                    'xs'  => ['value' => '0.25rem', 'css_var' => '--awx-space-xs', 'purpose' => '4px spacing'],
                    'sm'  => ['value' => '0.5rem',  'css_var' => '--awx-space-sm', 'purpose' => '8px spacing'],
                    'md'  => ['value' => '1rem',    'css_var' => '--awx-space-md', 'purpose' => '16px spacing'],
                    'lg'  => ['value' => '1.5rem',  'css_var' => '--awx-space-lg', 'purpose' => '24px spacing'],
                    'xl'  => ['value' => '2rem',    'css_var' => '--awx-space-xl', 'purpose' => '32px spacing'],
                    '2xl' => ['value' => '3rem',    'css_var' => '--awx-space-2xl', 'purpose' => '48px spacing']
                ],
                'fontSize' => [
                    'sm'   => ['value' => '0.875rem', 'css_var' => '--awx-font-sm', 'purpose' => 'Small caption text'],
                    'base' => ['value' => '1rem',     'css_var' => '--awx-font-base', 'purpose' => 'Body text'],
                    'lg'   => ['value' => '1.125rem', 'css_var' => '--awx-font-lg', 'purpose' => 'Lead paragraphs / subtitles'],
                    'xl'   => ['value' => '1.25rem',  'css_var' => '--awx-font-xl', 'purpose' => 'Card titles'],
                    '2xl'  => ['value' => '1.5rem',   'css_var' => '--awx-font-2xl', 'purpose' => 'Section headings'],
                    '3xl'  => ['value' => '2rem',     'css_var' => '--awx-font-3xl', 'purpose' => 'Hero titles']
                ],
                'radii' => [
                    'sm'   => ['value' => '0.25rem', 'css_var' => '--awx-radius-sm', 'purpose' => 'Small rounded corners'],
                    'md'   => ['value' => '0.5rem',  'css_var' => '--awx-radius-md', 'purpose' => 'Card corners'],
                    'lg'   => ['value' => '1rem',    'css_var' => '--awx-radius-lg', 'purpose' => 'Large dialog / banner corners'],
                    'full' => ['value' => '9999px',  'css_var' => '--awx-radius-full', 'purpose' => 'Pill / badge radius']
                ],
                'shadow' => [
                    'sm' => ['value' => '0 1px 2px 0 rgb(0 0 0 / 0.05)', 'css_var' => '--awx-shadow-sm', 'purpose' => 'Subtle elevation'],
                    'md' => ['value' => '0 4px 6px -1px rgb(0 0 0 / 0.1)', 'css_var' => '--awx-shadow-md', 'purpose' => 'Card hover / dropdown elevation'],
                    'lg' => ['value' => '0 10px 15px -3px rgb(0 0 0 / 0.1)', 'css_var' => '--awx-shadow-lg', 'purpose' => 'Modal / popover elevation']
                ]
            ];
        }

        $result = [];
        foreach ($all_tokens as $cat_key => $cat_data) {
            if (!empty($group_filter) && $cat_key !== $group_filter) {
                continue;
            }

            if ($cat_key === 'color') {
                $roles = $cat_data['roles'] ?? [];
                $result['color']['roles'] = $roles;
                if ($include_primitives && isset($cat_data['primitives'])) {
                    $result['color']['primitives'] = $cat_data['primitives'];
                }
            } else {
                $result[$cat_key] = $cat_data;
            }
        }

        return $this->success([
            'tokens'           => $result,
            'token_rule'       => 'Always use awx design tokens in block CSS instead of hardcoded hex colors or pixel dimensions.'
        ]);
    }
}
