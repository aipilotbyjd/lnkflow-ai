# Building Custom Nodes

LinkFlow is designed to be extensible. You can add new native node types to the Execution Engine (Go) to perform specialized tasks with high performance.

## Overview

A Node Executor in LinkFlow is a Go struct that implements the `Executor` interface.

```go
type Executor interface {
    Execute(ctx context.Context, input NodeInput) (NodeOutput, error)
}
```

## Step 1: Create the Executor

Create a new file in `apps/engine/internal/worker/executor/my_custom_node.go`.

```go
package executor

import (
    "context"
    "fmt"
)

type MyCustomNode struct {}

func (n *MyCustomNode) Execute(ctx context.Context, input NodeInput) (NodeOutput, error) {
    // 1. Parse Input
    config := input.Config
    data := input.Data
    
    // 2. Perform Logic
    result := fmt.Sprintf("Hello, %v", data["name"])
    
    // 3. Return Output
    return NodeOutput{
        Data: map[string]interface{}{
            "message": result,
        },
    }, nil
}
```

## Step 2: Register the Node

You need to register your executor so the Worker knows how to instantiate it.

Modify `apps/engine/internal/worker/executor/registry.go`:

```go
func RegisterExecutors(registry *Registry) {
    registry.Register("action_http", &HttpExecutor{})
    registry.Register("action_ai", &AiExecutor{})
    
    // Add your new node
    registry.Register("action_my_custom", &MyCustomNode{})
}
```

## Step 3: Define Metadata (Frontend)

To make the node visible in the UI (Control Plane), you need to add it to the Node Registry in the Laravel API.

Create a migration or seeder in `apps/api` to insert the node definition:

```php
Node::create([
    'type' => 'action_my_custom',
    'category' => 'Custom',
    'name' => 'My Custom Action',
    'icon' => 'star',
    'description' => 'Does something amazing.',
    'config_schema' => [
        'fields' => [
            [
                'name' => 'name',
                'type' => 'text',
                'label' => 'Who to greet?',
                'required' => true
            ]
        ]
    ]
]);
```

## Step 4: Build & Deploy

1.  Rebuild the Engine Docker image: `make docker-engine`
2.  Run the API migration.
3.  Restart the stack.

## Best Practices

-   **Context**: Always respect `ctx.Done()` for cancellation.
-   **Idempotency**: Nodes may be retried. Ensure your logic handles this (e.g., don't charge a credit card twice without idempotency keys).
-   **Secrets**: Do not log sensitive data. Credentials passed in `input.Credentials` are already decrypted but should be handled carefully.
