# Dynamic Gutenberg Blocks (DGB)

A powerful, flexible PHP library for creating WordPress Gutenberg blocks dynamically from JSON configurations.

## Features

- 🚀 **JSON-Driven**: Define blocks entirely in JSON files
- 🎨 **Multiple Field Types**: Text, textarea, number, select, toggle, image, repeaters, and more
- 📑 **Tab Support**: Organize complex blocks with tabbed interfaces
- 🔧 **Template System**: Use inline templates or external PHP files
- 🎯 **Nested Attributes**: Support for dot-notation attribute paths (e.g., `content.title`)
- 🔄 **Repeater Fields**: Built-in support for attribute and row repeaters
- 💅 **Custom Styling**: Easy asset enqueueing per block
- 🌐 **Gutenberg v3 API**: Built on the latest block API

## Installation

1. Copy the plugin files to your WordPress plugins directory:
```
wp-content/plugins/dynamic-gutenberg-blocks/
```

2. Activate the plugin through the WordPress admin

3. Create your block JSON files in the `blocks/` directory

## Directory Structure

```
dynamic-gutenberg-blocks/
├── lib/
│   └── class-block-library.php   # Main library class
├── assets/
│   ├── js/
│   │   └── blocks.js              # Editor JavaScript
│   └── css/
│       ├── editor.css             # Editor styles
│       └── style.css              # Frontend styles
├── blocks/                        # Your block JSON files go here
│   ├── service-block.json
│   └── conditional-block.json
├── templates/                     # Optional PHP templates
│   └── conditional-block.php
└── dgb-init.php                   # Plugin initialization
```

## Creating a Block

### 1. Basic Block (JSON Only)

Create a file `blocks/my-block.json`:

```json
{
  "name": "my-block",
  "title": "My Custom Block",
  "icon": "admin-generic",
  "category": "widgets",
  "fields": [
    {
      "type": "text",
      "name": "title",
      "label": "Title",
      "attr_name": "title"
    },
    {
      "type": "textarea",
      "name": "content",
      "label": "Content",
      "attr_name": "content"
    }
  ],
  "template": "<div class=\"my-block\">\n  <h3>{{title}}</h3>\n  <p>{{content}}</p>\n</div>"
}
```

### 2. Block with Tabs

```json
{
  "name": "advanced-block",
  "title": "Advanced Block",
  "icon": "admin-tools",
  "category": "widgets",
  "tabs": [
    {
      "name": "content",
      "title": "Content",
      "icon": "edit",
      "fields": [
        {
          "type": "title",
          "name": "block-title",
          "label": "Title",
          "attr_name": "content.title"
        },
        {
          "type": "image",
          "name": "featured-image",
          "label": "Featured Image",
          "attr_name": "content.image"
        }
      ]
    },
    {
      "name": "settings",
      "title": "Settings",
      "icon": "admin-settings",
      "fields": [
        {
          "type": "select",
          "name": "layout",
          "label": "Layout",
          "attr_name": "settings.layout",
          "default": "standard",
          "options": [
            {"label": "Standard", "value": "standard"},
            {"label": "Card", "value": "card"}
          ]
        },
        {
          "type": "toggle",
          "name": "featured",
          "label": "Featured",
          "attr_name": "settings.featured",
          "default": false
        }
      ]
    }
  ],
  "template_file": "/path/to/templates/advanced-block.php"
}
```

## Available Field Types

### Basic Fields

- **text**: Single-line text input
- **textarea**: Multi-line text input
- **number**: Numeric input
- **small-number**: Compact numeric input with min/max
- **select**: Dropdown select
- **radio**: Radio button group (single selection)
- **checkbox**: Checkbox group (multiple selections)
- **single-checkbox**: Single checkbox (boolean)
- **toggle**: Boolean toggle switch
- **date**: Date picker

### Special Fields

- **title**: Pre-configured title field
- **purpose**: Pre-configured purpose/description field
- **image**: Media library image selector with alt text
- **service**: Service name input (for Awesome Enterprise)
- **awesome_code**: Code editor textarea
- **env_path**: Environment path input
- **query**: SQL query textarea

### Advanced Fields

- **attributes-repeater**: Dynamic key-value pairs with type selection
- **row_repeater**: Custom repeatable rows with defined fields
- **innerblocks**: Nested Gutenberg blocks

## Field Configuration Options

```json
{
  "type": "text",
  "name": "field-name",
  "label": "Field Label",
  "attr_name": "path.to.attribute",
  "default": "default value",
  "placeholder": "Enter text...",
  "validation": {
    "required": true,
    "min": 0,
    "max": 100
  }
}
```

## Template System

### Inline Templates

Use mustache-style syntax in the `template` property:

