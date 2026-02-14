# Conditional Logic

Workflows often need to take different paths based on data. LinkFlow provides the **If/Else Node** for this purpose.

## The If/Else Node

This node splits the workflow into two paths: **True** and **False**.

### Configuration

You define a condition using a simple expression syntax.

-   **Variable**: The data to check (e.g., `{{ trigger.body.amount }}`)
-   **Operator**: The comparison logic (`==`, `!=`, `>`, `<`, `contains`, `exists`)
-   **Value**: The value to compare against.

### Examples

| Scenario | Variable | Operator | Value |
|----------|----------|----------|-------|
| High Value Order | `{{ trigger.body.total }}` | `>` | `1000` |
| Specific User | `{{ trigger.body.email }}` | `contains` | `@company.com` |
| Data Exists | `{{ http_1.body.id }}` | `exists` | |

## Branching

1.  Connect the input to the If/Else node.
2.  Connect the **True** port to the node you want to run if the condition is met.
3.  Connect the **False** port to the alternative path.

Both paths can eventually merge back into a single node if needed, or they can terminate independently.
