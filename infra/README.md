# LinkFlow Infrastructure

Shared infrastructure stack for LinkFlow services.

## Services

| Service | Container Name | Port (Host) | Description |
|---|---|---|---|
| **PostgreSQL** | `linkflow-postgres` | 5432 | Primary database for API and Engine |
| **Redis** | `linkflow-redis` | 6379 | Queue, Cache, and Pub/Sub |
| **Nginx** | `linkflow-nginx` | 80 / 443 | Reverse proxy and TLS termination (Production profile) |

## Quick Start

We recommend using the root `Makefile` for management, but you can run this stack independently.

### 1. Setup Environment
Copy the example environment file and set secure passwords:

```bash
cp .env.example .env
# Edit .env and set POSTGRES_PASSWORD, REDIS_PASSWORD, etc.
```

### 2. Run via Root Makefile (Recommended)
From the project root:

```bash
make infra-up
```

### 3. Run Manually
```bash
docker-compose up -d
```

## Production Mode (Nginx)

To enable Nginx with TLS termination:

1. Place your SSL certificates in `nginx/ssl/`:
   - `fullchain.pem`
   - `privkey.pem`
2. Start with the production profile:

```bash
# Via Makefile
make prod-up

# Or manually
docker-compose --profile production up -d
```

## Data Persistence

Docker volumes ensure data survives container restarts:
- `linkflow_postgres_data`: Database files
- `linkflow_redis_data`: Redis persistence
- `linkflow_nginx_logs`: Web server logs