```html
<div class="my-block">
  <h2>{{content.title}}</h2>
  
  {{#if content.image}}
    <img src="{{content.image.url}}" alt="{{content.image.alt}}" />
  {{/if}}
  
  <p>{{content.description}}</p>
  
  {{#if settings.showList}}
    <ul>
      {{#each content.items}}
        <li>{{name}}: {{value}}</li>
      {{/each}}
    </ul>
  {{/if}}
</div>
```

### Template Syntax

- **Variables**: `{{variable}}` or `{{nested.variable}}`
- **Conditionals**: `{{#if variable}}...{{/if}}`
- **Loops**: `{{#each array}}...{{/each}}`
- **Content**: `{{_content}}` (renders inner blocks)

### External PHP Templates

For complex rendering, use a PHP template file:

```json
{
  "template_file": "/full/path/to/template.php"
}
```

Template file example (`templates/my-block.php`):

```php
<?php
// $data contains all field values
// $content contains inner blocks HTML
?>
<div class="my-block">
    <h2><?php echo esc_html($data['content']['title']); ?></h2>
    
    <?php if (!empty($data['content']['image'])): ?>
        <img src="<?php echo esc_url($data['content']['image']['url']); ?>" 
             alt="<?php echo esc_attr($data['content']['image']['alt']); ?>" />
    <?php endif; ?>
    
    <?php if (!empty($data['content']['attributes'])): ?>
        <ul>
            <?php foreach ($data['content']['attributes'] as $attr): ?>
                <li>
                    <strong><?php echo esc_html($attr['name']); ?>:</strong>
                    <?php echo esc_html($attr['value']); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    
    <?php if ($content): ?>
        <div class="inner-content">
            <?php echo $content; ?>
        </div>
    <?php endif; ?>
</div>
```

## Row Repeater Example

Define custom repeatable rows with specific fields:

Define custom repeatable rows with specific fields:

```json
{
  "type": "row_repeater",
  "name": "pricing-tiers",
  "label": "Pricing Tiers",
  "attr_name": "pricing.tiers",
  "repeater_fields": [
    {
      "type": "text",
      "name": "tier_name",
      "label": "Tier Name",
      "width": "200px"
    },
    {
      "type": "number",
      "name": "price",
      "label": "Price",
      "width": "100px"
    },
    {
      "type": "toggle",
      "name": "popular",
      "label": "Popular",
      "width": "80px"
    },
    {
      "type": "select",
      "name": "billing",
      "label": "Billing",
      "width": "120px",
      "options": [
        {"label": "Monthly", "value": "monthly"},
        {"label": "Yearly", "value": "yearly"}
      ]
    }
  ]
}
```

## Asset Enqueueing

Add custom scripts and styles per block:

```json
{
  "name": "interactive-block",
  "title": "Interactive Block",
  "enqueue_scripts": [
    {
      "handle": "my-block-js",
      "src": "https://example.com/assets/block.js",
      "deps": ["jquery"],
      "version": "1.0.0"
    }
  ],
  "enqueue_styles": [
    {
      "handle": "my-block-css",
      "src": "https://example.com/assets/block.css",
      "deps": [],
      "version": "1.0.0"
    }
  ]
}
```

## Advanced Configuration

### Block Supports

Control block features:

```json
{
  "supports": {
    "html": false,
    "align": ["wide", "full"],
    "alignWide": true,
    "className": true,
    "anchor": true,
    "customClassName": true,
    "color": {
      "background": true,
      "text": true
    },
    "spacing": {
      "padding": true,
      "margin": true
    }
  }
}
```

### Keywords for Search

```json
{
  "keywords": ["service", "business", "feature", "showcase"]
}
```

## PHP API

### Initialize the Library

```php
// In your plugin or theme
require_once 'path/to/lib/class-block-library.php';

\DynamicGutenbergBlocks\dgb()->init(
    '/path/to/blocks/',  // Directory containing JSON files
    'https://example.com/assets/'  // Assets URL (optional)
);
```

### Helper Functions

```php
// Create a block programmatically
dgb_create_block('my-block', [
    'title' => 'My Block',
    'icon' => 'admin-generic',
    'fields' => [
        [
            'type' => 'text',
            'name' => 'title',
            'label' => 'Title',
            'attr_name' => 'title'
        ]
    ],
    'template' => '<div>{{title}}</div>'
]);

// Get block configuration
$block = dgb_get_block('my-block');

// Get all registered blocks
$all_blocks = dgb_get_all_blocks();
```

## Complete Example: Service Block

**blocks/service-showcase.json**:

