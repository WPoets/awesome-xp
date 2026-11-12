<p align="center">
	<a href="https://www.wpoets.com/" target="_blank"><img width="200"src="https://www.wpoets.com/wp-content/uploads/2018/05/WPoets-logo-1.svg" alt='Your Web and WordPress Experts'></a>
</p>

# Awesome XP

Awesome XP is a high-performance, low-code logic execution platform for WordPress. Designed for developers and agencies who need to build complex custom workflows without the overhead of heavy visual builders, it uses a streamlined, shortcode-based architecture to connect apps, services, and data.

Whether you are building custom internal tools, automating multi-step data processing, or extending the Awesome Enterprise Platform, Awesome Flow Builder gives you the "logic rails" to move faster and stay organised.

You will need to update the wp-config.php file with the various defines specified in _sample-config.php_, located in the samples-index folder.

⚠️ Awesome XP is intended for technical teams and advanced WordPress users.
Initial setup requires configuration in wp-config.php.

### Changelog 

##### 1.2.0
* Improved: AWX Sync code deploy — push Awesome code from a development site to production: Core modules, service modules, apps (definition plus config and module collections, optionally their pages and posts), Gutenberg block definitions, ACF blocks (the `gutenberg-blocks` module and ACF field groups) and managed patterns.
* Improved: New "Deploy Code" screen under Awesome Enterprise — tick collections or single modules, review a diff against production (new / changed / edited on target / unchanged / only on target), untick rows, deploy with progress and resume, and save selections as named deployment sets.
* Improved: New Abilities `awesome/sync-code-catalog`, `sync-code-analyze`, `sync-code-plan`, `sync-code-confirm`, `sync-code-step`, `sync-code-resume`, `sync-code-cancel`, `sync-code-status`, `sync-set-list`, `sync-set-save`, `sync-set-delete`, and the `wp awx sync deploy` and `wp awx sync sets` commands.
* Improved: Target sites opt in with `define('AWX_SYNC_ALLOW_CODE', true);` and a token carrying the new `code` scope (chosen on Settings > AWX Sync Tokens); code posts are matched by post type and slug, keep a revision when overwritten, and the `settings` module's postmeta is never deployed.
* Improved: After a deploy the target flushes the global cache, invalidates the block registry, recompiles the written block assets and refreshes rewrite rules when a new app was created.
* Note: 1.2.0 upgrades the `awx_sync_*` tables (schema v3) on first load and adds `awx_sync_sets`.

##### 1.1.0
* Improved: AWX Sync — move a saved page from a development site to production from the block editor.
* Improved: Added new Abilities `awesome/sync-analyze`, `sync-plan`, `sync-confirm`, `sync-step`, `sync-resume`, `sync-cancel` and `sync-status`, the `wp awx sync` command group (`push`, `backfill`, `pair`, `pair-patterns`, `export-pattern-ids`, `uuid`, `install`) and a "Sync to Production" editor sidebar with progress and resume.
* Improved: `AWX_SYNC_ROLE` (`source`, `target`, `none`) and related `wp-config.php` constants (`AWX_SYNC_TARGET_URL`, `AWX_SYNC_TOKEN`, `AWX_SYNC_SOURCE_URL`, `AWX_SYNC_SOURCE_AUTH`, `AWX_SYNC_ALLOW_PUSH`, `AWX_SYNC_IP_ALLOWLIST`) guard which sites may send or receive.
* Improved: Guide `cheatsheet.md` for the Abilities API — the 7-step authoring and cache-purging lifecycle, template rules and gotchas, tooling boundaries and a pre-publish checklist.
* Improved: Abilities are restricted to developers — all tiers now require `develop_for_awesomeui`, unknown abilities no longer bypass the permission check, template preview and log listing are developer-only, and `AWX_ABILITIES_TEST_MODE` is honoured only under WP-CLI.
* Improved: `util.constant` no longer returns sync tokens, the database password, or WordPress keys and salts.
* Improved: New `add_abilities()` extension point on the Abilities registry for other plugin components.
* Fixed: Unauthenticated AJAX code execution and path traversal in the import/export handlers — they now require a developer capability and a nonce, sanitise uploaded file names, restrict extensions and reject archives containing absolute paths, `..`, symlinks or hard links.
* Fixed: Path traversal in the file, file viewer and Excel controllers with strict in-app-folder checks and an extension allowlist; the code editor "Update Without Refresh" now requires a nonce and `edit_post`.
* Fixed: Potential command injection in `wp aw2 run` / `awcli.run` and SQL injection in the search controller, modules list table and import paths; nonces are compared with `hash_equals()`.
* Fixed: XSS in the search controller and ticket error messages; `aw2.upload` now enforces the extension allowlist before saving and sanitises client-supplied names; a credential left in a code comment was removed.
* Note: Activating 1.1.0 creates the `awx_sync_*` tables on first load; existing media must be stamped once with `wp awx sync backfill` before the first sync, or every image will be treated as new.

