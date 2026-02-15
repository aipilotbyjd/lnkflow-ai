# 🏗️ Production-Ready Infrastructure Plan (Improved)

**Date**: 2026-02-15  
**Author**: AdaL  
**Status**: Draft — Ready for review

---

## TL;DR

Your plan is solid in direction. This revision keeps the same spirit but fixes several architectural gaps:

| Change | Why |
|--------|-----|
| `docker-compose.override.yml` (auto-loaded) instead of `docker-compose.dev.yml` | Zero-friction dev — `docker compose up` just works, no `-f` flags |
| Drop `.env.production` file | Anti-pattern. Prod uses CI/CD injection, Docker secrets, or Vault — never a committed/gitignored file |
| Keep `apps/api/.env.docker` | Laravel needs ~40 app-specific vars that don't belong in root `.env` |
| Add `docker-compose.test.yml` | CI needs its own ephemeral, deterministic stack |
| Use Compose `profiles` for optional services | Cleaner than separate files for edge/control-plane/scheduler/monitoring |
| Fix `sslmode=require` → `sslmode=disable` for local | Current setup fails without certs |
| Add Nginx dev config | Simple HTTP proxy, no SSL headaches locally |

---

## 1. Current State Analysis

### What exists today

```
lnkflow-ai/
├── docker-compose.yml              # Root orchestrator (uses `include:`)
├── .env.example                    # Root env template
├── Makefile                        # Operations commands
├── infra/
│   ├── docker-compose.yml          # Postgres + Redis + Nginx (132 lines)
│   ├── .env.example                # Infra env template (dupes root)
│   ├── init/01-init.sql            # DB init script
│   └── nginx/nginx.conf            # Production nginx (SSL, rate limits)
├── apps/api/
│   ├── docker-compose.yml          # API + Queue + Scheduler (147 lines)
│   ├── Dockerfile                  # Multi-stage (dev/prod) ✅
│   ├── .env.docker                 # Laravel runtime config (HAS SECRETS!)
│   ├── .env.docker.example         # Template
│   ├── .env.example                # Local dev (SQLite-based, irrelevant)
│   └── docker/supervisord.conf     # Production supervisor
└── apps/engine/
    ├── docker-compose.yml          # 8 Go microservices + migrate (297 lines)
    ├── Dockerfile                  # Multi-stage Go build ✅
    └── .env.example                # Engine env template (dupes root)
```

### Problems identified

| # | Problem | Severity | Detail |
|---|---------|----------|--------|
| 1 | **4 scattered .env files** | High | Root, infra, api, engine — secrets duplicated, drift risk |
| 2 | **`sslmode=require` in engine compose** | High | Breaks local dev (no certs), hard-coded in `x-common-env` |
| 3 | **Dev hacks in prod files** | Medium | `target: development`, volume mounts, `artisan serve` in API compose |
| 4 | **No override pattern** | Medium | Can't layer dev/prod config cleanly |
| 5 | **Secrets in `.env.docker`** | High | `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD` committed to example with real values |
| 6 | **No CI/test compose** | Medium | Tests run against local dev stack or nothing |
| 7 | **Missing dev nginx** | Low | No simple HTTP proxy for local development |
| 8 | **`include:` complicates overrides** | Medium | Docker Compose `include` doesn't merge with override files cleanly |

---

## 2. Proposed Architecture

### File Structure

```
lnkflow-ai/
├── docker-compose.yml              # Base: ALL services, production-ready defaults
├── docker-compose.override.yml     # Dev: auto-loaded, volume mounts, dev targets, ports
├── docker-compose.prod.yml         # Prod: explicit -f, nginx, replicas, resources
├── docker-compose.test.yml         # CI: ephemeral, no volumes, fixed ports
│
├── .env.example                    # Single source of truth for ALL shared vars
├── .env                            # Local dev values (gitignored)
│
├── Makefile                        # Updated: dev/prod/test/scale targets
│
├── apps/
│   ├── api/
│   │   ├── Dockerfile              # Unchanged (multi-stage already good)
│   │   ├── .env.docker.example     # Laravel-specific vars (no secrets)
│   │   ├── .env.docker             # Laravel runtime (gitignored, derives secrets from root)
│   │   └── docker/supervisord.conf # Unchanged
│   └── engine/
│       └── Dockerfile              # Unchanged
│
└── infra/
    ├── init/01-init.sql            # Unchanged
    ├── nginx/
    │   ├── nginx.conf              # Production (SSL, rate limits) — unchanged
    │   ├── nginx.dev.conf          # NEW: Dev (HTTP only, simple proxy)
    │   └── ssl/                    # Certs directory
    └── postgres/
        └── postgresql.prod.conf    # NEW: Tuned Postgres for production
```

