# Execution Flame Chart

## Status
🟡 Planned

## Priority
High

## Difficulty
Hard

## Category
🔍 Observability & Debugging

## Summary
Provide a flame chart visualization of workflow executions showing precise timing of every node, including queue wait time, network latency, compute time, and callback overhead. This gives users a performance profiling tool similar to Chrome DevTools flame charts but for workflow executions.

## Problem Statement
The existing execution timeline from `TimeTravelDebuggerService` shows sequential events but lacks a visual representation of parallel execution, queue delays, and time distribution. Users can't identify performance bottlenecks — whether a slow workflow is due to a single slow API call, queue contention, or inefficient sequencing. Flame charts are the industry standard for performance profiling and no workflow platform offers this.

## Proposed Solution
1. Collect fine-grained timing data from the engine for each execution phase: queue wait, expression evaluation, node execution, callback processing.
2. Structure this data into a flame chart format with nested spans (execution → node → phases).
3. Expose via API endpoint that returns the flame chart data structure.
4. Include span annotations for key events: retry attempts, rate limit waits, data transformations.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/ExecutionFlameChartController.php`
- **API — New Service:** `apps/api/app/Services/ExecutionFlameChartService.php`
- **API — Existing Service:** `apps/api/app/Services/TimeTravelDebuggerService.php` — source of timing data
- **API — Existing Models:** `Execution`, `ExecutionNode`, `ExecutionLog`
- **Engine — Existing:** `apps/engine/internal/observability/` — emit detailed span data
- **Engine — Existing:** `apps/engine/internal/worker/` — node execution timing
- **Engine — Existing:** `apps/engine/internal/matching/` — queue wait time
- **Engine — Existing:** `apps/engine/internal/callback/` — callback timing

## API Endpoints

```
GET    /api/v1/workspaces/{workspace}/executions/{execution}/flame-chart
  Response: {
    "execution_id": "uuid",
    "total_duration_ms": 4520,
    "started_at": "ISO8601",
    "spans": [
      {
        "id": "span_1",
        "name": "webhook_trigger",
        "node_key": "webhook_1",
        "type": "node",
        "start_ms": 0,
        "duration_ms": 15,
        "children": [
          { "id": "span_1a", "name": "payload_parse", "type": "phase", "start_ms": 0, "duration_ms": 5 },
          { "id": "span_1b", "name": "expression_eval", "type": "phase", "start_ms": 5, "duration_ms": 10 }
        ]
      },
      {
        "id": "span_2",
        "name": "http_stripe",
        "node_key": "http_1",
        "type": "node",
        "start_ms": 15,
        "duration_ms": 3200,
        "children": [
          { "id": "span_2a", "name": "queue_wait", "type": "phase", "start_ms": 15, "duration_ms": 50 },
          { "id": "span_2b", "name": "expression_eval", "type": "phase", "start_ms": 65, "duration_ms": 8 },
          { "id": "span_2c", "name": "http_request", "type": "phase", "start_ms": 73, "duration_ms": 2900, "metadata": { "url": "https://api.stripe.com/v1/charges", "status": 200 } },
          { "id": "span_2d", "name": "callback", "type": "phase", "start_ms": 2973, "duration_ms": 242 }
        ],
        "annotations": [
          { "time_ms": 1500, "label": "DNS resolution", "type": "info" }
        ]
      }
    ]
  }
```

## Data Model

### New table: `execution_spans`
```sql
CREATE TABLE execution_spans (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    execution_id    UUID NOT NULL REFERENCES executions(id) ON DELETE CASCADE,
    parent_span_id  UUID REFERENCES execution_spans(id) ON DELETE CASCADE,
    node_key        VARCHAR(100),
    name            VARCHAR(100) NOT NULL,
    span_type       VARCHAR(20) NOT NULL,           -- 'node', 'phase', 'system'
    start_offset_ms INTEGER NOT NULL,
    duration_ms     INTEGER NOT NULL,
    metadata        JSONB,
    annotations     JSONB,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_exec_spans_execution ON execution_spans(execution_id);
CREATE INDEX idx_exec_spans_parent ON execution_spans(parent_span_id);
```

## Implementation Steps
1. Create migration for `execution_spans` table.
2. Create `ExecutionSpan` model with self-referencing parent relationship.
3. Extend `apps/engine/internal/observability/` to emit span data for each execution phase.
4. Extend `apps/engine/internal/worker/` to record per-phase timing (queue wait, expression eval, execution, callback).
5. Extend `JobCallbackController::handle()` to persist span data from engine callbacks.
6. Build `ExecutionFlameChartService` with methods: `getFlameChart()`, `buildSpanTree()`, `computeAnnotations()`.
7. Implement `buildSpanTree()` to reconstruct the hierarchical span tree from flat database records.
8. Create controller and route.
9. Write feature tests with mock span data.
10. Add span data to existing engine callback payloads — extend the protobuf definitions in `apps/engine/api/proto/`.

## Dependencies
- `TimeTravelDebuggerService` — complementary timing data.
- `apps/engine/internal/observability/` — engine-side span emission.
- `ExecutionNode` model — node-level timing data.
- Engine callback mechanism — `apps/engine/internal/callback/`.

## Success Metrics
- **Performance debugging:** 50% reduction in time to identify workflow performance bottlenecks.
- **Usage:** 30% of executions inspected via flame chart have actionable findings.
- **Optimization:** Users who view flame charts optimize workflows 40% more often.

## Estimated Effort
3–4 weeks (1 backend + 1 engine engineer)
- Week 1: Engine span emission, protobuf updates
- Week 2: Span persistence, callback extension
- Week 3: Flame chart service, span tree builder
- Week 4: Controller, testing, documentation
