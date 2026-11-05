# AWX Pattern Bridge & Portable Pattern Bundles

The AWX Pattern Bridge provides seamless portability for Gutenberg block patterns between environments, version control systems, and autonomous agent workflows.

---

## 1. Storage & Metadata Contracts

All managed patterns are stored in the WordPress `wp_block` custom post type with three required contracts:

1. **Unsynced Status (`wp_pattern_sync_status = 'unsynced'`)**:
   - In native WordPress, synced patterns (formerly reusable blocks) propagate changes across every page they appear on.
   - AWX patterns are strictly **unsynced** page-building templates. When an agent or user inserts a pattern, its blocks are decoupled from the master pattern so edits apply only to that specific instance.
2. **Managed Flag (`_awx_pattern_managed = 1`)**:
   - Differentiates AWX-curated patterns from random theme or user-created patterns.
   - Ensures export and list tooling only bundle AWX-managed patterns.
3. **Explicit Slug Meta (`_awx_pattern_slug`)**:
   - WordPress post names typically strip punctuation and dots.
   - AWX preserves explicit dotted slugs (e.g. `pricing.3_tier_standard`, `hero.standard_conversion`) in `_awx_pattern_slug` using `preg_replace('/[^a-z0-9_\.\-]/i', '', ...)`.

---

## 2. Portable Pattern Manifest Format

Pattern packs are represented as JSON arrays:

```json
[
  {
    "slug": "pricing.3_tier_standard",
    "title": "3-Tier Standard Pricing Matrix",
    "categories": ["pricing", "featured"],
    "content": "<!-- wp:dgb/awx-pricing {\"content.items\":[...],\"style.columns\":\"3\"} /-->"
  },
  {
    "slug": "hero.standard_conversion",
    "title": "Standard Conversion Hero",
    "categories": ["header", "featured"],
    "content": "<!-- wp:dgb/awx-section {\"style.surface\":\"surface\"} -->\n<!-- wp:dgb/awx-hero {...} -->...<!-- /wp:dgb/awx-section -->"
  }
]
```

---

## 3. Tooling & Admin Interfaces

### Admin UI Screens
- **Import Screen**: `?page=awesome-gt-block-import`
  - Upload a `.json` pattern file or paste a JSON manifest directly into the editor card.
  - Automatically invokes `AWX_Pattern_Bridge::import_bundle()`.
- **Export Screen**: `?page=awesome-app-import-export`
  - Features the "Export AWX Block Patterns" card with a single-click download of `awx-patterns.json`.

### WP-CLI Commands
```bash
# List all managed patterns
wp awx patterns list

# Import patterns from a bundle file
wp awx patterns import wp-content/plugins/awesome-xp/wp/libraries/gutenberg/fixtures/patterns.json

# Export managed patterns to a file
wp awx patterns export --output=my-patterns.json
```

---

## 4. Agent MCP Abilities

- **`awesome/pattern-export`** (Tier: `read`):
  ```json
  {
    "path": "optional/path/to/save.json"
  }
  ```
  Exports all managed patterns and optionally writes them to disk. Returns `{ "count": 4, "patterns": [ ... ] }`.

- **`awesome/pattern-import`** (Tier: `author`):
  ```json
  {
    "manifest": [
      {
        "slug": "testimonials.3_col",
        "title": "3-Column Testimonial Grid",
        "content": "<!-- wp:dgb/awx-testimonials ... /-->",
        "categories": ["testimonials"]
      }
    ]
  }
  ```
  Alternatively pass `{ "path": "/path/to/patterns.json" }`. Idempotently creates or updates `wp_block` records.