##### 1.0.9
* Improved: Role-Based Cache Purge Permissions
* Improved: Block Cache Management — Added a dedicated manual blocks cache flush button in the admin interface.
* Improved: Database & Migration Engine — Enhanced runtime self-healing and batch database migration routines with an expanded, tolerant schema.
* Improved: Agent & MCP Documentation Synchronization — Updated in-platform orientation abilities (awesome/guide-read, Abilities_Registry, and overview.md)
* Improved: Shortcode Reference & Discovery
* Fixed: Resolved schema validation error in get_attribute_config_for_field() by allowing null values for object types.
* Fixed: Corrected an issue within Awesome Enterprise core execution code.
* Fixed: Synchronized Allowlist guardrails with 26 core runtime libraries (cond, while, arr_each, arr_items, currency, regex, taxonomy, validate, etc.) to eliminate false linter rejections on valid dynamic block shortcodes

##### 1.0.8
* Improved: Eliminated the Per-Request 𝑂(𝑁) Runtime Bottleneck
* Improved: Payload Segregation (frontend_blocks vs. editor_blocks)
* Improved: Complete Attribute Schema Synthesizer with Bidirectional Attribute SSR Bridge
* Improved: Added Asset Compilation Pipeline 
* Improved: Toolbar Mode Switcher
* Improved: Live WYSIWYG Canvas View ("Preview" Mode)
* Improved: Distraction-Free Modal Dialogs for Repeaters & Code
* Improved: Spacious Canvas Form View ("Edit" Mode)
* Improved: Add WP-CLI commands (wp awx blocks flush, wp awx blocks list, wp awx blocks recompile)
* Improved: Add MCP Ability awesome/blocks-flush under lifecycle category
* Improved: Add wp_after_insert_post hook for REST API mutation handling with recursion guard
* Improved: Update authoring guides, readme, sample SFC modules and guardrails
* Improved: Implemented WAL engine and pattern bridge subsystems
* Improved: Added Core 12 DGB blocks and an idempotent database seeder
* Improved: Added 'wp awx blocks seed' CLI command in class-blocks-cli.php
* Improved: Added pattern bridge admin interfaces, CLI tooling, and fixtures to import and export pattern JSON files
* Improved: Added Patterns_CLI_Command in class-patterns-cli.php ('wp awx patterns import/export/list')
* Improved: Added awesome/pattern-export and awesome/pattern-import operations for portable JSON pattern bundles
* Improved: Added awesome/wal-compile and awesome/wal-decompile operations for declarative agent page authoring
* Improved: Registered awesome-wal category and ability schemas in Abilities_Registry


##### 1.0.7
* Improved: Introduced 24 abilities through the WordPress Abilities API
* Improved: Added support for hierarchical posts in apps
* Improved: Updated support for ACF Blocks V3
* Improved: Added support for hierarchical post types in sitemaps
* Fixed: The relative file path was not working properly

  
##### 1.0.6.1
1. Fixed: innerblocks fields' content was not accessible server-side.

##### 1.0.6
1. Fixed: Uncaught SimpleMySQLiException: Deadlock found when trying to get lock
2. Fixed: Uncaught Error: Failed opening required file
3. Fixed: "Using null as an array offset is deprecated; use an empty string instead"
4. Fixed: Updated the communication handler to the latest version to remove a security issue in the AWS SDK we were using


##### 1.0.5.2
* Improved: compatibility issues with PHP 8.5
  
##### 1.0.5.1
* Fixed: compatibility issues with PHP 8.2+
  
##### 1.0.4
* Fixed: Increased compatibility with PHP 8.0+
* Fixed: Upgraded the handler dependencies to the latest versions.


##### 1.0.3
* Improved: Added layout option to sub fields in row_repeater field, so that they look good
* Improved: Added three new fields - post-select,taxonomy-select,filtered-post-select
* Fixed: Remove button in repeater fields was not looking good.


##### 1.0.2
* Fixed: innerblock was not allowing selection of Gutenberg blocks
* Fixed: issue with keystorkes missing text and text area fields in GT blocks.

##### 1.0.1
* Dependency updates
* Added missing files for introducing shortcodes to register Gutenberg and Elementor blocks
* Fixed the issue with the utilities file.


##### 1.0.0  
* Initial release

## We're Hiring!

<p align="center">
<a href="https://www.wpoets.com/careers/"><img src="https://www.wpoets.com/wp-content/uploads/2020/11/work-with-us_1776x312.png" alt="Join us at WPoets, We specialize in designing, building and maintaining complex enterprise websites and portals in WordPress."></a>
</p>
