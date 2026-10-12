# Execution Lifecycle & Processor Architecture

AwesomeEnterprise processes templates containing comments, shortcodes, and HTML markup into a unified stream.

## The 3-Step Execution Lifecycle
1. **Comment Removal**: All comments (`//* ... *//`) are stripped out before execution.
2. **First-Level Shortcode Execution**: Every top-level shortcode is evaluated, resolved, and replaced with its text output.
3. **Stream Output**: The parsed text with evaluated shortcodes is returned.

## Shortcode Execution Order (4 Stages)
Every shortcode runs through four sequential processor stages:
1. **Conditions Processor (`c.*`, `and.*`, `or.*`)**: Evaluates conditional attributes. If condition fails, execution halts immediately and returns empty string.
2. **Service Processor**: Executes the core service logic with provided attributes and inner content.
3. **Modifiers Processor (`m.*`, `m2.*`)**: Sequentially transforms the returned value (e.g., lowercase, json_encode, math).
4. **Outputters Processor (`o.*`, `o2.*`)**: Directs the modified value (e.g., `o.set="path"` saves to environment; `o.destroy="yes"` discards output; if omitted, value prints directly).
