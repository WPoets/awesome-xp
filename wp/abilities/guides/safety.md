# Security & Database Escaping

**Zero-Tolerance Rule:** Never concatenate raw variables or inputs into SQL strings.

## Safe SQL Query Pattern
Always escape values using the `esc` library and assign them to MySQL session variables:

```sql
[mysqli.multi.fetch.rows o.set="template.results"]
    SET @category = [esc.str request.category /];
    SET @status = 'publish';

    SELECT ID, post_title 
    FROM wp_posts 
    WHERE post_status = @status AND post_name = @category;
[/mysqli.multi.fetch.rows]
```

## The Esc Library Services
All `esc.*` services take an environment path as an unnamed attribute:
- `[esc.str path /]`: Escapes string and adds quotes (`'escaped'`).
- `[esc.int path /]`: Casts to integer.
- `[esc.num path /]`: Casts to float.
- `[esc.id path /]`: Sanitizes alphanumeric IDs and wraps in quotes.
- `[esc.table path /]`: Escapes table name.
- `[esc.date path /]`: Returns `DATE('YYYYMMDD')`.
- `[esc.like path /]`: Escapes `%` and `_` wildcards for `LIKE` clauses (does not add quotes).

*Note:* MySQL prohibits session variables in the `LIMIT` clause. Limit values must be hardcoded integer literals in the query string itself.
