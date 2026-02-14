# Expression Language Reference

LinkFlow uses a powerful expression syntax that allows you to dynamically inject data into your workflow nodes. Any field in a node configuration can use expressions.

## Syntax Basics

Expressions are wrapped in double curly braces: `{{ expression }}`.

-   **Variable Access**: `{{ trigger.body.user.name }}`
-   **Arithmetic**: `{{ trigger.body.price * 1.2 }}`
-   **String Concatenation**: `{{ "Hello " + trigger.body.name }}`

## Global Variables

These variables are available in every workflow execution context.

| Variable | Description | Example |
|----------|-------------|---------|
| `trigger` | Output from the trigger node | `{{ trigger.body }}` |
| `workflow` | Metadata about the current workflow | `{{ workflow.id }}` |
| `execution` | Metadata about the current run | `{{ execution.id }}` |
| `env` | Workspace environment variables | `{{ env.API_BASE_URL }}` |
| `credential` | Secure credentials (redacted in logs) | `{{ credential.stripe_key }}` |

## Node Referencing

You can access the output of any previous node by using its **Node ID**.

```json
// If you have a node with ID "http_request_1"
{{ http_request_1.body.data[0].id }}
{{ http_request_1.status }}
{{ http_request_1.headers['Content-Type'] }}
```

## Built-in Functions

### String Manipulation

| Function | Description | Example | Result |
|----------|-------------|---------|--------|
| `toUpper(str)` | Convert to uppercase | `{{ toUpper("hello") }}` | `"HELLO"` |
| `toLower(str)` | Convert to lowercase | `{{ toLower("HELLO") }}` | `"hello"` |
| `trim(str)` | Remove whitespace | `{{ trim("  hi  ") }}` | `"hi"` |
| `split(str, delim)` | Split string into array | `{{ split("a,b,c", ",") }}` | `["a", "b", "c"]` |
| `replace(str, old, new)` | Replace text | `{{ replace("foo", "o", "a") }}` | `"faa"` |

### JSON & Data

| Function | Description | Example |
|----------|-------------|---------|
| `jsonParse(str)` | Parse JSON string | `{{ jsonParse('{"a":1}') }}` |
| `jsonStringify(obj)` | Convert object to JSON | `{{ jsonStringify(trigger.body) }}` |
| `length(arr)` | Array/String length | `{{ length(trigger.items) }}` |

### Math & Numbers

| Function | Description | Example |
|----------|-------------|---------|
| `round(num)` | Round to nearest integer | `{{ round(1.6) }}` |
| `random()` | Random float 0-1 | `{{ random() }}` |
| `randint(min, max)` | Random integer | `{{ randint(1, 100) }}` |

### Date & Time

LinkFlow uses Luxon-like syntax for dates.

| Function | Description | Example |
|----------|-------------|---------|
| `now()` | Current ISO timestamp | `2024-03-20T10:00:00Z` |
| `dateAdd(date, amount, unit)` | Add time | `{{ dateAdd(now(), 1, "day") }}` |
| `dateFormat(date, format)` | Format date | `{{ dateFormat(now(), "yyyy-MM-dd") }}` |

## Advanced Logic

### Ternary Operator
Conditional logic inside a field.
```
{{ trigger.body.amount > 100 ? "High Value" : "Standard" }}
```

### Accessing Array Items
```
{{ http_request_1.body.users[0].email }}
```

### Handling Missing Data (Safe Navigation)
If a field might not exist, use the optional chaining operator `?.` (if supported) or check existence in an **If/Else** node first.
*(Note: Support depends on the underlying expression engine implementation)*
