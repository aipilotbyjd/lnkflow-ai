# LinkFlow Infrastructure

PostgreSQL and Redis stack shared by all LinkFlow services.

## Quick Start

```bash
# Start infrastructure
docker-compose up -d

# Check status
docker-compose ps

# View logs
docker-compose logs -f

# Stop (keeps data)
docker-compose down

# Stop and delete all data
docker-compose down -v
```

## Services

| Service | Port | Description |
|---------|------|-------------|
| PostgreSQL | 5432 | Database |
| Redis | 6379 | Queue & Cache |

## Configuration

Create `.env` file to override defaults:

```env
POSTGRES_USER=linkflow
POSTGRES_PASSWORD=your-secure-password
POSTGRES_DB=linkflow
POSTGRES_PORT=5432
REDIS_PORT=6379
```

## Connecting From Other Stacks

Other docker-compose files connect via external network:

```yaml
networks:
  linkflow:
    external: true
```

Services connect using container names:
- Database: `linkflow-postgres:5432`
- Redis: `linkflow-redis:6379`

## Data Persistence

Data is stored in Docker volumes:
- `linkflow_postgres_data` - Database files
- `linkflow_redis_data` - Redis AOF/RDB files

## Backup

```bash
# Backup PostgreSQL
docker exec linkflow-postgres pg_dump -U linkflow linkflow > backup.sql

# Restore PostgreSQL
docker exec -i linkflow-postgres psql -U linkflow linkflow < backup.sql
```
