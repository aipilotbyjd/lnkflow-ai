# LinkFlow Multi-Provider High-Availability Deployment Plan

> **Philosophy:** Stack every free tier. Hetzner is your safety net — NOT your primary. You only pay €4.51/mo.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Provider Map & Roles](#2-provider-map--roles)
3. [Failover Cascade](#3-failover-cascade)
4. [Compute Setup](#4-compute-setup)
5. [Database Setup](#5-database-setup)
6. [Redis & Cache Setup](#6-redis--cache-setup)
7. [Storage Setup](#7-storage-setup)
8. [Email Setup](#8-email-setup)
9. [DNS, CDN & Security](#9-dns-cdn--security)
10. [Monitoring & Alerts](#10-monitoring--alerts)
11. [Status Page (Always On)](#11-status-page-always-on)
12. [CI/CD Pipeline](#12-cicd-pipeline)
13. [Secrets Management](#13-secrets-management)
14. [Emergency Procedures](#14-emergency-procedures)
15. [Cost Breakdown](#15-cost-breakdown)
16. [Weekly Maintenance Checklist](#16-weekly-maintenance-checklist)
17. [Provider Swap Guide](#17-provider-swap-guide)
18. [FAQ & Troubleshooting](#18-faq--troubleshooting)

---

## 1. Architecture Overview

### How It Works

```
                         Internet
                            │
                      Cloudflare (Free)
                     DNS + CDN + SSL + WAF
                            │
               ┌────────────┼────────────┐
               │            │            │
          Azure CA      Fly.io      Railway
         (Laravel)    (Engine FE)   (Matching)
               │            │            │
               │     ┌──────┼──────┐     │
               │     │      │      │     │
               │   Koyeb  Zeabur  Render │
               │  (History)(Worker)(Queue)│
               │     │      │      │     │
               └─────┴──────┴──────┴─────┘
                            │
                   ┌────────┼────────┐
                   │        │        │
              CockroachDB  Upstash  Cloudflare KV
              (Database)   (Redis)  (Cache)
                   │        │        │
                   ▼        ▼        ▼
            ┌──────────────────────────────┐
            │   🛡️ HETZNER CX22 (€4.51)   │
            │   LAST RESORT FALLBACK ONLY  │
            │                              │
            │  Runs EVERYTHING if any      │
            │  free provider goes down     │
            │                              │
            │  • Full Docker Compose       │
            │  • Postgres replica          │
            │  • Redis backup              │
            │  • All engine services       │
            │  • Laravel API               │
            └──────────────────────────────┘
```

### Key Rules

1. **Hetzner NEVER serves production traffic** unless a free provider fails
2. **Hetzner stays warm** — runs a standby copy of everything, syncs data every hour
3. **Cloudflare handles failover** — if primary is down, routes to Hetzner automatically
4. **Everything is a Docker container** — same image runs on any provider
5. **All config is environment variables** — swap any provider by changing one line

---

## 2. Provider Map & Roles

### Compute Providers (7 total)

| Priority | Service | Provider | Free Tier | Role |
|----------|---------|----------|-----------|------|
| 1 (Primary) | Laravel API | Azure Container Apps | 2M req/mo | REST API |
| 1 (Primary) | Queue Worker | Render | 750 hrs/mo | Background jobs |
| 1 (Primary) | Engine Frontend | Fly.io | 3 VMs, 256MB | API Gateway |
| 1 (Primary) | Engine History | Koyeb | 1 nano instance | Event store |
| 1 (Primary) | Engine Matching | Railway | $5 credit/mo | Task queue |
| 1 (Primary) | Engine Worker | Zeabur | Free container | Execution |
| 1 (Primary) | Engine Timer | Azure Container Apps | Shared quota | Scheduled tasks |
| 🛡️ LAST | ALL services | Hetzner CX22 | €4.51/mo | **Fallback for ANY service** |

### Database Providers (4 total)

| Priority | Provider | Free Tier | Role |
|----------|----------|-----------|------|
| 1 (Primary) | CockroachDB Serverless | 10GB, 50M RUs/mo | Main database |
| 2 (Hot Backup) | Neon | 0.5GB | Real-time backup |
| 3 (Warm Backup) | Supabase | 500MB | Daily backup |
| 🛡️ LAST | Hetzner (self-hosted Postgres) | On the VPS | Emergency fallback |

### Redis/Cache Providers (3 total)

| Priority | Provider | Free Tier | Role |
|----------|----------|-----------|------|
| 1 (Primary) | Upstash | 10K cmds/day | Task queues |
| 2 (Secondary) | Cloudflare KV | 100K reads/day | Caching/sessions |
| 🛡️ LAST | Hetzner (self-hosted Redis) | On the VPS | Emergency fallback |

### Storage Providers (3 total)

| Priority | Provider | Free Tier | Role |
|----------|----------|-----------|------|
| 1 | Cloudflare R2 | 10GB | User uploads |
| 2 | Backblaze B2 | 10GB | DB dumps |
| 3 | Azure Blob | 5GB (12mo) | Extra backups |

### Monitoring Providers (7 total)

| Provider | Free Tier | Monitors |
|----------|-----------|----------|
| UptimeRobot | 50 monitors | Uptime + failover trigger |
| Sentry | 5K events/mo | Error tracking |
| BetterStack | 1GB/mo | Log management |
| Grafana Cloud | 10K metrics | Dashboards |
| Instatus | 1 page | Public status page |
| Cronitor | 5 monitors | Cron/backup health |
| Azure Monitor | 10 rules | Azure-specific alerts |

### Other Free Services

| Provider | Free Tier | Purpose |
|----------|-----------|---------|
| Cloudflare | Unlimited | DNS, CDN, SSL, WAF, Tunnel |
| GitHub | Unlimited | Code, Actions, GHCR |
| Resend | 3K emails/mo | Transactional email |
| Brevo | 300/day | Email overflow |
| Doppler | 5 projects | Secrets management |
| 1Password | (paid or use Bitwarden free) | Credential vault |

**Total: 31 free services + 1 paid (Hetzner €4.51/mo)**

---

## 3. Failover Cascade

### How Failover Works — The Cascade

When any free provider fails, traffic automatically moves to the next level:

```
Level 1: Free Provider (Azure/Fly.io/Railway/etc.)
    │
    │ ── if down/quota hit ──→ UptimeRobot detects (< 2 min)
    │                              │
    ▼                              ▼
Level 2: Another Free Provider     Cloudflare Worker reroutes
    │                              (automatic, no manual work)
    │ ── if also down ──→
    │
    ▼
Level 3: 🛡️ HETZNER (Last Resort)
         Always warm, always synced
         Handles ALL traffic until free providers recover
```

### Failover Rules Per Service

| Service | Level 1 (Primary) | Level 2 (Secondary) | Level 3 (Last Resort) |
|---------|-------------------|---------------------|-----------------------|
| Laravel API | Azure Container Apps | Render (redeploy) | Hetzner |
| Queue Worker | Render | Azure Container Apps | Hetzner |
| Engine Frontend | Fly.io | Railway | Hetzner |
| Engine History | Koyeb | Zeabur | Hetzner |
| Engine Matching | Railway | Fly.io | Hetzner |
| Engine Worker | Zeabur | Koyeb | Hetzner |
| Engine Timer | Azure Container Apps | Fly.io | Hetzner |
| Database | CockroachDB | Neon | Hetzner Postgres |
| Redis | Upstash | Cloudflare KV | Hetzner Redis |

### Automatic Failover via Cloudflare Workers (Free)

A Cloudflare Worker (free: 100K requests/day) checks health and reroutes:

```javascript
// Simplified — real version checks all endpoints
addEventListener('fetch', event => {
  event.respondWith(handleRequest(event.request))
})

async function handleRequest(request) {
  const primaryUrl = 'https://linkflow-api.azurecontainerapps.io'
  const fallbackUrl = 'https://linkflow.hetzner-server.com'

  try {
    const response = await fetch(primaryUrl + new URL(request.url).pathname, {
      method: request.method,
      headers: request.headers,
      body: request.body,
      cf: { timeout: 5000 } // 5 second timeout
    })
    if (response.ok) return response
    // Primary failed, use fallback
    return fetch(fallbackUrl + new URL(request.url).pathname, request)
  } catch (e) {
    // Primary unreachable, use fallback
    return fetch(fallbackUrl + new URL(request.url).pathname, request)
  }
}
```

---

## 4. Compute Setup

### 4.1 Azure Container Apps (Laravel API + Engine Timer)

**What runs here:** Laravel API, Engine Timer

**Setup Steps:**

1. **Create Azure Account** (free tier)
   - Go to https://azure.microsoft.com/free
   - Sign up — get $200 credit for 30 days + always-free services

2. **Install Azure CLI**
   ```bash
   # macOS
   brew install azure-cli

   # Login
   az login
   ```

3. **Create Resource Group & Environment**
   ```bash
   az group create --name linkflow-rg --location eastus

   az containerapp env create \
     --name linkflow-env \
     --resource-group linkflow-rg \
     --location eastus
   ```

4. **Deploy Laravel API**
   ```bash
   az containerapp create \
     --name linkflow-api \
     --resource-group linkflow-rg \
     --environment linkflow-env \
     --image ghcr.io/aipilotbyjd/lnkflow/api:latest \
     --target-port 8000 \
     --ingress external \
     --min-replicas 0 \
     --max-replicas 2 \
     --cpu 0.5 \
     --memory 1.0Gi \
     --env-vars \
       APP_ENV=production \
       DATABASE_URL=secretref:database-url \
       REDIS_URL=secretref:redis-url
   ```

5. **Deploy Engine Timer**
   ```bash
   az containerapp create \
     --name linkflow-timer \
     --resource-group linkflow-rg \
     --environment linkflow-env \
     --image ghcr.io/aipilotbyjd/lnkflow/engine-timer:latest \
     --min-replicas 0 \
     --max-replicas 1 \
     --cpu 0.25 \
     --memory 0.5Gi
   ```

6. **Set Secrets**
   ```bash
   az containerapp secret set \
     --name linkflow-api \
     --resource-group linkflow-rg \
     --secrets \
       database-url="postgresql://user:pass@free-tier.cockroachlabs.cloud:26257/linkflow" \
       redis-url="rediss://default:token@your-redis.upstash.io:6379"
   ```

> **Free Tier Limits:** 2M requests/mo, 180,000 vCPU-seconds, 360,000 GiB-seconds. Scale to 0 when idle to save quota.

---

### 4.2 Fly.io (Engine Frontend)

**What runs here:** Engine Frontend (Go gRPC gateway)

**Setup Steps:**

1. **Create Account** at https://fly.io (free, no credit card for hobby plan)

2. **Install Fly CLI**
   ```bash
   # macOS
   brew install flyctl

   # Login
   fly auth login
   ```

3. **Create fly.toml** in `apps/engine/cmd/frontend/`
   ```toml
   app = "linkflow-engine-frontend"
   primary_region = "iad"  # US East (pick closest to your users)

   [build]
     image = "ghcr.io/aipilotbyjd/lnkflow/engine-frontend:latest"

   [env]
     HISTORY_ADDR = "linkflow-engine-history.koyeb.app:443"
     MATCHING_ADDR = "linkflow-engine-matching.up.railway.app:443"

   [http_service]
     internal_port = 8080
     force_https = true
     auto_stop_machines = true
     auto_start_machines = true
     min_machines_running = 0

   [[vm]]
     cpu_kind = "shared"
     cpus = 1
     memory_mb = 256
   ```

4. **Deploy**
   ```bash
   fly deploy
   ```

5. **Set Secrets**
   ```bash
   fly secrets set JWT_SECRET="your-jwt-secret" LINKFLOW_SECRET="your-secret"
   ```

> **Free Tier:** 3 shared VMs (256MB each), 160GB outbound transfer. Auto-stop saves quota.

---

### 4.3 Railway (Engine Matching)

**What runs here:** Engine Matching (task queue service)

**Setup Steps:**

1. **Create Account** at https://railway.app (GitHub login)
2. **Create New Project** → "Deploy from Docker Image"
3. **Set Image:** `ghcr.io/aipilotbyjd/lnkflow/engine-matching:latest`
4. **Add Variables:**
   ```
   REDIS_URL=rediss://default:token@your-redis.upstash.io:6379
   GRPC_PORT=7235
   ```
5. **Generate Domain** → Settings → Networking → Generate Domain

> **Free Tier:** $5 credit/mo, enough for a small always-on service. Auto-sleeps if unused.

---

### 4.4 Koyeb (Engine History)

**What runs here:** Engine History (event store)

**Setup Steps:**

1. **Create Account** at https://www.koyeb.com (GitHub login)
2. **Create Service** → Docker → `ghcr.io/aipilotbyjd/lnkflow/engine-history:latest`
3. **Configure:**
   - Instance: Nano (free)
   - Port: 7234
   - Region: Washington DC (closest to other services)
4. **Add Environment Variables:**
   ```
   DATABASE_URL=postgresql://user:pass@free-tier.cockroachlabs.cloud:26257/linkflow
   ```

> **Free Tier:** 1 nano instance (512MB, shared CPU), always on.

---

### 4.5 Zeabur (Engine Worker)

**What runs here:** Engine Worker (workflow execution)

**Setup Steps:**

1. **Create Account** at https://zeabur.com (GitHub login)
2. **Create Project** → Deploy from Image
3. **Set Image:** `ghcr.io/aipilotbyjd/lnkflow/engine-worker:latest`
4. **Add Variables:**
   ```
   MATCHING_ADDR=linkflow-engine-matching.up.railway.app:443
   HISTORY_ADDR=linkflow-engine-history.koyeb.app:443
   LINKFLOW_SECRET=your-callback-secret
   API_BASE_URL=https://linkflow-api.azurecontainerapps.io
   ```

> **Free Tier:** 1 container, shared resources.

---

### 4.6 Render (Queue Worker)

**What runs here:** Laravel Queue Worker (background jobs)

**Setup Steps:**

1. **Create Account** at https://render.com (GitHub login)
2. **New** → **Background Worker**
3. **Set Image:** `ghcr.io/aipilotbyjd/lnkflow/queue:latest`
4. **Instance Type:** Free
5. **Add Environment Variables:**
   ```
   APP_ENV=production
   DATABASE_URL=postgresql://...
   REDIS_URL=rediss://...
   QUEUE_CONNECTION=redis
   ```

> **Free Tier:** 750 hrs/mo for background workers. Spins down after 15min inactivity (free tier).

---

### 4.7 🛡️ Hetzner CX22 — LAST RESORT FALLBACK

**What runs here:** EVERYTHING — but only when free providers fail

**Setup Steps:**

1. **Create Server**
   - Go to https://console.hetzner.cloud
   - New Project → "linkflow-fallback"
   - New Server:
     - Location: Falkenstein (EU) or Ashburn (US)
     - Image: Ubuntu 24.04
     - Type: CX22 (2 vCPU, 4GB RAM, 40GB) — €4.51/mo
     - SSH Key: Add your key
     - Firewall: Create with rules (22, 80, 443)

2. **Initial Server Setup**
   ```bash
   ssh root@YOUR_HETZNER_IP

   # Update system
   apt update && apt upgrade -y

   # Install Docker
   apt install -y docker.io docker-compose-plugin git ufw fail2ban

   # Firewall
   ufw allow 22
   ufw allow 80
   ufw allow 443
   ufw enable

   # Clone project
   git clone https://github.com/aipilotbyjd/lnkflow.git /opt/linkflow
   cd /opt/linkflow

   # Configure
   cp .env.example .env
   nano .env  # Set all connection strings

   # Pull all images
   docker compose pull

   # Start in standby mode (all services running but not receiving traffic)
   docker compose up -d
   ```

3. **Keep It Warm — Hourly Sync**
   ```bash
   # Add to crontab (crontab -e)
   # Sync database from CockroachDB to local Postgres every hour
   0 * * * * /opt/linkflow/scripts/sync-db.sh >> /var/log/linkflow-sync.log 2>&1

   # Pull latest images every 6 hours
   0 */6 * * * cd /opt/linkflow && docker compose pull && docker compose up -d >> /var/log/linkflow-update.log 2>&1
   ```

4. **The Sync Script** (`/opt/linkflow/scripts/sync-db.sh`)
   ```bash
   #!/bin/bash
   set -e

   # Dump from CockroachDB (primary)
   PGPASSWORD="$COCKROACH_PASS" pg_dump \
     -h free-tier.cockroachlabs.cloud \
     -p 26257 \
     -U linkflow_user \
     -d linkflow \
     --no-owner \
     -f /tmp/linkflow-latest.sql

   # Restore to local Postgres
   docker exec -i linkflow-postgres psql -U linkflow -d linkflow < /tmp/linkflow-latest.sql

   # Cleanup
   rm /tmp/linkflow-latest.sql
   echo "$(date): DB sync completed" >> /var/log/linkflow-sync.log
   ```

> **Hetzner is ALWAYS ready.** Fresh images, synced database, running containers. If Cloudflare Worker detects primary failure → traffic routes here within 60 seconds.

---

## 5. Database Setup

### 5.1 CockroachDB Serverless (Primary)

**Why CockroachDB:** 10GB free (biggest), distributed by design, auto-scales, PostgreSQL compatible.

**Setup Steps:**

1. **Create Account** at https://cockroachlabs.cloud (free)

2. **Create Cluster**
   - Plan: Serverless (Free)
   - Cloud: AWS or GCP
   - Region: us-east-1 (or closest to your users)
   - Name: linkflow-production

3. **Create Database & User**
   ```sql
   CREATE DATABASE linkflow;
   CREATE USER linkflow_app WITH PASSWORD 'your-secure-password';
   GRANT ALL ON DATABASE linkflow TO linkflow_app;
   ```

4. **Get Connection String**
   ```
   postgresql://linkflow_app:password@free-tier.cockroachlabs.cloud:26257/linkflow?sslmode=verify-full
   ```

5. **Run Migrations**
   ```bash
   # From your local machine or CI/CD
   DATABASE_URL="postgresql://..." php artisan migrate --force
   ```

> **Free Tier:** 10GB storage, 50M Request Units/month, 10GB transfer/month.

---

### 5.2 Neon (Hot Backup)

**Setup Steps:**

1. **Create Account** at https://neon.tech (free)
2. **Create Project:** linkflow-backup
3. **Get Connection String** from dashboard
4. **Automated Daily Sync** (GitHub Actions — see CI/CD section)

> **Free Tier:** 0.5GB, 1 project, auto-suspend after 5 minutes inactivity.

---

### 5.3 Supabase (Warm Backup)

**Setup Steps:**

1. **Create Account** at https://supabase.com (free)
2. **Create Project:** linkflow-backup-2
3. **Get Connection String** from Settings → Database
4. **Automated Weekly Sync** (GitHub Actions — see CI/CD section)

> **Free Tier:** 500MB, 2 projects, daily auto-backups by Supabase.

---

### 5.4 Database Sync Automation

All database syncs are handled by GitHub Actions (free) — you do NOTHING manually:

```yaml
# .github/workflows/db-sync.yml
name: Database Sync

on:
  schedule:
    # Daily sync to Neon at 2 AM UTC
    - cron: '0 2 * * *'
    # Weekly sync to Supabase on Sunday at 3 AM UTC
    - cron: '0 3 * * 0'

jobs:
  sync-to-neon:
    if: github.event.schedule == '0 2 * * *'
    runs-on: ubuntu-latest
    steps:
      - name: Dump from CockroachDB
        run: |
          pg_dump "${{ secrets.COCKROACH_URL }}" --no-owner -f dump.sql
      - name: Restore to Neon
        run: |
          psql "${{ secrets.NEON_URL }}" < dump.sql
      - name: Notify
        run: |
          curl -X POST "${{ secrets.CRONITOR_PING_URL }}"

  sync-to-supabase:
    if: github.event.schedule == '0 3 * * 0'
    runs-on: ubuntu-latest
    steps:
      - name: Dump from CockroachDB
        run: |
          pg_dump "${{ secrets.COCKROACH_URL }}" --no-owner -f dump.sql
      - name: Restore to Supabase
        run: |
          psql "${{ secrets.SUPABASE_URL }}" < dump.sql
      - name: Upload to Backblaze B2
        run: |
          gzip dump.sql
          b2 upload-file linkflow-backups dump.sql.gz "db/$(date +%Y-%m-%d).sql.gz"
```

---

## 6. Redis & Cache Setup

### 6.1 Upstash (Primary Redis)

**Setup Steps:**

1. **Create Account** at https://upstash.com (free)
2. **Create Database:**
   - Name: linkflow-redis
   - Region: US-East-1
   - Type: Regional
   - TLS: Enabled
3. **Get Connection Details:**
   ```
   REDIS_URL=rediss://default:YOUR_TOKEN@usw1-fitting-toucan-38521.upstash.io:6379
   ```

> **Free Tier:** 10K commands/day, 256MB, 1 database. For queues and pub/sub.

---

### 6.2 Cloudflare KV (Secondary Cache)

Use for caching, sessions, and config — NOT for Redis Streams (KV is key-value only).

**Setup Steps:**

1. **Log into Cloudflare Dashboard** → Workers & Pages → KV
2. **Create Namespace:** linkflow-cache
3. **Use in Cloudflare Workers** for session caching and config

> **Free Tier:** 100K reads/day, 1K writes/day, 1GB storage.

---

### 6.3 Redis on Hetzner (Last Resort)

Already included in the Docker Compose on Hetzner. Auto-syncs via the hourly cron job.
