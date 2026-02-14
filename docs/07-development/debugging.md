# Debugging Guide

Tips and tricks for debugging LinkFlow locally.

## API Debugging

### Laravel Telescope
(If installed) Access at `/telescope` to view requests, exceptions, and database queries.

### Xdebug
To enable Xdebug in Docker:
1.  Uncomment Xdebug lines in `docker/php/ini`.
2.  Rebuild images.
3.  Configure IDE to listen on port 9003.

### Logs
Tail the logs:
```bash
docker-compose logs -f api
```

## Engine Debugging

### Delve (Go Debugger)
To debug a running container:
1.  Attach to the container.
2.  Run `dlv attach <pid>`.

### Detailed Logging
Increase log level in `.env`:
```bash
LOG_LEVEL=debug
```

### Redis Inspection
Monitor Redis commands in real-time:
```bash
docker exec -it linkflow-redis redis-cli monitor
```

### Database Inspection
Check running queries:
```sql
SELECT pid, state, query FROM pg_stat_activity;
```
