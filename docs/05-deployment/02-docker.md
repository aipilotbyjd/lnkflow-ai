# Docker Infrastructure Guide

**Last updated**: 2026-02-15

LinkFlow uses a **layered Docker Compose** pattern — one base file with environment-specific overrides for development, production, and CI/CD testing.

---

## TL;DR

```bash
make setup                    # First-time: copy .env files
make dev                      # Start development stack
make prod                     # Start production stack
make prod-scale-workers N=5   # Scale Go workers
```

---

## Table of Contents

- [Architecture](#architecture)
- [File Structure](#file-structure)
- [How Layering Works](#how-layering-works)
- [Environment Setup](#environment-setup)
- [Development](#development)
- [Production](#production)
- [CI/CD Testing](#cicd-testing)
- [Services Reference](#services-reference)
- [Scaling](#scaling)
- [Environment Variables](#environment-variables)
- [Makefile Reference](#makefile-reference)
- [Troubleshooting](#troubleshooting)

---

## Architecture

```
                         ┌──────────────┐
                         │    Client    │
                         └──────┬───────┘
                                │
               ┌────────────────┼────────────────┐
               │ Dev: direct    │ Prod: nginx    │
               │ ports (8000,   │ ports (80,443) │
               │ 8080)          │                │
               └────────┬───────┴───────┬────────┘
                        │               │
              ┌─────────▼──┐    ┌───────▼─────┐
              │  Laravel   │    │  Go Engine  │
              │  API :8000 │    │ Frontend    │
              │            │    │  :8080      │
              └─────┬──────┘    └──────┬──────┘
                    │                  │
         ┌──────┬──┘     ┌─────┬──────┼──────┬────────┐
         │      │        │     │      │      │        │
       Queue  Sched.  History Match  Timer  Worker  Visibility
         │      │        │     │      │      │        │
         └──────┴────────┴─────┴──────┴──────┴────────┘
                    │                  │
              ┌─────▼──────┐    ┌─────▼──────┐
              │ PostgreSQL │    │   Redis    │
              │    :5432   │    │   :6379    │
              └────────────┘    └────────────┘
```

### Communication Flow

| From | To | Protocol |
|------|----|----------|
| Client | API | HTTP/REST |
| Client | Engine | HTTP (via Frontend gateway) |
| API ↔ Engine | Redis Streams | Async job dispatch |
| Engine Services | Engine Services | gRPC |
| Engine → API | HTTP Callbacks | HMAC-signed |

---

## File Structure

```
lnkflow-ai/
├── docker-compose.yml              # Base: ALL services, production-ready defaults
├── docker-compose.override.yml     # Dev: auto-loaded, volumes, ports, debug
├── docker-compose.prod.yml         # Prod: nginx, replicas, resources, SSL
├── docker-compose.test.yml         # CI: ephemeral, test DB, fixed ports
│
├── .env.example                    # All shared env vars (single source of truth)
├── .env                            # Your local values (gitignored)
│
├── Makefile                        # Operations commands
│
├── apps/
│   ├── api/
│   │   ├── Dockerfile              # Multi-stage: development + production targets
│   │   ├── .env.docker.example     # Laravel-specific config (no secrets)
│   │   ├── .env.docker             # Laravel runtime (gitignored)
│   │   └── docker/supervisord.conf # Production process manager
│   └── engine/
│       └── Dockerfile              # Multi-stage Go build (SERVICE build arg)
│
└── infra/
    ├── init/01-init.sql            # DB schemas, extensions (runs on first boot)
    ├── nginx/
    │   ├── nginx.conf              # Production (SSL, rate limits, 2 server blocks)
    │   ├── nginx.dev.conf          # Dev (HTTP only, simple proxy)
    │   └── ssl/                    # Certificate directory
    └── postgres/
        └── postgresql.prod.conf    # Tuned for production workloads
```

---

## How Layering Works

Docker Compose supports **merging multiple files**. Values in later files override earlier ones.

### The Override Pattern

```
┌─────────────────────────────────────────────────────┐
│  docker-compose.yml (base)                          │
│  All 13 services with production-ready defaults     │
│  No ports exposed, no volumes, production targets   │
├─────────────────────────────────────────────────────┤
│                    MERGED WITH                      │
├──────────────┬──────────────┬───────────────────────┤
│  override.yml│  prod.yml    │  test.yml             │
│  (Dev)       │  (Prod)      │  (CI)                 │
│  Auto-loaded │  Explicit -f │  Explicit -f          │
│              │              │                       │
│  + dev target│  + nginx     │  + test DB            │
│  + volumes   │  + replicas  │  + fixed ports        │
│  + ports     │  + resources │  + dev target         │
│  + debug     │  + SSL mode  │  + container names    │
└──────────────┴──────────────┴───────────────────────┘
```

### Key Behavior

| Scenario | Files Loaded | Command |
|----------|-------------|---------|
| **Development** | `yml` + `override.yml` (auto) | `docker compose up -d` |
| **Production** | `yml` + `prod.yml` (explicit) | `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d` |
| **CI/Testing** | `yml` + `test.yml` (explicit) | `docker compose -f docker-compose.yml -f docker-compose.test.yml up -d` |

**Why `override.yml` for dev?** Docker Compose automatically loads `docker-compose.override.yml` when you run `docker compose up` without any `-f` flags. This means developers get the right config with zero friction — no flags to remember.

**Why explicit `-f` for prod/test?** When you specify `-f`, Docker Compose skips the auto-loaded override file. This ensures production never accidentally gets dev config (volume mounts, debug mode, etc.).

---

## Environment Setup

### First-Time Setup

```bash
make setup
```

This copies `.env.example` → `.env` and `apps/api/.env.docker.example` → `apps/api/.env.docker`.

### Required Secrets

Generate and fill in your `.env`:

```bash
# Generate passwords
openssl rand -base64 24   # For POSTGRES_PASSWORD, REDIS_PASSWORD

# Generate secrets
openssl rand -base64 32   # For LINKFLOW_SECRET, JWT_SECRET

# Generate Laravel key (after first container build)
docker compose run --rm api php artisan key:generate --show
```

| Variable | Purpose | Generator |
|----------|---------|-----------|
| `POSTGRES_PASSWORD` | Database auth | `openssl rand -base64 24` |
| `REDIS_PASSWORD` | Redis auth | `openssl rand -base64 24` |
| `LINKFLOW_SECRET` | Engine↔API callback signing | `openssl rand -base64 32` |
| `JWT_SECRET` | Engine JWT signing | `openssl rand -base64 32` |
| `APP_KEY` | Laravel encryption | `php artisan key:generate --show` |

### Where Secrets Live

```
.env (root)                     ← ALL secrets live here
    │
    ├── docker-compose.yml reads .env
    │   └── environment: block injects into each service
    │
    └── apps/api/.env.docker    ← Laravel app config ONLY (no secrets)
        Loaded via env_file: directive
        Secrets override from compose environment block
```

> **Important**: Never put secrets in `apps/api/.env.docker`. They are injected
> automatically from the root `.env` via the compose `environment:` block.

---

## Development

### Start

```bash
make dev          # Starts all core services
make dev-full     # Also starts scheduler + edge service
make dev-build    # Rebuild images and start
```

### What `docker-compose.override.yml` Adds

| Override | Detail |
|----------|--------|
| Build target | `development` (includes bash, vim, artisan serve) |
| Volume mounts | `./apps/api:/var/www/html` for live reload |
| Port exposure | Postgres (5432), Redis (6379), API (8000), Engine (8080, 9090) |
| Container names | Fixed names like `linkflow-api` for easy `docker exec` |
| Environment | `APP_DEBUG=true`, `LOG_LEVEL=debug` |
| Postgres SSL | `sslmode=disable` (base default, no override needed) |

### Access Points (Dev)

| Service | URL / Port |
|---------|-----------|
| Laravel API | http://localhost:8000 |
| Engine Frontend | http://localhost:8080 |
| Engine gRPC | localhost:9090 |
| PostgreSQL | localhost:5432 |
| Redis | localhost:6379 |

### Useful Commands

```bash
make shell-api      # bash into API container
make shell-db       # psql into Postgres
make shell-redis    # redis-cli
make logs           # Tail all logs
make ps             # Service status
make api-cache      # Clear Laravel caches
```

---

## Production

### Start

```bash
make prod           # Core services + nginx
make prod-full      # + scheduler, edge, nginx
make prod-build     # Rebuild and start
```

### What `docker-compose.prod.yml` Adds

| Override | Detail |
|----------|--------|
| Build target | `production` (base default, no change needed) |
| Nginx | Ports 80/443, SSL termination, rate limiting |
| Postgres SSL | `sslmode=require` on all DATABASE_URL values |
| Postgres tuning | Custom `postgresql.prod.conf` mounted |
| Redis persistence | RDB snapshots added (`save 900 1`, etc.) |
| Replicas | API: 2, Queue: 2, Worker: 3, Frontend: 2 |
| Resource limits | CPU + memory caps on every service |
| Resource reservations | Guaranteed minimums for critical services |
| Scheduler | Always on (profile removed in prod) |
| No ports exposed | Traffic flows through nginx only |
| No volume mounts | Code baked into images |

### SSL Certificates

Place certificates in `infra/nginx/ssl/`:

```
infra/nginx/ssl/
├── fullchain.pem    # Certificate chain
└── privkey.pem      # Private key
```

Or set `SSL_CERT_PATH` in `.env` to point elsewhere.

### Production Postgres

The `infra/postgres/postgresql.prod.conf` is mounted and includes:

| Setting | Value | Purpose |
|---------|-------|---------|
| `shared_buffers` | 1GB | In-memory cache |
| `effective_cache_size` | 3GB | Query planner hint |
| `work_mem` | 32MB | Per-operation memory |
| `max_connections` | 200 | Connection pool |
| `max_parallel_workers` | 8 | Parallel query execution |
| `ssl` | on | Encrypted connections |

### Secrets in Production

**Do NOT use a `.env.production` file on disk.** Instead, inject secrets via your deployment pipeline:

```yaml
# GitHub Actions example
- name: Deploy
  env:
    POSTGRES_PASSWORD: ${{ secrets.POSTGRES_PASSWORD }}
    REDIS_PASSWORD: ${{ secrets.REDIS_PASSWORD }}
    LINKFLOW_SECRET: ${{ secrets.LINKFLOW_SECRET }}
    JWT_SECRET: ${{ secrets.JWT_SECRET }}
    APP_KEY: ${{ secrets.APP_KEY }}
  run: |
    docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

Other options: Docker Swarm secrets, HashiCorp Vault, AWS SSM.

---

## CI/CD Testing

### Start Test Stack

```bash
make test-up        # Start postgres + redis only
make test-stack     # Start everything
make test-down      # Stop and remove volumes (clean slate)
```

### What `docker-compose.test.yml` Adds

| Override | Detail |
|----------|--------|
| Database | `linkflow_test` DB name |
| Passwords | Defaults for CI (`test_password`) |
| Container names | Prefixed with `linkflow-test-` |
| Build target | `development` (includes test tools) |
| Workers | Reduced to 1 for speed |
| Cleanup | `make test-down` removes volumes |

### CI Pipeline Example

```yaml
# .github/workflows/test.yml
jobs:
  test:
    steps:
      - uses: actions/checkout@v4
      - name: Start infrastructure
        run: make test-up
      - name: Wait for healthy
        run: docker compose -f docker-compose.yml -f docker-compose.test.yml exec postgres pg_isready
      - name: Run API tests
        run: docker compose -f docker-compose.yml -f docker-compose.test.yml exec api php artisan test
      - name: Cleanup
        if: always()
        run: make test-down
```

---

## Services Reference

### All Services

| Service | Type | Build | Port (Internal) | Profile |
|---------|------|-------|-----------------|---------|
| `postgres` | Infrastructure | Image | 5432 | — |
| `redis` | Infrastructure | Image | 6379 | — |
| `api` | Laravel | `apps/api/Dockerfile` | 8000 (dev) / 9000 (prod) | — |
| `queue` | Laravel | `apps/api/Dockerfile` | — | — |
| `scheduler` | Laravel | `apps/api/Dockerfile` | — | `scheduler` |
| `frontend` | Go Engine | `apps/engine/Dockerfile` | 8080 (HTTP) + 9090 (gRPC) | — |
| `history` | Go Engine | `apps/engine/Dockerfile` | 7234 (gRPC) + 8080 (health) | — |
| `matching` | Go Engine | `apps/engine/Dockerfile` | 7235 (gRPC) + 8080 (health) | — |
| `worker` | Go Engine | `apps/engine/Dockerfile` | 8080 (health) | — |
| `timer` | Go Engine | `apps/engine/Dockerfile` | 7238 (gRPC) + 8080 (health) | — |
| `visibility` | Go Engine | `apps/engine/Dockerfile` | 7237 (gRPC) + 8080 (health) | — |
| `edge` | Go Engine | `apps/engine/Dockerfile` | 8080 (health) | `edge` |
| `control-plane` | Go Engine | `apps/engine/Dockerfile` | 7239 (gRPC) + 8080 (health) | `control` |
| `nginx` | Infrastructure | Image | 80 + 443 | `production` |
| `migrate` | One-time | `apps/engine/Dockerfile` | — | `migrate` |

### Dependency Chain

```
postgres ─┐
           ├─→ api ──→ queue
redis ────┘     │       scheduler (profile: scheduler)
                │
postgres ─┐     │
           ├─→ history ──→ matching ──→ worker
redis ────┘     │              │
                ├─→ timer ─────┘
                ├─→ visibility
                ├─→ edge (profile: edge)
                └─→ control-plane (profile: control)
```

All `depends_on` use `condition: service_healthy` — services wait for their dependencies to pass health checks before starting.

### Profiles

Profiles gate optional services. They only start when explicitly requested:

```bash
# Start with specific profiles
docker compose --profile scheduler --profile edge up -d

# Or use Makefile shortcuts
make dev-full     # scheduler + edge
make prod-full    # scheduler + edge + production (nginx)
```

| Profile | Services | When to Use |
|---------|----------|-------------|
| `scheduler` | scheduler | Cron-based workflow triggers |
| `edge` | edge | Multi-region sync |
| `control` | control-plane | Multi-cell orchestration |
| `production` | nginx | SSL termination + reverse proxy |
| `migrate` | migrate | One-time Go engine migrations |

---

## Scaling

### Horizontal Scaling

```bash
# Scale individual services
make prod-scale-api N=3
make prod-scale-workers N=5
make prod-scale-queue N=3

# Or directly
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --scale worker=5 --no-recreate
```

### Scaling Limits

| Service | Dev | Prod Min | Prod Max | Notes |
|---------|-----|----------|----------|-------|
| API | 1 | 2 | 8 | Stateless, behind nginx |
| Queue | 1 | 2 | 8 | Stateless, Redis-backed |
| Worker (Go) | 1 | 3 | 16 | Each runs `NUM_WORKERS` goroutines |
| Frontend | 1 | 2 | 4 | API gateway, stateless |
| History | 1 | 1 | 2 | Event store, scale carefully |
| Matching | 1 | 1 | 2 | Partitioned task queue |
| Timer | 1 | 1 | 1 | Single-leader scheduling |
| Visibility | 1 | 1 | 2 | Stateless query service |
| Postgres | 1 | 1 | 1 | Vertical scaling or managed DB |
| Redis | 1 | 1 | 1 | Vertical or Redis Cluster |

> **Note**: `container_name` is only set in override/test files (for dev convenience).
> Production omits it to allow `--scale` (container names must be unique).

### Resource Limits (Production)

Defined in `docker-compose.prod.yml`:

| Service | Memory Limit | CPU Limit | Memory Reserved | CPU Reserved |
|---------|-------------|-----------|----------------|-------------|
| Postgres | 2G | 2.0 | 1G | 1.0 |
| Redis | 1G | 1.0 | 512M | 0.5 |
| API | 512M | 1.0 | 256M | 0.5 |
| Queue | 512M | 1.0 | 256M | 0.5 |
| Worker (Go) | 1G | 2.0 | 512M | 1.0 |
| History | 1G | 2.0 | 512M | 1.0 |
| Frontend | 512M | 1.0 | 256M | 0.5 |

### Beyond Docker Compose

| Stage | Trigger | Migration Path |
|-------|---------|----------------|
| Docker Compose | < 10K workflows/day | Current setup |
| Docker Swarm | Multi-node needed | Same compose files + `docker stack deploy` |
| Kubernetes | Auto-scaling, service mesh | Helm charts from existing Dockerfiles |

---

## Environment Variables

### Root `.env` (All Secrets)

| Variable | Required | Default | Purpose |
|----------|----------|---------|---------|
| `POSTGRES_USER` | — | `linkflow` | Database username |
| `POSTGRES_PASSWORD` | ✅ | — | Database password |
| `POSTGRES_DB` | — | `linkflow` | Database name |
| `POSTGRES_PORT` | — | `5432` | Host-mapped port (dev) |
| `REDIS_PASSWORD` | ✅ | — | Redis auth |
| `REDIS_PORT` | — | `6379` | Host-mapped port (dev) |
| `LINKFLOW_SECRET` | ✅ | — | API↔Engine callback HMAC |
| `JWT_SECRET` | ✅ | — | Engine JWT signing |
| `APP_KEY` | ✅ | — | Laravel encryption key |
| `ENGINE_PARTITION_COUNT` | — | `16` | Redis stream partitions |
| `LOG_LEVEL` | — | `info` | Global log level |
| `SSL_CERT_PATH` | — | `./infra/nginx/ssl` | SSL cert directory |

### `apps/api/.env.docker` (Laravel Config)

Application configuration only — **no secrets**. Covers:
- App name, locale, maintenance driver
- Session, cache, queue drivers (all Redis)
- Mail configuration
- Passport OAuth settings
- Storage drivers

Secrets (`DB_PASSWORD`, `REDIS_PASSWORD`, `APP_KEY`, `LINKFLOW_ENGINE_SECRET`) are injected via the compose `environment:` block from the root `.env`.

### Environment Differences

| Setting | Development | Production | CI/Test |
|---------|-------------|------------|---------|
| Build target | `development` | `production` | `development` |
| Code volumes | Mounted | Baked into image | None |
| Ports | All on localhost | 80/443 via nginx | Fixed |
| Postgres SSL | `sslmode=disable` | `sslmode=require` | `sslmode=disable` |
| Log level | `debug` | `warning` / `info` | `debug` |
| `APP_DEBUG` | `true` | `false` | `true` |
| OPcache | Revalidates | Cached forever | Revalidates |
| Resource limits | None | CPU + memory caps | None |
| Replicas | 1 each | Configurable | 1 each |
| Container names | Fixed | Dynamic | Fixed (test-prefixed) |

---

## Makefile Reference

### Development

| Command | Description |
|---------|-------------|
| `make setup` | First-time: copy env files |
| `make dev` | Start dev stack |
| `make dev-build` | Build and start |
| `make dev-down` | Stop dev stack |
| `make dev-full` | Start with scheduler + edge |
| `make dev-logs` | Tail dev logs |

### Production

| Command | Description |
|---------|-------------|
| `make prod` | Start production stack |
| `make prod-build` | Build and start |
| `make prod-down` | Stop production |
| `make prod-full` | Start with all profiles |
| `make prod-logs` | Tail production logs |

### Scaling

| Command | Description |
|---------|-------------|
| `make prod-scale-api N=3` | Scale API replicas |
| `make prod-scale-workers N=5` | Scale Go workers |
| `make prod-scale-queue N=3` | Scale queue workers |

### Database

| Command | Description |
|---------|-------------|
| `make migrate` | Run all migrations |
| `make migrate-fresh` | Reset and re-migrate (⚠️ destructive) |
| `make seed` | Seed database |

### Utilities

| Command | Description |
|---------|-------------|
| `make ps` | Service status |
| `make logs` | Tail all logs |
| `make health` | Health check table |
| `make shell-api` | Bash into API container |
| `make shell-db` | PostgreSQL shell |
| `make shell-redis` | Redis CLI |
| `make api-cache` | Clear Laravel caches |
| `make restart` | Restart all services |
| `make clean` | Remove everything including volumes (⚠️) |

### CI/Testing

| Command | Description |
|---------|-------------|
| `make test-up` | Start test postgres + redis |
| `make test-stack` | Start full test stack |
| `make test-down` | Stop and remove test volumes |

---

## Troubleshooting

### "Set POSTGRES_PASSWORD in .env"

Your `.env` file is missing or has empty required values. Run:
```bash
make setup
# Then edit .env with real values
```

### Services fail to connect to Postgres

1. Check Postgres is healthy: `docker compose ps postgres`
2. Verify password matches: `grep POSTGRES_PASSWORD .env`
3. For engine services, check `DATABASE_URL` format in compose

### "sslmode=require" fails locally

This should not happen with the current setup. The base `docker-compose.yml` uses `sslmode=disable`. If you see this error, you may be running production config:
```bash
# Make sure you're using dev (no -f flags)
docker compose up -d          # ✅ loads override.yml automatically
docker compose -f docker-compose.yml up -d    # ❌ skips override.yml
```

### Container names conflict with `--scale`

Scaling requires dynamic container names. The base `docker-compose.yml` has no `container_name` set. If you see name conflicts:
```bash
# Don't use dev override for scaling
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --scale worker=3
```

### Laravel "No application encryption key"

Generate and set `APP_KEY`:
```bash
docker compose run --rm api php artisan key:generate --show
# Copy the output to .env as APP_KEY=base64:...
```

### Reset everything

```bash
make clean          # Stop all containers + remove volumes
make dev            # Start fresh
make migrate        # Re-run migrations
make seed           # Re-seed data
```
