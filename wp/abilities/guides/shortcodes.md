# Awesome Enterprise Complete Shortcodes & Services Reference Guide

This document is an exhaustive, production-grade reference manual covering all shortcode services, processor attributes, syntax rules, execution semantics, and platform guardrails/limitations defined across the **Awesome XP** (`awesome-xp`) framework.

---

## Table of Contents

1. [Executive Summary & Skill Gap Review](#1-executive-summary--skill-gap-review)
2. [Core Processor Architecture & Execution Lifecycle](#2-core-processor-architecture--execution-lifecycle)
3. [Syntax, Typecasting & Variable Interpolation](#3-syntax-typecasting--variable-interpolation)
4. [Variable Scoping Hierarchy](#4-variable-scoping-hierarchy)
5. [Complete Core Lang Services Directory](#5-complete-core-lang-services-directory)
   - [Environment (`env`, `env2`)](#environment-env-env2)
   - [Lexical Block Scope (`do`)](#lexical-block-scope-do)
   - [Looping Iterators (`loop`, `loop.live_arr`, `loopv2`)](#looping-iterators-loop-looplive_arr-loopv2)
   - [Conditionals & Branching (`if`, `cond`)](#conditionals--branching-if-cond)
   - [While Loop Iterators (`while.arr`, `while.build`, `while.scalar`)](#while-loop-iterators-whilearr-whilebuild-whilescalar)
   - [Higher-Order Array Processors (`arr_each`)](#higher-order-array-processors-arr_each)
   - [Array Operations (`arr`, `arr.is`, `arr.has`, `arr.join`, `arr.to`, `arr_items`)](#array-operations-arr-arris-arrhas-arrjoin-arrto-arr_items)
   - [String Manipulation (`str`, `str.case`, `str.comp`, `str.needle`, `str.pad`, `str.slice`, `str.split`, `str.trim`)](#string-manipulation-str-strcase-strcomp-strneedle-strpad-strslice-strsplit-strtrim)
   - [Numeric Operations (`int`, `num`, `currency`, `math`)](#numeric-operations-int-num-currency-math)
   - [Boolean Library (`bool`)](#boolean-library-bool)
   - [Date & Time Library (`date`, `date.comp`)](#date--time-library-date-datecomp)
   - [Data Modeling & Validation (`email_id`, `regex`, `enum`, `taxonomy`, `validate`)](#data-modeling--validation-email_id-regex-enum-taxonomy-validate)
   - [Functions & Namespaces (`func`, `call`, `namespace`, `workflow`)](#functions--namespaces-func-call-namespace-workflow)
   - [Raw Objects, Code & System (`obj`, `code`, `content`, `php`, `aw2`, `aw2_error`)](#raw-objects-code--system-obj-code-content-php-aw2-aw2_error)
6. [Structure, Collections & Module Architecture](#6-structure-collections--module-architecture)
   - [Modules (`module`)](#modules-module)
   - [Templates (`template`, `templates`, `@template`)](#templates-template-templates-template)
   - [Collections & Apps (`collection`, `@collection`, `this_collection`, `app`)](#collections--apps-collection-collection-this_collection-app)
   - [Content Type Definitions (`content_type_def`, `@content_type`, `content_types`, `post_type`)](#content-type-definitions-content_type_def-content_type-content_types-post_type)
7. [Request, Response, Security & Utilities](#7-request-response-security--utilities)
   - [HTTP Request Sanitization (`request`, `request2`)](#http-request-sanitization-request-request2)
   - [SQL Escaping & Safety (`esc`)](#sql-escaping--safety-esc)
   - [Data Cleaning (`clean`) & Encoding (`encode`)](#data-cleaning-clean--encoding-encode)
   - [Cookies & Session Management (`cookie`, `session_ticket`, `vsession`)](#cookies--session-management-cookie-session_ticket-vsession)
   - [File System & Media (`file`, `aw2.upload`, `aw2.sideload`)](#file-system--media-file-aw2upload-aw2sideload)
   - [Utility Services (`unique`, `time`, `hashids`, `util`, `async_tickets`, `async_urls`)](#utility-services-unique-time-hashids-util-async_tickets-async_urls)
8. [Database Engine & Caching Services](#8-database-engine--caching-services)
   - [MySQL Database Queries (`mysqli`, `mysqli.fetch`, `mysqli.multi`, `mysqli.transaction`)](#mysql-database-queries-mysqli-mysqlifetch-mysqlimulti-mysqlitransaction)
   - [Multi-Tier Caching (`global_cache`, `session_cache`, `mysql.cache`, `redis`, `redis_db`)](#multi-tier-caching-global_cache-session_cache-mysqlcache-redis-redis_db)
9. [Front-End Assets, CSS & JS Pipeline](#9-front-end-assets-css--js-pipeline)
   - [Stylesheets (`css`, `less`, `minify`, `style`, `register.less_variables`)](#stylesheets-css-less-minify-style-registerless_variables)
   - [Scripts (`js`, `js.minify`, `js.run_on_activity`)](#scripts-js-jsminify-jsrun_on_activity)
   - [Trusted HTML (`html.trusted`)](#trusted-html-htmltrusted)
10. [Extra Handlers (Network, External APIs & Office)](#10-extra-handlers-network-external-apis--office)
    - [HTTP Networking (`curl`)](#http-networking-curl)
    - [Notifications & Email (`notify`, `aw2.push`)](#notifications--email-notify-aw2push)
    - [Debugging & Diagnostics (`debug`, `debug_cache`, `debugbar`, `live_debug`)](#debugging--diagnostics-debug-debug_cache-debugbar-live_debug)
    - [Document Generators (`excel`, `pdf`)](#document-generators-excel-pdf)
    - [Authentication & Identity (`google`, `jwt_token`)](#authentication--identity-google-jwt_token)
11. [WordPress & Gutenberg Integrations](#11-wordpress--gutenberg-integrations)
    - [WP Utilities (`wp.get`, `wp.menu`, `wp.signon`, `wp.image_resize`)](#wp-utilities-wpget-wpmenu-wpsignon-wpimage_resize)
    - [Blocks & Widgets (`gt_blocks`, `gb_blocks`, `acf_blocks`, `element_widgets`, `aw_elements`)](#blocks--widgets-gt_blocks-gb_blocks-acf_blocks-element_widgets-aw_elements)
    - [Posts, Queries & CLI (`posts.export`, `post_types.add`, `query.meta_query`, `multi.*`, `awcli.*`)](#posts-queries--cli-postsexport-post_typesadd-querymeta_query-multi-awcli)
12. [Processor Conditions (`c.*`), Modifiers (`m.*`), & Outputters (`o.*`)](#12-processor-conditions-c-modifiers-m--outputters-o)
13. [Platform Limitations, Guardrails & Common Pitfalls](#13-platform-limitations-guardrails--common-pitfalls)

---

## 1. Executive Summary & Skill Gap Review

The existing `awesome-enterprise` skill (`.agents/skills/awesome-enterprise/SKILL.md`) is strongly focused on high-level orchestration:
- Dynamic Gutenberg Blocks (DGB) SFC compiler standards (`aw_gb_blocks`)
- The 12 Foundation Blocks catalog (`awx-section`, `awx-hero`, `awx-split`, etc.)
- AWX Pattern Bridge (`wp_block`, metadata flags, JSON export/import)
- Declarative Website Assembly Language (WAL) compilation & decompilation
- 31 MCP Agent Abilities (`Abilities_Registry`)

### Gaps Identified in Existing Skill Files
1. **Omission of Core Lang Libraries**: The plugin implements over 450+ registered services across `core/core-handlers` and `core/extra-handlers`. The skill documents only a handful (`mysqli.multi.fetch.rows`, `template.get`, `curl.api.get`).
2. **Missing Advanced Logic Blocks**: Modern features such as `cond.block`, `cond.switch`, `while.arr`, `while.build`, and functional iterators (`arr_each.map`, `arr_each.filter`, `arr_each.reduce`) are completely absent from the skill.
3. **Missing Data Validation & Processing Libraries**: `regex`, `enum`, `taxonomy`, `currency`, `email_id`, `cookie`, `clean`, `unique`, and `validate.*` are omitted.
4. **Missing Multi-Tier Caching Documentation**: Redis (`redis_db`, `redis.*`), MySQL Cache (`mysql.cache.*`), Session Cache (`session_cache.*`), and Global Cache (`global_cache.*`) are not referenced.
5. **Partial Limitation Coverage**: Critical syntax constraints (such as nested attribute brackets, `{}` in content vs attributes, `LIMIT` variable bans, `template.get` auto-prefixing, and agent execution guardrails enforced by `Allowlist::is_service_allowed`) are not consolidated in one place.

This reference document bridges all of these gaps.

---

## 2. Core Processor Architecture & Execution Lifecycle

The Awesome Processor (`aw2_library`) interprets markup templates containing plain text, comments, and low-code shortcodes.

### The 3-Step Stream Parse Cycle
1. **Comment Stripping**: All comments enclosed in `//* ... *//` are stripped from the source before execution.
2. **First-Level Evaluation**: First-level shortcodes are parsed, evaluated sequentially, and replaced with their textual outputs.
3. **Stream Output**: Any untrapped output stream is emitted to the HTTP response or stored in memory.

### The 4-Stage Shortcode Lifecycle
Every individual shortcode executes through four distinct pipeline stages:

```
[tag c.* m.* o.*]
  │
  ├── Stage 1: Conditions Processor (c.*, and.*, or.*)
  │     Evaluates prerequisites. If condition evaluates to false, shortcode execution halts immediately.
  │
  ├── Stage 2: Service Processor (Service Tag Execution)
  │     Executes core service logic using tag attributes and inner content.
  │
  ├── Stage 3: Modifiers Processor (m.*, m2.*)
  │     Sequentially transforms the service return value (case changes, regex, formatting, json).
  │
  └── Stage 4: Outputters Processor (o.*, o2.*)
        Directs output: sets environment path (o.set), merges arrays (o.merge_with), echoes, or destroys (o.destroy).
```

---

## 3. Syntax, Typecasting & Variable Interpolation

### Shortcode Forms
- **Self-Closing**:
  ```html
  [str.create "Jane Doe" /]
  ```
- **Enclosing Content**:
  ```html
  [if.equal lhs="active" rhs="{user.status}"]
    <span class="badge">Active</span>
  [/if.equal]
  ```

### Datatype Castings
By default, all shortcode attribute values are treated as literal strings. Use explicit prefixes to cast values:
- `int:<val>`: Casts value to integer (e.g., `int:42`).
- `num:<val>` or `float:<val>`: Casts value to float (e.g., `num:19.99`).
- `str:<val>`: Casts value to string (e.g., `str:hello`).
- `bool:<val>`: Converts `"false"` to `false`, else casts to boolean.
- `comma:<a,b,c>`: Converts a comma-separated list into a string array.
- `get:<path>`: Fetches the value at the given environment path and preserves its native PHP data type.
- `x:<service>`: Executes an inline service call and preserves its native return type (e.g., `x:request2.get name`).

### Variable References & Attribute Boundaries
- **Single Curly Braces `{path.key}`**: Evaluates the environment variable path and inserts its value into an attribute.
- **Double Curly Braces `{{service.name}}`**: Executes the specified service call and inserts the result into an attribute.
- **CRITICAL BOUNDARY**: Curly braces `{}` and `{{}}` are **parsed strictly within shortcode attributes**. They are **never parsed within shortcode body content**. Inside inner content, always call full shortcodes such as `[env.get path /]` or `[template.get path /]`.

---

## 4. Variable Scoping Hierarchy

Awesome XP provides isolated memory tiers to prevent scope pollution:

```
Global Scope (env.*)
 └── Collection Scope (collection.*)
      └── Module Scope (module.*)
           └── Template Scope (template.*)
                └── Lexical Block Scope (do.@var)
```

1. **Global Environment (`env.*`)**:
   Global key-value repository available throughout the entire WordPress execution cycle.
2. **Module Scope (`module.*`)**:
   Active when executing a registered module. Arguments passed to a module (`[collection.module param1=val /]`) bind here. Access with `[module.get]`, `[module.set]`, and `[module.return]`.
3. **Template Scope (`template.*`)**:
   Scoped strictly to the local template execution context.
   > [!IMPORTANT]
   > `template.get` automatically prepends `template.` to the requested path. Calling `[template.get template.title /]` will incorrectly attempt to read `template.template.title`. Always call `[template.get title /]`.
4. **Lexical Block Scope (`do.*`)**:
   Enclosed in `[do.@var] ... [/do.@var]`. The local variable `@var` is created at block entry and automatically destroyed at `[/do.@var]`.
5. **Loop Scoping (`loop.@iterator`)**:
   Within array iterations, each item is placed under `.item`. Direct access like `{@iterator.name}` is **strictly forbidden**; use `{@iterator.item.name}` or `[env.@iterator.item.name /]`.

---

## 5. Complete Core Lang Services Directory

### Environment (`env`, `env2`)
- `[env.set key=val /]`: Sets one or more variables in environment.
- `[env.get path /]`: Retrieves value from path.
- `[env.set_value path="var" val="data" /]`: Sets a direct variable.
- `[env.set.key path="obj.key" val="val" /]`: Sets nested key.
- `[env.set_raw path]...[/env.set_raw]`: Stores raw unparsed markup/code.
- `[env.set_array path]...[/env.set_array]`: Constructs complex multidimensional array.
- `[env.set_obj path]...[/env.set_obj]`: Builds a PHP object instance.
- `[env.dump path /]`: Emits HTML `var_dump` of path.
- `[env.echo path /]`: Prints value via PHP `echo`.
- `[env.exists path /]`: Returns boolean true if path is set in env.
- `[env.path path /]`: Resolves environment path.
- `[env.set.path path val /]`, `[env.set.paths ...]`: Bulk path setters.
- `[env2.set path=val /]`, `[env2.get path /]`: Common env utility variants.

### Lexical Block Scope (`do`)
- `[do.@local_var] ... [/do.@local_var]`: Creates a localized scope `@local_var` destroyed at closing tag.

### Looping Iterators (`loop`, `loop.live_arr`, `loopv2`)
- `[loop.@iterator array_path] ... [/loop.@iterator]`: Iterates over an array.
  - Current item: `{@iterator.item.property}` or `[env.@iterator.item.property /]`.
  - Current key: `[env.@iterator.key /]`
  - Iteration count (0-based): `[env.@iterator.counter /]`
  - Iteration index (1-based): `[env.@iterator.index /]`
  - Flags: `[env.@iterator.first /]`, `[env.@iterator.last /]`, `[env.@iterator.odd /]`, `[env.@iterator.even /]`, `[env.@iterator.between /]`
- `[loop.@iterator start="1" stop="10" step="1"] ... [/loop.@iterator]`: Range loop.
- `[loop.live_arr]`: Dynamically evaluates array mutations during iteration.
- `[loopv2]`: Optimized hashing loop for large datasets.

### Conditionals & Branching (`if`, `cond`)
- **`if` Library**:
  - `[if.equal lhs=A rhs=B] ... [/if.equal]`
  - `[if.not_equal lhs=A rhs=B] ... [/if.not_equal]`
  - `[if.greater_than lhs=A rhs=B] ... [/if.greater_than]`
  - `[if.greater_equal lhs=A rhs=B] ... [/if.greater_equal]`
  - `[if.less_than lhs=A rhs=B] ... [/if.less_than]`
  - `[if.less_equal lhs=A rhs=B] ... [/if.less_equal]`
  - `[if.else] ... [/if.else]`: Fallback branch.
  - `[if.and] ... [/if.and]`, `[if.or] ... [/if.or]`
  - `[if.contains needle=val haystack={arr}] ... [/if.contains]`
  - `[if.not_contains needle=val haystack={arr}] ... [/if.not_contains]`
- **`cond` Library**:
  - `[cond.select c.cond=A c.eq=B true_service="x" false_service="y" /]`
  - `[cond.true c.cond=A ...]`: Executes if true.
  - `[cond.false c.cond=A ...]`: Executes if false.
  - `[cond.block]`: Structured multi-branch conditional container supporting:
    - `[cond_block_context.then]...[/cond_block_context.then]`
    - `[cond_block_context.then.and]...[/cond_block_context.then.and]`
    - `[cond_block_context.then.not]...[/cond_block_context.then.not]`
    - `[cond_block_context.else]...[/cond_block_context.else]`
    - `[cond_block_context.else.and]...[/cond_block_context.else.and]`
    - `[cond_block_context.else.not]...[/cond_block_context.else.not]`
    - `[cond_block_context.or]...[/cond_block_context.or]`
  - `[cond.switch]`: Case-based branching container supporting:
    - `[cond_switch_context.case value="abc"]...[/cond_switch_context.case]`
    - `[cond_switch_context.default]...[/cond_switch_context.default]`

### While Loop Iterators (`while.arr`, `while.build`, `while.scalar`)
- `[while.arr]`: Loops until condition breaks, collecting values into array:
  - Context tags: `[while_arr_context.cond /]`, `[while_arr_context.then /]`, `[while_arr_context.arr /]`
- `[while.build]`: Loops to construct a complex data structure:
  - Context tags: `[while_build_context.cond /]`, `[while_build_context.then /]`
- `[while.scalar]`: Loops to accumulate a scalar value:
  - Context tags: `[while_scalar_context.cond /]`, `[while_scalar_context.then /]`, `[while_scalar_context.scalar /]`

### Higher-Order Array Processors (`arr_each`)
- `[arr_each.map array="path"]`: Maps each item through a template.
  - Context tags: `[arr_map_context.map /]`, `[arr_map_context.discard /]`, `[arr_map_context._modify /]`
- `[arr_each.filter array="path"]`: Filters array items.
  - Context tags: `[arr_filter_context.filter /]`, `[arr_filter_context._select /]`, `[arr_filter_context._modify /]`
- `[arr_each.reduce array="path" initial=0]`: Reduces array to single value.
  - Context tags: `[arr_reduce_context.reduce /]`, `[arr_reduce_context._modify /]`
- `[arr_each.build array="path"]`: Generates complex transformed array.

### Array Operations (`arr`, `arr.is`, `arr.has`, `arr.join`, `arr.to`, `arr_items`)
- **`arr` Core**:
  - `[arr.create o.set="path"]...[/arr.create]`: Construct array from XML-like tags.
  - `[arr.set path key=val /]`: Sets key on target array.
  - `[arr.empty o.set="path" /]`: Returns empty array.
  - `[arr.get path index=0 /]`: Returns element.
  - `[arr.length path /]`: Returns element count.
  - `[arr.item.value path key="title" /]`: Extracts key from array item.
  - `[arr.search_deep main="path" search="val" field="key" /]`: Deep array/object search.
  - `[arr.unshift path val="item" /]`: Prepends element.
- **`arr.is` Validation**:
  - `[arr.is.empty path /]`, `[arr.is.not_empty path /]`
  - `[arr.is.associative path /]`, `[arr.is.sequential path /]`
  - `[arr.is.arr path /]`, `[arr.is.not_arr path /]`
- **`arr.has` Key & Value Checks**:
  - `[arr.has.key path key="id" /]`, `[arr.has.keys path keys="id,title" /]`
  - `[arr.has.value path value="val" /]`, `[arr.has.values path values="a,b" /]`
  - `[arr.has.empty path /]`, `[arr.has.null path /]`
- **`arr.join` Glue Operations**:
  - `[arr.join.comma path /]`, `[arr.join.dot path /]`, `[arr.join.space path /]`, `[arr.join.separator path sep=" | " /]`
- **`arr.to` Transformations**:
  - `[arr.to.json path /]`: Serializes to JSON string.
  - `[arr.to.csv path /]`: Converts 2D array to CSV format.
  - `[arr.to.query_string path /]`: Converts associative array to URL query string.
  - `[arr.to.nested path /]`: Transforms dot-notation keys into multi-level nested tree.
- **`arr_items` Splice & Manipulation**:
  - `[arr_items.reverse path /]`: Reverses items.
  - `[arr_items.insert path pos=1 item=val /]`: Inserts at position.
  - `[arr_items.remove path pos=1 count=1 /]`: Removes elements.
  - `[arr_items.replace path pos=1 item=val /]`: Replaces element.
  - `[arr_items.get path pos=0 /]`: Gets element at position.
  - `[arr_items.keys path /]`: Extracts all array keys.
  - `[arr_items.values path /]`: Extracts all array values.
  - `[arr_items.find path field="slug" val="home" /]`: Finds matching item.

### String Manipulation (`str`, `str.case`, `str.comp`, `str.needle`, `str.pad`, `str.slice`, `str.split`, `str.trim`)
- **`str` Core**:
  - `[str.create "Text" /]`, `[str.get path default="" /]`
  - `[str.append target="a" add="b" /]`, `[str.prepend target="b" add="a" /]`
  - `[str.is.str path /]`, `[str.is.not_str path /]`
  - `[str.is.yes path /]`, `[str.is.no path /]`
  - `[str.is.empty path /]`, `[str.is.whitespace path /]`, `[str.is.not_whitespace path /]`, `[str.is.not_empty path /]`
  - `[str.is.in needle="a" haystack="abc" /]`, `[str.is.not_in needle="x" haystack="abc" /]`
- **`str.case`**:
  - `[str.case.lower str /]`, `[str.case.upper str /]`
  - `[str.case.sentence str /]`, `[str.case.title str /]`, `[str.case.invert str /]`
- **`str.comp` Comparisons**:
  - `[str.comp.eq a="x" b="y" /]`, `[str.comp.neq a="x" b="y" /]`
  - `[str.comp.lt a="x" b="y" /]`, `[str.comp.lte a="x" b="y" /]`
  - `[str.comp.gt a="x" b="y" /]`, `[str.comp.gte a="x" b="y" /]`
- **`str.needle` Search**:
  - `[str.needle.count haystack="foo" needle="o" /]`
  - `[str.needle.pos_first haystack="foo" needle="o" /]`
  - `[str.needle.pos_last haystack="foo" needle="o" /]`
  - `[str.needle.pos_n haystack="foo" needle="o" n=2 /]`
  - `[str.needle.replace haystack="foo" search="o" replace="a" /]`
- **`str.pad` & `str.slice`**:
  - `[str.pad.left str length=10 pad="0" /]`, `[str.pad.right ...]`, `[str.pad.both ...]`
  - `[str.slice.left str length=5 /]`, `[str.slice.right str length=5 /]`, `[str.slice.mid str start=2 length=4 /]`
- **`str.split` & `str.trim`**:
  - `[str.split.comma str /]`, `[str.split.dot str /]`, `[str.split.ws str /]`, `[str.split.separator str sep=";" /]`
  - `[str.trim.left str /]`, `[str.trim.right str /]`, `[str.trim.both str /]`

### Numeric Operations (`int`, `num`, `currency`, `math`)
- **`int` (Integers)**:
  - `[int.create 42 /]`, `[int.get path default=0 /]`
  - `[int.is.odd int /]`, `[int.is.even int /]`, `[int.is.int int /]`, `[int.is.not_int int /]`
  - `[int.is.positive int /]`, `[int.is.negative int /]`, `[int.is.zero int /]`, `[int.is.between int min=1 max=100 /]`
  - `[int.abs int /]`, `[int.min a b /]`, `[int.max a b /]`, `[int.sum a b c /]`
  - `[int.random min=1 max=100 /]`
  - `[int.plus_one int /]`, `[int.minus_one int /]`
  - `[int.to.num int /]`, `[int.to.str int /]`
  - `[int.comp.eq]`, `[int.comp.neq]`, `[int.comp.lt]`, `[int.comp.lte]`, `[int.comp.gt]`, `[int.comp.gte]`
- **`num` (Floats/Decimals)**:
  - `[num.create 12.34 /]`, `[num.get path default=0.0 /]`
  - `[num.is.num num /]`, `[num.is.not_num num /]`
  - `[num.is.positive num /]`, `[num.is.negative num /]`, `[num.is.zero num /]`, `[num.is.between num min=0 max=1 /]`
  - `[num.ceiling num /]`, `[num.floor num /]`, `[num.round num decimals=2 /]`, `[num.truncate num decimals=2 /]`
  - `[num.abs num /]`, `[num.min a b /]`, `[num.max a b /]`, `[num.sum a b /]`, `[num.rand min=0.1 max=0.9 /]`
  - `[num.to.str num /]`, `[num.to.int num /]`
  - `[num.comp.eq]`, `[num.comp.neq]`, `[num.comp.lt]`, `[num.comp.lte]`, `[num.comp.gt]`, `[num.comp.gte]`
- **`currency`**:
  - `[currency.create 1499.50 /]`, `[currency.get path /]`
  - `[currency.is.currency val /]`
  - `[currency.display val /]`: Formats with 2 decimal precision.
  - `[currency.rupees.words val /]`: Converts Indian Rupees to textual words.
- **`math`**:
  - `[math.solve "((10 * 5) + 20) / 2" /]`: Evaluates expressions (power operator: `**`).
  - `[math.plus_one val /]`, `[math.minus_one val /]`

### Boolean Library (`bool`)
- `[bool.create "true" /]`, `[bool.get path default=false /]`
- `[bool.is.bool val /]`, `[bool.is.not_bool val /]`
- `[bool.is.true val /]`, `[bool.is.false val /]`
- `[bool.to.str val /]`: Converts `true`/`false` to string `"true"`/`"false"`.

### Date & Time Library (`date`, `date.comp`)
- `[date.create "2026-11-05" /]`: Creates DateTime object.
- `[date.get path default="now" /]`
- `[date.modify date={d} frequency="+1 month" /]`: Relative adjustments.
- `[date.format date={d} format="Y-m-d H:i:s" /]`
- `[date.diff date_from=A date_to=B type="days" /]`: Types: `mins`, `hours`, `days`, `years`, `english`.
- `[date.aw2_period period="last_month" /]`: Returns period `start_date` and `end_date`.
- `[date.comp.eq]`, `[date.comp.neq]`, `[date.comp.lt]`, `[date.comp.lte]`, `[date.comp.gt]`, `[date.comp.gte]`: Compares 8-digit date integers (`YYYYMMDD`).

### Data Modeling & Validation (`email_id`, `regex`, `enum`, `taxonomy`, `validate`)
- **`email_id`**:
  - `[email_id.create "user@example.com" /]`, `[email_id.get path /]`
  - `[email_id.is.valid email /]`, `[email_id.normalize email /]`
  - `[email_id.part.user email /]`, `[email_id.part.domain email /]`
  - `[email_id.mask email /]`: Obfuscates email for privacy (`u***r@example.com`).
  - `[email_id.comp.eq a b /]`
  - `[email_id.is.disposable email /]`: Flags burner email services.
  - `[email_id.is.role email /]`: Detects role accounts (`admin@`, `support@`, `sales@`).
  - `[email_id.mailto_link email subject="Hi" /]`
  - `[email_id.extract.email text /]`: Extracts emails from raw text blob.
  - `[email_id.has_mx email /]`: Checks MX record.
  - `[email_id.decompose email /]`: Breaks into domain, user, TLD.
- **`regex`**:
  - `[regex.match pattern="/^[a-z]+$/i" text="abc" /]`
  - `[regex.extract pattern="/([0-9]+)/" text="Item 42" /]`
  - `[regex.replace pattern="/\s+/" replace="-" text="hello world" /]`
  - `[regex.split pattern="/\s*,\s*/" text="a, b, c" /]`
  - `[regex.count pattern="/foo/" text="foofoofoo" /]`
- **`enum`**:
  - `[enum.define name="user_role"] admin="Admin", editor="Editor" [/enum.define]`
  - Context tags: `[enum_context.keys /]`, `[enum_context.list /]`, `[enum_context.validate val="admin" /]`
- **`taxonomy`**:
  - `[taxonomy.define name="categories"] ... [/taxonomy.define]`
  - Context tags: `[taxonomy_context.list /]`, `[taxonomy_context.get_terms /]`, `[taxonomy_context.get_term /]`, `[taxonomy_context.get_meta /]`, `[taxonomy_context.exists /]`, `[taxonomy_context.add_terms /]`
- **`validate`**:
  - `[validate.email val /]`
  - `[validate.positive_int val /]`, `[validate.zero_positive_int val /]`
  - `[validate.zero_positive_float val /]`
  - `[validate.name val /]`
  - `[validate.pan_card val /]`
  - `[validate.zip_code val /]`
  - `[validate.mobile_number val /]`
  - `[validate.printable val /]`
  - `[validate.object_id val /]`

### Functions & Namespaces (`func`, `call`, `namespace`, `workflow`)
- `[func.add name="calc_total"] ... [/func.add]`: Defines reusable callable block.
- `[func.service.create name="my_service" func="my_func" /]`: Registers function as service.
- `[call "func_name" param1=val /]`: Invokes localized function in same namespace.
- `[func.get path /]`, `[func.set path=val /]`, `[func.return val /]`
- `[func.dump /]`, `[func.echo /]`, `[func.exists /]`, `[funcstack.dump /]`
- `[namespace.build "aw2\my_ns"] ... [/namespace.build]`
- `[workflow.steps.run]`: Sequential pipeline runner executing steps in ordered transactions.

### Raw Objects, Code & System (`obj`, `code`, `content`, `php`, `aw2`, `aw2_error`)
- `[obj.create]`, `[obj.set]`, `[obj.empty]`, `[obj.is.obj]`, `[obj.is.not_obj]`
- `[code.run]`, `[code.dump]`, `[code.highlight]`, `[code.sample]`
- `[content.get]`: Returns raw inner content of enclosing tag without evaluating.
- `[content.run]`: Evaluates and returns inner content.
- `[php.site_url /]`: Returns WordPress home URL.
- `[php.die "Fatal stop" /]`: Invokes PHP `die()`.
- `[aw2.module]`, `[aw2.this]`, `[aw2.echo]`, `[aw2.set]`, `[aw2.set_array]`, `[aw2.get]`, `[aw2.raw]`, `[aw2.die]`
- `[aw2.switch]`, `[aw2.case]`, `[aw2.case_else]`
- `[aw2.save_form]`
- `[aw2.destroy_sessions]`
- `[aw2_error.create code="E100" message="Failed validation" /]`

---

## 6. Structure, Collections & Module Architecture

### Modules (`module`)
- `[module.register name="auth" /]`
- `[module.run "auth" /]`: Executes target module.
- `[module.include "auth" /]`: Inlines module without creating fresh execution state.
- `[module.return val /]`: Terminates module execution and returns value.
- `[module.get path /]`, `[module.set key=val /]`, `[module.dump path /]`, `[module.echo path /]`
- `[module.code.run]`, `[module.template.register]`, `[module.service.register]`

### Templates (`template`, `templates`, `@template`)
- `[templates.add "item_view"] ... [/templates.add]`: Defines a named template within the module.
- `[templates.run "item_view" /]`: Executes named template.
- `[template.get path /]`: Reads variable from template scope.
- `[template.set key=val /]`: Sets variable in template scope.
- `[template.return val /]`: Exits template and returns value.
- `[@template.return val /]`: Direct access to active template instance.

### Collections & Apps (`collection`, `@collection`, `this_collection`, `app`)
- `[collection.register name="crm" post_type="aw_crm" /]`
- `[collection.run "crm.contacts" /]`: Runs module belonging to collection.
- `[collection.include "crm.helpers" /]`, `[collection.include_raw ...]`
- `[collection.export]`, `[collection.export_compare]`, `[collection.import]`
- `[@collection]`, `[this_collection]`: Pointer to currently active collection instance.
- `[app.register]`, `[app.run]`, `[app.collection.module.get]`, `[app.collection.modules.list]`

### Content Type Definitions (`content_type_def`, `@content_type`, `content_types`, `post_type`)
- `[content_types.add "deal"]`
- `[content_type_def.run]`, `[content_type_def.template]`, `[content_type_def.get]`, `[content_type_def.meta]`, `[content_type_def.config]`
- `[@content_type.run]`, `[@content_type.get]`, `[@content_type.meta]`, `[@content_type.config]`
- `[post_type.service.register]`

---

## 7. Request, Response, Security & Utilities

### HTTP Request Sanitization (`request`, `request2`)
- `[request2.get "param_name" default="val" /]`: Reads and cleans parameter from `$_REQUEST` (protects against XSS).
  - Shorthand syntax: `[request2.param_name /]`
  - `[request2.get "request_body" /]`: Reads raw payload stream (`php://input`).
  - `[request2.get "post_json" /]`: Returns JSON-decoded `$_POST` array.
- `[request2.dump "key" /]`, `[request2.echo "key" /]`
- `[request.get]`, `[request.dump]`, `[request.echo]` (Legacy request handler).

### SQL Escaping & Safety (`esc`)
All `esc.*` services take an environment path as an unnamed argument:
- `[esc.str path /]`: Escapes string and wraps in single quotes (`'sanitized'`).
- `[esc.int path /]`: Casts to safe integer.
- `[esc.num path /]`: Casts to safe float.
- `[esc.id path /]`: Sanitizes alphanumeric identifier and wraps in single quotes.
- `[esc.table path /]`: Sanitizes table name.
- `[esc.in path /]`: Converts array of strings to `'a', 'b', 'c'`.
- `[esc.like path /]`: Escapes `%` and `_` wildcards for `LIKE` clauses (does NOT wrap in quotes).
- `[esc.date path /]`: Converts date to `DATE('YYYYMMDD')`.
- `[esc.unsafe path /]`: Converts numeric array to comma-separated list (`1, 2, 3`).
- `[esc.safe path /]`: Declares value as pre-escaped.

### Data Cleaning (`clean`) & Encoding (`encode`)
- **`clean`**:
  - `[clean.safe str /]`, `[clean.printable str /]`, `[clean.id str /]`
  - `[clean.int str /]`, `[clean.num str /]`, `[clean.date str /]`, `[clean.column_name str /]`
- **`encode`**:
  - `[encode.src url /]`: URL encode.
  - `[encode.class str /]`: Converts string to valid CSS class name.
  - `[encode.id str /]`: Converts string to valid HTML DOM ID.
  - `[encode.atts arr /]`: Serializes associative array into HTML attribute string `foo="bar"`.
  - `[encode.str html /]`: HTML purifier filter.

### Cookies & Session Management (`cookie`, `session_ticket`, `vsession`)
- **`cookie`**:
  - `[cookie.set name="x" value="y" expire=3600 secure=true httponly=true /]`
  - `[cookie.get "x" default="" /]`
  - `[cookie.is.valid "x" /]`, `[cookie.comp.equal "x" match="val" /]`
  - `[cookie.dump "x" /]`, `[cookie.echo "x" /]`
- **`session_ticket`**:
  - `[session_ticket.create o.set="env.tkt" /]`
  - `[session_ticket.validate ticket={tkt} /]`
  - `[session_ticket.set ticket={tkt} key=val /]`, `[session_ticket.get ticket={tkt} key="k" /]`
  - `[session_ticket.set_activity ticket={tkt} /]`, `[session_ticket.set_timeout ticket={tkt} seconds=1800 /]`
  - `[session_ticket.generate_token ticket={tkt} /]`, `[session_ticket.destroy ticket={tkt} /]`
- **`vsession` (Virtual Session)**:
  - `[vsession.create /]`, `[vsession.exists /]`, `[vsession.set key=val /]`, `[vsession.get key /]`
- `[if.user_can_access url="/admin" /]`

### File System & Media (`file`, `aw2.upload`, `aw2.sideload`)
- `[file.write path="/path/to/file" content="data" /]`
- `[file.file_put path="..." content="..." /]`, `[file.file_get path="..." /]`
- `[file.open_file path="..." mode="r" /]`
- `[file.log_reader_sql_error /]`, `[file.read_deadlock /]`
- `[aw2.upload file="upload_field" /]`: Handles multipart HTTP file upload.
- `[aw2.sideload url="https://example.com/image.png" /]`: Downloads remote asset and attaches to WordPress Media Library.

### Utility Services (`unique`, `time`, `hashids`, `util`, `async_tickets`, `async_urls`)
- **`unique`**:
  - `[unique.token length=32 /]`: Cryptographically random alphanumeric token.
  - `[unique.id prefix="usr_" /]`: PHP `uniqid()` string.
  - `[unique.longid prefix="evt_" /]`: High-entropy UUID-like string.
  - `[unique.guid /]`: RFC 4122 compliant UUID v4.
- **`time`**:
  - `[time.start /]`: Starts execution benchmark timer.
  - `[time.diff /]`: Returns elapsed milliseconds.
  - `[time.diff_echo /]`: Prints elapsed time.
- **`hashids`**:
  - `[hashids.set id=123 /]`: Encodes integer into YouTube-style hash.
  - `[hashids.get hash="aB9" /]`: Decodes hash back to integer.
- **`util`**:
  - `[util.otp length=6 /]`: Generates numeric one-time password.
  - `[util.nonce action="delete_post" /]`: Generates WordPress nonce.
  - `[util.qs_parse query="a=1&b=2" /]`: Parses query string into array.
  - `[util.form_data_array /]`: Parses submitted form fields.
  - `[util.constant /]`: Returns array of defined PHP constants.
  - `[util.async_url url="https://..." /]`: Asynchronous HTTP dispatch.
  - `[util.save_csv_page /]`
- `[async_tickets.run]`, `[async_urls.run]`
- `[search.new_ticket]`, `[search.set_ticket]`, `[search.get_ticket]`

---

## 8. Database Engine & Caching Services

### MySQL Database Queries (`mysqli`, `mysqli.fetch`, `mysqli.multi`, `mysqli.transaction`)
- **Single Statements**:
  - `[mysqli.cud] INSERT INTO ... [/mysqli.cud]`: Executes Create/Update/Delete.
  - `[mysqli.fetch.rows o.set="template.rows"] SELECT ... [/mysqli.fetch.rows]`: Returns array of row arrays.
  - `[mysqli.fetch.row o.set="template.row"] SELECT ... [/mysqli.fetch.row]`: Returns single row (or null).
  - `[mysqli.fetch.col o.set="template.col"] SELECT id FROM ... [/mysqli.fetch.col]`: Returns single column as array.
  - `[mysqli.fetch.scalar o.set="template.val"] SELECT count(*) FROM ... [/mysqli.fetch.scalar]`: Returns single scalar.
  - `[mysqli.fetch.count] SELECT count(*) FROM ... [/mysqli.fetch.count]`: Returns count integer.
  - `[mysqli.fetch.exactly_one_row] SELECT ... [/mysqli.fetch.exactly_one_row]`: Throws error if 0 or >1 rows returned.
- **Multi-Query Statements & Transactions**:
  - `[mysqli.multi.fetch.rows o.set="template.results"] ... [/mysqli.multi.fetch.rows]`: Runs batch of queries separated by semicolons and returns final resultset.
  - `[mysqli.multi.cud] ... [/mysqli.multi.cud]`: Runs batch of insert/update/delete queries.
  - `[mysqli.transaction.commit] ... [/mysqli.transaction.commit]`: Runs multi-query inside SQL transaction and commits.
  - `[mysqli.transaction.rollback] ... [/mysqli.transaction.rollback]`: Runs multi-query and automatically rolls back.

### Multi-Tier Caching (`global_cache`, `session_cache`, `mysql.cache`, `redis`, `redis_db`)
- **`global_cache` (High-level cross-session application cache)**:
  - `[global_cache.set key="key" val="data" expire=3600 /]`
  - `[global_cache.get key="key" /]`
  - `[global_cache.hset key="h" field="f" val="d" /]`, `[global_cache.hget key="h" field="f" /]`
  - `[global_cache.exists key="k" /]`, `[global_cache.del key="k" /]`, `[global_cache.flush /]`
  - `[global_cache.run key="k" expire=600] ... [/global_cache.run]`: Evaluates inner block on cache miss.
- **`session_cache` (User-bound cache tier)**:
  - `[session_cache.set key=val /]`, `[session_cache.get key /]`
  - `[session_cache.hset key field val /]`, `[session_cache.hget key field /]`
  - `[session_cache.exists key /]`, `[session_cache.del key /]`, `[session_cache.hlen key /]`
  - `[session_cache.flush /]`, `[session_cache.clean /]`
- **`mysql.cache` (Relational table-backed key-value store)**:
  - `[mysql.cache.set]`, `[mysql.cache.get]`, `[mysql.cache.hset]`, `[mysql.cache.hget]`
  - `[mysql.cache.exists]`, `[mysql.cache.del]`, `[mysql.cache.flush]`, `[mysql.cache.clean]`
  - `[mysql.cache.keys pattern="user:*" /]`, `[mysql.cache.hgetall key /]`, `[mysql.cache.hlen key /]`
- **`redis` & `redis_db`**:
  - `[redis.set key val expire=3600 /]`, `[redis.get key /]`
  - `[redis.hset key field val /]`, `[redis.hget key field /]`
  - `[redis.exists key /]`, `[redis.del key /]`, `[redis.flush /]`
  - `[redis_db.stream_add stream="logs" data={payload} /]`
  - `[redis_db.stream_last stream="logs" /]`
  - `[redis_db.stream_fetch_all stream="logs" /]`
  - `[redis_db.stream_fetch_usage stream="logs" /]`
  - `[redis_db.get key /]`

---

## 9. Front-End Assets, CSS & JS Pipeline

### Stylesheets (`css`, `less`, `minify`, `style`, `register.less_variables`)
- `[css] body { color: red; } [/css]`: Inlines or bundles CSS.
- `[less] @primary: #0073aa; .btn { color: @primary; } [/less]`: Compiles LESS code to CSS at runtime.
- `[minify] ... [/minify]`: Strips comments and compacts CSS.
- `[style] ... [/style]`: Outputs formatted `<style>` block.
- `[register.less_variables]`: Registers global LESS design tokens.

### Scripts (`js`, `js.minify`, `js.run_on_activity`)
- `[js] console.log('Ready'); [/js]`: Inlines JavaScript.
- `[js.minify] ... [/js.minify]`: Minifies JS code.
- `[js.run_on_activity] ... [/js.run_on_activity]`: Defers script execution until user interactions (scroll, click, pointer).

### Trusted HTML (`html.trusted`)
- `[html.trusted] <div>Raw unescaped safe HTML</div> [/html.trusted]`: Bypasses HTML sanitization for trusted markup.

---

## 10. Extra Handlers (Network, External APIs & Office)

### HTTP Networking (`curl`)
Returns an associative array containing `info` (HTTP status, timing), `headers`, `cookies`, and `result` (response body).
- `[curl.api.get url="https://api.example.com/v1" header={headers} proxy={proxy} /]`
- `[curl.api.post url="https://api.example.com/v1" data={payload} header={headers} /]`
- `[curl.api.patch url="https://api.example.com/v1" data={payload} header={headers} /]`
- `[curl.page.get url="https://example.com" /]`: Downloads full rendered web page.

### Notifications & Email (`notify`, `aw2.push`)
- `[notify.wpmail to="user@example.com" subject="Hello" message="Content" /]`: WordPress `wp_mail()`.
- `[notify.sendgrid to=... subject=... template_id=... /]`: SendGrid email dispatch.
- `[notify.amazonses to=... subject=... message=... /]`: Amazon Simple Email Service (SES).
- `[notify.kookoo to=... message=... /]`: Kookoo SMS dispatch.
- `[notify.msg91 to=... message=... template_id=... /]`: Msg91 SMS gateway.
- `[aw2.push title="Alert" body="Message" icon="..." /]`: Web push notification.

### Debugging & Diagnostics (`debug`, `debug_cache`, `debugbar`, `live_debug`)
- `[debug.dump path /]`: Emits debug dump to debug panel.
- `[debug.html content /]`: Renders raw HTML inside debug view.
- `[debug.wp_queries /]`: Dumps all WordPress queries executed during request.
- `[debug.query /]`: Outputs current query stats and timing.
- `[debug.flow /]`, `[debug.module /]`, `[debug.setup /]`, `[debug.z /]`, `[debug.ignore /]`
- `[debug_cache.*]`: Cache diagnostics.
- `[debugbar.*]`: PHP DebugBar UI integration.
- `[live_debug.*]`: Real-time streaming diagnostic events (`live_debug.active`, `live_debug.output.dump`, etc.).

### Document Generators (`excel`, `pdf`)
- `[excel ... /]`: Generates and parses spreadsheet files (`.xlsx`).
- `[pdf.generate template="invoice" data={order} /]`: Converts markup to PDF document.
- `[pdf.parse file="/path/to/doc.pdf" /]`: Parses PDF AcroForm or XFA forms into structured data.

### Authentication & Identity (`google`, `jwt_token`)
- `[google.login_url redirect_uri="..." /]`: Generates Google OAuth2 login link.
- `[google.auth code="..." /]`: Validates Google OAuth code.
- `[google.auth_offline refresh_token="..." /]`: Refreshes Google API tokens.
- `[jwt_token.encode payload={user} secret="key" /]`: Generates JSON Web Token.
- `[jwt_token.decode token="xyz" secret="key" /]`: Validates and decodes JWT.

---

## 11. WordPress & Gutenberg Integrations

### WP Utilities (`wp.get`, `wp.menu`, `wp.signon`, `wp.image_resize`)
- `[wp.get "site_url" /]`, `[wp.get "blog_name" /]`, `[wp.get "post_meta" post_id=10 key="price" /]`
- `[wp.menu theme_location="primary" /]`: Renders WordPress navigation menu.
- `[wp.signon username="admin" password="..." /]`: Authenticates WordPress user.
- `[wp.image_resize id=42 width=300 height=200 crop=true /]`: Generates resized media image.

### Blocks & Widgets (`gt_blocks`, `gb_blocks`, `acf_blocks`, `element_widgets`, `aw_elements`)
- `[gt_blocks.register]`: Dynamic Gutenberg Block SFC registration header.
- `[gb_blocks.<slug>.render]`, `[gb_blocks.<slug>.controls]`, `[gb_blocks.<slug>.styles]`
- `[acf_blocks]`: Integrates with Advanced Custom Fields block system.
- `[element_widgets]`, `[aw_elements]`: Elementor custom widget bridges.

### Posts, Queries & CLI (`posts.export`, `post_types.add`, `query.meta_query`, `multi.*`, `awcli.*`)
- `[posts.export posts={post_ids} /]`
- `[post_types.add "event" args={args} /]`
- `[query.meta_query post_type="product" meta_key="price" meta_value=100 /]`
- `[multi.select]`, `[multi.update]`
- `[awcli.run cmd="ls -la" /]`: Spawns async background CLI process.
- `[awcli.isrunning pid=123 /]`, `[awcli.allrunning /]`, `[awcli.killpid pid=123 /]`

---

## 12. Processor Conditions (`c.*`), Modifiers (`m.*`), & Outputters (`o.*`)

### Conditions Processor (`c.*`, `and.*`, `or.*`)
Conditions run before the service executes. If any condition returns false, the shortcode is skipped:
- `c.ignore=yes`: Always bypasses execution.
- `c.cond=A c.eq=B`: True if `A === B`.
- `c.cond=A c.neq=B`: True if `A !== B`.
- `c.cond=A c.gt=B`, `c.cond=A c.gte=B`, `c.cond=A c.lt=B`, `c.cond=A c.lte=B`: Comparison checks.
- `c.odd=i:N`, `c.even=i:N`: Parity checks.
- `c.true=b:val`, `c.false=b:val`: Boolean validations.
- `c.yes=val`, `c.no=val`: Checks for string `"yes"` or `"no"`.
- `c.arr=path`, `c.not_arr=path`: Array type checks.
- `c.str=path`, `c.not_str=path`: String type checks.
- `c.bool=path`, `c.not_bool=path`: Boolean type checks.
- `c.num=path`, `c.not_num=path`: Numeric type checks.
- `c.int=path`, `c.not_int=path`: Integer type checks.
- `c.empty=path`, `c.not_empty=path`: Empty checks (`""`, `null`, `[]`, `0`).
- `c.null=path`, `c.not_null=path`: Strict `null` checks.
- `c.zero=path`, `c.positive=path`, `c.negative=path`: Number sign checks.
- `c.ws=path`, `c.not_ws=path`: Whitespace checks.
- `c.contains=needle c.haystack={arr}`: Array membership checks.
- `c.not_contains=needle c.haystack={arr}`
- `c.exists=key`, `c.not_exists=key`: Environment path existence.
- `c.request_exists=key`, `c.request_not_exists=key`: `$_REQUEST` existence.
- `c.logged_in=yes`, `c.not_logged_in=yes`: User session state.
- `c.user_can="manage_options"`, `c.user_cannot="..."`: Role/capability permissions.
- `c.device="mobile,tablet"`: User-agent device detection.

### Modifiers Processor (`m.*`, `m2.*`)
Modifiers sequentially mutate the service return value:
- `m.run=yes`: Executes unparsed template code inside string.
- `m.lower=yes`, `m.upper=yes`, `m.capitalize=yes`, `m.sentence=yes`: Case transformations.
- `m.trim=yes`, `m.ltrim=yes`, `m.rtrim=yes`: Trims whitespace.
- `m.left=i:10`, `m.right=i:10`: Character truncation.
- `m.substr=yes m.start=i:0 m.chars=i:50`: Substring slice.
- `m.length=yes`: Computes string length or array count.
- `m.json_encode=yes`, `m.json_decode=yes`: JSON encoding/decoding.
- `m.explode_on="s:,"`: Explodes string into array.
- `m.implode_on="s:,"`: Implodes array into string.
- `m.number_format=yes m.decimals=i:2`: Formats number with decimal places.
- `m.money_format="en_IN" m.currency="INR"`: Currency formatting.
- `m.date_format="M d, Y"`: Date formatting.
- `m.hhmm_format=yes`: Formats seconds into `HH:MM:SS`.
- `m.words=i:25`: Limits string to word count.
- `m.url_encode=yes`, `m.url_decode=yes`: URL encoding/decoding.
- `m.arr_item="s:first"`, `m.arr_item="s:last"`, `m.arr_item=i:2`, `m.arr_item="s:key"`: Picks array item.
- `m.unset_item="s:key"`: Removes item from array.
- `m.shuffle=yes`: Randomizes array order.
- `m.sort="asort"`: Sorts array (`asort`, `arsort`, `ksort`, `rsort`).
- `m.solve=yes`: Evaluates string as mathematical formula.
- `m.to_str=yes`, `m.to_int=yes`, `m.to_num=yes`, `m.to_bool=yes`: Casts data type.
- `m.append="str"`, `m.prepend="str"`: Appends/prepends text.
- `m.strip_tags=yes`, `m.stripslashes_deep=yes`: Sanitizers.
- `m.entities_encode=yes`, `m.entities_decode=yes`: HTML entity conversions.
- `m.esc_sql=yes`: SQL real escape string.
- `m.the_content=yes`: Applies WordPress `the_content` filter.
- `m.do_shortcode=yes`: Applies WordPress `do_shortcode` filter.

### Outputters Processor (`o.*`, `o2.*`)
Outputters handle where the final value is sent:
- `o.set="template.user_id"`: Saves value at env path and outputs an empty string.
- `o.merge_with="template.items"`: Merges array value into target array at env path.
- `o.arr_push="template.items"`: Pushes item onto target array.
- `o.destroy=yes`: Drops return value, outputting empty string.
- `o.echo=yes`: Prints value to output stream while keeping it in pipeline.
- `o.dump=yes`: Emits HTML debug dump to output stream.
- `o.console=yes`: Logs value to browser developer console.
- `o.log=yes`: Writes value to PHP error log file.
- `o.die=yes`: Prints value and halts execution via `die()`.
- `o.exit=yes`: Prints value and halts execution via `exit()`.

---

## 13. Platform Limitations, Guardrails & Common Pitfalls

### 1. SQL Injection Zero-Tolerance & Session Variables
- **Direct Concatenation Forbidden**: Writing `WHERE post_title = '{request.title}'` will fail template validation (`awesome/template-validate`).
- **Parameterization Pattern**: Always escape variables into MySQL session variables (`SET @title = [esc.str request.title /];`) at the start of multi-queries.
- **`LIMIT` Clause Restriction**: MySQL does not permit session variables in the `LIMIT` clause (e.g. `LIMIT @limit` is illegal). Limit values must be hardcoded integer literals.
- **Table Prefixes**: Never write `FROM wp_posts`. Always use `FROM [env.get db.prefix /]posts`.

### 2. Stream Leaks from Untrapped Output
- Any service returning data (e.g., `mysqli.fetch.rows`, `curl.api.get`, `arr.create`) will print raw PHP debug arrays (`print_r`) directly into the HTML response stream unless trapped:
  - Trapping into variable: `o.set="template.posts"`
  - Discarding: `o.destroy=yes`

### 3. Syntax Parsing Boundaries
- **No Square Brackets Inside Attributes**: `[env.set x=[esc.str y] /]` fails to parse. Store the value in a variable first:
  ```html
  [esc.str request.name o.set="temp.name" /]
  [env.set customer_name={temp.name} /]
  ```
- **No Nested Curly Braces**: `{user.{field}}` is invalid. Resolve the inner property first.
- **Curly Braces in Content**: `{variable}` and `{{service}}` are only parsed in attributes. In body content, they will print literally. Use `[env.get variable /]` in content.
- **Single Quotes in Unnamed Main Attributes**: Writing `[str.create 'text' /]` is invalid. Use double quotes `[str.create "text" /]` or bare words `[str.create text /]`.

### 4. Scoping & Variable Traversal Rules
- **`template.get` Automatic Namespace**: `template.get` automatically prepends `template.`. Therefore, `[template.get template.title /]` evaluates to `template.template.title`. Always write `[template.get title /]`.
- **Loop Scoping Isolation**: Inside `[loop.@post posts]`, the iterated item is placed under `.item`. Direct access `{@post.title}` or `[env.@post.title /]` is forbidden. Always write `{@post.item.title}` or `[env.@post.item.title /]`.

### 5. Dynamic Gutenberg Blocks (DGB) Enforcement
- **Root Container Dynamic Wrapper**: In block render templates, the root HTML container **must** include `[template.get wp.wrapper_attributes /]`. Hardcoding `class="..."` breaks Gutenberg block-gap, alignments, anchor IDs, and custom classes.
- **Cascade Layer Scoping**: All block CSS inside `[templates.add styles]` **must** be wrapped in `@layer blocks { .wp-block-dgb-<slug> { ... } }`. Bare CSS will fail compilation.

### 6. Agent Security Guardrails (`Allowlist`)
Enforced via `aw2\abilities\guardrails\Allowlist::is_service_allowed($service, $context)`:
- **Block Context (`context = 'block'`)**:
  - `mysqli.cud` and `mysqli.multi.cud` are strictly **disallowed** in block templates. Only read queries (`mysqli.fetch.*`, `mysqli.multi.fetch.rows`) are permitted.
  - `curl.*` network calls are **disallowed** in block templates.
  - `wp.signon` is **disallowed** in block templates.
- **Service Context (`context = 'service'`)**:
  - `mysqli.multi.cud` is disabled by default unless explicitly allowed via the `awx_allow_service_cud_transactions` filter.
  - Custom services must adhere to the registered allowlist.
