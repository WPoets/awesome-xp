# Website Assembly Language (WAL) Engine

Website Assembly Language (WAL) is the declarative Intermediate Representation (IR) used by autonomous agents and LLMs to create, compose, and modify WordPress pages deterministically without hand-crafting fragile HTML comments.

---

## 1. Why Website Assembly Language?

Gutenberg stores page content as serialized block comment strings (`<!-- wp:dgb/awx-section {...} -->`). While robust for browsers, directly generating raw block comments in LLMs poses serious issues:
- LLMs frequently hallucinate JSON delimiters or fail to escape nested quotes properly.
- Theme/plugin namespaces are easily misapplied (e.g. `awx-hero` vs `dgb/awx-hero` vs `core/button`).
- Reusing patterns requires querying database IDs and inlining bulky block markup.

WAL solves this by providing a clean, validated JSON/YAML structure with flat attributes and pattern resolution.

---

## 2. WAL Document Specification

A WAL document contains a `page` envelope with metadata and an ordered array of `sections`:

```yaml
page:
  title: "Modern Enterprise Compute"
  slug: "enterprise-compute"
  status: "publish" # "publish" | "draft"
  sections:
    # 1. Section with custom block and child InnerBlocks
    - block: "awx-section"
      attrs:
        style.surface: "surface"
        style.padding: "default"
      inner:
        - block: "awx-hero"
          attrs:
            content.badge: "Next-Gen Cloud"
            content.title: "Deterministic Compute for AI Teams"
            content.text: "Deploy workloads globally without infrastructure drift."
            style.align: "is-aligned-left"
            style.level: "h1"
          inner:
            - block: "core/button"
              attrs:
                text: "Get Started"
                url: "/signup"
                className: "is-style-fill"

    # 2. Section with responsive grid
    - block: "awx-section"
      attrs:
        style.surface: "surface-sunken"
      inner:
        - block: "awx-feature-grid"
          attrs:
            style.columns: "3"
          inner:
            - block: "core/paragraph"
              attrs:
                content: "Sub-second edge replication included by default."

    # 3. Section embedding a pattern with attribute overrides
    - block: "awx-section"
      attrs:
        style.surface: "surface"
      inner:
        - pattern: "pricing.3_tier_standard"
          overrides:
            content.badge: "Transparent Tiers"
            style.columns: "3"
```

---

## 3. Transpiler Pipeline (`WAL_Transpiler`)

The transpiler processes WAL into native WordPress AST nodes:

1. **Namespace Resolution**: Blocks without an explicit namespace are automatically prefixed with `dgb/` (e.g. `awx-section` -> `dgb/awx-section`). Blocks that already carry a namespace (such as WordPress core blocks `core/button`, `core/paragraph`, `core/heading`) are left intact without double-prefixing.
2. **Pattern Inlining**: When a node specifies `pattern: "slug"`, the transpiler:
   - Queries `wp_block` via `_awx_pattern_slug`.
   - Parses the pattern content via `parse_blocks()`.
   - Merges any provided `overrides` into the root block's attribute dictionary.
   - Slices the resolved block nodes directly into the target AST position.
3. **AST Serialization**: Converts the block nodes using `serialize_blocks()`.
4. **Database Insertion**: Saves the page using `wp_insert_post()` with clean `wp_slash()` handling.

---

## 4. Decompiler Pipeline (`WAL_Decompiler`)

Post-publication, human editors may adjust content via the Gutenberg Site Editor. To allow autonomous agents to continue editing pages without losing human modifications:

1. `WAL_Decompiler::decompile(int $post_id)` retrieves the page via `get_post()`.
2. Parses `post_content` using native `parse_blocks()`.
3. Strips `dgb/` prefixes, preserves flat attribute dictionaries, and retains nested block hierarchies.
4. Returns a clean WAL document ready for agent modification.

---

## 5. Agent MCP Abilities

Agents interact with the WAL Engine using these two standardized abilities:

- **`awesome/wal-compile`** (Tier: `publish`):
  ```json
  {
    "wal": {
      "page": {
        "title": "Pricing Page",
        "slug": "pricing",
        "status": "publish",
        "sections": [ ... ]
      }
    }
  }
  ```
  Returns: `{ "post_id": 104, "slug": "pricing", "title": "Pricing Page", "url": "https://..." }`

- **`awesome/wal-decompile`** (Tier: `read`):
  ```json
  {
    "post_id": 104
  }
  ```
  Returns: `{ "page": { "title": "Pricing Page", "slug": "pricing", "sections": [ ... ] } }`
