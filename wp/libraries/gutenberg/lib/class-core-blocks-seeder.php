<?php
/**
 * AWX Core Blocks Seeder
 *
 * Implements the idempotent database seeder for the Core 12 Dynamic Gutenberg Blocks (DGB)
 * according to the AWX engineering specification.
 *
 * @package DynamicGutenbergBlocks
 * @version 1.0.0
 */

namespace aw2\gutenberg_blocks;

if (!defined('ABSPATH')) {
  exit;
}

final class AWX_Core_Blocks_Seeder
{
  /**
   * Returns the array of complete Single-File Component (SFC) definitions
   * for the Core 12 blocks.
   *
   * @return array<string, array>
   */
  public static function get_blocks(): array
  {
    return [
      // 1. awx-section
      'awx-section' => [
        'slug' => 'awx-section',
        'title' => 'AWX Section Wrapper',
        'icon' => 'align-wide',
        'category' => 'layout',
        'description' => 'Layout container for vertical rhythm, maximum content boundaries, and surface contrast.',
        'sfc' => self::build_sfc(
          'awx-section',
          'AWX Section Wrapper',
          'align-wide',
          'layout',
          'Layout container for vertical rhythm, maximum content boundaries, and surface contrast.',
          <<<CONTROLS
    [sections new]
      [name _value='section_content' /]
      [title _value="Section Content" /]
      [icon _value="edit" /]
      [fields new]
        {
          "type": "text",
          "name": "anchor",
          "label": "HTML Anchor / ID",
          "attr_name": "content.anchor",
          "default": ""
        }
      [/fields]
      [fields new]
        {
          "type": "innerblocks",
          "name": "section_slot",
          "label": "Section Content",
          "attr_name": "content.innerblocks"
        }
      [/fields]
    [/sections]

    [sections new]
      [name _value='section_appearance' /]
      [title _value="Section Appearance" /]
      [icon _value="admin-settings" /]
      [sidebar _value="true" /]
      [fields new]
        {
          "type": "select",
          "name": "surface",
          "label": "Surface Theme",
          "attr_name": "style.surface",
          "default": "surface",
          "options": [
            { "label": "Surface", "value": "surface" },
            { "label": "Sunken", "value": "sunken" },
            { "label": "Brand", "value": "brand" },
            { "label": "Brand Subtle", "value": "brand-subtle" }
          ]
        }
      [/fields]
      [fields new]
        {
          "type": "select",
          "name": "width",
          "label": "Max Width",
          "attr_name": "style.width",
          "default": "default",
          "options": [
            { "label": "Default (1280px)", "value": "default" },
            { "label": "Narrow (768px)", "value": "narrow" },
            { "label": "Wide (1440px)", "value": "wide" },
            { "label": "Full Width", "value": "full" }
          ]
        }
      [/fields]
      [fields new]
        {
          "type": "select",
          "name": "padding",
          "label": "Vertical Padding",
          "attr_name": "style.padding",
          "default": "default",
          "options": [
            { "label": "Default", "value": "default" },
            { "label": "Compact", "value": "compact" },
            { "label": "Spacious", "value": "spacious" }
          ]
        }
      [/fields]
    [/sections]
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-section {
      width: 100%;
      padding-block: var(--awx-space-section-padding);
      background-color: var(--awx-color-background);
      color: var(--awx-color-text);
    }
    .wp-block-dgb-awx-section.has-surface-surface { background-color: var(--awx-color-surface); }
    .wp-block-dgb-awx-section.has-surface-sunken { background-color: var(--awx-color-surface-sunken); }
    .wp-block-dgb-awx-section.has-surface-brand {
      background-color: var(--awx-color-surface-brand);
      color: var(--awx-color-on-primary);
    }
    .wp-block-dgb-awx-section.has-surface-brand-subtle { background-color: var(--awx-color-surface-brand-subtle); }
    
    .wp-block-dgb-awx-section.has-padding-compact { padding-block: var(--awx-space-8); }
    .wp-block-dgb-awx-section.has-padding-spacious { padding-block: var(--awx-space-20); }

    .wp-block-dgb-awx-section > .wp-block-awx-section-inner {
      width: 100%;
      margin-inline: auto;
      padding-inline: var(--awx-space-6);
      max-width: var(--awx-width-max);
    }
    .wp-block-dgb-awx-section.has-width-narrow > .wp-block-awx-section-inner { max-width: var(--awx-width-content); }
    .wp-block-dgb-awx-section.has-width-wide > .wp-block-awx-section-inner { max-width: var(--awx-width-wide); }
    .wp-block-dgb-awx-section.has-width-full > .wp-block-awx-section-inner { max-width: 100%; padding-inline: 0; }
  }
STYLES
          ,
          <<<RENDER
  <section [template.get wp.wrapper_attributes /] [if.not_empty template.content.anchor]id="[template.get content.anchor m.entities_encode=yes /]"[/if.not_empty]>
    <div class="wp-block-awx-section-inner">
      [template.get content.innerblocks /]
    </div>
  </section>
RENDER
        )
      ],

      // 2. awx-hero
      'awx-hero' => [
        'slug' => 'awx-hero',
        'title' => 'AWX Hero Banner',
        'icon' => 'cover-image',
        'category' => 'common',
        'description' => 'Primary above-the-fold conversion anchor with fluid display typography and open button slots.',
        'sfc' => self::build_sfc(
          'awx-hero',
          'AWX Hero Banner',
          'cover-image',
          'common',
          'Primary above-the-fold conversion anchor with fluid display typography and open button slots.',
          <<<CONTROLS
    [sections new]
      [name _value='section_content' /]
      [title _value="Hero Content" /]
      [icon _value="edit" /]
      [fields new]
        {
          "type": "text",
          "name": "badge",
          "label": "Badge / Eyebrow",
          "attr_name": "content.badge",
          "default": ""
        }
      [/fields]
      [fields new]
        {
          "type": "title",
          "name": "title",
          "label": "Hero Title",
          "attr_name": "content.title",
          "default": ""
        }
      [/fields]
      [fields new]
        {
          "type": "textarea",
          "name": "text",
          "label": "Hero Text",
          "attr_name": "content.text",
          "default": ""
        }
      [/fields]
    [/sections]

    [sections new]
      [name _value='section_actions' /]
      [title _value="Actions Slot" /]
      [icon _value="button" /]
      [fields new]
        {
          "type": "innerblocks",
          "name": "actions",
          "label": "Call to Action Buttons",
          "attr_name": "content.innerblocks"
        }
      [/fields]
    [/sections]

    [sections new]
      [name _value='section_appearance' /]
      [title _value="Appearance" /]
      [icon _value="admin-settings" /]
      [sidebar _value="true" /]
      [fields new]
        {
          "type": "select",
          "name": "align",
          "label": "Alignment",
          "attr_name": "style.align",
          "default": "is-aligned-left",
          "options": [
            { "label": "Align Left", "value": "is-aligned-left" },
            { "label": "Align Center", "value": "is-aligned-center" }
          ]
        }
      [/fields]
      [fields new]
        {
          "type": "select",
          "name": "level",
          "label": "Heading Level",
          "attr_name": "style.level",
          "default": "h1",
          "options": [
            { "label": "H1", "value": "h1" },
            { "label": "H2", "value": "h2" }
          ]
        }
      [/fields]
    [/sections]
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-hero {
      display: flex;
      flex-direction: column;
      gap: var(--awx-space-6);
    }
    .wp-block-dgb-awx-hero.is-aligned-center {
      align-items: center;
      text-align: center;
      margin-inline: auto;
      max-width: var(--awx-width-content);
    }
    .wp-block-dgb-awx-hero.is-aligned-left {
      align-items: flex-start;
      text-align: left;
    }
    .wp-block-dgb-awx-hero .wp-block-awx-hero-badge {
      font-size: var(--awx-font-size--1);
      font-weight: var(--awx-font-weight-semibold);
      padding: var(--awx-space-1) var(--awx-space-3);
      border-radius: var(--awx-radius-full);
      background-color: var(--awx-color-surface-brand-subtle);
      color: var(--awx-color-primary);
    }
    .wp-block-dgb-awx-hero .wp-block-awx-hero-title {
      font-size: var(--awx-font-size-8);
      line-height: var(--awx-leading-tight);
      letter-spacing: var(--awx-tracking-tight);
      margin: 0;
    }
    .wp-block-dgb-awx-hero .wp-block-awx-hero-text {
      font-size: var(--awx-font-size-1);
      line-height: var(--awx-leading-relaxed);
      color: var(--awx-color-text-muted);
      margin: 0;
      max-width: 65ch;
    }
    .wp-block-dgb-awx-hero .wp-block-awx-hero-actions {
      display: flex;
      flex-wrap: wrap;
      gap: var(--awx-space-4);
      margin-top: var(--awx-space-2);
    }
  }
STYLES
          ,
          <<<RENDER
  <header [template.get wp.wrapper_attributes /]>
    [if.not_empty template.content.badge]
      <span class="wp-block-awx-hero-badge">[template.get content.badge m.entities_encode=yes /]</span>
    [/if.not_empty]
    
    <[template.get style.level default="h1" m.entities_encode=yes /] class="wp-block-awx-hero-title">
      [template.get content.title m.entities_encode=yes /]
    </[template.get style.level default="h1" m.entities_encode=yes /]>

    [if.not_empty template.content.text]
      <p class="wp-block-awx-hero-text">[template.get content.text m.entities_encode=yes /]</p>
    [/if.not_empty]

    <div class="wp-block-awx-hero-actions">
      [template.get content.innerblocks /]
    </div>
  </header>
RENDER
        )
      ],

