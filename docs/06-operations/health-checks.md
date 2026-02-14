# Health Checks Runbook

## Overview

Health checks are critical for container orchestration platforms like Kubernetes and Docker to manage application lifecycle. They enable:

- **Automatic container restarts** when applications become unresponsive
- **Traffic routing** only to healthy instances
- **Zero-downtime deployments** through rolling updates
- **Load balancer integration** to remove unhealthy backends
- **Self-healing infrastructure** that recovers from transient failures

LinkFlow uses two types of health checks:
- **Liveness probes**: Detect if the application is running (restart if failing)
- **Readiness probes**: Detect if the application can serve traffic (remove from load balancer if failing)

---

## API Health Endpoints (Laravel)

### `/healthz` - Liveness Check

Basic check that verifies the Laravel application is running and can respond to HTTP requests.

```php
// routes/api.php
Route::get('/healthz', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
    ]);
});
```

### `/readyz` - Readiness Check

Comprehensive check that verifies all dependencies (database, Redis, cache) are available.

```php
// routes/api.php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;

Route::get('/readyz', function () {
    $checks = [];
    $healthy = true;

    // Database check
    try {
        DB::connection()->getPdo();
        $checks['database'] = 'ok';
    } catch (\Exception $e) {
        $checks['database'] = 'error: ' . $e->getMessage();
        $healthy = false;
    }

    // Redis check
    try {
        Redis::ping();
        $checks['redis'] = 'ok';
    } catch (\Exception $e) {
        $checks['redis'] = 'error: ' . $e->getMessage();
        $healthy = false;
    }

    // Cache check
    try {
        Cache::put('health_check', true, 10);
        Cache::get('health_check');
        $checks['cache'] = 'ok';
    } catch (\Exception $e) {
        $checks['cache'] = 'error: ' . $e->getMessage();
        $healthy = false;
    }

    $statusCode = $healthy ? 200 : 503;

    return response()->json([
        'status' => $healthy ? 'ok' : 'degraded',
        'checks' => $checks,
        'timestamp' => now()->toISOString(),
    ], $statusCode);
});
```

### Health Check Controller (Production)

For production use, create a dedicated controller:

```php
// app/Http/Controllers/HealthController.php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function liveness(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function readiness(): JsonResponse
    {
        $checks = $this->runChecks();
        $healthy = !in_array(false, array_column($checks, 'ok'));

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function runChecks(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        try {
            Redis::ping();
            return ['ok' => true];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
```

---

## Engine Health Endpoints (Go Microservices)

Each Go microservice exposes a `/health` endpoint for both liveness and readiness:

| Service       | HTTP Port | Health Endpoint              |
|---------------|-----------|------------------------------|
| Frontend      | 8080      | `http://localhost:8080/health` |
| History       | 8081      | `http://localhost:8081/health` |
| Matching      | 8082      | `http://localhost:8082/health` |
| Worker        | 8083      | `http://localhost:8083/health` |
| Timer         | 8084      | `http://localhost:8084/health` |
| Visibility    | 8085      | `http://localhost:8085/health` |
| Edge          | 8086      | `http://localhost:8086/health` |
| Control Plane | 8087      | `http://localhost:8087/health` |

### Example Health Handler (Go)

```go
// internal/handler/health.go
func (h *HTTPHandler) Health(w http.ResponseWriter, r *http.Request) {
    h.writeJSON(w, http.StatusOK, map[string]string{"status": "healthy"})
}

// With dependency checks
func (h *HTTPHandler) Readiness(w http.ResponseWriter, r *http.Request) {
    checks := make(map[string]string)
    healthy := true

    // Database check
    if err := h.db.PingContext(r.Context()); err != nil {
        checks["database"] = err.Error()
        healthy = false
    } else {
        checks["database"] = "ok"
    }

    // Redis check
    if err := h.redis.Ping(r.Context()).Err(); err != nil {
        checks["redis"] = err.Error()
        healthy = false
    } else {
        checks["redis"] = "ok"
    }

    status := http.StatusOK
    if !healthy {
        status = http.StatusServiceUnavailable
    }

    h.writeJSON(w, status, map[string]interface{}{
        "status": healthy,
        "checks": checks,
    })
}
```

### gRPC Health Checking Protocol

For gRPC services, implement the standard health checking protocol:

```protobuf
// grpc/health/v1/health.proto
syntax = "proto3";
package grpc.health.v1;

service Health {
    rpc Check(HealthCheckRequest) returns (HealthCheckResponse);
    rpc Watch(HealthCheckRequest) returns (stream HealthCheckResponse);
}

message HealthCheckRequest {
    string service = 1;
}

message HealthCheckResponse {
    enum ServingStatus {
        UNKNOWN = 0;
        SERVING = 1;
        NOT_SERVING = 2;
        SERVICE_UNKNOWN = 3;
    }
    ServingStatus status = 1;
}
```

```go
// Using grpc-health-probe
import "google.golang.org/grpc/health"
import "google.golang.org/grpc/health/grpc_health_v1"

healthServer := health.NewServer()
grpc_health_v1.RegisterHealthServer(grpcServer, healthServer)
healthServer.SetServingStatus("", grpc_health_v1.HealthCheckResponse_SERVING)
```

---

## Docker Compose Health Checks

