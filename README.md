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
