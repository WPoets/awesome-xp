# Awesome Enterprise — Dynamic Gutenberg Blocks (DGB)

Dynamic Gutenberg Blocks (DGB) is the native Gutenberg block engine for Awesome Enterprise. It compiles Single-File Component (SFC) modules stored in the `aw_gb_blocks` post type into high-performance, cascade-layered WordPress blocks with bidirectional SSR data binding, live preview editing, and automated asset compilation.

---

## 1. System Architecture

```
┌────────────────────────────────────────────────────────────────────────┐
│                        Post Type: aw_gb_blocks                         │
│                    (Single-File Component in DSL)                      │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   BlockLibrary Compiler & Registry                     │
│  ├── Partitioned Cache: awx_gb_registry_frontend & awx_gb_registry_ed │
│  ├── Atomic Stampede Lock: awx_gb_registry_lock                        │
│  ├── Schema Synthesizer: auto-generates Gutenberg attribute types      │
│  └── Asset Compiler: compiles CSS (@layer blocks) & JS to uploads/     │
└──────────────────┬─────────────────────────────────┬───────────────────┘
                   │                                 │
                   ▼                                 ▼
┌──────────────────────────────────────┐  ┌──────────────────────────────┐
│            Gutenberg Editor          │  │       Frontend Runtime       │
│  ├── Hybrid Toolbar (✏️ Edit / 👁️ Prev) │  │  ├── Zero-overhead O(1) exec │
│  ├── Spacious Canvas Form View       │  │  ├── Auto-enqueued hashed    │
│  ├── 800px Wide Table Modal          │  │      CSS/JS assets           │
│  ├── Dynamic Sidebar Inspector       │  │  ├── @layer blocks scoping   │
│  └── Live WYSIWYG + InnerBlocks      │  │  └── Bidirectional SSR HTML  │
└──────────────────────────────────────┘  └──────────────────────────────┘
```

---

## 2. The Single-File Component (SFC) Standard

Every block post in `aw_gb_blocks` defines five core templates:

### A. `[templates.add register]`
Registers block metadata, icon, category, and maps service hooks:
```html
[templates.add register]
  [gt_blocks.register]
    [config]
      [name]my-block[/name]
      [title]My Custom Block[/title]
      [icon]grid-view[/icon]
      [category]common[/category]
      [description]Modern block description.[/description]
    [/config]
    [render_service   _value="gb_blocks.my-block.render" /]
    [controls_service _value="gb_blocks.my-block.controls" /]
    [styles_service   _value="gb_blocks.my-block.styles" /]
    [scripts_service  _value="gb_blocks.my-block.scripts" /]
  [/gt_blocks.register]
[/templates.add]
```

### B. `[templates.add controls]`
Defines visual editor controls organized in tabs. Any section can be dynamically routed to the Gutenberg Settings Sidebar:
```html
[templates.add controls]
  [arr.create o.set='template.controls']
    [sections new]
      [name _value='section_content' /]
      [title _value="Content" /]
      [icon _value="edit" /]
      [fields new]
        {
          "type": "title",
          "name": "heading",
          "label": "Heading",
          "attr_name": "content.heading",
          "default": "Feature Title"
        }
      [/fields]
    [/sections]

    <!-- Routed directly to Gutenberg Sidebar panel -->
    [sections new]
      [name _value='section_appearance' /]
      [title _value="Card Appearance" /]
      [icon _value="admin-settings" /]
      [sidebar _value="true" /]
      [fields new]
        {
          "type": "select",
          "name": "columns",
          "label": "Columns",
          "attr_name": "style.columns",
          "default": "3",
          "options": [
            { "label": "2 Columns", "value": "2" },
            { "label": "3 Columns", "value": "3" }
          ]
        }
      [/fields]
    [/sections]
  [/arr.create]
  [template.return template.controls /]
[/templates.add]
```

### C. `[templates.add styles]`
Styles **must** be wrapped in `@layer blocks { ... }`. The compiler scopes styles to `.wp-block-dgb-{slug}` and writes content-hashed files:
```html
[templates.add styles]
  @layer blocks {
    .wp-block-dgb-my-block {
      padding: var(--awx-space-6, 32px);
      background: var(--awx-color-surface, #ffffff);
    }
    .wp-block-dgb-my-block .my-title {
      font-size: var(--awx-font-size-4, 24px);
      color: var(--awx-color-text, #1e293b);
    }
  }
[/templates.add]
```

### D. `[templates.add render]`
Server-side HTML rendering template. Attributes are accessible via bidirectional dot/nested notation:
```html
[templates.add render]
  [template.get content.heading default="" o.set="template.heading" /]
  [template.get style.columns default="3" o.set="template.cols" /]

  <!-- Note: Variables set in template scope (e.g. o.set="template.cols") are retrieved using [template.get cols /] -->
  <div class="wp-block-dgb-my-block cols-[template.get cols /]" [template.get wp.wrapper_attributes /]>
    <h2 class="my-title">[template.get heading /]</h2>
    <div class="my-innerblocks">
      [template.get content.innerblocks /]
    </div>
  </div>
[/templates.add]
```

