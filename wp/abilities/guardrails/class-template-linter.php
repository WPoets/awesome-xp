<?php
namespace aw2\abilities\guardrails;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template and Shortcode Linter
 *
 * Enforces framework safety rules and design system conventions.
 */
class Template_Linter
{
    /**
     * Run all lint rules on shortcode template code.
     *
     * @param string $code
     * @param string $context 'block' | 'service' | 'app'
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public static function lint($code, $context = 'block')
    {
        $errors = [];
        $warnings = [];

        if (empty(trim((string)$code))) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'rule' => 'empty-template',
                        'message' => 'Template code cannot be empty.',
                        'fix_hint' => 'Provide valid HTML/shortcode template content.'
                    ]
                ],
                'warnings' => []
            ];
        }

        // Rule 1: Nested brackets in attributes
        // e.g. [env.set x=[esc.str y] /] or attr="[...]"
        if (preg_match('/\[[a-zA-Z0-9_\.]+\s+[^\]]*=\s*["\']?\s*\[/i', $code, $matches, PREG_OFFSET_CAPTURE)) {
            $errors[] = [
                'rule' => 'nested-brackets-in-attributes',
                'message' => 'Nested shortcodes inside attributes are forbidden.',
                'fix_hint' => 'Execute the nested service beforehand and assign output using o.set="temp.var", then reference {temp.var}.'
            ];
        }

        // Rule 2: Nested curly braces (e.g. {person.{name}})
        // Double curly braces like {{service.name}} are allowed in AwesomeEnterprise
        $code_without_double = preg_replace('/\{\{[^{}]*\}\}/', '', $code);
        if (preg_match('/\{[^{}]*\{[^{}]*\}[^{}]*\}/', $code_without_double)) {
            $errors[] = [
                'rule' => 'nested-curly-braces',
                'message' => 'Nested curly braces inside attributes are invalid.',
                'fix_hint' => 'Resolve the inner variable path into an intermediate variable first.'
            ];
        }

        // Rule 3: Loop properties accessed without `.item`
        // e.g. [loop.@it arr] ... {@it.prop} or [env.@it.prop /]
        // Match loop blocks and check for direct property access on iterator
        if (preg_match_all('/\[loop\.@([a-zA-Z0-9_]+)[^\]]*\](.*?)\[\/loop\.@\1\]/s', $code, $loops, PREG_SET_ORDER)) {
            foreach ($loops as $loop) {
                $iter = preg_quote($loop[1], '/');
                $body = $loop[2];
                // Check {@iter.something} where something is NOT 'item', 'key', 'index', 'counter', 'first', 'last', 'between', 'odd', 'even'
                $reserved = 'item|key|index|counter|first|last|between|odd|even';
                if (preg_match('/\{@' . $iter . '\.(?!(' . $reserved . ')\b)[a-zA-Z0-9_]+\}/', $body, $bad_match) ||
                    preg_match('/\[env\.@' . $iter . '\.(?!(' . $reserved . ')\b)[a-zA-Z0-9_]+[^\]]*\/\]/', $body, $bad_match)) {
                    $errors[] = [
                        'rule' => 'loop-property-without-item',
                        'message' => "Iterator properties must be accessed through '.item' (found direct access on @{$loop[1]}).",
                        'fix_hint' => "Change direct access to {@{$loop[1]}.item.<property>} or [env.@{$loop[1]}.item.<property> /]."
                    ];
                    break;
                }
            }
        }

        // Rule 4: Data-returning services without o.set or o.destroy
        // e.g. [mysqli.fetch.rows] or [curl.api.get]
        $data_services = [
            'mysqli.fetch.rows',
            'mysqli.fetch.row',
            'mysqli.fetch.col',
            'mysqli.fetch.scalar',
            'mysqli.multi.fetch.rows',
            'curl.api.get',
            'curl.api.post',
            'curl.page.get'
        ];
        foreach ($data_services as $ds) {
            $quoted = preg_quote($ds, '/');
            if (preg_match_all('/\[' . $quoted . '\b([^\]]*)\]/i', $code, $svc_matches)) {
                foreach ($svc_matches[1] as $attrs) {
                    if (!preg_match('/\bo\.(set|destroy|die)\b/i', $attrs)) {
                        $errors[] = [
                            'rule' => 'untrapped-data-stream',
                            'message' => "Service [{$ds}] dumps raw data unless trapped with an outputter.",
                            'fix_hint' => "Add o.set=\"template.var\" or o.destroy=\"yes\" to the service attributes."
                        ];
                        break;
                    }
                }
            }
        }

        // Rule 5: Unescaped values in SQL & Variables in LIMIT clause
        if (preg_match_all('/\[mysqli\.(?:multi\.)?(?:fetch|cud)[^\]]*\](.*?)\[\/mysqli\.[^\]]*\]/is', $code, $sql_blocks)) {
            foreach ($sql_blocks[1] as $sql) {
                // Check variables in LIMIT clause (e.g. LIMIT @limit or LIMIT {limit})
                if (preg_match('/LIMIT\s+(@[a-zA-Z0-9_]+|\{[^}]+\})/i', $sql, $limit_match)) {
                    $errors[] = [
                        'rule' => 'variable-in-limit-clause',
                        'message' => 'MySQL session variables or curly braces in LIMIT clauses are invalid.',
                        'fix_hint' => 'Hardcode a constant integer limit in the query string.'
                    ];
                }
                // Check direct curly brace concatenation in SQL (e.g. WHERE col = '{var}')
                if (preg_match('/(?:WHERE|AND|OR|SET|VALUES)\s+[^;]*[\'"]?\{[a-zA-Z0-9_\.]+\}[\'"]?/i', $sql)) {
                    $errors[] = [
                        'rule' => 'unescaped-sql-value',
                        'message' => 'Raw environment variables or curly braces cannot be embedded directly in SQL queries.',
                        'fix_hint' => 'Escape values at the beginning of query using [esc.* /] and set session variables.'
                    ];
                }
            }
        }

        // Rule 6: Services off the allowlist
        if (preg_match_all('/\[([a-zA-Z0-9_\.\@]+)(?:\s+[^\]]*)?(\/\]|\])/', $code, $all_tags)) {
            $ignored_tags = ['if.else'];
            foreach ($all_tags[1] as $tag_name) {
                if (strpos($tag_name, '/') === 0 || in_array($tag_name, $ignored_tags, true)) {
                    continue;
                }
                if (!Allowlist::is_service_allowed($tag_name, $context)) {
                    $errors[] = [
                        'rule' => 'disallowed-service',
                        'message' => "Service '{$tag_name}' is not permitted for agent execution in context '{$context}'.",
                        'fix_hint' => 'Use only services registered on the agent allowlist (check awesome/service-list).'
                    ];
                    break;
                }
            }
        }

        // Rule 7: CSS Design System linter (Raw colour or spacing values where an awx token exists)
        // Check for inline style attributes or CSS blocks
        // e.g. color: #ff0000; or background-color: rgb(...); or padding: 24px;
        if (preg_match_all('/style\s*=\s*["\']([^"\']+)["\']/i', $code, $style_attrs)) {
            foreach ($style_attrs[1] as $style_content) {
                // Check hex colors
                if (preg_match('/#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b/', $style_content, $hex_match)) {
                    $warnings[] = [
                        'rule' => 'raw-css-color-value',
                        'message' => "Raw hex color '{$hex_match[0]}' found in inline styles.",
                        'fix_hint' => 'Use an Awesome CSS Design System token (e.g., var(--awx-color-brand-primary)) or utility class.'
                    ];
                }
                // Check hardcoded px/rem padding/margin spacing
                if (preg_match('/(?:padding|margin)(?:-[a-z]+)?\s*:\s*\d+(?:px|rem)\b/i', $style_content, $space_match)) {
                    $warnings[] = [
                        'rule' => 'raw-css-spacing-value',
                        'message' => "Hardcoded spacing '{$space_match[0]}' found.",
                        'fix_hint' => 'Use an awx spacing token (e.g. var(--awx-space-md) or utility class awx-p-md).'
                    ];
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
}