### Files removed

| File | Reason |
|------|--------|
| `infra/docker-compose.yml` | Merged into root `docker-compose.yml` |
| `apps/api/docker-compose.yml` | Merged into root `docker-compose.yml` |
| `apps/engine/docker-compose.yml` | Merged into root `docker-compose.yml` |
| `infra/.env.example` | Consolidated into root `.env.example` |
| `apps/engine/.env.example` | Consolidated into root `.env.example` |
| `apps/api/.env.example` | Keep only `.env.docker.example` for Laravel-specific vars |

### Files kept (renamed)

| File | Reason |
|------|--------|
| `apps/api/.env.docker.example` | Laravel needs ~40 app-specific vars (mail, session, cache drivers, etc.) |
| `apps/api/.env.docker` | Runtime config — secrets injected via compose `environment` block |

---

## 3. How the Override Pattern Works

### Development (default — zero friction)

```bash
docker compose up -d
# Automatically loads: docker-compose.yml + docker-compose.override.yml
```

`docker-compose.override.yml` is auto-loaded by Docker Compose when no `-f` flag is specified. Developers don't need to know about the layering — it just works.

### Production (explicit)

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
# Loads base + prod override. override.yml is SKIPPED because -f is explicit.
```

### CI/CD Testing

```bash
docker compose -f docker-compose.yml -f docker-compose.test.yml up -d
# Ephemeral stack with fixed ports, no volumes
```

### Key principle

> **`docker-compose.override.yml`** = dev by default (auto-loaded)  
> **`docker-compose.prod.yml`** = prod by explicit `-f` flag  
> **`docker-compose.test.yml`** = CI by explicit `-f` flag  

This is the official Docker Compose recommended pattern.

---

## 4. Detailed File Designs

### 4.1 `docker-compose.yml` (Base)

This is the **single source of truth** for all services. Contains production-ready defaults.

**Design principles:**
- All 13+ services defined here with production defaults
- YAML anchors (`x-common-*`) for shared config (DRY)
- No volume mounts (added by override)
- No exposed host ports except load balancer (added by override/prod)
- `sslmode=disable` as default (overridden to `require` in prod)
- Health checks on everything
- Proper `depends_on` chains with `condition: service_healthy`
- Profiles for optional services: `edge`, `control`, `monitoring`, `scheduler`

**Service dependency chain:**
```
postgres ─┐
           ├─> api ──> queue
redis ────┘    │        scheduler (profile: scheduler)
               │
postgres ─┐    │
           ├─> history ──> matching ──> worker
redis ────┘    │              │
               ├─> timer ────┘
               ├─> visibility
               ├─> edge (profile: edge)
               └─> control-plane (profile: control)
```

**Key sections:**

```yaml
# YAML anchors for DRY config
x-common-env: &common-env
  DATABASE_URL: postgres://${POSTGRES_USER}:${POSTGRES_PASSWORD}@postgres:5432/${POSTGRES_DB}?sslmode=disable&search_path=workflow
  REDIS_URL: redis://:${REDIS_PASSWORD}@redis:6379
  LOG_LEVEL: ${LOG_LEVEL:-info}
  LOG_FORMAT: json

x-common-config: &common-config
  restart: unless-stopped
  networks:
    - linkflow
  logging:
    driver: json-file
    options:
      max-size: "10m"
      max-file: "3"

