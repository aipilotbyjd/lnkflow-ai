# Monitoring Guide

## Metrics (Prometheus)

LinkFlow services expose Prometheus-compatible metrics at `/metrics`.

### Key Metrics to Watch

| Service | Metric | Description |
|---------|--------|-------------|
| **API** | `http_requests_total` | Request volume (by status code) |
| **API** | `http_request_duration_seconds` | Latency (p95, p99) |
| **History** | `persistence_latency` | Database write latency |
| **Matching** | `task_queue_latency` | Time tasks wait in queue |
| **Worker** | `workflow_success_total` | Successful executions |
| **Worker** | `workflow_failed_total` | Failed executions |

## Logging (Structured)

All services emit structured JSON logs to `stdout`.

```json
{
  "level": "info",
  "ts": "2024-03-20T10:00:00Z",
  "caller": "worker/executor.go:123",
  "msg": "Task completed",
  "workflow_id": "uuid",
  "node_id": "node-1",
  "duration_ms": 150
}
```

### Log Levels
-   `DEBUG`: Detailed flow information (verbose).
-   `INFO`: Standard operational events (start/stop, major steps).
-   `WARN`: Recoverable errors (retries).
-   `ERROR`: Recoverable but significant failures.
-   `FATAL`: Service crash.

## Tracing (OpenTelemetry)

LinkFlow supports OpenTelemetry for distributed tracing. Configure the `OTEL_EXPORTER_OTLP_ENDPOINT` environment variable to send traces to Jaeger, Zipkin, or Honeycomb.
