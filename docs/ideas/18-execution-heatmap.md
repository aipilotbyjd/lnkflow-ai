# Execution Heatmap

## Status
🟡 Planned

## Priority
Low

## Difficulty
Medium

## Category
🔍 Observability & Debugging

## Summary
Visualize execution patterns across time with a GitHub-style contribution heatmap showing execution volume, success rates, and duration trends. Each cell represents an hour or day, colored by execution health. Helps users identify patterns like "failures always happen on Monday mornings" or "throughput drops during business hours."

## Problem Statement
Users lack a temporal overview of their workflow execution patterns. The existing `ExecutionController::stats()` endpoint provides aggregate statistics but doesn't show temporal patterns. Understanding when workflows run, when they fail, and how performance varies over time is crucial for capacity planning, scheduling optimization, and incident prevention.

## Proposed Solution
1. Aggregate execution data into time buckets (hourly or daily).
2. Compute metrics per bucket: execution count, success rate, average duration, error count.
3. Return a heatmap data structure that can be rendered as a grid visualization.
4. Support multiple heatmap types: volume, success rate, duration.
5. Filter by workflow, status, or tag.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/ExecutionHeatmapController.php`
- **API — New Service:** `apps/api/app/Services/ExecutionHeatmapService.php`
- **API — Existing Models:** `Execution`, `ExecutionNode`
- **API — Existing Controller:** `ExecutionController` — extend with heatmap data

## API Endpoints

```
GET    /api/v1/workspaces/{workspace}/executions/heatmap
  Query: ?workflow_id=uuid&metric=success_rate&granularity=hourly&days=90
  Response: {
    "metric": "success_rate",
    "granularity": "hourly",
    "start_date": "2025-11-20",
    "end_date": "2026-02-18",
    "cells": [
      { "date": "2026-02-18", "hour": 10, "value": 0.95, "count": 23, "level": 4 },
      { "date": "2026-02-18", "hour": 9, "value": 0.88, "count": 18, "level": 3 },
      { "date": "2026-02-17", "hour": 14, "value": 0.40, "count": 5, "level": 1 }
    ],
    "scale": {
      "min": 0,
      "max": 1.0,
      "levels": [
        { "level": 0, "label": "No data", "range": null },
        { "level": 1, "label": "Critical (<60%)", "range": [0, 0.6] },
        { "level": 2, "label": "Warning (60-80%)", "range": [0.6, 0.8] },
        { "level": 3, "label": "Good (80-95%)", "range": [0.8, 0.95] },
        { "level": 4, "label": "Excellent (>95%)", "range": [0.95, 1.0] }
      ]
    },
    "patterns_detected": [
      "Higher failure rate on Mondays between 9-11 AM",
      "Execution volume peaks at 2 PM UTC daily"
    ]
  }
```

## Data Model

No new tables required. The heatmap is computed from existing `executions` table using aggregation queries:

```sql
SELECT
    DATE(started_at) as date,
    EXTRACT(HOUR FROM started_at) as hour,
    COUNT(*) as total,
    COUNT(*) FILTER (WHERE status = 'completed') as succeeded,
    AVG(duration_ms) as avg_duration
FROM executions
WHERE workspace_id = ? AND started_at >= ?
GROUP BY DATE(started_at), EXTRACT(HOUR FROM started_at)
ORDER BY date, hour;
```

## Implementation Steps
1. Build `ExecutionHeatmapService` with methods: `getHeatmap()`, `aggregateByTime()`, `computeLevels()`, `detectPatterns()`.
2. Implement `aggregateByTime()` with raw SQL for efficient aggregation across large datasets.
3. Support granularity options: hourly (for 7-30 day windows), daily (for 30-365 day windows).
4. Implement `computeLevels()` to map metric values to 0-4 intensity levels.
5. Implement `detectPatterns()` for basic pattern detection (e.g., recurring failures on specific days/hours).
6. Create `ExecutionHeatmapController` with a single `index` action.
7. Register route under workspace scope.
8. Add caching with 5-minute TTL for frequently accessed heatmaps.
9. Write feature tests with mock execution data.

## Dependencies
- `Execution` model — execution history.
- `ExecutionController::stats()` — complementary statistics.

## Success Metrics
- **Pattern discovery:** 30% of users discover actionable patterns via heatmap.
- **Scheduling optimization:** 15% of workflows rescheduled after viewing heatmap.
- **Usage:** Heatmap viewed at least monthly by 40% of active workspaces.

## Estimated Effort
1 week (1 backend engineer)
- Days 1-3: Aggregation service, level computation
- Days 4-5: Controller, pattern detection, testing
