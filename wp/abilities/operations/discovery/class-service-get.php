<?php
namespace aw2\abilities\operations\discovery;

use aw2\abilities\operations\Operation_Base;
use aw2\abilities\guardrails\Allowlist;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/service-get
 *
 * Returns a service's attributes, return type, examples, and agent_allowed status.
 */
class Service_Get extends Operation_Base
{
    public function execute(array $params = [])
    {
        $name = isset($params['name']) ? sanitize_text_field(trim($params['name'])) : '';
        if (empty($name)) {
            return $this->error('Service name is required.', 'Pass {"name": "service.name"} in parameters.', 'missing_service_name');
        }

        $service_info = [
            'name'          => $name,
            'agent_allowed' => Allowlist::is_service_allowed($name, 'block') || Allowlist::is_service_allowed($name, 'service'),
            'signature'     => "[{$name} /]",
            'attributes'    => [],
            'return_type'   => 'mixed',
            'examples'      => []
        ];

        // Core services definitions
        $catalog = [
            'env.get' => [
                'signature'   => '[env.get path default=val /]',
                'attributes'  => ['path' => 'string (unnamed or named)', 'default' => 'mixed'],
                'return_type' => 'mixed',
                'examples'    => ['[env.get app.name /]', '[env.get template.title default="Untitled" /]']
            ],
            'env.set' => [
                'signature'   => '[env.set key=val /]',
                'attributes'  => ['key' => 'mixed (key-value pairs)'],
                'return_type' => 'void',
                'examples'    => ['[env.set template.count=int:5 /]', '[env.set user.role="editor" /]']
            ],
            'loop' => [
                'signature'   => '[loop.@iterator array_path]...[/loop.@iterator]',
                'attributes'  => ['@iterator' => 'iterator identifier', 'start' => 'int', 'stop' => 'int', 'step' => 'int'],
                'return_type' => 'string',
                'examples'    => ['[loop.@item template.posts][env.@item.item.title /][/loop.@item]']
            ],
            'if.equal' => [
                'signature'   => '[if.equal lhs=A rhs=B]...[/if.equal]',
                'attributes'  => ['lhs' => 'mixed', 'rhs' => 'mixed'],
                'return_type' => 'string',
                'examples'    => ['[if.equal lhs={user.status} rhs="active"]Active user[/if.equal]']
            ],
            'esc.str' => [
                'signature'   => '[esc.str path /]',
                'attributes'  => ['path' => 'env path to string value'],
                'return_type' => 'string',
                'examples'    => ['SET @title = [esc.str request.title /];']
            ],
            'esc.int' => [
                'signature'   => '[esc.int path /]',
                'attributes'  => ['path' => 'env path to integer value'],
                'return_type' => 'int',
                'examples'    => ['SET @id = [esc.int request.id /];']
            ],
            'mysqli.fetch.rows' => [
                'signature'   => '[mysqli.fetch.rows o.set="template.res"]SQL[/mysqli.fetch.rows]',
                'attributes'  => ['o.set' => 'destination env path'],
                'return_type' => 'array',
                'examples'    => ['[mysqli.fetch.rows o.set="template.rows"]SELECT ID, post_title FROM wp_posts WHERE post_status = "publish"[/mysqli.fetch.rows]']
            ],
            'wp.get' => [
                'signature'   => '[wp.get path default=val /]',
                'attributes'  => ['path' => 'WordPress property path, e.g. option.blogname, site_url, post.ID, user.display_name', 'default' => 'mixed fallback value'],
                'return_type' => 'mixed',
                'examples'    => ['[wp.get option.blogname /]', '[wp.get site_url /]', '[wp.get post.post_title /]']
            ]
        ];

        if (isset($catalog[$name])) {
            $service_info = array_merge($service_info, $catalog[$name]);
        } else {
            // 1. Check custom service drafts/published options
            if (function_exists('get_option')) {
                $custom_drafts = get_option('awx_custom_service_drafts', []);
                if (isset($custom_drafts[$name])) {
                    $svc = $custom_drafts[$name];
                    $service_info['is_custom'] = true;
                    $service_info['summary'] = $svc['description'] ?? ($svc['summary'] ?? "Custom service: {$name}");
                    $service_info['signature'] = "[{$name} /]";
                    $service_info['attributes'] = $svc['attributes'] ?? [];
                    $service_info['return_type'] = $svc['return_type'] ?? 'mixed';
                    $service_info['status'] = $svc['status'] ?? 'draft';
                    $service_info['version'] = $svc['version'] ?? 1;
                    return $this->success($service_info);
                }
            }

            // 2. Check runtime handlers by traversing dot path
            $handlers =& \aw2_library::get_array_ref('handlers');
            if (is_array($handlers)) {
                $pieces = explode('.', $name);
                $node = $handlers;
                foreach ($pieces as $p) {
                    if (is_array($node) && isset($node[$p])) {
                        $node = $node[$p];
                    } else {
                        $node = null;
                        break;
                    }
                }

                if (is_array($node)) {
                    $desc = '';
                    if (isset($node['desc']) && is_string($node['desc']) && trim($node['desc']) !== '') {
                        $desc = trim($node['desc']);
                    } elseif (isset($node['service_label']) && is_string($node['service_label']) && trim($node['service_label']) !== '') {
                        $desc = trim($node['service_label']);
                    } elseif (isset($node['description']) && is_string($node['description']) && trim($node['description']) !== '') {
                        $desc = trim($node['description']);
                    }

                    $is_custom = strpos($name, 'custom.') === 0 || !empty($node['@custom']);
                    $service_info['is_custom'] = $is_custom;
                    $service_info['summary'] = $desc !== '' ? $desc : ($is_custom ? "Custom service: {$name}" : "Core service: {$name}");
                    $service_info['signature'] = "[{$name} /]";
                }
            }
        }

        return $this->success($service_info);
    }
}
