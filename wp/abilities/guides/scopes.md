# Variable Scopes

AwesomeEnterprise supports three primary scopes to isolate execution state:

## 1. Module Scope (`module.*`)
Active while an Awesome module is running.
- Parameters passed (`[collection.module param1=val /]`) are bound to `module.*`.
- Read and write with `[module.get]`, `[module.set]`, and `[module.set_array]`.
- Exit early with `[module.return <env_path> /]`.

## 2. Template Scope (`template.*`)
Active within a template execution context.
- Scoped strictly to the active template.
- Read and write with `[template.get]`, `[template.set]`, and `[template.set_array]`.
- Return with `[template.return <env_path> /]`.

## 3. Block Scope (`do.*`)
Creates a temporary lexical variable block.
```
[do.@temp]
    [env.set @temp.x="Hello World" /]
    [env.get @temp.x /]
[/do.@temp]
//* @temp is destroyed immediately on block exit *//
```
