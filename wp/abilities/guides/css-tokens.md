# Awesome CSS Design System: The Token Model

The Awesome CSS Design System (`awx`) enforces a structured, two-layer design token architecture.

## The Two-Layer Token Architecture
1. **Primitives Layer**: Raw physical scale values (e.g. `brand-a.600`, `gray.100`, `spacing.4`). Primitives are internal palettes and measurements.
2. **Role / Semantic Tokens**: Purpose-bound tokens exposed to blocks and themes (e.g., `--awx-color-brand-primary`, `--awx-color-text-body`, `--awx-space-md`, `--awx-radius-sm`).

## Available Token Categories
- `color`: Semantic roles (surface, brand, text, border, status).
- `space`: Spacing scale (e.g., `xs`, `sm`, `md`, `lg`, `xl`, `2xl`).
- `fontSize`: Typography sizes (`sm`, `base`, `lg`, `xl`, `2xl`, etc.).
- `radii`: Border radiuses (`none`, `sm`, `md`, `lg`, `full`).
- `shadow`: Elevation presets (`sm`, `md`, `lg`, `xl`).

## Token Usage Rules
- **Never hardcode hex/rgb colors or pixel spacing in block templates.**
  - Bad: `style="color: #1e3a8a; padding: 16px;"`
  - Good: `class="awx-color-brand-primary awx-p-md"` or using CSS variables `var(--awx-color-brand-primary)`.
- The `awesome/template-validate` linter flags raw color codes and hardcoded measurements as warnings, directing you to the appropriate token.
- Fetch live site tokens anytime using `awesome/token-list`.