### E. `[templates.add scripts]`
Optional client-side JavaScript compiled into `wp-content/uploads/awx-js/{slug}-{md5}.js`.

---

## 3. Supported Controls & Advanced Features

### Standard Controls
`text`, `textarea`, `title`, `select`, `toggle`, `radio`, `color`, `date`, `number`, `range`, `image`, `media`.

### `row_repeater` Control
Manages structured tabular data with multiple subfields:
- `layout`: `"table"`, `"card"`, or `"card-collapsed"`.
- **Wide Table Editor Modal**: Users can click the "Wide Table Editor" button in the form header to expand the repeater into an 800px distraction-free modal dialog.

```json
{
  "type": "row_repeater",
  "name": "features",
  "label": "Feature Highlights",
  "attr_name": "content.features",
  "layout": "table",
  "repeater_fields": [
    { "type": "text", "name": "icon", "label": "Icon", "width": "15%", "default": "⚡" },
    { "type": "text", "name": "title", "label": "Title", "width": "35%", "default": "Fast" },
    { "type": "text", "name": "desc", "label": "Description", "width": "50%", "default": "Compiled assets." }
  ]
}
```

In `render`, repeaters are iterated cleanly using `[loop.@iterator array_path]`:
```html
[template.get content.features default="[]" o.set="template.features" /]
[if.not_empty template.features]
  [loop.@f template.features]
    <div class="card">
      <span>[env.@f.item.icon /]</span>
      <h4>[env.@f.item.title /]</h4>
      <p>[env.@f.item.desc /]</p>
    </div>
  [/loop.@f]
[/if.not_empty]
```

### `innerblocks` Control
Allows embedding native WordPress blocks (Buttons, Headings, Media, Paragraphs) inside the component:
```json
{
  "type": "innerblocks",
  "name": "cta_slot",
  "label": "Call-to-Action Area",
  "attr_name": "content.innerblocks"
}
```
- In the editor, switching to **Live Preview (👁️)** displays the block inserter (`+`) for adding nested blocks.
- On render, output the inner blocks using `[template.get content.innerblocks /]`.

### Dynamic Sidebar Assignment
To place a tab in the Inspector Sidebar, set `[sidebar _value="true" /]` on the section:
```html
[sections new]
  [name _value='section_layout' /]
  [title _value="Layout Settings" /]
  [icon _value="admin-settings" /]
  [sidebar _value="true" /]
  ...
[/sections]
```
Do **not** hardcode `section_style`; any section can be assigned dynamically.

---

## 4. Asset Compilation & Resilience

1. **Cascade Layers**: Styles are enclosed in `@layer blocks { ... }`.
2. **Content Hashing**: Assets are written to `uploads/awx-css/{slug}-{hash}.css` and `uploads/awx-js/{slug}-{hash}.js`.
3. **Ephemeral Self-Healing**: Missing files on stateless nodes are transparently recompiled on request.
4. **Cloud Offloading**: Remote streams (`wp_is_stream`) automatically fallback to inline styles.
5. **Garbage Collection**: Outdated hash files are automatically cleaned up on post updates or trashing.

---

## 5. Developer Tooling & CLI

### WP-CLI Commands
```bash
# Invalidate the block registry cache
wp awx blocks flush

# Invalidate cache and synchronously recompile all block assets
wp awx blocks flush --recompile

# Recompile assets for a specific block
wp awx blocks flush --slug=awx-features-showcase

# List all registered blocks and asset status
wp awx blocks list
wp awx blocks list --format=json

# Recompile block CSS/JS assets
wp awx blocks recompile awx-features-showcase
wp awx blocks recompile --all
```
*(Also aliased under `wp aw2 blocks ...`)*

### MCP Agent Ability (`awesome/blocks-flush`)
AI agents and external services can invalidate the cache programmatically:
```json
{
  "recompile": true,
  "slug": "awx-features-showcase"
}
```
Available via the WordPress Abilities API and REST (`POST /wp-json/awesome-xp/v1/abilities/awesome/blocks-flush/execute`).

---

## 6. Guardrails & Strict Limitations

| Constraint | Severity | Requirement |
|---|---|---|
| **`@layer blocks` Scoping** | **MUST** | Always wrap styles in `@layer blocks { ... }`. |
| **Wrapper Attributes** | **MUST** | Include `[template.get wp.wrapper_attributes /]` on the root element. |
| **SQL Table Prefixes** | **MUST** | Never hardcode `wp_`. Always use `[env.get db.prefix /]` with `[esc.*]`. |
| **Sidebar Routing** | **MUST** | Use `[sidebar _value="true" /]` explicitly instead of hardcoding section names. |
| **Loop Scoping** | **MUST** | Access loop item properties strictly via `.item` (e.g., `[env.@iterator.item.field /]`). |
| **No Global Tag Overrides** | **MUST** | Never style bare HTML tags globally without component class prefixing. |
