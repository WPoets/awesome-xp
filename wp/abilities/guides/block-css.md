# Authoring Dynamic Gutenberg Blocks (DGB) & Modern CSS Architecture

Awesome Enterprise provides a high-performance, Single-File Component (SFC) architecture for authoring custom WordPress Gutenberg blocks inside the `aw_gb_blocks` post type.

---

## 1. The Single-File Component (SFC) Standard

Every Gutenberg block in Awesome Enterprise is authored as a unified Single-File Component comprising five dedicated template sections:

```html
[templates.add register]
  [gt_blocks.register]
    [config]
      [name]block-slug[/name]
      [title]Block Display Title[/title]
      [icon]dashicon-name-or-svg[/icon]
      [category]common[/category]
      [description]Short description of the block.[/description]
    [/config]
    [render_service   _value="gb_blocks.block-slug.render" /]
    [controls_service _value="gb_blocks.block-slug.controls" /]
    [styles_service   _value="gb_blocks.block-slug.styles" /]
    [scripts_service  _value="gb_blocks.block-slug.scripts" /]
  [/gt_blocks.register]
[/templates.add]

[templates.add controls]
  [arr.create o.set='template.controls']
    <!-- Sections and fields definition -->
  [/arr.create]
  [template.return template.controls /]
[/templates.add]

[templates.add styles]
  @layer blocks {
    .wp-block-dgb-block-slug {
      /* Scoped CSS using --awx design tokens */
    }
  }
[/templates.add]

[templates.add render]
  <!-- Server-side HTML render template with shortcodes -->
[/templates.add]

[templates.add scripts]
  /* Optional compiled client-side JavaScript */
[/templates.add]
```

---

## 2. Controls & Dynamic Sidebar Routing

Blocks define controls inside `[templates.add controls]` organized by tabbed sections.

### Dynamic Sidebar Placement
Any section or field can be designated for the Gutenberg Settings Sidebar (Inspector) dynamically:
- **Section level**: Add `[sidebar _value="true" /]` or `[show_in_sidebar _value="true" /]` to any section. Do **not** rely on hardcoded section names like `section_style`.
- **Field level**: Set `"show_in_sidebar": true` on any individual field definition.
- **Config level**: Set `[sidebar_tabs]section_style,section_advanced[/sidebar_tabs]` in `[config]`.

### Supported Field Types
- **Standard Controls**: `text`, `textarea`, `title`, `select`, `toggle`, `radio`, `color`, `date`, `number`, `range`, `image`, `media`.
- **Advanced Controls**:
  - `row_repeater`: Multidimensional array of objects. Supports `layout: "table"`, `"card"`, or `"card-collapsed"`, plus an **800px Wide Table Editor** modal.
  - `innerblocks`: Native WordPress core block nesting slot (allowing Buttons, Headings, Media, Paragraphs inside the component).
  - `awesome_code`: Shortcode/code editor with fullscreen distraction-free modal dialog.

---

## 3. Resilient Asset Compilation Pipeline

Block CSS and JS are extracted, compiled, and content-hashed at registration time:

1. **Cascade Layers (`@layer`)**: All block styles **must** be wrapped within `@layer blocks { ... }`. The compiler declares the global layer hierarchy `@layer tokens, base, blocks, sections;` ensuring zero specificity conflicts with theme stylesheets.
2. **Content Hashing**: Styles and scripts are written to `wp-content/uploads/awx-css/{slug}-{md5}.css` and `wp-content/uploads/awx-js/{slug}-{md5}.js`.
3. **Ephemeral Self-Healing**: If a hashed asset is deleted or running on a stateless container cluster, the runtime detects the missing file and transparently recompiles it on the fly.
4. **Cloud Storage Fallback**: On remote/offloaded media storage (`wp_is_stream`), assets automatically fallback to non-blocking inlining to avoid broken file paths.

---

## 4. Render Template & Bidirectional Attribute Access

In `[templates.add render]`, block attributes are accessible via dot notation or nested array keys:

1. **Bidirectional Access**: Both `[template.get content.heading /]` and `[template.get content_heading /]` resolve to the same attribute value.
2. **Root Wrapper Attributes**: Always include `[template.get wp.wrapper_attributes /]` on the root HTML element.
3. **Repeater Loops**: Use `[loop.@iterator array_path]` to loop over repeater rows. Inside the loop, access item fields strictly via `[env.@iterator.item.field_name /]`.
4. **Nested InnerBlocks**: Render nested WordPress blocks using `[template.get content.innerblocks /]`.

