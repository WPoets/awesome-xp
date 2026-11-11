# Awesome XP — Template Validator Cheatsheet (for agents)

Audience: agents authoring AWX Gutenberg blocks, patterns and services through the Awesome XP MCP abilities.
Source: failures observed while building the blog list and single-post blocks on zero.thecancerus.com. Where a rule is "precaution" rather than "confirmed", it says so.

## 1. Authoring lifecycle (follow in order)

1. Read `awesome/guide-read`, then `awesome/block-list`, `awesome/token-list` and `awesome/service-list`. Prefer tokens and existing blocks before creating new ones.
2. Write the full template (register, controls, styles, scripts, render).
3. `awesome/template-validate` with `context: "block"`. On success it returns a `validation_token`.
4. `awesome/block-update` (or `block-create`) with the **identical string** and the token. The token is bound to the exact string. Changing a single character, including a mistyped `attr_name`, gives `invalid_validation_token`. Re-validate after any edit.
5. `awesome/version-publish` with `artifact_type`, `slug` and `version`.
6. **Purge caches.** Publishing alone does not change live pages. Purge the Awesome global cache and the blocks cache, plus the NGINX cache if one is present (Awesome Enterprise admin page: "Purge Global Cache", "Purge Blocks", "Purge NGINX Cache"; links carry a nonce, so re-read them from the page each time). `awesome/blocks-flush` is not sufficient on its own.
7. Verify on a **live page**, not only in preview. `awesome/template-preview` renders the body only: no `[templates.add render]` wrapper, no real post context. Use `[env.set ...]` to simulate state in preview.

Tip: `awesome/log-list` shows recent exceptions after publish.

## 2. Rules and gotchas

### 2.1 Nested same-name tags leak the closing tag
`[if.equal]` inside `[if.equal]` (or any same-name pair) emits a stray closing tag in the output.
Fix: set a flag with `str.create` / `template.get`, then use **different** tag names for outer and inner (e.g. outer `if.not_empty`, inner `if.equal`), or flatten the logic into one condition on a flag.

### 2.2 `nested-curly-braces`
Nested `{}` is rejected.
- No `@media` inside `@layer`. Use intrinsic layouts instead (`grid-template-columns: repeat(auto-fit, minmax(...))`, `clamp()`).
- No inline JSON manifests that nest braces.
- JS: write flat top-level functions. Braces inside if-blocks inside the scripts template are risky. Use ternaries, `&&` short-circuits and `.bind()` instead of nested blocks.

### 2.3 `disallowed-service` from bracket text in JS
Text shaped like `name[a.b.c]` is parsed as a shortcode service call.
- Confirmed failure: `urls[el.dataset.net]` -> `Service 'el.dataset.net' is not permitted`.
- Confirmed passing: `[data-net]`, `[hidden]`, `:not([hidden])`, `[data-net="copy"]`-style attribute selectors (no dots inside the brackets). These were removed anyway in the final code as a precaution; prefer class selectors.
- Instead of `map[key]`: use `el.getAttribute('data-x')`, a ternary chain in a helper function, or separate named functions.

### 2.4 Reserved and non-existent names
- `template.last` is reserved. Use another name (`lastn`).
- Do not guess tag names. `if.not_less_than` does not exist; `if.greater_than`, `if.equal`, `if.not_empty` do. Check `awesome/service-list` first.

### 2.5 SQL must be escaped through services
```
[mysqli.multi.fetch.rows o.set="template.row"]
  SET @id = [esc.int template.post_id /];
  SELECT ... FROM [esc.table template.posts_table /] p WHERE p.ID = @id LIMIT [esc.int template.limit /];
[/mysqli.multi.fetch.rows]
```
Never interpolate raw values. Build table names from `wpdb.prefix` via `str.create`, then `esc.table`.

### 2.6 Useful, confirmed services and modifiers
- Query params: `[request2.get pg m.to_int=yes]`
- Site URL: `[wp.get "option.siteurl" o.set="template.site" /]`
- Current post: `[wp.get post.ID default="0" o.set="template.post_id" /]`
- Modifiers: `m.date_format="F j, Y"`, `m.strip_tags=yes`, `m.to_int=yes`
- Wrapper attributes in render: `<header [template.get wp.wrapper_attributes /]>`

### 2.7 CSS
- Use `--awx-*` tokens only (`--awx-color-primary`, `--awx-color-secondary`, `--awx-color-surface`, `--awx-color-on-primary`, `--awx-space-*`, `--awx-radius-*`, `--awx-border-thin`, `--awx-shadow-2`, ...). Get the list from `awesome/token-list`.
- Wrap block CSS in `@layer blocks { ... }` and scope selectors under `.wp-block-dgb-<block-name>`.
- Theme styles can win over block styles: some properties need `!important` (heading margins, font-size, link colour).
- Surface values: the valid option is `sunken`; `surface-sunken` is not valid (aliased in awx-section).
- Breadcrumb separators: use CSS `content: "/"`, not an HTML entity string (an entity rendered as "203A").

### 2.8 Scripts
- Flat functions, no inline handlers.
- Bind once: `window.flag = window.flag || bindFn();`
- Event delegation on `document`, activate only on user interaction (click, keydown).
- Do not use `navigator.share` if the requirement is social sharing; build an explicit menu (LinkedIn, X, Facebook, WhatsApp, copy link).
- Check other modules that may attach competing handlers to the same element (e.g. an app/module post that ships its own share script).

### 2.9 Controls
- Field `attr_name` values must match what render reads (`content.share_label`, `content.show_author`). A typo makes the control silently ineffective.
- Toggles default to `true`; compare with `[if.equal lhs="{template.x}" rhs="1"]`.
- Use Universal Dictionary names (`awesome/dictionary-get`) for field names.

## 3. Known limitations of the tooling (so you do not waste attempts)

- Validator errors carry a rule id and a generic `fix_hint`, but **no line number or snippet**. Locate the offending code by reading your template or bisecting.
- FSE templates and template parts cannot be edited through these abilities. Blocks used in templates run on their **default attributes**.
- Cache purge is not an ability; it requires the admin-page links.
- `template-preview` does not reproduce page context (current post, query vars) unless you simulate it.

## 4. Pre-publish checklist

- [ ] Existing blocks/tokens checked before creating anything new
- [ ] No nested same-name tags; flags used instead
- [ ] No nested `{}` in CSS or JS
- [ ] No `name[dotted.path]` text in JS
- [ ] SQL uses `SET @var = [esc.*]`, `[esc.table]`, `[esc.int]`
- [ ] Only `--awx-*` tokens in CSS, inside `@layer blocks`
- [ ] Validated; the exact validated string was sent to update/create
- [ ] Draft published, caches purged
- [ ] Verified on a live page (render, interaction, mobile width)

## 5. For plugin maintainers (optional)

Suggested way to expose this from the Awesome XP plugin:
1. Keep one rule registry (id, description, bad example, good example, fix). Generate everything below from it.
2. Return rule id, line/column, offending snippet and a good example from `template-validate`.
3. Add a `validator-rules` topic to `awesome/guide-read`.
4. Add a read-only `awesome/validator-rules` ability returning the registry as structured data.
5. Add `awesome/cache-purge` (scopes: global, blocks, nginx) or a `purge` option on `version-publish`.
6. Add an "Authoring rules" page in Awesome Enterprise, rendered from the same registry.
