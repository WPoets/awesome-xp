<?php
namespace aw2\abilities\operations\discovery;

use aw2\abilities\operations\Operation_Base;
use aw2\abilities\guardrails\Allowlist;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/service-list
 *
 * Lists available services (core libraries like env, loop, if, mysqli, and custom services),
 * filterable by library. Each entry carries agent_allowed from the allowlist.
 */
class Service_List extends Operation_Base
{
    /**
     * Curated summaries for common core services
     */
    const CORE_CURATED_SUMMARIES = [
        'env'                     => 'Handles the environment and context data.',
        'env.get'                 => 'Returns the value at environment path.',
        'env.set'                 => 'Sets one or more variables in the environment.',
        'env.dump'                => 'Dumps debug info of path.',
        'loop'                    => 'Range-based or array-based loop processor.',
        'if'                      => 'Conditional execution processor.',
        'if.equal'                => 'Evaluates equality between lhs and rhs.',
        'if.not_equal'            => 'Evaluates inequality between lhs and rhs.',
        'if.contains'             => 'Checks if needle is in haystack array.',
        'if.empty'                => 'Checks if value is empty.',
        'if.not_empty'            => 'Checks if value is not empty.',
        'math.solve'              => 'Evaluates mathematical expression.',
        'str.create'              => 'Creates or casts a string value.',
        'int.create'              => 'Creates an integer value.',
        'bool.create'             => 'Creates a boolean value.',
        'arr.create'              => 'Constructs an array.',
        'arr.set'                 => 'Sets array elements.',
        'request2.get'            => 'Reads parameter from HTTP request safely.',
        'esc.str'                 => 'Escapes string and wraps in quotes for SQL.',
        'esc.int'                 => 'Casts value to safe integer for SQL.',
        'esc.num'                 => 'Casts value to safe float for SQL.',
        'mysqli.fetch.rows'       => 'Executes SELECT query and returns rows.',
        'mysqli.fetch.row'        => 'Executes SELECT query and returns single row.',
        'mysqli.multi.fetch.rows' => 'Executes multi-statement SQL query.',
        'mysqli.cud'              => 'Executes Create/Update/Delete database query.',
        'module.get'              => 'Reads from module scope.',
        'module.set'              => 'Sets in module scope.',
        'template.get'            => 'Reads from template scope.',
        'template.set'            => 'Sets in template scope.',
        'wp.get'                  => 'Gets WordPress options, site properties, or post metadata.',
        'wp.menu'                 => 'Renders or retrieves a WordPress navigation menu.',
        'wp.image_resize'         => 'Resizes an image using WordPress media functions.'
    ];