---

## 5. Full Production Example: `awx-features-showcase`

```html
[templates.add register]
  [gt_blocks.register]
    [config]
      [name]awx-features-showcase[/name]
      [title]AWX Features Showcase[/title]
      [icon]grid-view[/icon]
      [category]common[/category]
      [description]Modern feature showcase testing row_repeater, Wide Table modal, and nested InnerBlocks.[/description]
    [/config]
    [render_service   _value="gb_blocks.awx-features-showcase.render" /]
    [controls_service _value="gb_blocks.awx-features-showcase.controls" /]
    [styles_service   _value="gb_blocks.awx-features-showcase.styles" /]
    [scripts_service  _value="gb_blocks.awx-features-showcase.scripts" /]
  [/gt_blocks.register]
[/templates.add]

[templates.add controls]
  [arr.create o.set='template.controls']
    
    <!-- Tab 1: Header (Main Form Tab) -->
    [sections new]
      [name _value='section_header' /]
      [title _value="Header" /]
      [icon _value="edit" /]
      [fields new]
        {
          "type": "title",
          "name": "heading",
          "label": "Main Heading",
          "attr_name": "content.heading",
          "default": "Built for High Performance",
          "validation": { "required": true }
        }
      [/fields]
      [fields new]
        {
          "type": "textarea",
          "name": "subheading",
          "label": "Subheading",
          "attr_name": "content.subheading",
          "default": "Zero runtime overhead with compiled CSS and modern editor controls."
        }
      [/fields]
    [/sections]

    <!-- Tab 2: Feature Repeater (row_repeater with Wide Table Modal) -->
    [sections new]
      [name _value='section_features' /]
      [title _value="Features List" /]
      [icon _value="editor-table" /]
      [fields new]
        {
          "type": "row_repeater",
          "name": "features",
          "label": "Feature Items",
          "attr_name": "content.features",
          "layout": "table",
          "repeater_fields": [
            { "type": "text", "name": "icon", "label": "Icon", "width": "15%", "default": "⚡" },
            { "type": "text", "name": "title", "label": "Title", "width": "35%", "default": "Fast" },
            { "type": "text", "name": "desc", "label": "Description", "width": "50%", "default": "Compiled assets." }
          ],
          "default": [
            { "icon": "⚡", "title": "Compiled Assets", "desc": "Precompiled hashed CSS." },
            { "icon": "🧩", "title": "InnerBlocks", "desc": "Embed WordPress core blocks inside." }
          ]
        }
      [/fields]
    [/sections]

    <!-- Tab 3: Child Blocks (innerblocks) -->
    [sections new]
      [name _value='section_innerblocks' /]
      [title _value="Child Content" /]
      [icon _value="block-default" /]
      [fields new]
        {
          "type": "innerblocks",
          "name": "cta_blocks",
          "label": "Call-to-Action Area",
          "attr_name": "content.innerblocks"
        }
      [/fields]
    [/sections]

    <!-- Tab 4: Dynamic Sidebar Panel -->
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
            { "label": "3 Columns", "value": "3" },
            { "label": "4 Columns", "value": "4" }
          ]
        }
      [/fields]
      [fields new]
        {
          "type": "toggle",
          "name": "show_icons",
          "label": "Display Feature Icons",
          "attr_name": "style.show_icons",
          "default": true
        }
      [/fields]
    [/sections]

  [/arr.create]
  [template.return template.controls /]
[/templates.add]

[templates.add styles]
  @layer blocks {
    .wp-block-dgb-awx-features-showcase {
      padding: var(--awx-space-6, 32px) var(--awx-space-4, 16px);
      box-sizing: border-box;
    }
    .wp-block-dgb-awx-features-showcase .awx-fs-header {
      text-align: center;
      margin-bottom: var(--awx-space-6, 32px);
    }
    .wp-block-dgb-awx-features-showcase .awx-fs-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: var(--awx-space-4, 16px);
      margin-bottom: var(--awx-space-6, 32px);
    }
    @media (min-width: 640px) {
      .wp-block-dgb-awx-features-showcase.has-cols-2 .awx-fs-grid { grid-template-columns: repeat(2, 1fr); }
      .wp-block-dgb-awx-features-showcase.has-cols-3 .awx-fs-grid { grid-template-columns: repeat(3, 1fr); }
      .wp-block-dgb-awx-features-showcase.has-cols-4 .awx-fs-grid { grid-template-columns: repeat(4, 1fr); }
    }
    .wp-block-dgb-awx-features-showcase .awx-fs-card {
      background: var(--awx-color-surface, #ffffff);
      border: 1px solid var(--awx-color-border, #e2e8f0);
      border-radius: var(--awx-radius-md, 8px);
      padding: var(--awx-space-4, 16px);
    }
    .wp-block-dgb-awx-features-showcase .awx-fs-footer {
      display: flex;
      justify-content: center;
      margin-top: var(--awx-space-4, 16px);
    }
  }
[/templates.add]

[templates.add render]
  [template.get content.heading default="" o.set="template.heading" /]
  [template.get content.subheading default="" o.set="template.subheading" /]
  [template.get style.columns default="3" o.set="template.cols" /]
  [template.get style.show_icons default="1" o.set="template.show_icons" /]
  [template.get content.features default="[]" o.set="template.features" /]

  <div class="wp-block-dgb-awx-features-showcase has-cols-[template.get template.cols /]" [template.get wp.wrapper_attributes /]>
    <div class="awx-fs-header">
      [if.not_empty template.heading]
        <h2 class="awx-fs-heading">[template.get template.heading /]</h2>
      [/if.not_empty]
      [if.not_empty template.subheading]
        <p class="awx-fs-subheading">[template.get template.subheading /]</p>
      [/if.not_empty]
    </div>

    [if.not_empty template.features]
      <div class="awx-fs-grid">
        [loop.@f template.features]
          <div class="awx-fs-card">
            [if.true template.show_icons]
              [if.not_empty @f.item.icon]
                <div class="awx-fs-card-icon">[env.@f.item.icon /]</div>
              [/if.not_empty]
            [/if.true]
            <h3 class="awx-fs-card-title">[env.@f.item.title /]</h3>
            <p class="awx-fs-card-desc">[env.@f.item.desc /]</p>
          </div>
        [/loop.@f]
      </div>
    [/if.not_empty]

    <div class="awx-fs-footer">
      [template.get content.innerblocks /]
    </div>
  </div>
[/templates.add]
```