      // 3. awx-split
      'awx-split' => [
        'slug' => 'awx-split',
        'title' => 'AWX Media & Text Split',
        'icon' => 'columns',
        'category' => 'common',
        'description' => 'Narrative 50/50 or 60/40 presentation section supporting alternating zig-zag storytelling.',
        'sfc' => self::build_sfc(
          'awx-split',
          'AWX Media & Text Split',
          'columns',
          'common',
          'Narrative 50/50 or 60/40 presentation section supporting alternating zig-zag storytelling.',
          <<<CONTROLS
    [sections new]
      [name _value='section_content' /]
      [title _value="Split Content" /]
      [icon _value="edit" /]
      [fields new]
        {
          "type": "text",
          "name": "badge",
          "label": "Badge",
          "attr_name": "content.badge",
          "default": ""
        }
      [/fields]
      [fields new]
        {
          "type": "title",
          "name": "title",
          "label": "Title",
          "attr_name": "content.title",
          "default": ""
        }
      [/fields]
      [fields new]
        {
          "type": "textarea",
          "name": "text",
          "label": "Text",
          "attr_name": "content.text",
          "default": ""
        }
      [/fields]
      [fields new]
        {
          "type": "image",
          "name": "image_id",
          "label": "Image",
          "attr_name": "content.image_id",
          "default": 0
        }
      [/fields]
      [fields new]
        {
          "type": "innerblocks",
          "name": "slot",
          "label": "Supporting Content",
          "attr_name": "content.innerblocks"
        }
      [/fields]
    [/sections]

    [sections new]
      [name _value='section_appearance' /]
      [title _value="Appearance" /]
      [icon _value="admin-settings" /]
      [sidebar _value="true" /]
      [fields new]
        {
          "type": "select",
          "name": "layout",
          "label": "Split Ratio",
          "attr_name": "style.layout",
          "default": "is-split-50-50",
          "options": [
            { "label": "50 / 50", "value": "is-split-50-50" },
            { "label": "60 / 40", "value": "is-split-60-40" },
            { "label": "40 / 60", "value": "is-split-40-60" }
          ]
        }
      [/fields]
      [fields new]
        {
          "type": "select",
          "name": "order",
          "label": "Media Order",
          "attr_name": "style.order",
          "default": "media-right",
          "options": [
            { "label": "Media Right", "value": "media-right" },
            { "label": "Media Left", "value": "media-left" }
          ]
        }
      [/fields]
      [fields new]
        {
          "type": "select",
          "name": "level",
          "label": "Heading Level",
          "attr_name": "style.level",
          "default": "h2",
          "options": [
            { "label": "H2", "value": "h2" },
            { "label": "H3", "value": "h3" }
          ]
        }
      [/fields]
    [/sections]
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-split {
      display: grid;
      gap: var(--awx-space-12);
      align-items: center;
      grid-template-columns: 1fr;
    }
    @media (min-width: 768px) {
      .wp-block-dgb-awx-split.is-split-50-50 { grid-template-columns: 1fr 1fr; }
      .wp-block-dgb-awx-split.is-split-60-40 { grid-template-columns: 3fr 2fr; }
      .wp-block-dgb-awx-split.is-split-40-60 { grid-template-columns: 2fr 3fr; }
      .wp-block-dgb-awx-split.is-order-media-left > .wp-block-awx-split-media { order: -1; }
    }
    .wp-block-dgb-awx-split .wp-block-awx-split-media img {
      width: 100%;
      height: auto;
      border-radius: var(--awx-radius-lg);
      box-shadow: var(--awx-shadow-2);
    }
    .wp-block-dgb-awx-split .wp-block-awx-split-content {
      display: flex;
      flex-direction: column;
      gap: var(--awx-space-4);
    }
    .wp-block-dgb-awx-split .wp-block-awx-split-title {
      font-size: var(--awx-font-size-6);
      line-height: var(--awx-leading-tight);
      margin: 0;
    }
    .wp-block-dgb-awx-split .wp-block-awx-split-text {
      font-size: var(--awx-font-size-1);
      color: var(--awx-color-text-muted);
      line-height: var(--awx-leading-relaxed);
      margin: 0;
    }
  }
STYLES
          ,
          <<<RENDER
  <div [template.get wp.wrapper_attributes /]>
    <div class="wp-block-awx-split-content">
      [if.not_empty template.content.badge]
        <span class="wp-block-awx-badge">[template.get content.badge m.entities_encode=yes /]</span>
      [/if.not_empty]
      <[template.get style.level default="h2" m.entities_encode=yes /] class="wp-block-awx-split-title">
        [template.get content.title m.entities_encode=yes /]
      </[template.get style.level default="h2" m.entities_encode=yes /]>
      [if.not_empty template.content.text]
        <p class="wp-block-awx-split-text">[template.get content.text m.entities_encode=yes /]</p>
      [/if.not_empty]
      <div class="wp-block-awx-split-slot">
        [template.get content.innerblocks /]
      </div>
    </div>
    <div class="wp-block-awx-split-media">
      [if.not_empty template.content.image_id]
        [wp.attachment.image id="{template.content.image_id}" size="large" /]
      [/if.not_empty]
    </div>
  </div>
RENDER
        )
      ],

      // 4. awx-feature-grid
      'awx-feature-grid' => [
        'slug' => 'awx-feature-grid',
        'title' => 'AWX Feature Grid',
        'icon' => 'grid-view',
        'category' => 'common',
        'description' => 'Standard multi-column container for capabilities, features, and service cards.',
        'sfc' => self::build_sfc(
          'awx-feature-grid',
          'AWX Feature Grid',
          'grid-view',
          'common',
          'Standard multi-column container for capabilities, features, and service cards.',
          <<<CONTROLS
    [sections new]
      [name _value='section_content' /]
      [title _value="Grid Items" /]
      [icon _value="edit" /]
      [fields new]
        {
          "type": "innerblocks",
          "name": "grid_cards",
          "label": "Feature Cards Slot",
          "attr_name": "content.innerblocks"
        }
      [/fields]
    [/sections]

    [sections new]
      [name _value='section_appearance' /]
      [title _value="Appearance" /]
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
          "type": "select",
          "name": "layout",
          "label": "Layout Mode",
          "attr_name": "style.layout",
          "default": "standard",
          "options": [
            { "label": "Standard Grid", "value": "standard" },
            { "label": "Bento Grid", "value": "bento" }
          ]
        }
      [/fields]
    [/sections]
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-feature-grid {
      display: grid;
      gap: var(--awx-space-8);
      grid-template-columns: 1fr;
    }
    @media (min-width: 640px) {
      .wp-block-dgb-awx-feature-grid.has-cols-2 { grid-template-columns: repeat(2, 1fr); }
      .wp-block-dgb-awx-feature-grid.has-cols-3 { grid-template-columns: repeat(2, 1fr); }
      .wp-block-dgb-awx-feature-grid.has-cols-4 { grid-template-columns: repeat(2, 1fr); }
    }
    @media (min-width: 1024px) {
      .wp-block-dgb-awx-feature-grid.has-cols-3 { grid-template-columns: repeat(3, 1fr); }
      .wp-block-dgb-awx-feature-grid.has-cols-4 { grid-template-columns: repeat(4, 1fr); }
      .wp-block-dgb-awx-feature-grid.is-bento {
        grid-template-columns: repeat(3, 1fr);
      }
      .wp-block-dgb-awx-feature-grid.is-bento > *:first-child {
        grid-column: span 2;
      }
    }
  }
