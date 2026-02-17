# Anomaly Detection

## Status
🟡 Planned

## Priority
Medium

## Difficulty
Hard

## Category
🔍 Observability & Debugging

## Summary
Automatically detect anomalous execution patterns — unusual duration spikes, unexpected output sizes, abnormal error rates, or deviation from historical baselines. Alert users proactively before small anomalies become production incidents.

## Problem Statement
Users currently discover issues only when workflows fail completely. But many problems start as subtle degradation: API response times slowly increasing, output data shrinking (indicating upstream data loss), or error rates creeping up. By the time the workflow fails, the root cause may have been building for days. Anomaly detection catches these early signals.

## Proposed Solution
1. Build historical baselines for each workflow's execution metrics: duration, node durations, output sizes, error rates.
2. Compare each new execution against the baseline using statistical methods (z-score, moving averages, percentile thresholds).
3. Flag executions that deviate significantly from the baseline.
4. Send alerts via existing notification channels.
5. Use `ConnectorMetricDaily` data for connector-level anomaly detection.

## Architecture
- **API — New Service:** `apps/api/app/Services/AnomalyDetectionService.php`
- **API — New Job:** `apps/api/app/Jobs/DetectExecutionAnomalies.php`
- **API — New Job:** `apps/api/app/Jobs/ComputeExecutionBaselines.php`
- **API — Existing Models:** `Execution`, `ExecutionNode`, `ConnectorMetricDaily`, `ConnectorCallAttempt`
- **API — Existing Service:** `apps/api/app/Services/ConnectorReliabilityService.php` — reliability data
- **Engine — Existing:** `apps/engine/internal/observability/` — metric emission

## API Endpoints

```
GET    /api/v1/workspaces/{workspace}/anomalies
  Query: ?workflow_id=uuid&severity=high&from=ISO8601&to=ISO8601
  Response: {
    "anomalies": [
      {
        "id": "uuid",
        "workflow_id": "uuid",
        "execution_id": "uuid",
        "type": "duration_spike",
        "severity": "warning",
        "description": "Execution took 12.4s — 3.2x slower than the 30-day average of 3.9s",
        "metric": "total_duration_ms",
        "value": 12400,
        "baseline_value": 3900,
        "deviation_factor": 3.18,
        "node_key": null,
        "detected_at": "ISO8601"
      },
      {
        "id": "uuid",
        "workflow_id": "uuid",
        "execution_id": "uuid",
        "type": "output_size_drop",
        "severity": "critical",
        "description": "Node 'http_api' returned 0 records — baseline is 150-200 records",
        "metric": "output_record_count",
        "value": 0,
        "baseline_value": 175,
        "deviation_factor": -175,
        "node_key": "http_api",
        "detected_at": "ISO8601"
      }
    ],
    "total": 12
  }

GET    /api/v1/workspaces/{workspace}/workflows/{workflow}/baselines
  Response: {
    "workflow_id": "uuid",
    "computed_at": "ISO8601",
    "metrics": {
      "total_duration_ms": { "mean": 3900, "p50": 3700, "p95": 5200, "p99": 8100 },
      "node_durations": {
        "http_api": { "mean": 2100, "p95": 3500 },
        "transform_1": { "mean": 50, "p95": 120 }
      },
      "success_rate": 0.97,
      "sample_count": 450
    }
  }
```

## Data Model

### New table: `execution_baselines`
```sql
CREATE TABLE execution_baselines (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    workflow_id     UUID NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    metrics         JSONB NOT NULL,                  -- per-metric statistics
    sample_count    INTEGER NOT NULL,
    window_days     INTEGER NOT NULL DEFAULT 30,
    computed_at     TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(workflow_id)
);

CREATE INDEX idx_exec_baselines_workspace ON execution_baselines(workspace_id);
```

### New table: `execution_anomalies`
```sql
CREATE TABLE execution_anomalies (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    workflow_id     UUID NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    execution_id    UUID NOT NULL REFERENCES executions(id) ON DELETE CASCADE,
    anomaly_type    VARCHAR(50) NOT NULL,            -- duration_spike, output_size_drop, error_rate_increase
    severity        VARCHAR(20) NOT NULL,            -- info, warning, critical
    description     TEXT NOT NULL,
    metric          VARCHAR(50) NOT NULL,
    observed_value  DOUBLE PRECISION NOT NULL,
    baseline_value  DOUBLE PRECISION NOT NULL,
    deviation_factor DOUBLE PRECISION NOT NULL,
    node_key        VARCHAR(100),
    acknowledged    BOOLEAN NOT NULL DEFAULT FALSE,
    detected_at     TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_exec_anomalies_workspace ON execution_anomalies(workspace_id);
CREATE INDEX idx_exec_anomalies_workflow ON execution_anomalies(workflow_id);
CREATE INDEX idx_exec_anomalies_severity ON execution_anomalies(severity);
```

## Implementation Steps
1. Create migrations for `execution_baselines` and `execution_anomalies` tables.
2. Create `ExecutionBaseline` and `ExecutionAnomaly` models.
3. Build `ComputeExecutionBaselines` job — runs daily, computes rolling 30-day statistics per workflow.
4. Implement baseline computation: mean, median, p50, p95, p99 for duration, output size, error rate.
5. Build `DetectExecutionAnomalies` job — runs after each execution completes (dispatched from `JobCallbackController`).
6. Implement anomaly detection rules:
   - Duration spike: execution time > p95 baseline
   - Output size drop: output size < 10% of baseline mean
   - Error rate increase: rolling 1-hour error rate > 2x baseline
   - New error type: error message pattern not seen in last 30 days
7. Build `AnomalyDetectionService` with methods: `detect()`, `getAnomalies()`, `getBaselines()`.
8. Create controller and routes.
9. Schedule `ComputeExecutionBaselines` job daily via Laravel scheduler.
10. Write feature tests with mock execution data.

## Dependencies
- `Execution` and `ExecutionNode` models — historical execution data.
- `ConnectorMetricDaily` — connector-level baselines.
- Laravel scheduler — for daily baseline computation.

## Success Metrics
- **Early detection:** 70% of production incidents preceded by detected anomalies.
- **False positive rate:** Less than 10% of alerts are false positives.
- **MTTR:** 40% reduction in mean-time-to-resolution for slowly degrading issues.

## Estimated Effort
3 weeks (1 backend engineer)
- Week 1: Baseline computation, statistical engine
- Week 2: Anomaly detection rules, persistence
- Week 3: Controller, alerting, testing
