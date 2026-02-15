# LinkFlow Infrastructure

Shared infrastructure components for LinkFlow services.

## Services

| Service | Port (Dev) | Description |
|---|---|---|
| **PostgreSQL 16** | 5432 | Primary database for API and Engine |
| **Redis 7** | 6379 | Queue, Cache, and Pub/Sub |
| **Nginx** | 80 / 443 | Reverse proxy and TLS termination (production only) |

## Directory Structure

```
infra/
├── init/01-init.sql              # Database initialization (schemas, extensions)
├── nginx/
│   ├── nginx.conf                # Production nginx (SSL, rate limits)
│   ├── nginx.dev.conf            # Development nginx (HTTP only)
│   └── ssl/                      # SSL certificates (production)
└── postgres/
    └── postgresql.prod.conf      # Production-tuned Postgres config
```

## Usage

All infrastructure is managed from the **project root** via Docker Compose layering:

```bash
# Development (from project root)
make dev              # Starts postgres, redis, and all services

# Production (from project root)
make prod             # Starts with nginx, SSL, production Postgres config

# Shells
make shell-db         # PostgreSQL shell
make shell-redis      # Redis CLI
```

> **Note**: There is no `docker-compose.yml` in this directory. All services are defined
> in the root `docker-compose.yml` and layered with environment-specific overrides.

## Production Mode (Nginx)

To enable Nginx with TLS termination:

1. Place your SSL certificates in `nginx/ssl/`:
   - `fullchain.pem`
   - `privkey.pem`
2. Start with the production compose:

```bash
make prod
# Or: docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

## Data Persistence

Docker volumes ensure data survives container restarts:
- `linkflow_postgres_data`: Database files
- `linkflow_redis_data`: Redis persistence
- `linkflow_nginx_logs`: Web server logs (production)

## Production Postgres Tuning

The `postgres/postgresql.prod.conf` is mounted in production and includes:
- Memory: 1GB shared_buffers, 3GB effective_cache_size
- WAL: 64MB buffers, 2GB max WAL size
- Parallel queries: up to 4 workers per gather
- Autovacuum: tuned for high-throughput workloads
- SSL enabled
