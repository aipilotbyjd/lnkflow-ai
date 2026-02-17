# Data Flow Inspector

## Status
🟡 Planned

## Priority
High

## Difficulty
Medium

## Category
🔍 Observability & Debugging

## Summary
Provide a detailed view of data as it flows through each node in a workflow execution. For every edge between nodes, show the exact data that was passed — input received, transformations applied, output produced. Users can click on any connection in the workflow graph to see the data payload at that point.

## Problem Statement
Currently, users can see per-node output via `GET /executions/{execution}/nodes`, but they can't easily trace how data transforms as it flows through the graph. When a downstream node receives unexpected data, users must manually compare output of node A with input of node B to find where the data went wrong. The data flow inspector makes this visual and automatic — a critical debugging tool for complex data pipelines.

## Proposed Solution
1. Extend execution data capture to store both input and output for each node execution.
2. For each edge (connection between two nodes), compute the data that flowed through it.
3. Provide an API endpoint that returns edge-level data flow for any execution.
4. Include data transformation summaries: what fields were added, removed, or changed between connected nodes.
5. Integrate with `TimeTravelDebuggerService` to allow inspecting data at any point in time.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/DataFlowController.php`
- **API — New Service:** `apps/api/app/Services/DataFlowInspectorService.php`
- **API — Existing Service:** `apps/api/app/Services/TimeTravelDebuggerService.php` — snapshot data
- **API — Existing Models:** `Execution`, `ExecutionNode`, `ExecutionLog`
- **Engine — Existing:** `apps/engine/internal/worker/` — capture node inputs
- **Engine — Existing:** `apps/engine/internal/resolver/` — data resolution between nodes

## API Endpoints

```
GET    /api/v1/workspaces/{workspace}/executions/{execution}/data-flow
  Response: {
    "execution_id": "uuid",
    "edges": [
      {
        "from_node": "http_1",
        "to_node": "transform_2",
        "data": { "body": { "users": [...] }, "status_code": 200 },
        "size_bytes": 4096,
        "field_count": 12
      },
      {
        "from_node": "transform_2",
        "to_node": "slack_3",
        "data": { "message": "Found 5 new users", "channel": "#team" },
        "size_bytes": 128,
        "field_count": 2,
        "transformation_summary": {
          "fields_added": ["message", "channel"],
          "fields_removed": ["body", "status_code"],
          "fields_from_input": 0
        }
      }
    ]
  }

GET    /api/v1/workspaces/{workspace}/executions/{execution}/data-flow/{nodeKey}
  Response: {
    "node_key": "transform_2",
    "input": { "body": { "users": [...] }, "status_code": 200 },
    "output": { "message": "Found 5 new users", "channel": "#team" },
    "input_source_nodes": ["http_1"],
    "output_target_nodes": ["slack_3"],
    "expressions_evaluated": [
      { "expression": "{{ $node.http_1.body.users.length }}", "result": 5 }
    ]
  }
```

## Data Model

### Extend `execution_nodes` table:
```sql
ALTER TABLE execution_nodes
    ADD COLUMN input_data JSONB,
    ADD COLUMN input_size_bytes INTEGER,
    ADD COLUMN output_size_bytes INTEGER;
```

The `output_data` column likely already exists. Adding `input_data` enables full input/output tracing.

## Implementation Steps
1. Create migration to add `input_data`, `input_size_bytes`, `output_size_bytes` to `execution_nodes`.
2. Update `ExecutionNode` model with new fillable fields.
3. Extend engine callback payload in `apps/engine/internal/callback/` to include node input data.
4. Update `JobCallbackController::handle()` to persist input data.
5. Build `DataFlowInspectorService` with methods: `getFullFlow()`, `getNodeFlow()`, `computeTransformationSummary()`.
6. Implement `computeTransformationSummary()` to diff input and output schemas and identify field-level changes.
7. Create `DataFlowController` with `index` (full flow) and `show` (per-node) actions.
8. Register routes under execution scope.
9. Add data size limits — truncate large payloads (>1MB) with a download link for full data.
10. Write feature tests with various data shapes.

## Dependencies
- `ExecutionNode` model — extended with input data.
- `TimeTravelDebuggerService` — snapshot integration.
- Engine callback system — must send input data.
- `apps/engine/internal/resolver/` — data resolution context.

## Success Metrics
- **Debugging time:** 60% reduction in time to identify data mapping issues.
- **Usage:** 40% of failed execution inspections use data flow view.
- **Bug identification:** 80% of data-related bugs found within first 2 minutes.

## Estimated Effort
2 weeks (1 backend + 1 engine engineer)
- Week 1: Engine input capture, callback extension, migration
- Week 2: Service, controller, transformation summary, testing