x-api-env: &api-env
  DB_HOST: postgres
  DB_PORT: 5432
  DB_DATABASE: ${POSTGRES_DB:-linkflow}
  DB_USERNAME: ${POSTGRES_USER:-linkflow}
  DB_PASSWORD: ${POSTGRES_PASSWORD}
  REDIS_HOST: redis
  REDIS_PASSWORD: ${REDIS_PASSWORD}
  LINKFLOW_ENGINE_SECRET: ${LINKFLOW_SECRET}
  ENGINE_PARTITION_COUNT: ${ENGINE_PARTITION_COUNT:-16}
```

**Important changes from current:**
- Container names use service name directly (e.g., `postgres` not `linkflow-postgres`) — enables clean `depends_on` references
- `sslmode=disable` in base (safe for dev, overridden in prod)
- API services reference `postgres` and `redis` (not `linkflow-postgres`)
- All build targets default to `production` (overridden to `development` in dev)

### 4.2 `docker-compose.override.yml` (Dev — Auto-loaded)

**What it adds:**
- Build target → `development` for API services
- Source code volume mounts for hot reload
- Host port mappings for direct access
- Lower resource limits (or none)
- Debug-friendly environment vars
- `sslmode=disable` already in base (no override needed)

```yaml
services:
  postgres:
    ports:
      - "127.0.0.1:${POSTGRES_PORT:-5432}:5432"

  redis:
    ports:
      - "127.0.0.1:${REDIS_PORT:-6379}:6379"

  api:
    build:
      target: development
    ports:
      - "8000:8000"
    volumes:
      - ./apps/api:/var/www/html
    environment:
      APP_ENV: local
      APP_DEBUG: "true"
      LOG_LEVEL: debug

  queue:
    build:
      target: development
    volumes:
      - ./apps/api:/var/www/html

  frontend:
    ports:
      - "8080:8080"
      - "9090:9090"
    environment:
      LOG_LEVEL: debug
```

### 4.3 `docker-compose.prod.yml` (Production — Explicit)

**What it adds/overrides:**
- Nginx reverse proxy (ports 80/443)
- `sslmode=require` for Postgres connections
- Production resource limits (CPU + memory caps)
- Replica counts for horizontal scaling
- No volume mounts (code baked into image)
- Production-tuned Postgres config
- No host port exposure (only through nginx)

```yaml
services:
  postgres:
    command: postgres -c config_file=/etc/postgresql/postgresql.conf
    volumes:
      - postgres_data:/var/lib/postgresql/data
      - ./infra/postgres/postgresql.prod.conf:/etc/postgresql/postgresql.conf:ro
    deploy:
      resources:
        limits:
          memory: 2G
          cpus: '2.0'

  api:
    # No ports exposed — nginx handles routing
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
      LOG_LEVEL: warning
    deploy:
      replicas: 2
      resources:
        limits:
          memory: 512M
          cpus: '1.0'

  worker:
    environment:
      DATABASE_URL: postgres://${POSTGRES_USER}:${POSTGRES_PASSWORD}@postgres:5432/${POSTGRES_DB}?sslmode=require&search_path=workflow
      NUM_WORKERS: 8
    deploy:
      replicas: 3
      resources:
        limits:
          memory: 1G
          cpus: '2.0'

  nginx:
    # Enabled in prod (no profile gate, or always-on in prod file)
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./infra/nginx/nginx.conf:/etc/nginx/nginx.conf:ro
      - ${SSL_CERT_PATH}:/etc/nginx/ssl:ro
```

### 4.4 `docker-compose.test.yml` (CI/CD)

```yaml
services:
  postgres:
    ports:
      - "5432:5432"
    tmpfs:
      - /var/lib/postgresql/data  # Ephemeral — fast, no persistence

  redis:
    ports:
      - "6379:6379"

  api:
    build:
      target: development  # Includes dev tools for testing
    environment:
      APP_ENV: testing
      DB_DATABASE: linkflow_test
    depends_on:
      postgres:
        condition: service_healthy
