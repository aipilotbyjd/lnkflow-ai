# Backup & Restore

## Database (PostgreSQL)

### Backup
Use `pg_dump` to create a consistent snapshot.

```bash
# Docker
docker exec linkflow-postgres pg_dump -U linkflow linkflow > backup_$(date +%Y%m%d).sql

# Kubernetes
kubectl exec -it linkflow-postgres-0 -- pg_dump -U linkflow linkflow > backup.sql
```

### Restore
**Warning**: This overwrites existing data.

```bash
# Docker
cat backup.sql | docker exec -i linkflow-postgres psql -U linkflow linkflow
```

## Credential Encryption Key

**CRITICAL**: You must back up your `APP_KEY` (Laravel) and `JWT_SECRET`.
If you lose the `APP_KEY`, all encrypted credentials in the database become unreadable forever.

## Redis

Redis persistence is configured via AOF (Append Only File).
-   Back up the `appendonly.aof` file from the Redis volume.
-   Redis is generally treated as ephemeral cache, but the Matching Service uses it for active task queues. Loss of Redis means loss of *pending* tasks (not history).