STYLES
          ,
          <<<RENDER
  <div [template.get wp.wrapper_attributes /]>
    [template.get content.innerblocks /]
  </div>
RENDER
        )
      ],

      // 5. awx-post-grid
      'awx-post-grid' => [
        'slug' => 'awx-post-grid',
        'title' => 'AWX Dynamic Query Grid',
        'icon' => 'excerpt-view',
        'category' => 'common',
        'description' => 'Dynamic database query engine for blogs, archives, and custom post types.',
        'sfc' => self::build_sfc(
          'awx-post-grid',
          'AWX Dynamic Query Grid',
          'excerpt-view',
          'common',
          'Dynamic database query engine for blogs, archives, and custom post types.',
          <<<CONTROLS
    [sections new]
      [name _value='section_query' /]
      [title _value="Query Settings" /]
      [icon _value="search" /]
      [fields new]
        {
          "type": "text",
          "name": "post_type",
          "label": "Post Type",
          "attr_name": "content.post_type",
          "default": "post"
        }
      [/fields]
      [fields new]
        {
          "type": "number",
          "name": "count",
          "label": "Posts Limit",
          "attr_name": "content.count",
          "default": 3
        }
      [/fields]
    [/sections]

    [sections new]
      [name _value='section_appearance' /]
      [title _value="Appearance" /]
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
    [/sections]
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-post-grid {
      display: grid;
      gap: var(--awx-space-6);
      grid-template-columns: 1fr;
    }
    @media (min-width: 640px) {
      .wp-block-dgb-awx-post-grid.has-cols-2 { grid-template-columns: repeat(2, 1fr); }
      .wp-block-dgb-awx-post-grid.has-cols-3 { grid-template-columns: repeat(2, 1fr); }
      .wp-block-dgb-awx-post-grid.has-cols-4 { grid-template-columns: repeat(2, 1fr); }
    }
    @media (min-width: 1024px) {
      .wp-block-dgb-awx-post-grid.has-cols-3 { grid-template-columns: repeat(3, 1fr); }
      .wp-block-dgb-awx-post-grid.has-cols-4 { grid-template-columns: repeat(4, 1fr); }
    }
    .wp-block-dgb-awx-post-grid .wp-block-awx-card {
      background: var(--awx-color-surface-raised);
      border: var(--awx-border-thin) solid var(--awx-color-border);
      border-radius: var(--awx-radius-md);
      padding: var(--awx-space-6);
      display: flex;
      flex-direction: column;
      gap: var(--awx-space-3);
      transition: transform var(--awx-duration-fast) var(--awx-ease-standard),
                  box-shadow var(--awx-duration-fast) var(--awx-ease-standard);
    }
    .wp-block-dgb-awx-post-grid .wp-block-awx-card:hover {
      box-shadow: var(--awx-shadow-2);
      transform: translateY(-2px);
    }
    .wp-block-dgb-awx-post-grid .wp-block-awx-card-title {
      font-size: var(--awx-font-size-4);
      margin: 0;
    }
    .wp-block-dgb-awx-post-grid .wp-block-awx-card-title a {
      color: var(--awx-color-text);
      text-decoration: none;
    }
    .wp-block-dgb-awx-post-grid .wp-block-awx-card-title a:hover {
      color: var(--awx-color-primary);
    }
    .wp-block-dgb-awx-post-grid .wp-block-awx-card-excerpt {
      font-size: var(--awx-font-size-0);
      color: var(--awx-color-text-muted);
      line-height: var(--awx-leading-relaxed);
      margin: 0;
      flex-grow: 1;
    }
  }
STYLES
          ,
          <<<RENDER
  <div [template.get wp.wrapper_attributes /]>
    [template.get content.post_type default="post" o.set="template.ptype" /]
    [template.get content.count default="6" o.set="template.pcount" /]
    [env.get db.prefix o.set="template.current_prefix" /]

    [mysqli.multi.fetch.rows o.set="template.posts"]
      SET @ptype = [esc.str template.ptype /];
      SELECT ID, post_title, post_date, post_excerpt
      FROM [template.get current_prefix /]posts
      WHERE post_type = @ptype AND post_status = 'publish'
      ORDER BY post_date DESC
      LIMIT [esc.int template.pcount /];
    [/mysqli.multi.fetch.rows]

    [loop.@p template.posts.rows]
      <article class="wp-block-awx-card">
        <h3 class="wp-block-awx-card-title">
          <a href="[wp.post.permalink id="{@p.item.ID}" /]">[env.get @p.item.post_title m.entities_encode=yes /]</a>
        </h3>
        <p class="wp-block-awx-card-excerpt">[env.get @p.item.post_excerpt m.entities_encode=yes /]</p>
      </article>
    [/loop.@p]
  </div>
RENDER
        )
      ],

      // 6. awx-pricing
      'awx-pricing' => [
        'slug' => 'awx-pricing',
        'title' => 'AWX Pricing Matrix',
        'icon' => 'money-alt',
        'category' => 'common',
        'description' => 'Commercial comparison table with structured tier cards and feature checkmarks.',
        'sfc' => self::build_sfc(
          'awx-pricing',
          'AWX Pricing Matrix',
          'money-alt',
          'common',
          'Commercial comparison table with structured tier cards and feature checkmarks.',
          <<<CONTROLS
    [sections new]
      [name _value='section_tiers' /]
      [title _value="Pricing Tiers" /]
      [icon _value="editor-table" /]
      [fields new]
        {
          "type": "row_repeater",
          "name": "tiers",
          "label": "Pricing Tiers",
          "attr_name": "content.items",
          "layout": "card",
          "repeater_fields": [
            { "type": "text", "name": "title", "label": "Tier Name", "default": "Starter" },
            { "type": "text", "name": "price", "label": "Price", "default": "$29/mo" },
            { "type": "text", "name": "badge", "label": "Badge", "default": "" },
            { "type": "toggle", "name": "featured", "label": "Featured Tier", "default": false },
            { "type": "text", "name": "button_text", "label": "Button Label", "default": "Get Started" },
            { "type": "text", "name": "url", "label": "Button URL", "default": "#" }
          ]
        }
      [/fields]
    [/sections]

    [sections new]
      [name _value='section_appearance' /]
      [title _value="Appearance" /]
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
    [/sections]
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-pricing {
      display: grid;
      gap: var(--awx-space-6);
      grid-template-columns: 1fr;
    }
    @media (min-width: 768px) {
      .wp-block-dgb-awx-pricing.has-cols-2 { grid-template-columns: repeat(2, 1fr); }
      .wp-block-dgb-awx-pricing.has-cols-3 { grid-template-columns: repeat(3, 1fr); }
      .wp-block-dgb-awx-pricing.has-cols-4 { grid-template-columns: repeat(4, 1fr); }
    }
    .wp-block-dgb-awx-pricing .wp-block-awx-pricing-card {
      background: var(--awx-color-surface);
      border: var(--awx-border-thin) solid var(--awx-color-border);
      border-radius: var(--awx-radius-lg);
      padding: var(--awx-space-8);
      display: flex;
      flex-direction: column;
      position: relative;
    }
    .wp-block-dgb-awx-pricing .wp-block-awx-pricing-card.is-featured {
      border-color: var(--awx-color-primary);
      border-width: var(--awx-border-thick);
      box-shadow: var(--awx-shadow-3);
    }
    .wp-block-dgb-awx-pricing .wp-block-awx-pricing-badge {
      position: absolute;
      top: calc(var(--awx-space-3) * -1);
      left: 50%;
      transform: translateX(-50%);
      background: var(--awx-color-primary);
      color: var(--awx-color-on-primary);
      font-size: var(--awx-font-size--1);
      font-weight: var(--awx-font-weight-semibold);
      padding: var(--awx-space-1) var(--awx-space-3);
      border-radius: var(--awx-radius-full);
    }
    .wp-block-dgb-awx-pricing .wp-block-awx-pricing-name {
      font-size: var(--awx-font-size-5);
      margin: 0;
    }
    .wp-block-dgb-awx-pricing .wp-block-awx-pricing-cost {
      font-size: var(--awx-font-size-7);
      font-weight: var(--awx-font-weight-bold);
      margin-block: var(--awx-space-4);
    }
    .wp-block-dgb-awx-pricing .wp-block-awx-pricing-features {
      list-style: none;
      padding: 0;
      margin: 0 0 var(--awx-space-6) 0;
      flex-grow: 1;
      display: flex;
      flex-direction: column;
      gap: var(--awx-space-2);
    }
    .wp-block-dgb-awx-pricing .wp-block-awx-pricing-features li {
      font-size: var(--awx-font-size-0);
      color: var(--awx-color-text-muted);
    }
  }
STYLES
          ,
          <<<RENDER
  <div [template.get wp.wrapper_attributes /]>
    [loop.@tier template.content.items]
      <div class="wp-block-awx-pricing-card [if.not_empty @tier.item.featured]is-featured[/if.not_empty]">
        [if.not_empty @tier.item.badge]
          <span class="wp-block-awx-pricing-badge">[env.get @tier.item.badge m.entities_encode=yes /]</span>
        [/if.not_empty]
        <h3 class="wp-block-awx-pricing-name">[env.get @tier.item.title m.entities_encode=yes /]</h3>
        <div class="wp-block-awx-pricing-cost">[env.get @tier.item.price m.entities_encode=yes /]</div>
        <ul class="wp-block-awx-pricing-features">
          [loop.@feat @tier.item.features]
            <li>[env.get @feat.item m.entities_encode=yes /]</li>
          [/loop.@feat]
        </ul>
        <a href="[env.get @tier.item.url m.entities_encode=yes /]" class="wp-block-awx-button [if.not_empty @tier.item.featured]is-primary[/if.not_empty][if.empty @tier.item.featured]is-secondary[/if.empty]">
          [env.get @tier.item.button_text default="Select Plan" m.entities_encode=yes /]
        </a>
      </div>
    [/loop.@tier]
  </div>
RENDER
        )
      ],

      // 7. awx-accordion
      'awx-accordion' => [
        'slug' => 'awx-accordion',
        'title' => 'AWX Accordion / FAQ',
        'icon' => 'list-view',
        'category' => 'common',
        'description' => 'Zero-JS semantic accordion leveraging native details and summary HTML elements.',
        'sfc' => self::build_sfc(
          'awx-accordion',
          'AWX Accordion / FAQ',
          'list-view',
          'common',
          'Zero-JS semantic accordion leveraging native details and summary HTML elements.',
          <<<CONTROLS
    [sections new]
      [name _value='section_faq' /]
      [title _value="FAQ Items" /]
      [icon _value="editor-help" /]
      [fields new]
        {
          "type": "row_repeater",
          "name": "faq_items",
          "label": "Accordion Items",
          "attr_name": "content.items",
          "layout": "card",
          "repeater_fields": [
            { "type": "text", "name": "title", "label": "Question / Summary", "default": "Frequently Asked Question" },
            { "type": "textarea", "name": "text", "label": "Answer / Content", "default": "Detailed answer explaining the solution." }
          ]
        }
      [/fields]
    [/sections]
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-accordion {
      display: flex;
      flex-direction: column;
      gap: var(--awx-space-4);
      width: 100%;
      max-width: var(--awx-width-content);
      margin-inline: auto;
    }
    .wp-block-dgb-awx-accordion details {
      border: var(--awx-border-thin) solid var(--awx-color-border);
      border-radius: var(--awx-radius-md);
      background-color: var(--awx-color-surface);
      padding: var(--awx-space-4) var(--awx-space-6);
      transition: background-color var(--awx-duration-normal) var(--awx-ease-standard);
    }
    .wp-block-dgb-awx-accordion details[open] {
      background-color: var(--awx-color-surface-raised);
    }
    .wp-block-dgb-awx-accordion summary {
      font-weight: var(--awx-font-weight-semibold);
      font-size: var(--awx-font-size-1);
      cursor: pointer;
      list-style: none;
      position: relative;
    }
    .wp-block-dgb-awx-accordion summary::-webkit-details-marker { display: none; }
    .wp-block-dgb-awx-accordion .wp-block-awx-accordion-answer {
      margin-top: var(--awx-space-4);
      color: var(--awx-color-text-muted);
      line-height: var(--awx-leading-relaxed);
    }
  }
STYLES
          ,
          <<<RENDER
  <div [template.get wp.wrapper_attributes /]>
    [loop.@faq template.content.items]
      <details class="wp-block-awx-accordion-item">
        <summary class="wp-block-awx-accordion-summary">[env.get @faq.item.title m.entities_encode=yes /]</summary>
        <div class="wp-block-awx-accordion-answer">
          [env.get @faq.item.text m.entities_encode=yes /]
        </div>
      </details>
    [/loop.@faq]
  </div>
RENDER
        )
      ],

      // 8. awx-testimonials
      'awx-testimonials' => [
        'slug' => 'awx-testimonials',
        'title' => 'AWX Testimonials',
        'icon' => 'testimonial',
        'category' => 'common',
        'description' => 'Social proof grid presenting quotations, avatars, reviewer titles, and star ratings.',
        'sfc' => self::build_sfc(
          'awx-testimonials',
          'AWX Testimonials',
          'testimonial',
          'common',
          'Social proof grid presenting quotations, avatars, reviewer titles, and star ratings.',
          <<<CONTROLS
    [sections new]
      [name _value='section_testimonials' /]
      [title _value="Quotes" /]
      [icon _value="format-quote" /]
      [fields new]
        {
          "type": "row_repeater",
          "name": "testimonials",
          "label": "Testimonial Cards",
          "attr_name": "content.items",
          "layout": "card",
          "repeater_fields": [
            { "type": "textarea", "name": "text", "label": "Quotation Text", "default": "Outstanding experience." },
            { "type": "text", "name": "title", "label": "Author Name", "default": "Jane Doe" },
            { "type": "text", "name": "role", "label": "Role / Company", "default": "Head of Product" },
            { "type": "image", "name": "image_id", "label": "Avatar", "default": 0 }
          ]
        }
      [/fields]
    [/sections]

    [sections new]
      [name _value='section_appearance' /]
      [title _value="Appearance" /]
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
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-testimonials {
      display: grid;
      gap: var(--awx-space-6);
      grid-template-columns: 1fr;
    }
    @media (min-width: 768px) {
      .wp-block-dgb-awx-testimonials.has-cols-2 { grid-template-columns: repeat(2, 1fr); }
      .wp-block-dgb-awx-testimonials.has-cols-3 { grid-template-columns: repeat(3, 1fr); }
    }
    .wp-block-dgb-awx-testimonials .wp-block-awx-testimonial-card {
      background: var(--awx-color-surface);
      border: var(--awx-border-thin) solid var(--awx-color-border);
      border-radius: var(--awx-radius-lg);
      padding: var(--awx-space-6);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      margin: 0;
    }
    .wp-block-dgb-awx-testimonials .wp-block-awx-testimonial-quote {
      font-size: var(--awx-font-size-1);
      line-height: var(--awx-leading-relaxed);
      margin: 0 0 var(--awx-space-6) 0;
      color: var(--awx-color-text);
      font-style: italic;
    }
    .wp-block-dgb-awx-testimonials .wp-block-awx-testimonial-meta {
      display: flex;
      align-items: center;
      gap: var(--awx-space-3);
    }
    .wp-block-dgb-awx-testimonials .wp-block-awx-testimonial-avatar img {
      width: 48px;
      height: 48px;
      border-radius: var(--awx-radius-full);
      object-fit: cover;
    }
    .wp-block-dgb-awx-testimonials .wp-block-awx-testimonial-author {
      font-weight: var(--awx-font-weight-semibold);
      font-style: normal;
      display: block;
    }
    .wp-block-dgb-awx-testimonials .wp-block-awx-testimonial-role {
      font-size: var(--awx-font-size--1);
      color: var(--awx-color-text-muted);
      display: block;
    }
  }
STYLES
          ,
          <<<RENDER
  <div [template.get wp.wrapper_attributes /]>
    [loop.@t template.content.items]
      <blockquote class="wp-block-awx-testimonial-card">
        <p class="wp-block-awx-testimonial-quote">“[env.get @t.item.text m.entities_encode=yes /]”</p>
        <footer class="wp-block-awx-testimonial-meta">
          [if.not_empty @t.item.image_id]
            <div class="wp-block-awx-testimonial-avatar">
              [wp.attachment.image id="{@t.item.image_id}" size="thumbnail" /]
            </div>
          [/if.not_empty]
          <div>
            <cite class="wp-block-awx-testimonial-author">[env.get @t.item.title m.entities_encode=yes /]</cite>
            <span class="wp-block-awx-testimonial-role">[env.get @t.item.role m.entities_encode=yes /]</span>
          </div>
        </footer>
      </blockquote>
    [/loop.@t]
  </div>
RENDER
        )
      ],

      // 9. awx-cta
      'awx-cta' => [
        'slug' => 'awx-cta',
        'title' => 'AWX Call to Action Strip',
        'icon' => 'megaphone',
        'category' => 'common',
        'description' => 'Full-surface conversion section enforcing high-contrast typography.',
        'sfc' => self::build_sfc(
          'awx-cta',
          'AWX Call to Action Strip',
          'megaphone',
          'common',
          'Full-surface conversion section enforcing high-contrast typography.',
          <<<CONTROLS
    [sections new]
      [name _value='section_content' /]
      [title _value="CTA Content" /]
      [icon _value="edit" /]
      [fields new]
        {
          "type": "title",
          "name": "title",
          "label": "Headline",
          "attr_name": "content.title",
          "default": "Take the Next Step"
        }
      [/fields]
      [fields new]
        {
          "type": "textarea",
          "name": "text",
          "label": "Supporting Text",
          "attr_name": "content.text",
          "default": ""
        }
      [/fields]
      [fields new]
        {
          "type": "innerblocks",
          "name": "actions",
          "label": "Call-to-Action Buttons Slot",
          "attr_name": "content.innerblocks"
        }
      [/fields]
    [/sections]

    [sections new]
      [name _value='section_appearance' /]
      [title _value="Appearance" /]
      [icon _value="admin-settings" /]
      [sidebar _value="true" /]
      [fields new]
        {
          "type": "select",
          "name": "surface",
          "label": "Surface",
          "attr_name": "style.surface",
          "default": "brand",
          "options": [
            { "label": "Brand", "value": "brand" },
            { "label": "Sunken", "value": "sunken" }
          ]
        }
      [/fields]
    [/sections]
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-cta {
      padding: var(--awx-space-12) var(--awx-space-6);
      border-radius: var(--awx-radius-xl);
      text-align: center;
    }
    .wp-block-dgb-awx-cta.is-surface-brand {
      background-color: var(--awx-color-surface-brand);
      color: var(--awx-color-on-primary);
    }
    .wp-block-dgb-awx-cta.is-surface-sunken {
      background-color: var(--awx-color-surface-sunken);
      color: var(--awx-color-text);
    }
    .wp-block-dgb-awx-cta .wp-block-awx-cta-title {
      font-size: var(--awx-font-size-7);
      margin: 0 0 var(--awx-space-3) 0;
    }
    .wp-block-dgb-awx-cta .wp-block-awx-cta-text {
      font-size: var(--awx-font-size-1);
      max-width: 60ch;
      margin: 0 auto var(--awx-space-6) auto;
      opacity: 0.9;
    }
    .wp-block-dgb-awx-cta .wp-block-awx-cta-actions {
      display: flex;
      justify-content: center;
      gap: var(--awx-space-4);
      flex-wrap: wrap;
    }
  }
STYLES
          ,
          <<<RENDER
  <div [template.get wp.wrapper_attributes /]>
    <div class="wp-block-awx-cta-inner">
      <h2 class="wp-block-awx-cta-title">[template.get content.title m.entities_encode=yes /]</h2>
      [if.not_empty template.content.text]
        <p class="wp-block-awx-cta-text">[template.get content.text m.entities_encode=yes /]</p>
      [/if.not_empty]
      <div class="wp-block-awx-cta-actions">
        [template.get content.innerblocks /]
      </div>
    </div>
  </div>
RENDER
        )
      ],

      // 10. awx-stats
      'awx-stats' => [
        'slug' => 'awx-stats',
        'title' => 'AWX Metrics & Stats',
        'icon' => 'chart-bar',
        'category' => 'common',
        'description' => 'Numeric credibility strip displaying bold figures with descriptive subtext.',
        'sfc' => self::build_sfc(
          'awx-stats',
          'AWX Metrics & Stats',
          'chart-bar',
          'common',
          'Numeric credibility strip displaying bold figures with descriptive subtext.',
          <<<CONTROLS
    [sections new]
      [name _value='section_metrics' /]
      [title _value="Key Metrics" /]
      [icon _value="chart-bar" /]
      [fields new]
        {
          "type": "row_repeater",
          "name": "stats",
          "label": "KPI Figures",
          "attr_name": "content.items",
          "layout": "table",
          "repeater_fields": [
            { "type": "text", "name": "title", "label": "Metric Value", "default": "99.9%" },
            { "type": "text", "name": "text", "label": "Metric Label", "default": "Uptime SLA" }
          ]
        }
      [/fields]
    [/sections]

    [sections new]
      [name _value='section_appearance' /]
      [title _value="Appearance" /]
      [icon _value="admin-settings" /]
      [sidebar _value="true" /]
      [fields new]
        {
          "type": "select",
          "name": "columns",
          "label": "Columns",
          "attr_name": "style.columns",
          "default": "4",
          "options": [
            { "label": "2 Columns", "value": "2" },
            { "label": "3 Columns", "value": "3" },
            { "label": "4 Columns", "value": "4" }
          ]
        }
      [/fields]
    [/sections]
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-stats {
      display: grid;
      gap: var(--awx-space-6);
      grid-template-columns: repeat(2, 1fr);
      text-align: center;
    }
    @media (min-width: 768px) {
      .wp-block-dgb-awx-stats.has-cols-3 { grid-template-columns: repeat(3, 1fr); }
      .wp-block-dgb-awx-stats.has-cols-4 { grid-template-columns: repeat(4, 1fr); }
    }
    .wp-block-dgb-awx-stats .wp-block-awx-stat-item {
      display: flex;
      flex-direction: column;
      gap: var(--awx-space-1);
    }
    .wp-block-dgb-awx-stats .wp-block-awx-stat-value {
      font-size: var(--awx-font-size-7);
      font-weight: var(--awx-font-weight-bold);
      color: var(--awx-color-primary);
      line-height: var(--awx-leading-tight);
    }
    .wp-block-dgb-awx-stats .wp-block-awx-stat-label {
      font-size: var(--awx-font-size--1);
      color: var(--awx-color-text-muted);
      text-transform: uppercase;
      letter-spacing: var(--awx-tracking-wide);
    }
  }
STYLES
          ,
          <<<RENDER
  <div [template.get wp.wrapper_attributes /]>
    [loop.@stat template.content.items]
      <div class="wp-block-awx-stat-item">
        <span class="wp-block-awx-stat-value">[env.get @stat.item.title m.entities_encode=yes /]</span>
        <span class="wp-block-awx-stat-label">[env.get @stat.item.text m.entities_encode=yes /]</span>
      </div>
    [/loop.@stat]
  </div>
RENDER
        )
      ],

      // 11. awx-logo-cloud
      'awx-logo-cloud' => [
        'slug' => 'awx-logo-cloud',
        'title' => 'AWX Logo Cloud',
        'icon' => 'images-alt2',
        'category' => 'common',
        'description' => 'Client and partner logo strip with responsive auto-wrapping and CSS grayscale hover effects.',
        'sfc' => self::build_sfc(
          'awx-logo-cloud',
          'AWX Logo Cloud',
          'images-alt2',
          'common',
          'Client and partner logo strip with responsive auto-wrapping and CSS grayscale hover effects.',
          <<<CONTROLS
    [sections new]
      [name _value='section_logos' /]
      [title _value="Partner Logos" /]
      [icon _value="images-alt2" /]
      [fields new]
        {
          "type": "row_repeater",
          "name": "logos",
          "label": "Logos List",
          "attr_name": "content.items",
          "layout": "table",
          "repeater_fields": [
            { "type": "image", "name": "image_id", "label": "Logo Image", "default": 0 },
            { "type": "text", "name": "url", "label": "Destination URL", "default": "" }
          ]
        }
      [/fields]
    [/sections]
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-logo-cloud {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      align-items: center;
      gap: var(--awx-space-8);
      padding-block: var(--awx-space-4);
    }
    .wp-block-dgb-awx-logo-cloud .wp-block-awx-logo-item img {
      max-height: 2.5rem;
      width: auto;
      filter: grayscale(100%);
      opacity: 0.7;
      transition: opacity var(--awx-duration-fast) var(--awx-ease-standard),
                  filter var(--awx-duration-fast) var(--awx-ease-standard);
    }
    .wp-block-dgb-awx-logo-cloud .wp-block-awx-logo-item img:hover {
      filter: grayscale(0%);
      opacity: 1;
    }
  }
STYLES
          ,
          <<<RENDER
  <div [template.get wp.wrapper_attributes /]>
    [loop.@logo template.content.items]
      <div class="wp-block-awx-logo-item">
        [if.not_empty @logo.item.url]
          <a href="[env.get @logo.item.url m.entities_encode=yes /]" target="_blank" rel="noopener">
            [wp.attachment.image id="{@logo.item.image_id}" size="medium" /]
          </a>
        [/if.not_empty]  
        [if.else]
          [wp.attachment.image id="{@logo.item.image_id}" size="medium" /]
        [/if.else]
      </div>
    [/loop.@logo]
  </div>
RENDER
        )
      ],

      // 12. awx-steps
      'awx-steps' => [
        'slug' => 'awx-steps',
        'title' => 'AWX Process Steps',
        'icon' => 'randomize',
        'category' => 'common',
        'description' => 'Sequential numbered roadmap explaining workflows or onboarding stages.',
        'sfc' => self::build_sfc(
          'awx-steps',
          'AWX Process Steps',
          'randomize',
          'common',
          'Sequential numbered roadmap explaining workflows or onboarding stages.',
          <<<CONTROLS
    [sections new]
      [name _value='section_steps' /]
      [title _value="Steps" /]
      [icon _value="editor-ol" /]
      [fields new]
        {
          "type": "row_repeater",
          "name": "steps",
          "label": "Process Steps",
          "attr_name": "content.items",
          "layout": "card",
          "repeater_fields": [
            { "type": "text", "name": "index", "label": "Step Counter", "default": "01" },
            { "type": "text", "name": "title", "label": "Step Headline", "default": "Connect Workflow" },
            { "type": "textarea", "name": "text", "label": "Step Narrative", "default": "Authorize integration in seconds." }
          ]
        }
      [/fields]
    [/sections]

    [sections new]
      [name _value='section_appearance' /]
      [title _value="Appearance" /]
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
CONTROLS
          ,
          <<<STYLES
  @layer blocks {
    .wp-block-dgb-awx-steps {
      display: grid;
      gap: var(--awx-space-8);
      grid-template-columns: 1fr;
    }
    @media (min-width: 768px) {
      .wp-block-dgb-awx-steps.has-cols-2 { grid-template-columns: repeat(2, 1fr); }
      .wp-block-dgb-awx-steps.has-cols-3 { grid-template-columns: repeat(3, 1fr); }
    }
    .wp-block-dgb-awx-steps .wp-block-awx-step-card {
      border-left: var(--awx-border-thick) solid var(--awx-color-border);
      padding-left: var(--awx-space-4);
      display: flex;
      flex-direction: column;
      gap: var(--awx-space-2);
    }
    .wp-block-dgb-awx-steps .wp-block-awx-step-counter {
      font-size: var(--awx-font-size-5);
      font-weight: var(--awx-font-weight-bold);
      color: var(--awx-color-primary);
      line-height: var(--awx-leading-tight);
    }
    .wp-block-dgb-awx-steps .wp-block-awx-step-title {
      font-size: var(--awx-font-size-4);
      margin: 0;
    }
    .wp-block-dgb-awx-steps .wp-block-awx-step-text {
      font-size: var(--awx-font-size-0);
      color: var(--awx-color-text-muted);
      line-height: var(--awx-leading-relaxed);
      margin: 0;
    }
  }
STYLES
          ,
          <<<RENDER
  <div [template.get wp.wrapper_attributes /]>
    [loop.@step template.content.items]
      <div class="wp-block-awx-step-card">
        <span class="wp-block-awx-step-counter">[env.get @step.index /]</span>
        <h3 class="wp-block-awx-step-title">[env.get @step.item.title m.entities_encode=yes /]</h3>
        <p class="wp-block-awx-step-text">[env.get @step.item.text m.entities_encode=yes /]</p>
      </div>
    [/loop.@step]
  </div>
RENDER
        )
      ]
    ];
  }

  /**
   * Helper to assemble a complete Single-File Component (SFC) string.
   */
  private static function build_sfc(
    string $slug,
    string $title,
    string $icon,
    string $category,
    string $description,
    string $controls_dsl,
    string $styles_css,
    string $render_dsl
  ): string {
    return <<<SFC
[templates.add register]
  [gt_blocks.register]
    [config]
      [name]{$slug}[/name]
      [title]{$title}[/title]
      [icon]{$icon}[/icon]
      [category]{$category}[/category]
      [description]{$description}[/description]
    [/config]
    [render_service   _value="gb_blocks.{$slug}.render" /]
    [controls_service _value="gb_blocks.{$slug}.controls" /]
    [styles_service   _value="gb_blocks.{$slug}.styles" /]
  [/gt_blocks.register]
[/templates.add]

[templates.add controls]
  [arr.create o.set='template.controls']
{$controls_dsl}
  [/arr.create]
  [template.return template.controls /]
[/templates.add]

[templates.add styles]
{$styles_css}
[/templates.add]

[templates.add render]
{$render_dsl}
[/templates.add]
SFC;
  }

  /**
   * Seeds the Core 12 blocks into the aw_gb_blocks custom post type.
   *
   * @param bool $force_update Whether to overwrite existing block posts
   * @return array Summary of operations [seeded => int, updated => int, skipped => int, errors => array]
   */
  public static function seed(bool $force_update = false): array
  {
    $results = ['seeded' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

    if (!function_exists('wp_insert_post')) {
      $results['errors'][] = 'WordPress environment not loaded (wp_insert_post unavailable).';
      return $results;
    }

    $blocks = self::get_blocks();

    foreach ($blocks as $slug => $data) {
      $existing = null;

      if (function_exists('get_posts')) {
        $posts = get_posts([
          'name' => $slug,
          'post_type' => 'aw_gb_blocks',
          'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
          'posts_per_page' => 1
        ]);
        if (!empty($posts)) {
          $existing = $posts[0];
        }
      }

      if (!$existing && function_exists('get_page_by_path')) {
        $existing = get_page_by_path($slug, OBJECT, 'aw_gb_blocks');
      }

      $post_data = [
        'post_title' => sanitize_text_field($data['title']),
        'post_name' => sanitize_title($slug),
        'post_type' => 'aw_gb_blocks',
        'post_status' => 'publish',
        'post_content' => $data['sfc'],
        'post_excerpt' => sanitize_text_field($data['description']),
      ];

      if ($existing) {
        if ($force_update) {
          $post_data['ID'] = $existing->ID;
          $res = wp_update_post($post_data, true);
          if (is_wp_error($res)) {
            $results['errors'][] = "Failed updating {$slug}: " . $res->get_error_message();
          } else {
            if (function_exists('update_post_meta')) {
              update_post_meta($existing->ID, '_awx_core_block', 1);
            }
            $results['updated']++;
          }
        } else {
          $results['skipped']++;
        }
      } else {
        $res = wp_insert_post($post_data, true);
        if (is_wp_error($res)) {
          $results['errors'][] = "Failed inserting {$slug}: " . $res->get_error_message();
        } else {
          if (function_exists('update_post_meta')) {
            update_post_meta($res, '_awx_core_block', 1);
          }
          $results['seeded']++;
        }
      }
    }

    // Trigger cache invalidation and synchronous asset recompilation
    if (function_exists('aw2\gutenberg_blocks\dgb')) {
      $dgb = dgb();
      $dgb->invalidate_registry();
      $dgb->build_registry();
    }

    return $results;
  }
}

// Global class alias for root-namespace access
if (!class_exists('AWX_Core_Blocks_Seeder', false)) {
  class_alias('aw2\gutenberg_blocks\AWX_Core_Blocks_Seeder', 'AWX_Core_Blocks_Seeder');
}
