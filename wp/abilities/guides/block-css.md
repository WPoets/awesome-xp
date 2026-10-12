# Authoring Block CSS & Composing Gutenberg Layouts

When creating or modifying Dynamic Gutenberg Blocks (DGB), follow this strict hierarchy:

## Hierarchy of Styling
1. **Tokens (`awx`)**: Use core design token variables (`var(--awx-...)`) or token utility classes.
2. **Modifiers**: Add BEM-style modifier classes (e.g. `block-card--bordered`, `block-card--compact`) driven by block select/toggle controls.
3. **Patterns (`awesome/pattern-create`)**: Combine existing registered blocks into compound layouts rather than coding a monolith block.
4. **New Block (`awesome/block-create`)**: Build a custom block only when no pattern or combination can meet the requirements.

## Block Template Construction
Block templates combine HTML structure and Awesome Enterprise shortcodes:
```html
<div class="service-card service-card--{{style.layout}} awx-p-lg">
  [if.not_empty content.icon]
    <div class="service-card__icon awx-mb-sm">
      <span class="dashicons [env.get content.icon /]"></span>
    </div>
  [/if.not_empty]

  <h3 class="service-card__title awx-text-xl">[env.get content.title /]</h3>
  <p class="service-card__text awx-text-body">[env.get content.text /]</p>

  [if.not_empty content.button_text]
    <a href="[env.get content.url /]" class="awx-btn awx-btn-primary">
      [env.get content.button_text /]
    </a>
  [/if.not_empty]
</div>
```

## DGB Field Taxonomy
Always name fields using the **Universal Dictionary** names (`content.title`, `content.text`, `content.image`, `content.items`, `style.layout`). Query `awesome/dictionary-get` for full mappings.
