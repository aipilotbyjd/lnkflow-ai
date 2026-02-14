# Creating Workflows

Workflows are the core unit of automation in LinkFlow. A workflow is a directed graph of nodes (actions) connected by edges (dependencies).

## The Workflow Editor

The visual editor allows you to drag and drop nodes onto a canvas and connect them.

1.  **Add a Trigger**: Every workflow starts with a trigger node.
2.  **Add Actions**: Drag action nodes (e.g., HTTP Request, Send Email) from the sidebar.
3.  **Connect Nodes**: Draw lines between nodes to define the execution order.
4.  **Configure Nodes**: Click a node to set its properties (URL, method, headers, etc.).
5.  **Save & Activate**: Click "Save" to persist changes, then "Activate" to enable execution.

## Workflow Structure (JSON)

Under the hood, a workflow is stored as a JSON object:

```json
{
  "name": "My Workflow",
  "nodes": [
    {
      "id": "trigger",
      "type": "trigger_manual",
      "data": { "label": "Start" }
    },
    {
      "id": "action-1",
      "type": "action_http",
      "data": {
        "url": "https://api.example.com/data",
        "method": "GET"
      }
    }
  ],
  "edges": [
    {
      "source": "trigger",
      "target": "action-1"
    }
  ]
}
```

## Validating Workflows

The API automatically validates workflows upon save:
-   **Cycles**: Circular dependencies are not allowed (DAG structure required).
-   **Orphans**: All nodes (except trigger) must have at least one incoming connection.
-   **Configuration**: Required fields for each node type must be present.

## Versioning

Currently, workflows support basic versioning:
-   **Draft**: The version you edit in the UI.
-   **Active**: The version used for execution.
-   **History**: Previous versions are archived (coming soon).
