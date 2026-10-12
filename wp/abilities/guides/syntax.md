# Syntax & Datatypes

## Shortcode Formats
- **Self-closing**: `[str.create "John Doe" /]`
- **Enclosing**:
  ```
  [if.equal lhs="6" rhs="6"]
    Matched!
  [/if.equal]
  ```

## Casting Prefixes
By default, all shortcode attribute values are treated as strings. Use prefixes to enforce types:
- `int:<value>`: Casts to integer (`int:42`).
- `num:<value>`: Casts to float (`num:29.99`).
- `str:<value>`: Casts to string (`str:active`).
- `bool:<value>`: Converts `"false"` to `false`, else casts to `bool`.
- `comma:<a,b,c>`: Converts comma-separated list into string array.
- `get:<path>`: Fetches value at env path and preserves its original data type.
- `x:<service_call>`: Executes a service and preserves return data type (`x:request2.get name`).

## Variables & Service Calls in Attributes
- **Curly braces `{path.key}`**: Evaluates the environment variable path and inserts its value. Nested braces (`{person.{name}}`) are strictly forbidden.
- **Double curly braces `{{service.name}}`**: Executes the service and inserts return value.
- **Content rule**: `{}` and `{{}}` are **only parsed within attributes**, never inside shortcode content. Use `[env.get path /]` in content.
