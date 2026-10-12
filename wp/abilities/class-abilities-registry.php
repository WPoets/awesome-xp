<?php
namespace aw2\abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central Abilities Registry for Awesome XP
 *
 * Bridges the Portable Operations Layer to the WordPress Abilities API
 * and MCP Adapter.
 */
class Abilities_Registry
{
    private static $instance = null;
    private $abilities = [];
    private $categories = [];

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->register_definitions();
    }

    /**
     * Define all categories and abilities metadata from Catalogue v1
     */
    private function register_definitions()
    {
        $this->categories = [
            'awesome-orientation' => [
                'label'       => 'Awesome Orientation',
                'description' => 'Entry points and workflow orientation'
            ],
            'awesome-discovery' => [
                'label'       => 'Awesome Discovery',
                'description' => 'Readonly discovery of existing platform artifacts'
            ],
            'awesome-verify' => [
                'label'       => 'Awesome Verify',
                'description' => 'Template validation and sandboxed preview'
            ],
            'awesome-author' => [
                'label'       => 'Awesome Author',
                'description' => 'Draft creation and updates for blocks, services, and apps'
            ],
            'awesome-patterns' => [
                'label'       => 'Awesome Patterns',
                'description' => 'Block pattern composition from registered Awesome blocks'
            ],
            'awesome-lifecycle' => [
                'label'       => 'Awesome Lifecycle',
                'description' => 'Version promotion and rollback'
            ],
            'awesome-debug' => [
                'label'       => 'Awesome Debug',
                'description' => 'Self-healing diagnostic log retrieval'
            ]
        ];

        $this->abilities = [
            // Orientation
            'awesome/guide-read' => [
                'label'       => 'Guide Read',
                'description' => 'Start here. With no topic, returns the Awesome overview, the authoring workflow, and a topic index. With a topic, returns that section of the DSL reference.',
                'category'    => 'awesome-orientation',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\orientation\Guide_Read::class,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'topic' => ['type' => 'string', 'description' => 'Optional guide topic slug (overview, lifecycle, syntax, scopes, safety, css-tokens, block-css, pitfalls)']
                    ]
                ]
            ],

            // Discovery
            'awesome/app-list' => [
                'label'       => 'App List',
                'description' => 'Lists registered apps with name, slug, status, and a one-line purpose. Use before creating an app to check whether one already exists.',
                'category'    => 'awesome-discovery',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\discovery\App_List::class,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'description' => 'Optional status filter (publish, draft)']
                    ]
                ]
            ],
            'awesome/app-get' => [
                'label'       => 'App Get',
                'description' => 'Returns an app\'s full manifest, including the services, modules, and blocks it uses. Secrets and credentials redacted.',
                'category'    => 'awesome-discovery',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\discovery\App_Get::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['slug'],
                    'properties' => [
                        'slug'    => ['type' => 'string', 'description' => 'App slug identifier'],
                        'version' => ['type' => 'integer', 'description' => 'Optional version number']
                    ]
                ]
            ],
            'awesome/service-list' => [
                'label'       => 'Service List',
                'description' => 'Lists available services (core libraries like env, loop, if, mysqli, and custom services), filterable by library. Use to find what exists before writing templates.',
                'category'    => 'awesome-discovery',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\discovery\Service_List::class,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'library'     => ['type' => 'string', 'description' => 'Optional library prefix filter (e.g. env, loop, esc)'],
                        'custom_only' => ['type' => 'boolean', 'description' => 'List only custom registered services']
                    ]
                ]
            ],
            'awesome/service-get' => [
                'label'       => 'Service Get',
                'description' => 'Returns a service\'s attributes, return type, and examples.',
                'category'    => 'awesome-discovery',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\discovery\Service_Get::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Full service name, e.g. env.get or mysqli.fetch.rows']
                    ]
                ]
            ],
            'awesome/block-list' => [
                'label'       => 'Block List',
                'description' => 'Lists registered blocks with purpose, fields, and usage count. Check this before creating a block: prefer tokens, then modifiers, then patterns, and create a new block only as a last resort.',
                'category'    => 'awesome-discovery',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\discovery\Block_List::class,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => ['type' => 'string', 'description' => 'Optional search query'],
                        'category' => ['type' => 'string', 'description' => 'Optional category filter']
                    ]
                ]
            ],
            'awesome/block-get' => [
                'label'       => 'Block Get',
                'description' => 'Returns a block\'s DGB JSON config and shortcode template.',
                'category'    => 'awesome-discovery',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\discovery\Block_Get::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['slug'],
                    'properties' => [
                        'slug'    => ['type' => 'string', 'description' => 'Block slug identifier'],
                        'version' => ['type' => 'integer', 'description' => 'Optional version number']
                    ]
                ]
            ],
            'awesome/token-list' => [
                'label'       => 'Token List',
                'description' => 'Lists awx design tokens (role tokens by default, grouped by type). Blocks must use these tokens rather than raw values.',
                'category'    => 'awesome-discovery',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\discovery\Token_List::class,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'group'              => ['type' => 'string', 'description' => 'Optional token group (color, space, fontSize, radii, shadow)'],
                        'include_primitives' => ['type' => 'boolean', 'description' => 'Include internal palette primitives (default false)']
                    ]
                ]
            ],
            'awesome/dictionary-get' => [
                'label'       => 'Dictionary Get',
                'description' => 'Returns Universal Dictionary parameter names (title, text, items, image, url, etc.) and their meaning by context. Use these names for all block fields.',
                'category'    => 'awesome-discovery',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\discovery\Dictionary_Get::class,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => []
                ]
            ],
            'awesome/fieldtype-list' => [
                'label'       => 'Fieldtype List',
                'description' => 'Lists DGB field types with their config options. Use when defining block fields.',
                'category'    => 'awesome-discovery',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\discovery\Fieldtype_List::class,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => []
                ]
            ],

            // Verify
            'awesome/template-validate' => [
                'label'       => 'Template Validate',
                'description' => 'Validates shortcode template code for syntax errors and rule violations. Required before any create or update. Returns a validation token on success.',
                'category'    => 'awesome-verify',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\verify\Template_Validate::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['code'],
                    'properties' => [
                        'code'    => ['type' => 'string', 'description' => 'Shortcode template code to validate'],
                        'context' => ['type' => 'string', 'description' => 'Validation context (block, service, app)', 'default' => 'block']
                    ]
                ]
            ],
            'awesome/template-preview' => [
                'label'       => 'Template Preview',
                'description' => 'Renders template code with sample data without saving anything. Use after validation to check output.',
                'category'    => 'awesome-verify',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\verify\Template_Preview::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['code'],
                    'properties' => [
                        'code'              => ['type' => 'string', 'description' => 'Template code to preview'],
                        'context'           => ['type' => 'string', 'description' => 'Context (block, service, app)', 'default' => 'block'],
                        'sample_attributes' => ['type' => 'object', 'description' => 'Sample key-value attributes for rendering']
                    ]
                ]
            ],

            // Author (Drafts Only)
            'awesome/block-create' => [
                'label'       => 'Block Create',
                'description' => 'Creates a new block as a draft. Only use after block-list confirms no existing block or variation fits.',
                'category'    => 'awesome-author',
                'tier'        => 'author',
                'operation'   => \aw2\abilities\operations\author\Block_Create::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['config', 'template', 'validation_token'],
                    'properties' => [
                        'config'           => ['type' => 'object', 'description' => 'DGB JSON configuration (fields use dictionary names)'],
                        'template'         => ['type' => 'string', 'description' => 'Shortcode template code for the block'],
                        'validation_token' => ['type' => 'string', 'description' => 'Token returned by template-validate for this exact template']
                    ]
                ]
            ],
            'awesome/block-update' => [
                'label'       => 'Block Update',
                'description' => 'Saves changes to a block as a new draft version. Does not affect the live version.',
                'category'    => 'awesome-author',
                'tier'        => 'author',
                'operation'   => \aw2\abilities\operations\author\Block_Update::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['slug'],
                    'properties' => [
                        'slug'             => ['type' => 'string', 'description' => 'Slug of block to update'],
                        'config'           => ['type' => 'object', 'description' => 'Updated DGB configuration'],
                        'template'         => ['type' => 'string', 'description' => 'Updated shortcode template'],
                        'validation_token' => ['type' => 'string', 'description' => 'Token matching updated template'],
                        'if_version'       => ['type' => 'integer', 'description' => 'Optimistic lock version check']
                    ]
                ]
            ],
            'awesome/service-create' => [
                'label'       => 'Service Create',
                'description' => 'Creates a custom service as a draft. Stricter allowlist: namespaced (custom.*).',
                'category'    => 'awesome-author',
                'tier'        => 'author',
                'operation'   => \aw2\abilities\operations\author\Service_Create::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['name', 'code', 'validation_token'],
                    'properties' => [
                        'name'                  => ['type' => 'string', 'description' => 'Service name (automatically prefixed with custom.)'],
                        'code'                  => ['type' => 'string', 'description' => 'Shortcode logic code for the service'],
                        'attribute_definitions' => ['type' => 'array', 'description' => 'Attribute schema definitions'],
                        'return_type'           => ['type' => 'string', 'description' => 'Expected return data type'],
                        'validation_token'      => ['type' => 'string', 'description' => 'Validation token matching code']
                    ]
                ]
            ],
            'awesome/service-update' => [
                'label'       => 'Service Update',
                'description' => 'Saves changes to a custom service as a new draft version.',
                'category'    => 'awesome-author',
                'tier'        => 'author',
                'operation'   => \aw2\abilities\operations\author\Service_Update::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['name'],
                    'properties' => [
                        'name'             => ['type' => 'string', 'description' => 'Custom service name'],
                        'changes'          => ['type' => 'object', 'description' => 'Changed properties (code, attributes, return_type)'],
                        'validation_token' => ['type' => 'string', 'description' => 'Validation token if code changed'],
                        'if_version'       => ['type' => 'integer', 'description' => 'Expected version for optimistic locking']
                    ]
                ]
            ],
            'awesome/app-create' => [
                'label'       => 'App Create',
                'description' => 'Creates an app manifest as a draft. Apps compose existing blocks and services; create those first.',
                'category'    => 'awesome-author',
                'tier'        => 'author',
                'operation'   => \aw2\abilities\operations\author\App_Create::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['manifest', 'validation_token'],
                    'properties' => [
                        'manifest'         => ['type' => 'object', 'description' => 'App manifest schema'],
                        'validation_token' => ['type' => 'string', 'description' => 'Validation token for manifest']
                    ]
                ]
            ],
            'awesome/app-update' => [
                'label'       => 'App Update',
                'description' => 'Saves changes to an app manifest as a new draft version.',
                'category'    => 'awesome-author',
                'tier'        => 'author',
                'operation'   => \aw2\abilities\operations\author\App_Update::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['slug', 'manifest'],
                    'properties' => [
                        'slug'             => ['type' => 'string', 'description' => 'App slug'],
                        'manifest'         => ['type' => 'object', 'description' => 'Updated manifest'],
                        'validation_token' => ['type' => 'string', 'description' => 'Validation token'],
                        'if_version'       => ['type' => 'integer', 'description' => 'Expected version']
                    ]
                ]
            ],

            // Patterns
            'awesome/pattern-list' => [
                'label'       => 'Pattern List',
                'description' => 'Lists block patterns built from Awesome blocks, with title, category, and whether each is synced. Check this before composing a new layout.',
                'category'    => 'awesome-patterns',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\patterns\Pattern_List::class,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => ['type' => 'string', 'description' => 'Search pattern titles'],
                        'category' => ['type' => 'string', 'description' => 'Filter by pattern category']
                    ]
                ]
            ],
            'awesome/pattern-get' => [
                'label'       => 'Pattern Get',
                'description' => 'Returns a pattern\'s block markup and the blocks it uses.',
                'category'    => 'awesome-patterns',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\patterns\Pattern_Get::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'WordPress pattern post ID']
                    ]
                ]
            ],
            'awesome/pattern-create' => [
                'label'       => 'Pattern Create',
                'description' => 'Creates a pattern as a draft by composing existing blocks. Use when a layout can be built from registered blocks; create a new block only if no combination works.',
                'category'    => 'awesome-patterns',
                'tier'        => 'author',
                'operation'   => \aw2\abilities\operations\patterns\Pattern_Create::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['title', 'markup'],
                    'properties' => [
                        'title'    => ['type' => 'string', 'description' => 'Pattern title'],
                        'category' => ['type' => 'string', 'description' => 'Pattern category slug', 'default' => 'featured'],
                        'markup'   => ['type' => 'string', 'description' => 'Gutenberg block markup (<!-- wp:... -->)'],
                        'synced'   => ['type' => 'boolean', 'description' => 'Whether pattern is synced', 'default' => false]
                    ]
                ]
            ],
            'awesome/pattern-update' => [
                'label'       => 'Pattern Update',
                'description' => 'Saves changes to a pattern as a new draft.',
                'category'    => 'awesome-patterns',
                'tier'        => 'author',
                'operation'   => \aw2\abilities\operations\patterns\Pattern_Update::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['id', 'markup'],
                    'properties' => [
                        'id'         => ['type' => 'integer', 'description' => 'Pattern post ID'],
                        'markup'     => ['type' => 'string', 'description' => 'Updated block markup'],
                        'if_version' => ['type' => 'integer', 'description' => 'Expected version for optimistic locking']
                    ]
                ]
            ],

            // Lifecycle
            'awesome/version-list' => [
                'label'       => 'Version List',
                'description' => 'Lists versions of a block, service, app, or pattern with status, author, and date. Use to find a draft to publish or a version to roll back to.',
                'category'    => 'awesome-lifecycle',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\lifecycle\Version_List::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['artifact_type', 'slug'],
                    'properties' => [
                        'artifact_type' => ['type' => 'string', 'description' => 'Type: block, service, app, pattern'],
                        'slug'          => ['type' => 'string', 'description' => 'Artifact slug identifier']
                    ]
                ]
            ],
            'awesome/version-publish' => [
                'label'       => 'Version Publish',
                'description' => 'Makes a draft version live. Affects every page using this artifact.',
                'category'    => 'awesome-lifecycle',
                'tier'        => 'publish',
                'operation'   => \aw2\abilities\operations\lifecycle\Version_Publish::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['artifact_type', 'slug'],
                    'properties' => [
                        'artifact_type' => ['type' => 'string', 'description' => 'Type: block, service, app, pattern'],
                        'slug'          => ['type' => 'string', 'description' => 'Artifact slug identifier'],
                        'version'       => ['type' => 'integer', 'description' => 'Optional target version to publish']
                    ]
                ]
            ],
            'awesome/version-rollback' => [
                'label'       => 'Version Rollback',
                'description' => 'Restores a previous version as live.',
                'category'    => 'awesome-lifecycle',
                'tier'        => 'publish',
                'operation'   => \aw2\abilities\operations\lifecycle\Version_Rollback::class,
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['artifact_type', 'slug'],
                    'properties' => [
                        'artifact_type' => ['type' => 'string', 'description' => 'Type: block, service, app, pattern'],
                        'slug'          => ['type' => 'string', 'description' => 'Artifact slug identifier'],
                        'version'       => ['type' => 'integer', 'description' => 'Target revision ID or version number to restore']
                    ]
                ]
            ],

            // Debug
            'awesome/log-list' => [
                'label'       => 'Log List',
                'description' => 'Returns recent exceptions from the Awesome error log, filterable by artifact. Use after preview or publish to diagnose failures.',
                'category'    => 'awesome-debug',
                'tier'        => 'read',
                'operation'   => \aw2\abilities\operations\debug\Log_List::class,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'artifact'    => ['type' => 'string', 'description' => 'Filter by artifact / module / app name'],
                        'time_window' => ['type' => 'string', 'description' => 'Optional time window, e.g. 1h, 24h'],
                        'limit'       => ['type' => 'integer', 'description' => 'Max number of entries to return (default 20, max 50)', 'default' => 20]
                    ]
                ]
            ]
        ];
    }

    /**
     * Register ability categories with WordPress Abilities API
     *
     * @param mixed $registry Optional registry passed by hook
     */
    public function register_categories($registry = null)
    {
        if (function_exists('wp_register_ability_category')) {
            foreach ($this->categories as $cat_slug => $cat_data) {
                wp_register_ability_category($cat_slug, $cat_data);
            }
        }
    }

    /**
     * Register abilities with WordPress Abilities API (wp_register_ability)
     *
     * @param mixed $registry Optional registry passed by hook
     */
    public function register_with_wordpress($registry = null)
    {
        // 1. Ensure categories are registered first
        $this->register_categories();

        // 2. Register abilities if function exists
        if (function_exists('wp_register_ability')) {
            foreach ($this->abilities as $ability_name => $meta) {
                $args = [
                    'label'               => $meta['label'],
                    'description'         => $meta['description'],
                    'category'            => $meta['category'],
                    'input_schema'        => $meta['input_schema'],
                    'output_schema'       => isset($meta['output_schema']) ? $meta['output_schema'] : ['type' => 'object'],
                    'meta'                => isset($meta['meta']) ? $meta['meta'] : [
                        'readonly'    => ($meta['tier'] === 'read'),
                        'destructive' => ($meta['tier'] === 'publish'),
                        'idempotent'  => ($meta['tier'] !== 'publish'),
                        'tier'        => $meta['tier']
                    ],
                    'execute_callback'    => function (array $input = []) use ($ability_name) {
                        return $this->execute_ability($ability_name, $input);
                    },
                    'permission_callback' => function () use ($meta) {
                        return $this->check_tier_permission($meta['tier']);
                    }
                ];

                $registered = wp_register_ability($ability_name, $args);
                if (is_wp_error($registered)) {
                    error_log("Awesome XP Abilities API registration error for '{$ability_name}': " . $registered->get_error_message());
                }
            }
        }
    }

    /**
     * Check permission based on capability tier
     *
     * @param string $tier 'read' | 'author' | 'publish'
     * @return bool
     */
    public function check_tier_permission($tier)
    {
        // Allow programmatic bypass in tests or CLI
        if (defined('AWX_ABILITIES_TEST_MODE') && AWX_ABILITIES_TEST_MODE) {
            return true;
        }

        // Allow site filters to override tier permissions (e.g. AcrossAI tokens, custom agent auth)
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('awx_abilities_check_tier_permission', null, $tier);
            if ($filtered !== null) {
                return (bool)$filtered;
            }
        }

        switch ($tier) {
            case 'read':
                return current_user_can('read') || current_user_can('develop_for_awesomeui') || current_user_can('edit_posts');
            case 'author':
                return current_user_can('edit_posts') || current_user_can('develop_for_awesomeui');
            case 'publish':
                // Destructive: requires publish_posts or manage_options
                return current_user_can('publish_posts') || current_user_can('manage_options');
            default:
                return false;
        }
    }

    /**
     * Execute an ability by name
     *
     * @param string $ability_name
     * @param array $input
     * @return array
     */
    public function execute_ability($ability_name, array $input = [])
    {
        if (!isset($this->abilities[$ability_name])) {
            return [
                'success' => false,
                'error'   => [
                    'code'      => 'ability_not_found',
                    'message'   => "Ability '{$ability_name}' is not registered.",
                    'next_step' => 'Check available abilities via discovery.'
                ]
            ];
        }

        $meta = $this->abilities[$ability_name];

        // Instantiate operation class
        $operation_class = $meta['operation'];
        if (!class_exists($operation_class)) {
            return [
                'success' => false,
                'error'   => [
                    'code'      => 'class_not_found',
                    'message'   => "Operation class '{$operation_class}' is missing.",
                    'next_step' => 'Verify plugin installation.'
                ]
            ];
        }

        /** @var \aw2\abilities\operations\Operation_Base $operation */
        $operation = new $operation_class();
        return $operation->execute($input);
    }

    /**
     * Get all registered abilities metadata
     *
     * @return array
     */
    public function get_all_abilities()
    {
        return $this->abilities;
    }
}
