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
