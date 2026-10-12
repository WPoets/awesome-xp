<?php
/**
 * Awesome XP — Abilities Subsystem
 *
 * Implements the 24+ abilities for Awesome XP, exposing them through
 * the WordPress Abilities API, MCP Adapter, and REST API.
 *
 * @package AwesomeXP
 * @subpackage Abilities
 */

namespace aw2\abilities;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

define('AWX_ABILITIES_VERSION', '1.0.0');
define('AWX_ABILITIES_PATH', __DIR__ . '/');

// 1. Load Guardrails
require_once AWX_ABILITIES_PATH . 'guardrails/class-validation-token.php';
require_once AWX_ABILITIES_PATH . 'guardrails/class-allowlist.php';
require_once AWX_ABILITIES_PATH . 'guardrails/class-template-linter.php';
require_once AWX_ABILITIES_PATH . 'guardrails/class-sandbox-runner.php';

// 2. Load Base Operation
require_once AWX_ABILITIES_PATH . 'operations/class-operation-base.php';

// 3. Load Operations
// Orientation
require_once AWX_ABILITIES_PATH . 'operations/orientation/class-guide-read.php';

// Discovery
require_once AWX_ABILITIES_PATH . 'operations/discovery/class-app-list.php';
require_once AWX_ABILITIES_PATH . 'operations/discovery/class-app-get.php';
require_once AWX_ABILITIES_PATH . 'operations/discovery/class-service-list.php';
require_once AWX_ABILITIES_PATH . 'operations/discovery/class-service-get.php';
require_once AWX_ABILITIES_PATH . 'operations/discovery/class-block-list.php';
require_once AWX_ABILITIES_PATH . 'operations/discovery/class-block-get.php';
require_once AWX_ABILITIES_PATH . 'operations/discovery/class-token-list.php';
require_once AWX_ABILITIES_PATH . 'operations/discovery/class-dictionary-get.php';
require_once AWX_ABILITIES_PATH . 'operations/discovery/class-fieldtype-list.php';

// Verify
require_once AWX_ABILITIES_PATH . 'operations/verify/class-template-validate.php';
require_once AWX_ABILITIES_PATH . 'operations/verify/class-template-preview.php';

// Author
require_once AWX_ABILITIES_PATH . 'operations/author/class-block-create.php';
require_once AWX_ABILITIES_PATH . 'operations/author/class-block-update.php';
require_once AWX_ABILITIES_PATH . 'operations/author/class-service-create.php';
require_once AWX_ABILITIES_PATH . 'operations/author/class-service-update.php';
require_once AWX_ABILITIES_PATH . 'operations/author/class-app-create.php';
require_once AWX_ABILITIES_PATH . 'operations/author/class-app-update.php';

// Patterns
require_once AWX_ABILITIES_PATH . 'operations/patterns/class-pattern-list.php';
require_once AWX_ABILITIES_PATH . 'operations/patterns/class-pattern-get.php';
require_once AWX_ABILITIES_PATH . 'operations/patterns/class-pattern-create.php';
require_once AWX_ABILITIES_PATH . 'operations/patterns/class-pattern-update.php';

// Lifecycle
require_once AWX_ABILITIES_PATH . 'operations/lifecycle/class-version-list.php';
require_once AWX_ABILITIES_PATH . 'operations/lifecycle/class-version-publish.php';
require_once AWX_ABILITIES_PATH . 'operations/lifecycle/class-version-rollback.php';

// Debug
require_once AWX_ABILITIES_PATH . 'operations/debug/class-log-list.php';

// 4. Load Central Registry
require_once AWX_ABILITIES_PATH . 'class-abilities-registry.php';

/**
 * Initialize and hook into WordPress
 */
function init_abilities()
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    $registry = Abilities_Registry::instance();

    // 1. Hook Categories into WordPress Abilities API
    add_action('wp_abilities_api_categories_init', [$registry, 'register_categories']);

    // 2. Hook Abilities into WordPress Abilities API
    add_action('wp_abilities_api_init', [$registry, 'register_with_wordpress']);

    // 3. Fallback registration on init (priority 20) for plugins like AcrossAI Abilities Manager
    // that discover or manage abilities during init
    add_action('init', [$registry, 'register_with_wordpress'], 20);

    // 4. Register REST API endpoints for HTTP/MCP clients
    add_action('rest_api_init', 'aw2\abilities\register_rest_routes');
}
// Set up hooks immediately so early actions are never missed
init_abilities();
add_action('plugins_loaded', 'aw2\abilities\init_abilities', 5);

/**
 * REST API routes for Awesome XP Abilities
 */
function register_rest_routes()
{
    // GET /wp-json/awesome-xp/v1/abilities
    register_rest_route('awesome-xp/v1', '/abilities', [
        'methods'             => 'GET',
        'permission_callback' => function () {
            return Abilities_Registry::instance()->check_tier_permission('read');
        },
        'callback'            => function () {
            $registry = Abilities_Registry::instance();
            $abilities = $registry->get_all_abilities();
            $list = [];
            foreach ($abilities as $name => $meta) {
                $list[] = [
                    'name'         => $name,
                    'label'        => $meta['label'],
                    'description'  => $meta['description'],
                    'category'     => $meta['category'],
                    'tier'         => $meta['tier'],
                    'input_schema' => $meta['input_schema']
                ];
            }
            return rest_ensure_response(['abilities' => $list]);
        }
    ]);

    // POST /wp-json/awesome-xp/v1/abilities/(?P<ability>[a-zA-Z0-9_\-\/]+)/execute
    register_rest_route('awesome-xp/v1', '/abilities/(?P<ability>[a-zA-Z0-9_\-\/]+)/execute', [
        'methods'             => 'POST',
        'permission_callback' => function (\WP_REST_Request $request) {
            $ability = $request->get_param('ability');
            $registry = Abilities_Registry::instance();
            $abilities = $registry->get_all_abilities();
            if (!isset($abilities[$ability])) {
                return true; // Let execute return 404/error
            }
            return $registry->check_tier_permission($abilities[$ability]['tier']);
        },
        'callback'            => function (\WP_REST_Request $request) {
            $ability = $request->get_param('ability');
            $params = $request->get_json_params() ?: $request->get_params();
            $result = Abilities_Registry::instance()->execute_ability($ability, (array)$params);
            return rest_ensure_response($result);
        }
    ]);
}

/**
 * Public execution helper
 *
 * @param string $ability_name e.g. 'awesome/block-create'
 * @param array $input Input arguments
 * @return array
 */
function awx_execute_ability($ability_name, array $input = [])
{
    return Abilities_Registry::instance()->execute_ability($ability_name, $input);
}
