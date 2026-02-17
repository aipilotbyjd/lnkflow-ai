# Execution Comparison

## Status
🟡 Planned

## Priority
Medium

## Difficulty
Medium

## Category
🔍 Observability & Debugging

## Summary
Compare two executions of the same workflow side-by-side — highlighting differences in timing, data, status, and errors. Essential for debugging "it worked yesterday but fails today" scenarios. Builds on the existing `DeterministicReplayService` and `TimeTravelDebuggerService`.

## Problem Statement
When a workflow that was working starts failing, the first question is "what changed?" Currently users must open two execution detail pages and manually compare node outputs, timing, and errors. This is slow and error-prone. A dedicated comparison view automates this, instantly showing what's different between a successful and a failed execution.

## Proposed Solution
1. Accept two execution IDs for the same workflow.
2. Align nodes by their key across both executions.
3. For each node, compare: status, duration, input data, output data, error messages.
4. Highlight differences at the field level.
5. Leverage `DeterministicReplayService` to optionally replay one execution with the other's inputs.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/ExecutionComparisonController.php`
- **API — New Service:** `apps/api/app/Services/ExecutionComparisonService.php`
- **API — Existing Services:** `TimeTravelDebuggerService`, `DeterministicReplayService`
- **API — Existing Models:** `Execution`, `ExecutionNode`, `ExecutionLog`, `ExecutionReplayPack`

## API Endpoints

```
GET    /api/v1/workspaces/{workspace}/executions/compare
  Query: ?left=execution_id_1&right=execution_id_2
  Response: {
    "left": { "id": "uuid", "status": "completed", "duration_ms": 3200, "created_at": "ISO8601" },
    "right": { "id": "uuid", "status": "failed", "duration_ms": 8500, "created_at": "ISO8601" },
    "node_comparisons": [
      {
        "node_key": "http_1",
        "left": { "status": "completed", "duration_ms": 1200, "output_summary": {...} },
        "right": { "status": "completed", "duration_ms": 4500, "output_summary": {...} },
        "differences": {
          "duration_change_ms": 3300,
          "duration_change_pct": 275,
          "output_diffs": [
            { "path": "body.users.length", "left": 5, "right": 12 }
          ]
        }
      },
      {
        "node_key": "transform_2",
        "left": { "status": "completed", "duration_ms": 50 },
        "right": { "status": "failed", "error": "Cannot read property 'email' of undefined" },
        "differences": {
          "status_changed": true,
          "error_introduced": true
        }
      }
    ],
    "summary": {
      "total_nodes": 5,
      "identical_nodes": 2,
      "different_nodes": 3,
      "new_errors": 1,
      "duration_change_ms": 5300
    }
  }
```

## Data Model

No new tables required. This feature queries existing `Execution`, `ExecutionNode`, and `ExecutionLog` tables. Comparison results are computed at query time and not persisted.

## Implementation Steps
1. Build `ExecutionComparisonService` with methods: `compare()`, `alignNodes()`, `compareNodePair()`, `computeOutputDiff()`.
2. Implement `alignNodes()` to match nodes by `node_key` across two executions.
3. Implement `compareNodePair()` to compute differences in status, duration, input, output, and errors.
4. Implement `computeOutputDiff()` for deep JSON comparison of node outputs.
5. Validate that both executions belong to the same workflow and workspace.
6. Create `ExecutionComparisonController` with a single `compare` action.
7. Register route under workspace scope.
8. Add optional integration with `DeterministicReplayService` to replay with alternate inputs.
9. Write feature tests with various comparison scenarios.

## Dependencies
- `ExecutionNode` model — node-level data for both executions.
- `TimeTravelDebuggerService` — snapshot data for detailed comparison.
- `DeterministicReplayService` — optional replay integration.

## Success Metrics
- **Debugging speed:** 70% reduction in time to identify what changed between two executions.
- **Usage:** 25% of failed execution investigations use comparison view.
- **Root cause identification:** 80% of comparisons lead to root cause within 5 minutes.

## Estimated Effort
1.5 weeks (1 backend engineer)
- Week 1: Comparison service, node alignment, diff engine
- Week 1.5: Controller, testing, edge cases