```json
{
  "name": "service-showcase",
  "title": "Service Showcase",
  "description": "Showcase your services with style",
  "icon": "portfolio",
  "category": "widgets",
  "keywords": ["service", "feature", "business"],
  "tabs": [
    {
      "name": "content",
      "title": "Content",
      "icon": "edit",
      "fields": [
        {
          "type": "title",
          "name": "service-title",
          "label": "Service Title",
          "attr_name": "content.title"
        },
        {
          "type": "textarea",
          "name": "description",
          "label": "Description",
          "attr_name": "content.description"
        },
        {
          "type": "image",
          "name": "icon",
          "label": "Service Icon",
          "attr_name": "content.icon"
        },
        {
          "type": "row_repeater",
          "name": "features",
          "label": "Features",
          "attr_name": "content.features",
          "repeater_fields": [
            {
              "type": "text",
              "name": "feature_name",
              "label": "Feature",
              "width": "250px"
            },
            {
              "type": "toggle",
              "name": "included",
              "label": "Included",
              "width": "80px"
            }
          ]
        }
      ]
    },
    {
      "name": "style",
      "title": "Style",
      "icon": "admin-appearance",
      "fields": [
        {
          "type": "select",
          "name": "layout",
          "label": "Layout Style",
          "attr_name": "style.layout",
          "default": "card",
          "options": [
            {"label": "Card", "value": "card"},
            {"label": "Minimal", "value": "minimal"},
            {"label": "Bordered", "value": "bordered"}
          ]
        },
        {
          "type": "select",
          "name": "alignment",
          "label": "Text Alignment",
          "attr_name": "style.alignment",
          "default": "left",
          "options": [
            {"label": "Left", "value": "left"},
            {"label": "Center", "value": "center"},
            {"label": "Right", "value": "right"}
          ]
        },
        {
          "type": "toggle",
          "name": "show-button",
          "label": "Show Action Button",
          "attr_name": "style.showButton",
          "default": true
        }
      ]
    },
    {
      "name": "advanced",
      "title": "Advanced",
      "icon": "admin-tools",
      "fields": [
        {
          "type": "attributes-repeater",
          "name": "custom-attributes",
          "label": "Custom Data Attributes",
          "attr_name": "advanced.dataAttributes"
        },
        {
          "type": "text",
          "name": "custom-class",
          "label": "Custom CSS Class",
          "attr_name": "advanced.customClass"
        }
      ]
    }
  ],
  "template": "<div class=\"service-showcase service-showcase--{{style.layout}} text-{{style.alignment}} {{advanced.customClass}}\">\n  {{#if content.icon}}\n    <div class=\"service-showcase__icon\">\n      <img src=\"{{content.icon.url}}\" alt=\"{{content.icon.alt}}\" />\n    </div>\n  {{/if}}\n  \n  <div class=\"service-showcase__content\">\n    <h3 class=\"service-showcase__title\">{{content.title}}</h3>\n    <p class=\"service-showcase__description\">{{content.description}}</p>\n    \n    {{#if content.features}}\n      <ul class=\"service-showcase__features\">\n        {{#each content.features}}\n          <li class=\"feature-item {{#if included}}included{{/if}}\">\n            {{#if included}}<span class=\"checkmark\">✓</span>{{/if}}\n            {{feature_name}}\n          </li>\n        {{/each}}\n      </ul>\n    {{/if}}\n    \n    {{#if style.showButton}}\n      <a href=\"#\" class=\"service-showcase__button\">Learn More</a>\n    {{/if}}\n  </div>\n</div>",
  "enqueue_styles": [
    {
      "handle": "service-showcase-style",
      "src": "/wp-content/plugins/dynamic-gutenberg-blocks/assets/css/service-showcase.css",
      "version": "1.0.0"
    }
  ]
}
```

## Tips & Best Practices

1. **Use Nested Attributes**: Organize complex blocks with dot notation (`content.title`, `settings.layout`)

2. **Validation**: Add validation rules to ensure data quality:
   ```json
   "validation": {
     "required": true,
     "min": 0,
     "max": 100
   }
   ```

3. **Default Values**: Always provide sensible defaults for better UX

4. **Template Organization**: Use external PHP files for complex rendering logic

5. **Asset Management**: Load scripts/styles only when needed per block

6. **Testing**: Test blocks with various content combinations

7. **Security**: All template variables are automatically escaped with `esc_html()`. Use PHP templates for complex escaping needs.

## Troubleshooting

### Block Not Appearing in Editor

- Check JSON syntax is valid
- Ensure `name` and `title` are present
- Verify blocks directory path is correct
- Check browser console for JavaScript errors

### Template Not Rendering

- Verify template syntax (mustache-style)
- Check attribute paths match field `attr_name` values
- For PHP templates, ensure file path is absolute

### Fields Not Saving

- Ensure `attr_name` is unique for each field
- Check field type is supported
- Verify attribute configuration in JSON

## License

GPL v2 or later

## Support

For issues and feature requests, please create an issue on the repository.