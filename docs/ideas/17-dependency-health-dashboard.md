# Dependency Health Dashboard

## Status
🟡 Planned

## Priority
Medium

## Difficulty
Medium

## Category
🔍 Observability & Debugging

## Summary
A unified dashboard showing the health status of all external services and APIs that workflows depend on. Aggregates data from `ConnectorMetricDaily`, `ConnectorCallAttempt`, and execution history to show real-time reliability scores, latency trends, error patterns, and affected workflows for each dependency.

## Problem Statement
Workflows depend on external services (Stripe, Slack, databases, custom APIs). When a third-party service degrades, multiple workflows may be affected. The existing `ConnectorReliabilityController` provides per-connector reliability data, but there's no holistic view showing all dependencies, their health, and which workflows they impact. Ops teams need a single pane of glass for dependency health.

## Proposed Solution
1. Aggregate data from `ConnectorMetricDaily` and `ConnectorCallAttempt` to compute per-dependency health scores.
2. Cross-reference with workflow definitions to map dependencies to affected workflows.
3. Provide a dashboard API with health status, latency trends, error rate trends, and affected workflow counts.
4. Include external status page integration (e.g., check Stripe's status page for known incidents).
5. Auto-correlate workflow failures with dependency degradation.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/DependencyHealthController.php`
- **API — New Service:** `apps/api/app/Services/DependencyHealthService.php`
- **API — Existing Service:** `apps/api/app/Services/ConnectorReliabilityService.php`
- **API — Existing Models:** `ConnectorMetricDaily`, `ConnectorCallAttempt`, `Workflow`, `Execution`
- **API — Existing Controller:** `ConnectorReliabilityController` — extend with dashboard data

## API Endpoints

```
GET    /api/v1/workspaces/{workspace}/dependencies/health
  Response: {
    "dependencies": [
      {
        "connector_key": "stripe",
        "display_name": "Stripe API",
        "health_score": 98.5,
        "status": "healthy",            -- healthy, degraded, down
        "metrics": {
          "success_rate_24h": 0.985,
          "avg_latency_ms": 340,
          "p95_latency_ms": 850,
          "total_calls_24h": 1240,
          "errors_24h": 19
        },
        "trend": {
          "latency_7d": [320, 330, 340, 350, 340, 335, 340],
          "error_rate_7d": [0.01, 0.012, 0.015, 0.013, 0.011, 0.014, 0.015]
        },
        "affected_workflows": [
          { "id": "uuid", "name": "Payment Processing", "last_error_at": "ISO8601" }
        ],
        "affected_workflow_count": 3,
        "last_error": {
          "message": "Rate limit exceeded",
          "occurred_at": "ISO8601",
          "execution_id": "uuid"
        }
      }
    ],
    "overall_health": 96.2,
    "total_dependencies": 8,
    "degraded_count": 1,
    "down_count": 0
  }

GET    /api/v1/workspaces/{workspace}/dependencies/{connectorKey}/history
  Query: ?days=30
  Response: {
    "connector_key": "stripe",
    "daily_metrics": [
      { "date": "2026-02-17", "success_rate": 0.985, "avg_latency_ms": 340, "total_calls": 1240 },
      { "date": "2026-02-16", "success_rate": 0.992, "avg_latency_ms": 330, "total_calls": 1180 }
    ],
    "incidents": [
      { "date": "2026-02-14", "description": "Elevated error rate (5.2%)", "duration_hours": 2 }
    ]
  }
```

## Data Model

No new tables required. This feature aggregates existing data from:
- `connector_metric_dailies` — daily aggregated metrics per connector
- `connector_call_attempts` — individual call attempts with timing and status
- `workflows` — workflow definitions containing node types/connector references

Optionally, add a materialized view for performance:
```sql
CREATE MATERIALIZED VIEW dependency_health_summary AS
SELECT
    workspace_id,
    connector_key,
    AVG(success_rate) as avg_success_rate,
    AVG(avg_latency_ms) as avg_latency,
    SUM(total_calls) as total_calls,
    MAX(date) as last_updated
FROM connector_metric_dailies
WHERE date >= CURRENT_DATE - INTERVAL '7 days'
GROUP BY workspace_id, connector_key;
```

## Implementation Steps
1. Build `DependencyHealthService` with methods: `getDashboard()`, `computeHealthScore()`, `getAffectedWorkflows()`, `getDependencyHistory()`.
2. Implement `computeHealthScore()` using weighted formula: `(success_rate * 0.5) + (latency_score * 0.3) + (trend_score * 0.2)`.
3. Implement `getAffectedWorkflows()` by parsing workflow definitions to find which workflows use each connector.
4. Query `ConnectorMetricDaily` for trends and `ConnectorCallAttempt` for recent errors.
5. Add status classification: healthy (>95% success), degraded (80-95%), down (<80%).
6. Create `DependencyHealthController` with `index` (dashboard) and `history` (per-connector) actions.
7. Register routes under workspace scope.
8. Optionally create the materialized view and refresh it every 5 minutes via scheduler.
9. Write feature tests with mock connector data.

## Dependencies
- `ConnectorReliabilityService` — existing reliability logic.
- `ConnectorMetricDaily` model — daily metric aggregates.
- `ConnectorCallAttempt` model — individual call data.

## Success Metrics
- **Proactive detection:** 60% of dependency issues detected via dashboard before user reports.
- **Usage:** Dashboard viewed at least weekly by 50% of active workspaces.
- **Incident correlation:** 80% of multi-workflow failures correlated with dependency degradation.

## Estimated Effort
2 weeks (1 backend engineer)
- Week 1: Health score computation, affected workflow mapping
- Week 2: Controller, trends, history endpoint, testing