---

## 6. Developer Tooling & Cache Management

### WP-CLI Commands
- `wp awx blocks flush`: Flushes the block registry cache and bumps registry stamp.
- `wp awx blocks flush --recompile`: Flushes cache and synchronously recompiles all block assets.
- `wp awx blocks flush --slug=<slug>`: Flushes cache and targets single block asset recompilation.
- `wp awx blocks list`: Displays tabular list of all registered blocks and asset status.
- `wp awx blocks recompile [<slug>] [--all]`: Rebuilds CSS/JS assets.

### MCP Agent Ability
- **`awesome/blocks-flush`**: Allows programmatic invalidation and recompilation via MCP or REST API (`POST /wp-json/awesome-xp/v1/abilities/awesome/blocks-flush/execute`).

---

## 7. Guardrails & Strict Limitations

| Rule | Severity | Requirement |
|---|---|---|
| **`@layer blocks` Scoping** | **MUST** | All CSS styles must be wrapped inside `@layer blocks { ... }`. |
| **No Bare HTML Selectors** | **MUST** | Never write unscoped selectors like `h2`, `p`, or `button` without namespace prefix `.wp-block-dgb-{slug}`. |
| **Wrapper Attributes** | **MUST** | Always output `[template.get wp.wrapper_attributes /]` on the root container element. |
| **SQL Table Prefixes** | **MUST** | Never hardcode `wp_`. Always retrieve prefix with `[env.get db.prefix /]` and escape variables with `[esc.str]` or `[esc.int]`. |
| **Dynamic Sidebar** | **MUST** | Never assume sidebar section is named `section_style`. Use `[sidebar _value="true" /]` explicitly. |
| **Loop Scoping** | **MUST** | Inside `[loop.@iterator arr]`, always access item properties using `.item` (e.g. `[env.@iterator.item.field /]`). Direct access (`{@iterator.field}`) will fail. |
