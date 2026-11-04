# Common Pitfalls & Lint Rules

The `awesome/template-validate` ability enforces these rules before issuing a validation token:

1. **Unescaped Values in SQL (Error)**: Concatenating strings or curly braces into SQL queries without `[esc.*]` is blocked.
2. **Untrapped Output Streams (Error)**: Data-returning services like `mysqli.fetch.rows` or `curl.api.get` will dump raw debug arrays unless captured with `o.set="path"` or suppressed with `o.destroy="yes"`.
3. **Nested Shortcode Brackets in Attributes (Error)**: `[env.set x=[esc.str y] /]` fails parsing. Store the escaped value in a temporary variable first.
4. **Nested Curly Braces (Error)**: `{person.{key}}` is illegal. Flatten variable paths before referencing.
5. **Direct Loop Iterator Property Access (Error)**: Inside `[loop.@iterator arr]`, properties **must** be accessed via `.item`: `{@iterator.item.title}` or `[env.@iterator.item.title /]`. Never use `{@iterator.title}`.
6. **Variables in LIMIT Clause (Error)**: MySQL session variables (e.g. `@limit`) cannot be used in a `LIMIT` clause.
7. **Disallowed Services (Error)**: Services not on the site allowlist fail validation.
8. **Raw CSS Colors/Spacing (Warning)**: Hardcoded `#hex`, `rgb()`, or pixel measurements where an `awx` design token exists generate warnings.
9. **Missing Wrapper Attributes in Blocks (Error)**: All Gutenberg block render templates **must** output `[template.get wp.wrapper_attributes /]` on their root HTML container. Omitting it breaks core Gutenberg features like alignment, block gap, anchor IDs, and custom classes.
10. **Unscoped Block Styles without `@layer blocks` (Error)**: Block styles in `[templates.add styles]` **must** be wrapped within `@layer blocks { ... }`. Bare, unscoped CSS causes specificity collisions and fails the compiler layer contract.
11. **Hardcoded Table Prefixes in SQL (Error)**: Never hardcode `wp_` or `wp_posts`. Always use `[env.get db.prefix /]` (e.g. `FROM [env.get db.prefix /]posts`).
12. **Hardcoding `section_style` for Sidebar Placement (Warning)**: Never assume a sidebar section will always be named `section_style`. Any section can be routed to the sidebar using `[sidebar _value="true" /]`, or individual fields with `"show_in_sidebar": true`.
13. **Prefixing `template.get` with `template.` (Error)**: Writing `[template.get template.xyz /]` is incorrect because `template.get` automatically targets the `template` scope (`_prefix = 'template'`), resolving to `template.template.xyz`. To read a template variable `xyz` set via `o.set="template.xyz"`, always write `[template.get xyz /]`.
