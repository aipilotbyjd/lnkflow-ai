# Custom Nodes

If the built-in nodes don't meet your needs, you can extend LinkFlow.

## 1. Script Node (JavaScript)
Run arbitrary JavaScript code in a secure sandbox.

```javascript
// Input is available as $input
const total = $input.items.reduce((sum, item) => sum + item.price, 0);

// Return value becomes the node output
return { total_price: total };
```

-   **Libraries**: Standard JS objects (`Math`, `Date`, `JSON`) are available. No external network access (use HTTP node for that).
-   **Timeout**: Scripts have a strict execution time limit (default 1s).

## 2. HTTP Node as a Custom Integration
You can save a configured HTTP node as a "Custom Node" template.
1.  Configure an HTTP node for a specific API (e.g., "Send Slack Message").
2.  Right-click -> "Save as Template".
3.  It now appears in your node palette.

## 3. Developing Native Go Nodes
For maximum performance, you can add new node types to the Go Engine.

1.  Create a struct implementing the `Executor` interface.
2.  Register it in `internal/worker/executor/registry.go`.
3.  Add the definition to the Frontend registry.
4.  Rebuild the Engine.

*(See Developer Guide for full details)*