### Infrastructure Services

```yaml
# infra/docker-compose.yml
services:
  postgres:
    image: postgres:16-alpine
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${POSTGRES_USER:-linkflow} -d ${POSTGRES_DB:-linkflow}"]
      interval: 5s
      timeout: 5s
      retries: 5
      start_period: 10s

  redis:
    image: redis:7-alpine
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 5
```

### API Service

```yaml
# apps/api/docker-compose.yml
services:
  api:
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8000/api/healthz"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 60s
```

### Engine Services

```yaml
# apps/engine/docker-compose.yml
services:
  frontend:
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://localhost:8080/health"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 10s
    depends_on:
      history:
        condition: service_healthy
      matching:
        condition: service_healthy

  history:
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://localhost:8080/health"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s
```

---

## Kubernetes Probes

### API Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: linkflow-api
spec:
  template:
    spec:
      containers:
        - name: api
          image: linkflow/api:latest
          ports:
            - containerPort: 8000
          livenessProbe:
            httpGet:
              path: /api/healthz
              port: 8000
            initialDelaySeconds: 30
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 3
          readinessProbe:
            httpGet:
              path: /api/readyz
              port: 8000
            initialDelaySeconds: 10
            periodSeconds: 5
            timeoutSeconds: 3
            failureThreshold: 3
          startupProbe:
            httpGet:
              path: /api/healthz
              port: 8000
            initialDelaySeconds: 5
            periodSeconds: 5
            failureThreshold: 30  # 5s * 30 = 150s max startup time
```

### Engine Service Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: linkflow-frontend
spec:
  template:
    spec:
      containers:
        - name: frontend
          image: linkflow/engine-frontend:latest
          ports:
            - containerPort: 8080
              name: http
            - containerPort: 9090
              name: grpc
          livenessProbe:
            httpGet:
              path: /health
              port: 8080
            initialDelaySeconds: 10
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 3
          readinessProbe:
            httpGet:
              path: /health
              port: 8080
            initialDelaySeconds: 5
            periodSeconds: 5
            timeoutSeconds: 3
            failureThreshold: 3
```

### gRPC Probe (Kubernetes 1.24+)

```yaml
livenessProbe:
  grpc:
    port: 9090
    service: ""  # Empty string checks all services
  initialDelaySeconds: 10
  periodSeconds: 10
```

---

## Monitoring Integration

### Prometheus Metrics

Expose health check results as Prometheus metrics:

```go
// Go service
var (
    healthStatus = prometheus.NewGaugeVec(
        prometheus.GaugeOpts{
            Name: "linkflow_health_status",
            Help: "Health status of the service (1 = healthy, 0 = unhealthy)",
        },
        []string{"service", "check"},
    )
)

func updateHealthMetrics(checks map[string]bool) {
    for check, healthy := range checks {
        value := 0.0
        if healthy {
            value = 1.0
        }
        healthStatus.WithLabelValues(serviceName, check).Set(value)
    }
}
```

### Grafana Dashboard Query

```promql
# Overall health status
sum(linkflow_health_status) by (service)

# Alert on unhealthy services
linkflow_health_status == 0
```

### AlertManager Rules

```yaml
groups:
  - name: linkflow-health
    rules:
      - alert: ServiceUnhealthy
        expr: linkflow_health_status == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "{{ $labels.service }} health check failing"
          description: "{{ $labels.check }} check has been failing for more than 1 minute"

      - alert: HighReadinessFailureRate
        expr: |
          rate(http_requests_total{path="/readyz", status!="200"}[5m])
          / rate(http_requests_total{path="/readyz"}[5m]) > 0.1
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High readiness check failure rate"
```

### Health Check Aggregation Script

```bash
#!/bin/bash
# scripts/check-health.sh

services=(
    "http://localhost:8000/api/healthz:API"
    "http://localhost:8080/health:Frontend"
    "http://localhost:8081/health:History"
    "http://localhost:8082/health:Matching"
    "http://localhost:8083/health:Worker"
    "http://localhost:8084/health:Timer"
    "http://localhost:8085/health:Visibility"
)

echo "=== LinkFlow Health Check ==="
echo ""

for service in "${services[@]}"; do
    url="${service%%:*}"
    name="${service##*:}"

    response=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$url" 2>/dev/null)

    if [ "$response" = "200" ]; then
        echo "✅ $name: healthy"
    else
        echo "❌ $name: unhealthy (HTTP $response)"
    fi
done
```

---

## Troubleshooting

### Common Issues

| Symptom | Possible Cause | Solution |
|---------|----------------|----------|
| Liveness failing on startup | `initialDelaySeconds` too short | Increase startup delay |
| Readiness flapping | Database connection pool exhausted | Increase pool size, add connection timeout |
| All services unhealthy | Infrastructure down | Check PostgreSQL and Redis |
| Intermittent failures | Network issues | Check DNS resolution, increase timeouts |

### Debug Commands

```bash
# Check container health status
docker inspect --format='{{.State.Health.Status}}' linkflow-api

# View health check logs
docker inspect --format='{{json .State.Health}}' linkflow-api | jq

# Manual health check
curl -v http://localhost:8000/api/readyz | jq

# Check Kubernetes probe status
kubectl describe pod linkflow-api-xxx | grep -A 20 "Conditions"
```
