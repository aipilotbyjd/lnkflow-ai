# Quick Start Tutorial

This tutorial guides you through creating and executing your first workflow using the LinkFlow API.

## Prerequisites

- LinkFlow stack is running (`make start`).
- `curl` or Postman installed.
- `jq` (optional, for pretty-printing JSON).

## Step 1: Check Health

Ensure the API is responsive.

```bash
curl -s http://localhost:8000/api/v1/health | jq
```
*Response should be `{"status": "ok", ...}`*

## Step 2: Create a User

Register a new user to get an authentication token.

```bash
# Register
TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Demo User",
    "email": "demo@example.com",
    "password": "Password123!",
    "password_confirmation": "Password123!"
  }' | jq -r '.token')

echo "Token: $TOKEN"
```

*Save this token for subsequent requests.*

## Step 3: Get Your Workspace

A default workspace is created for you upon registration.

```bash
# Get Workspace ID
WORKSPACE_ID=$(curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/v1/workspaces \
  | jq -r '.data[0].id')

echo "Workspace ID: $WORKSPACE_ID"
```

## Step 4: Define a Workflow

We'll create a simple workflow: **Manual Trigger -> HTTP Request**.

```bash
WORKFLOW_PAYLOAD='{
  "name": "My First Workflow",
  "description": "Fetches data from an external API",
  "trigger_type": "manual",
  "nodes": [
    {
      "id": "trigger-1",
      "type": "trigger_manual",
      "position": { "x": 100, "y": 100 },
      "data": { "label": "Start" }
    },
    {
      "id": "http-1",
      "type": "action_http_request",
      "position": { "x": 300, "y": 100 },
      "data": {
        "label": "Get IP Info",
        "config": {
          "url": "https://httpbin.org/get",
          "method": "GET"
        }
      }
    }
  ],
  "edges": [
    {
      "id": "edge-1",
      "source": "trigger-1",
      "target": "http-1"
    }
  ]
}'

# Create Workflow
WORKFLOW_ID=$(curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "$WORKFLOW_PAYLOAD" \
  http://localhost:8000/api/v1/workspaces/$WORKSPACE_ID/workflows \
  | jq -r '.data.id')

echo "Workflow ID: $WORKFLOW_ID"
```

## Step 5: Activate and Execute

1.  **Activate the Workflow**:
    ```bash
    curl -X POST -H "Authorization: Bearer $TOKEN" \
      http://localhost:8000/api/v1/workspaces/$WORKSPACE_ID/workflows/$WORKFLOW_ID/activate
    ```

2.  **Execute**:
    ```bash
    EXECUTION_ID=$(curl -s -X POST \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" \
      -d '{"input": {"test": "data"}}' \
      http://localhost:8000/api/v1/workspaces/$WORKSPACE_ID/workflows/$WORKFLOW_ID/execute \
      | jq -r '.data.id')

    echo "Execution ID: $EXECUTION_ID"
    ```

## Step 6: Monitor Execution

Check the status of your execution.

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/v1/workspaces/$WORKSPACE_ID/executions/$EXECUTION_ID \
  | jq
```

You should see `"status": "completed"` and the output from `httpbin.org` in the response.

## Next Steps

- Explore [available node types](../03-guides/nodes/README.md).
- Learn about [workflow triggers](../03-guides/workflows/triggers.md).
- Dive into the [Architecture](../02-architecture/01-overview.md).