    public function execute(array $params = [])
    {
        $library_filter = isset($params['library']) ? sanitize_text_field(trim($params['library'])) : '';
        $custom_only = !empty($params['custom_only']);

        $services_map = [];

        // 1. Traverse registered runtime handlers
        $handlers =& \aw2_library::get_array_ref('handlers');
        if (is_array($handlers)) {
            $this->extract_handlers_tree($handlers, '', $services_map);
        }

        // 2. Add curated core services if not present or to refine summary
        foreach (self::CORE_CURATED_SUMMARIES as $core_name => $curated_summary) {
            $lib = explode('.', $core_name)[0];
            if (isset($services_map[$core_name])) {
                // If summary was generic or empty, upgrade with curated summary
                if (empty($services_map[$core_name]['summary']) || $services_map[$core_name]['summary'] === 'Custom registered service') {
                    $services_map[$core_name]['summary'] = $curated_summary;
                }
            } else {
                $services_map[$core_name] = [
                    'name'          => $core_name,
                    'library'       => $lib,
                    'is_custom'     => false,
                    'summary'       => $curated_summary,
                    'agent_allowed' => Allowlist::is_service_allowed($core_name, 'block')
                ];
            }
        }

        // 3. Load custom services from drafts and database options
        if (function_exists('get_option')) {
            $custom_drafts = get_option('awx_custom_service_drafts', []);
            if (is_array($custom_drafts)) {
                foreach ($custom_drafts as $name => $svc) {
                    $lib = explode('.', $name)[0];
                    $desc = '';
                    if (isset($svc['description']) && is_string($svc['description'])) {
                        $desc = trim($svc['description']);
                    } elseif (isset($svc['summary']) && is_string($svc['summary'])) {
                        $desc = trim($svc['summary']);
                    }
                    if ($desc === '') {
                        $status_label = !empty($svc['status']) ? " ({$svc['status']})" : '';
                        $desc = "Custom service: {$name}{$status_label}";
                    }

                    $services_map[$name] = [
                        'name'          => $name,
                        'library'       => $lib,
                        'is_custom'     => true,
                        'summary'       => $desc,
                        'agent_allowed' => Allowlist::is_service_allowed($name, 'service')
                    ];
                }
            }
        }

        // 4. Filter and format results
        $results = [];
        foreach ($services_map as $name => $item) {
            // Guarantee summary is always a clean string
            if (!is_string($item['summary']) || trim($item['summary']) === '' || $item['summary'] === 'Custom registered service') {
                $item['summary'] = ($item['is_custom'] ? 'Custom service: ' : 'Core service: ') . $name;
            }

            // Custom filter
            if ($custom_only && !$item['is_custom']) {
                continue;
            }

            // Library filter
            if (!empty($library_filter) && $item['library'] !== $library_filter) {
                continue;
            }

            $results[] = $item;
        }

        // Sort alphabetically by name
        usort($results, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $this->success($results);
    }

    /**
     * Recursively traverses handlers tree to extract services with proper names and string summaries
     *
     * @param array $tree Handlers tree or subtree
     * @param string $prefix Current namespace/service prefix
     * @param array $services_map Output map of services
     */
    private function extract_handlers_tree(array $tree, $prefix, array &$services_map)
    {
        foreach ($tree as $key => $val) {
            if (!is_array($val)) {
                continue;
            }
            if (strpos((string)$key, '@') === 0) {
                continue;
            }

            $fullName = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            $lib = explode('.', $fullName)[0];

            $is_service = !empty($val['@service']);
            $summary = '';

            // Extract string description: check desc first (canonical aw2_library::add_service property)
            if (isset($val['desc']) && is_string($val['desc']) && trim($val['desc']) !== '') {
                $summary = trim($val['desc']);
            } elseif (isset($val['service_label']) && is_string($val['service_label']) && trim($val['service_label']) !== '') {
                $summary = trim($val['service_label']);
            } elseif (isset($val['description']) && is_string($val['description']) && trim($val['description']) !== '') {
                // Only use if it is an actual string (not an array of sub-services like code_sample_context['description'])
                $summary = trim($val['description']);
            }

            $is_custom = (strpos($fullName, 'custom.') === 0 || !empty($val['@custom']) || $lib === 'custom');

            // If it is registered as a service or has a description
            if ($is_service || $summary !== '') {
                if ($summary === '' || $summary === 'Custom registered service') {
                    if (isset(self::CORE_CURATED_SUMMARIES[$fullName])) {
                        $summary = self::CORE_CURATED_SUMMARIES[$fullName];
                    } else {
                        $summary = ($is_custom ? 'Custom service: ' : 'Core service: ') . $fullName;
                    }
                }

                $services_map[$fullName] = [
                    'name'          => $fullName,
                    'library'       => $lib,
                    'is_custom'     => $is_custom,
                    'summary'       => $summary,
                    'agent_allowed' => Allowlist::is_service_allowed($fullName, 'block') || Allowlist::is_service_allowed($fullName, 'service')
                ];
            }

            // Recurse into child services
            $this->extract_handlers_tree($val, $fullName, $services_map);
        }
    }
}
