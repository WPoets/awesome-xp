<?php
namespace aw2\abilities\guardrails;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service and Feature Allowlist
 *
 * Enforces allowed libraries and services for AI agent execution.
 */
class Allowlist
{
    /**
     * Default core services permitted for block templates
     */
    private static $block_allowed_libraries = [
        'env',
        'loop',
        'if',
        'math',
        'str',
        'int',
        'bool',
        'num',
        'date',
        'arr',
        'request2',
        'esc',
        'module',
        'template',
        'templates',
        'gt_blocks',
        'flow',
        'atom',
        'atoms',
        'layouts',
        'do',
        'gb_blocks',
        'custom',
        'auth',
        'time',
        'clean',
        'util',
        'unique',
        'cookie',
        'session',
        'wp'
    ];

    /**
     * Check if a service is allowed for agents in the given context.
     *
     * @param string $service_name
     * @param string $context 'block' | 'service' | 'app'
     * @return bool
     */
    public static function is_service_allowed($service_name, $context = 'block')
    {
        // Strip loop iterator e.g. loop.@post -> loop
        $service_name = preg_replace('/\.@[a-zA-Z0-9_]+/', '', $service_name);

        // Single word tags without dot (e.g. title, params, heading, arrow, sections, fields, config, icon, atom)
        // are array-builder keys or data tags inside arr.create / gt_blocks, not services.
        if (strpos($service_name, '.') === false) {
            $known_services = ['loop', 'if', 'env', 'do', 'math', 'arr', 'str', 'int', 'date', 'num', 'bool', 'template', 'module', 'wp'];
            if (!in_array($service_name, $known_services, true)) {
                return true;
            }
        }

        $parts = explode('.', $service_name);
        $library = $parts[0] ?? '';

        // Block templates have restrictions: no arbitrary raw database writes or curls by default
        if ($context === 'block') {
            if ($library === 'wp') {
                // Sensitive authentication actions disallowed in blocks
                if ($service_name === 'wp.signon') {
                    return false;
                }
                return true;
            }
            if (in_array($library, self::$block_allowed_libraries, true)) {
                return true;
            }
            if ($library === 'mysqli' && isset($parts[1]) && in_array($parts[1], ['fetch', 'multi'], true)) {
                // Read-only queries allowed in blocks
                return apply_filters('awx_allow_block_sql_reads', true);
            }
            return false;
        }

        // Custom services context
        if ($context === 'service') {
            if (in_array($library, self::$block_allowed_libraries, true)) {
                return true;
            }
            if ($library === 'mysqli') {
                // In v1, no mysqli.multi.cud unless enabled per site
                if (isset($parts[1]) && $parts[1] === 'multi' && isset($parts[2]) && $parts[2] === 'cud') {
                    return apply_filters('awx_allow_service_cud_transactions', false);
                }
                return true;
            }
            if ($library === 'curl') {
                return true;
            }
        }

        // Check dynamic filter
        return apply_filters('awx_service_allowed', false, $service_name, $context);
    }

    /**
     * Get summary of allowlist for discovery
     *
     * @return array
     */
    public static function get_allowed_libraries()
    {
        return self::$block_allowed_libraries;
    }
}