```

### 4.5 `.env.example` (Consolidated)

Single file containing ALL variables needed by ALL services:

```env
# ============================================
# Infrastructure
# ============================================
POSTGRES_USER=linkflow
POSTGRES_PASSWORD=          # REQUIRED
POSTGRES_DB=linkflow
POSTGRES_PORT=5432
REDIS_PASSWORD=             # REQUIRED
REDIS_PORT=6379

# ============================================
# Security
# ============================================
LINKFLOW_SECRET=            # REQUIRED — shared API↔Engine
JWT_SECRET=                 # REQUIRED — Engine JWT signing
APP_KEY=                    # REQUIRED — Laravel encryption key

# ============================================
# Engine
# ============================================
ENGINE_PARTITION_COUNT=16
LOG_LEVEL=info

# ============================================
# SSL (Production Only)
# ============================================
SSL_CERT_PATH=./infra/nginx/ssl
```

### 4.6 `apps/api/.env.docker.example` (Laravel-specific, NO secrets)

This file contains only Laravel application configuration. All secrets come from compose `environment` blocks which reference root `.env`:

```env
# App Config (no secrets here — they come from docker-compose environment)
APP_NAME=LinkFlow
APP_ENV=local
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000

# Drivers
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

# Mail
MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@linkflow.io"
MAIL_FROM_NAME="${APP_NAME}"

# Note: DB_HOST, DB_PASSWORD, REDIS_HOST, REDIS_PASSWORD, APP_KEY,
# LINKFLOW_ENGINE_SECRET are injected by docker-compose from root .env
```

### 4.7 Updated Makefile

```makefile
# ============================================
# Development (default — auto-loads override.yml)
# ============================================
dev:             ## Start dev stack
	docker compose up -d

dev-build:       ## Build and start dev stack
	docker compose up -d --build

dev-down:        ## Stop dev stack
	docker compose down

# ============================================
# Production (explicit -f)
# ============================================
prod:            ## Start production stack
	docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

prod-build:      ## Build and start production stack
	docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

prod-down:       ## Stop production stack
	docker compose -f docker-compose.yml -f docker-compose.prod.yml down

# ============================================
# Scaling
# ============================================
prod-scale-api:       ## Scale API (usage: make prod-scale-api N=3)
	docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --scale api=$(N)

prod-scale-workers:   ## Scale Go workers (usage: make prod-scale-workers N=5)
	docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --scale worker=$(N)

prod-scale-queue:     ## Scale queue workers (usage: make prod-scale-queue N=3)
	docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --scale queue=$(N)

# ============================================
# Testing
# ============================================
test-up:         ## Start test infrastructure
	docker compose -f docker-compose.yml -f docker-compose.test.yml up -d postgres redis

test-down:       ## Stop test infrastructure
	docker compose -f docker-compose.yml -f docker-compose.test.yml down -v

# ============================================
# Profiles (optional services)
# ============================================
dev-full:        ## Start dev with all optional services
	docker compose --profile scheduler --profile edge up -d

prod-full:       ## Start prod with all optional services
	docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile scheduler --profile edge --profile monitoring up -d
```

### 4.8 `infra/nginx/nginx.dev.conf` (Dev Nginx)

Simple HTTP-only proxy for local development (no SSL):

```nginx
worker_processes 1;
events { worker_connections 256; }

