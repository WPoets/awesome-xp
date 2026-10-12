# Awesome XP — Authoring Overview & Workflow

Awesome XP exposes 24 abilities through the WordPress Abilities API and MCP Adapter to let AI agents develop services, apps, and Gutenberg blocks safely and reliably.

## The Agent Lifecycle
Every agent must follow this exact progression:
**orient → discover → validate → preview → save draft → publish**

Agents never write live artifacts directly. All authoring operations create or update drafts. Promotion to live is handled via the separate `awesome/version-publish` lifecycle ability.

## Core Rule: Reuse Before Creating
When requested to build a UI element or block:
1. **Tokens**: Can existing `awx` design tokens satisfy the styling?
2. **Modifiers**: Can an existing block variation or modifier class handle the design?
3. **Patterns**: Can registered blocks be composed into a block pattern (`awesome/pattern-create`)?
4. **New Block**: Only create a new Gutenberg block (`awesome/block-create`) as a last resort.

## Available Guide Topics
Pass any of the following topic slugs to `awesome/guide-read`:
- `overview`: This overview document and topic index.
- `lifecycle`: Execution lifecycle and 4-stage processor order.
- `syntax`: Shortcode syntax, casting prefixes, and variable syntax.
- `scopes`: Module, template, and block scoping rules.
- `safety`: SQL safety, the `esc` library, and parameterization.
- `css-tokens`: The Awesome CSS Design System two-layer token model.
- `block-css`: Authoring CSS for Gutenberg blocks using tokens and modifiers.
- `pitfalls`: Common architectural and syntax mistakes.
