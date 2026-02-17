# Live Execution Streaming

## Status
🟡 Planned

## Priority
High

## Difficulty
Medium

## Category
🤖 AI-Native

## Summary
Stream workflow execution progress in real-time to the frontend via Server-Sent Events (SSE) or WebSockets. Users can watch each node execute live with data flowing through the graph, see timing metrics update, and observe log output as it happens — rather than waiting for the execution to complete and refreshing.

## Problem Statement
Currently, users trigger a workflow and must poll the `GET /executions/{id}` endpoint or refresh the page to see results. For long-running workflows with many nodes, this creates a frustrating blind spot. n8n has basic execution progress but lacks real-time data flow visualization. Live streaming transforms debugging from a post-mortem activity into a real-time interactive experience.

## Proposed Solution
1. The Go engine already sends progress callbacks via `POST /v1/jobs/progress` to `JobCallbackController`.
2. Extend this to broadcast each node completion event to a Redis pub/sub channel.
3. Build an SSE endpoint in Laravel that subscribes to the Redis channel and streams events to connected clients.
4. Events include: `node.started`, `node.completed`, `node.failed`, `execution.completed`, `execution.failed`.
5. Each event payload contains the node key, status, duration, output data summary, and error (if any).

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/ExecutionStreamController.php`
- **API — Existing Controller:** `apps/api/app/Http/Controllers/Api/V1/JobCallbackController.php` — extend `progress` method
- **API — Redis Pub/Sub:** Publish to channel `execution:{execution_id}:events`
- **Engine — Existing:** `apps/engine/internal/callback/` — already sends progress updates
- **Engine — Existing:** `apps/engine/internal/worker/` — emits node completion events
- **Engine — Existing:** `apps/engine/internal/observability/` — provides timing data

## API Endpoints

```
GET    /api/v1/workspaces/{workspace}/executions/{execution}/stream
  Headers: Accept: text/event-stream
  Response: SSE stream of execution events

  Event types:
    event: node.started
    data: { "node_key": "http_1", "started_at": "ISO8601" }

    event: node.completed
    data: { "node_key": "http_1", "duration_ms": 234, "output_summary": {...}, "completed_at": "ISO8601" }

    event: node.failed
    data: { "node_key": "http_1", "error": "Connection timeout", "failed_at": "ISO8601" }

    event: execution.completed
    data: { "execution_id": "uuid", "total_duration_ms": 1520, "node_count": 5 }
```

## Data Model

No new tables required. This feature uses ephemeral Redis pub/sub channels:

```
Channel pattern: execution:{execution_id}:events
TTL: Events auto-expire after 5 minutes (configurable)
```

### Redis message format:
```json
{
  "event": "node.completed",
  "execution_id": "uuid",
  "node_key": "http_1",
  "data": {
    "status": "completed",
    "duration_ms": 234,
    "output_keys": ["body", "status_code", "headers"],
    "output_size_bytes": 1024
  },
  "timestamp": "2026-02-18T10:30:00Z"
}
```

## Implementation Steps
1. Add Redis pub/sub publish call in `JobCallbackController::progress()` method.
2. Create `ExecutionStreamController` with an SSE endpoint that subscribes to the Redis channel.
3. Implement SSE response using Laravel's `StreamedResponse` with proper headers (`Content-Type: text/event-stream`, `Cache-Control: no-cache`).
4. Add authentication check — user must be a member of the workspace owning the execution.
5. Add heartbeat events (every 15 seconds) to keep the connection alive.
6. Add connection timeout (max 30 minutes) to prevent resource leaks.
7. Register route under workspace-scoped group in `api.php`.
8. Extend `apps/engine/internal/callback/` to include richer progress payloads (node timing, output summary).
9. Write feature tests for SSE stream, authentication, and event format.
10. Add rate limiting: max 5 concurrent SSE connections per workspace.

## Dependencies
- `JobCallbackController` — existing progress callback mechanism.
- Redis — already used for queues and caching.
- Engine callback system — `apps/engine/internal/callback/`.

## Success Metrics
- **User engagement:** 60% of users watching at least one execution live within first month.
- **Debugging speed:** 40% reduction in time spent on execution inspection.
- **Page refreshes:** 80% reduction in execution detail page refreshes.

## Estimated Effort
2 weeks (1 backend engineer)
- Week 1: Redis pub/sub integration, SSE controller, authentication
- Week 2: Engine callback enrichment, heartbeat, testing