http {
    upstream api { server api:8000; }
    upstream engine { server frontend:8080; }

    server {
        listen 80;

        location /api/ { proxy_pass http://api; }
        location /engine/ { proxy_pass http://engine; }
        location /health { return 200 'OK'; }
    }
}
```

### 4.9 `infra/postgres/postgresql.prod.conf` (Production Postgres)

```conf
# Performance
shared_buffers = 1GB
effective_cache_size = 3GB
work_mem = 32MB
maintenance_work_mem = 256MB
max_connections = 200

# WAL
wal_buffers = 64MB
checkpoint_completion_target = 0.9
max_wal_size = 2GB

# Logging
log_min_duration_statement = 500
log_checkpoints = on
log_lock_waits = on

# Security
ssl = on
ssl_cert_file = '/etc/ssl/certs/ssl-cert-snakeoil.pem'
ssl_key_file = '/etc/ssl/private/ssl-cert-snakeoil.key'
```

---

## 5. Environment Differences Matrix

| Setting | Development | Production | CI/Test |
|---------|-------------|------------|---------|
| **Compose files** | `yml` + `override.yml` (auto) | `yml` + `prod.yml` (explicit) | `yml` + `test.yml` (explicit) |
| **Build target** | `development` | `production` | `development` |
| **Code volumes** | Mounted (live reload) | Baked into image | None |
| **Ports exposed** | All on localhost | Only 80/443 via nginx | Fixed for CI |
| **Postgres SSL** | `sslmode=disable` | `sslmode=require` | `sslmode=disable` |
| **Postgres storage** | Docker volume | Docker volume + backups | tmpfs (ephemeral) |
| **Log level** | `debug` | `warning` | `debug` |
| **Laravel debug** | `true` | `false` | `true` |
| **OPcache validate** | `1` (revalidate) | `0` (cached forever) | `1` |
| **Resource limits** | None | CPU + memory caps | None |
| **Replicas** | 1 each | Configurable (2-8) | 1 each |
| **Nginx** | Not used (direct ports) | SSL termination + rate limits | Not used |
| **Container names** | Fixed (for easy `exec`) | Dynamic (for scaling) | Fixed |

---

## 6. Scaling Strategy

### Horizontal Scaling (Docker Compose)

| Service | Dev | Prod Min | Prod Max | Scaling Method |
|---------|-----|----------|----------|----------------|
| API | 1 | 2 | 8 | `--scale api=N` behind nginx |
| Queue Worker | 1 | 2 | 8 | `--scale queue=N` |
| Go Worker | 1 | 3 | 16 | `--scale worker=N` × `NUM_WORKERS` per container |
| Frontend (Gateway) | 1 | 2 | 4 | `--scale frontend=N` |
| History | 1 | 1 | 2 | Stateful — scale carefully |
| Matching | 1 | 1 | 2 | Partitioned — scale with partition count |
| Timer | 1 | 1 | 1 | Single-leader pattern |
| Visibility | 1 | 1 | 2 | Stateless — scale freely |
| Postgres | 1 | 1 | 1 | Vertical / managed DB in production |
| Redis | 1 | 1 | 1 | Vertical / Redis Cluster in production |

### Beyond Docker Compose (Future)

When you outgrow Compose:

| Stage | Trigger | Migration Path |
|-------|---------|----------------|
| **Stage 1**: Docker Compose | < 10K workflows/day | Current plan |
| **Stage 2**: Docker Swarm | Need multi-node, secrets management | Same compose files + `docker stack deploy` |
| **Stage 3**: Kubernetes | Need auto-scaling, service mesh | Convert to Helm charts, use existing Dockerfiles |

Docker Swarm is a natural next step because your compose files work almost unchanged:
- `deploy.replicas` already defined
- `deploy.resources` already defined
- Switch from `.env` to `docker secret create`
- `docker stack deploy -c docker-compose.yml -c docker-compose.prod.yml linkflow`

---

## 7. Secrets Management Strategy

### Development (Current — `.env` file)

```
.env (gitignored) → docker-compose reads → injects into services
```

Fine for local dev. ✅

### Production — DO NOT use `.env.production` file

**Why not:**
- Files on disk are a security risk (leaked in logs, backups, container mounts)
- No audit trail of who accessed/changed secrets
- No rotation mechanism
- If gitignored, it must be manually synced across deployment hosts

**Instead, use one of:**

| Method | When | How |
|--------|------|-----|
| **CI/CD env injection** | Single server / VPS | GitHub Actions secrets → `docker compose` env vars |
| **Docker Swarm secrets** | Docker Swarm deployment | `docker secret create` → mounted as files in containers |
| **HashiCorp Vault** | Multi-environment / compliance | Agent sidecar or init container |
| **AWS SSM / Secrets Manager** | AWS deployment | IAM-authenticated fetch at startup |
| **Kubernetes Secrets** | K8s deployment | `Secret` resources mounted as env/files |

**Recommended for your current stage:** CI/CD environment injection via GitHub Actions:

```yaml
# .github/workflows/deploy.yml
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

---

## 8. Migration Steps

### Phase 1: Create new files (non-breaking)

1. Create `docker-compose.yml` (new consolidated base)
2. Create `docker-compose.override.yml` (dev overrides)
3. Create `docker-compose.prod.yml` (prod overrides)
4. Create `docker-compose.test.yml` (CI overrides)
5. Update `.env.example` (consolidated)
6. Create `infra/nginx/nginx.dev.conf`
7. Create `infra/postgres/postgresql.prod.conf`
8. Update `apps/api/.env.docker.example` (remove secrets)

### Phase 2: Update tooling

9. Update `Makefile` with new targets
10. Update `apps/api/.dockerignore` (if needed)
11. Update CI/CD workflows

### Phase 3: Remove old files

12. Remove `infra/docker-compose.yml`
13. Remove `apps/api/docker-compose.yml`
14. Remove `apps/engine/docker-compose.yml`
15. Remove `infra/.env.example`
16. Remove `apps/engine/.env.example`
17. Remove `apps/api/.env.example` (keep `.env.docker.example`)

### Phase 4: Verify

18. `make dev` — full dev stack starts
19. `make prod` — production stack starts (or test with `--dry-run`)
20. `make test-up` — CI infrastructure starts
21. Run test suite against test stack

---

## 9. Key Differences from Your Original Plan

| Your Plan | This Plan | Why |
|-----------|-----------|-----|
| `docker-compose.dev.yml` | `docker-compose.override.yml` | Auto-loaded by Docker Compose — zero friction, no `-f` flags for dev |
| `.env.production` file | CI/CD secret injection | Files on disk are a security anti-pattern for production |
| Remove all per-app .env files | Keep `apps/api/.env.docker` | Laravel genuinely needs ~40 app config vars (drivers, mail, etc.) |
| `sslmode` toggled by env | `sslmode=disable` in base, `require` in prod override | Can't reliably toggle via .env when it's embedded in DATABASE_URL |
| No test compose | `docker-compose.test.yml` | CI needs deterministic, ephemeral infrastructure |
| Profiles not mentioned | Profiles for scheduler, edge, control, monitoring | Cleaner than always defining/skipping optional services |
| Flat Makefile targets | Grouped with dev/prod/test/scale sections | More discoverable, matches the compose layering |

---

## 10. Decision: Container Naming

**Current**: Fixed names like `linkflow-postgres`, `linkflow-api`  
**Recommended**: Use Docker Compose service names (no `container_name`)

**Why:**
- `container_name` prevents `--scale` (names must be unique)
- Docker Compose service discovery works via service name (e.g., `postgres:5432`)
- For dev convenience, add aliases if needed

**Exception**: Keep `container_name` in `docker-compose.override.yml` for dev (easy `docker exec`), omit in prod.

---

## 11. Open Questions

1. **Scheduler**: Currently gated behind `profiles: [full]`. Should it always run in prod? → Recommend: yes, always in prod, profile-gated in dev.
2. **Monitoring stack**: Add Prometheus + Grafana as a compose profile? → Recommend: yes, as `docker-compose.monitoring.yml` or profile.
3. **Log aggregation**: Ship to CloudWatch/Datadog/Loki in prod? → Recommend: configure via compose logging driver override in prod.
4. **Database backups**: Add a backup sidecar container? → Recommend: yes for self-hosted, not needed if using managed DB.
5. **Redis persistence**: Current `appendonly yes` is good. Add RDB snapshots for prod? → Recommend: yes, add `save 900 1 300 10` in prod.

---

## Next Steps

Once you approve (or adjust) this plan, I'll implement all files in order:

1. `docker-compose.yml` (base)
2. `docker-compose.override.yml` (dev)
3. `docker-compose.prod.yml` (prod)
4. `docker-compose.test.yml` (CI)
5. `.env.example` (consolidated)
6. `apps/api/.env.docker.example` (updated)
7. `infra/nginx/nginx.dev.conf`
8. `infra/postgres/postgresql.prod.conf`
9. `Makefile` (updated)
10. Remove old files
