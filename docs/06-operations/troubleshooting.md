# Troubleshooting Guide

## Common Issues

### 1. Workflows are stuck in "Pending"
-   **Cause**: No workers are polling the task queue.
-   **Check**:
    -   Is `linkflow-worker` running?
    -   Is `linkflow-matching` running?
    -   Check Redis connectivity.

### 2. "Unauthorized" errors between services
-   **Cause**: Mismatched secrets.
-   **Fix**: Ensure `JWT_SECRET` is identical in API and Engine env vars.

### 3. Webhooks not triggering
-   **Cause**: Queue worker is down.
-   **Check**:
    -   `php artisan queue:monitor`
    -   Check `laravel.log` for dispatch errors.

### 4. Database Connection Refused
-   **Cause**: Connection pool exhausted.
-   **Fix**: Increase `max_connections` in Postgres or use PgBouncer.

## Debugging Tools

### Inspect Redis Queues
```bash
redis-cli
> LLEN tasks:default
> LRANGE tasks:default 0 5
```

### Force Workflow Termination
If a workflow is zombie (stuck running forever):
```bash
curl -X POST /api/v1/workspaces/{ws}/executions/{id}/cancel
```

### Reset State (Dev Only)
To wipe everything and start fresh:
```bash
make stop
docker volume prune -f
make start
```
