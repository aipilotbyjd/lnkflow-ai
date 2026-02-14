# LinkFlow Multi-Provider Operations Guide

> **Part 2 of the Multi-Provider HA Plan.** See `07-multi-provider-ha-plan.md` for architecture, compute, database, and Redis setup.

---

## 7. Storage Setup

### 7.1 Cloudflare R2 (Primary File Storage)

**Use for:** User uploads, workflow attachments, public assets.

**Setup Steps:**

1. **Log into Cloudflare** → R2 → Create Bucket
2. **Bucket Name:** linkflow-uploads
3. **Location:** Automatic (Cloudflare chooses closest)
4. **Create API Token:**
   - Go to R2 → Manage R2 API Tokens
   - Create token with Object Read & Write
   - Save Access Key ID and Secret Access Key

5. **Laravel .env Configuration:**
   ```env
   FILESYSTEM_DISK=r2
   R2_ACCESS_KEY_ID=your-access-key
   R2_SECRET_ACCESS_KEY=your-secret-key
   R2_BUCKET=linkflow-uploads
   R2_ENDPOINT=https://your-account-id.r2.cloudflarestorage.com
   R2_URL=https://uploads.yourdomain.com  # Custom domain via Cloudflare
   ```

6. **Laravel Filesystem Config** (`config/filesystems.php`):
   ```php
   'r2' => [
       'driver' => 's3',
       'key' => env('R2_ACCESS_KEY_ID'),
       'secret' => env('R2_SECRET_ACCESS_KEY'),
       'region' => 'auto',
       'bucket' => env('R2_BUCKET'),
       'endpoint' => env('R2_ENDPOINT'),
       'use_path_style_endpoint' => false,
   ],
   ```

> **Free Tier:** 10GB storage, 10M Class A ops/mo, 10M Class B ops/mo, zero egress fees.

---

### 7.2 Backblaze B2 (Database Backups)

**Use for:** Database dump files, disaster recovery archives.

**Setup Steps:**

1. **Create Account** at https://www.backblaze.com/b2 (free)
2. **Create Bucket:** linkflow-backups (private)
3. **Create Application Key:** with read/write to linkflow-backups bucket
4. **Install B2 CLI:**
   ```bash
   pip install b2
   b2 authorize-account YOUR_KEY_ID YOUR_APP_KEY
   ```

> **Free Tier:** 10GB storage, 1GB/day download, 2,500 API calls/day.

---

### 7.3 Azure Blob Storage (Extra Backups)

**Use for:** Monthly full backups, long-term retention.

**Setup Steps:**

1. **Azure Portal** → Create Storage Account
   - Name: linkflowbackups
   - Region: East US
   - Performance: Standard
   - Redundancy: LRS (cheapest)
2. **Create Container:** monthly-backups
3. **Get Connection String** from Access Keys

> **Free Tier:** 5GB LRS, 20K read ops, 10K write ops (12 months).

---

## 8. Email Setup

### 8.1 Resend (Primary — Transactional Email)

**Setup Steps:**

1. **Create Account** at https://resend.com
2. **Add Domain:** yourdomain.com → Add DNS records to Cloudflare
3. **Get API Key** from dashboard
4. **Laravel .env:**
   ```env
   MAIL_MAILER=resend
   RESEND_API_KEY=re_your_api_key
   MAIL_FROM_ADDRESS=noreply@yourdomain.com
   MAIL_FROM_NAME=LinkFlow
   ```
5. **Install Laravel Package:**
   ```bash
   composer require resend/resend-laravel
   ```

> **Free Tier:** 3,000 emails/month, 1 domain.

---

### 8.2 Brevo (Overflow — When Resend Quota Hits)

**Setup Steps:**

1. **Create Account** at https://www.brevo.com
2. **Get SMTP Credentials** from Settings → SMTP & API
3. **Laravel Fallback Config:** Use a mail failover in `config/mail.php`:
   ```php
   'mailers' => [
       'failover' => [
           'transport' => 'failover',
           'mailers' => ['resend', 'brevo'],
       ],
       'resend' => [
           'transport' => 'resend',
       ],
       'brevo' => [
           'transport' => 'smtp',
           'host' => 'smtp-relay.brevo.com',
           'port' => 587,
           'username' => env('BREVO_USERNAME'),
           'password' => env('BREVO_PASSWORD'),
           'encryption' => 'tls',
       ],
   ],
   ```

> **Free Tier:** 300 emails/day (~9,000/month). Combined with Resend = **12,000 emails/month free.**

---

## 9. DNS, CDN & Security

### 9.1 Cloudflare (Everything Network)

**Setup Steps:**

1. **Add Your Domain** to Cloudflare (free plan)
2. **Update Nameservers** at your registrar to Cloudflare's

3. **DNS Records:**
   ```
   Type  Name              Value                                    Proxy
   A     api               Azure Container Apps IP                  ✅ ON
   CNAME engine            linkflow-engine-frontend.fly.dev         ✅ ON
   CNAME status            your-instatus-page.instatus.com          ✅ ON
   A     fallback          YOUR_HETZNER_IP                          ✅ ON
   ```

4. **SSL/TLS Settings:**
   - Mode: Full (Strict)
   - Always Use HTTPS: ON
   - Minimum TLS: 1.2
   - Auto HTTPS Rewrites: ON

5. **Security Settings:**
   - WAF: ON (Managed Rules - free)
   - Bot Fight Mode: ON
   - Rate Limiting: Create rule (100 req/10sec per IP)

6. **Caching:**
   - Cache Level: Standard
   - Browser Cache TTL: 4 hours
   - Always Online: ON (serves cached pages if your server is down!)

### 9.2 Cloudflare Tunnel (Secure Connection to Hetzner)

Instead of exposing Hetzner ports directly, use a tunnel:

```bash
# On Hetzner server
curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -o /usr/local/bin/cloudflared
chmod +x /usr/local/bin/cloudflared

# Authenticate
cloudflared tunnel login

# Create tunnel
cloudflared tunnel create linkflow-fallback

# Configure
cat > /etc/cloudflared/config.yml << 'EOF'
tunnel: your-tunnel-id
credentials-file: /root/.cloudflared/your-tunnel-id.json

ingress:
  - hostname: fallback-api.yourdomain.com
    service: http://localhost:8000
  - hostname: fallback-engine.yourdomain.com
    service: http://localhost:8080
  - service: http_status:404
EOF

# Run as service
cloudflared service install
systemctl start cloudflared
```

> **This means Hetzner has NO open ports except SSH.** Everything goes through Cloudflare's secure tunnel.

---

## 10. Monitoring & Alerts — "Set and Forget"

### 10.1 UptimeRobot (Uptime + Failover Trigger)

**Setup Steps:**

1. **Create Account** at https://uptimerobot.com (free)
2. **Add Monitors:**

| Monitor | URL | Check Interval | Alert |
|---------|-----|---------------|-------|
| API Health | `https://api.yourdomain.com/api/v1/health` | 5 min | Email + Slack |
| Engine Health | `https://engine.yourdomain.com/health` | 5 min | Email + Slack |
| Status Page | `https://status.yourdomain.com` | 5 min | Email |
| Hetzner Fallback | `https://fallback-api.yourdomain.com/api/v1/health` | 5 min | Email |
| CockroachDB | TCP check on port 26257 | 5 min | Email + SMS |
| Neon Backup | `https://your-neon-project.neon.tech` | 60 min | Email |

3. **Alert Contacts:**
   - Email: your-email@gmail.com
   - Slack Webhook: Add from Slack integration
   - SMS: Add phone number (free for critical alerts)

### 10.2 Sentry (Error Tracking)

**Setup Steps:**

1. **Create Account** at https://sentry.io (free)
2. **Create Projects:** linkflow-api (PHP), linkflow-engine (Go)

3. **Laravel Integration:**
   ```bash
   composer require sentry/sentry-laravel
   ```
   ```env
   SENTRY_LARAVEL_DSN=https://your-dsn@sentry.io/123
   SENTRY_TRACES_SAMPLE_RATE=0.1
   ```

4. **Go Integration:**
   ```go
   import "github.com/getsentry/sentry-go"

   sentry.Init(sentry.ClientOptions{
       Dsn: "https://your-dsn@sentry.io/456",
       TracesSampleRate: 0.1,
   })
   ```

> **Free Tier:** 5K errors/month, 10K performance transactions/month.

### 10.3 BetterStack (Logs)

**Setup Steps:**

1. **Create Account** at https://betterstack.com (free)
2. **Create Source:** linkflow-production
3. **Laravel Logging** (`.env`):
   ```env
   LOG_CHANNEL=stack
   BETTERSTACK_SOURCE_TOKEN=your-token
   ```

> **Free Tier:** 1GB logs/month, 3-day retention.

### 10.4 Grafana Cloud (Metrics Dashboard)

**Setup Steps:**

1. **Create Account** at https://grafana.com/products/cloud (free)
2. **Install Grafana Agent** on Hetzner:
   ```bash
   # Download and configure agent to ship metrics
   # to your Grafana Cloud instance
   ```
3. **Import Dashboards:**
   - Docker dashboard (ID: 1229)
   - PostgreSQL dashboard (ID: 9628)
   - Redis dashboard (ID: 11835)

> **Free Tier:** 10K metrics, 50GB logs, 50GB traces, 500 VUh k6 testing.

### 10.5 Cronitor (Backup & Cron Monitoring)

**Setup Steps:**

1. **Create Account** at https://cronitor.io (free)
2. **Add Monitors for:**
   - Daily DB sync to Neon
   - Weekly DB sync to Supabase
   - Hourly Hetzner DB sync
   - Daily Backblaze B2 upload

Each cron job pings Cronitor when done:
```bash
# At end of each cron script, add:
curl -s "https://cronitor.link/p/YOUR_KEY/sync-neon?state=complete"
```

If Cronitor doesn't receive a ping on schedule → alerts you.

> **Free Tier:** 5 monitors.

---

## 11. Status Page (Always On)

### 11.1 Instatus (Public Status Page)

**This page is ALWAYS accessible** — even if your entire infrastructure is down, because Instatus is a separate service.

**Setup Steps:**

1. **Create Account** at https://instatus.com (free)
2. **Create Status Page:** status.yourdomain.com
3. **Add Components:**

| Component | Type | Monitor |
|-----------|------|---------|
| API | Operational | UptimeRobot webhook |
| Workflow Engine | Operational | UptimeRobot webhook |
| Database | Operational | Manual or UptimeRobot |
| Background Jobs | Operational | Cronitor integration |
| File Storage | Operational | UptimeRobot webhook |

4. **Connect to Cloudflare DNS:**
   ```
   CNAME status → your-page.instatus.com
   ```

5. **Automatic Updates:**
   - Connect UptimeRobot → Instatus via webhook
   - When UptimeRobot detects downtime → Instatus automatically shows "Degraded" or "Down"
   - When it recovers → Instatus automatically shows "Operational"

6. **Subscribe Notifications:**
   - Users can subscribe to email/SMS updates
   - You can post incident updates from the Instatus dashboard or mobile app

### 11.2 Status Page Custom Domain

```
https://status.yourdomain.com
```

Users see:
```
✅ API                    Operational
✅ Workflow Engine         Operational
✅ Database                Operational
✅ Background Jobs         Operational
✅ File Storage            Operational

Uptime: 99.95% (last 90 days)
```

> **This page runs on Instatus servers, NOT yours. It stays up even if everything else is down.**

---

## 12. CI/CD Pipeline — Fully Automated

### 12.1 GitHub Actions — One Push Deploys Everywhere

Create `.github/workflows/deploy.yml`:

```yaml
name: Build & Deploy to All Providers

on:
  push:
    branches: [main]

env:
  REGISTRY: ghcr.io
  IMAGE_PREFIX: ghcr.io/${{ github.repository }}

jobs:
  # ─────────────────────────────────────────
  # Step 1: Build all Docker images
  # ─────────────────────────────────────────
  build:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write
    strategy:
      matrix:
        include:
          - service: api
            context: apps/api
            dockerfile: apps/api/Dockerfile
          - service: queue
            context: apps/api
            dockerfile: apps/api/Dockerfile.queue
          - service: engine-frontend
            context: apps/engine
            dockerfile: apps/engine/cmd/frontend/Dockerfile
          - service: engine-history
            context: apps/engine
            dockerfile: apps/engine/cmd/history/Dockerfile
          - service: engine-matching
            context: apps/engine
            dockerfile: apps/engine/cmd/matching/Dockerfile
          - service: engine-worker
            context: apps/engine
            dockerfile: apps/engine/cmd/worker/Dockerfile
          - service: engine-timer
            context: apps/engine
            dockerfile: apps/engine/cmd/timer/Dockerfile
    steps:
      - uses: actions/checkout@v4

      - name: Login to GHCR
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: ${{ matrix.context }}
          file: ${{ matrix.dockerfile }}
          push: true
          tags: ${{ env.IMAGE_PREFIX }}/${{ matrix.service }}:latest

  # ─────────────────────────────────────────
  # Step 2: Deploy to all providers
  # ─────────────────────────────────────────
  deploy-azure:
    needs: build
    runs-on: ubuntu-latest
    steps:
      - name: Login to Azure
        uses: azure/login@v2
        with:
          creds: ${{ secrets.AZURE_CREDENTIALS }}
      - name: Update API container
        run: |
          az containerapp update \
            --name linkflow-api \
            --resource-group linkflow-rg \
            --image ${{ env.IMAGE_PREFIX }}/api:latest
      - name: Update Timer container
        run: |
          az containerapp update \
            --name linkflow-timer \
            --resource-group linkflow-rg \
            --image ${{ env.IMAGE_PREFIX }}/engine-timer:latest

  deploy-fly:
    needs: build
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: superfly/flyctl-actions/setup-flyctl@master
      - run: flyctl deploy --remote-only
        env:
          FLY_API_TOKEN: ${{ secrets.FLY_API_TOKEN }}

  deploy-railway:
    needs: build
    runs-on: ubuntu-latest
    steps:
      - name: Trigger Railway redeploy
        run: |
          curl -X POST "${{ secrets.RAILWAY_WEBHOOK_URL }}"

  deploy-koyeb:
    needs: build
    runs-on: ubuntu-latest
    steps:
      - name: Trigger Koyeb redeploy
        run: |
          curl -X POST "https://app.koyeb.com/v1/services/${{ secrets.KOYEB_SERVICE_ID }}/redeploy" \
            -H "Authorization: Bearer ${{ secrets.KOYEB_API_TOKEN }}"

  deploy-zeabur:
    needs: build
    runs-on: ubuntu-latest
    steps:
      - name: Trigger Zeabur redeploy
        run: |
          curl -X POST "${{ secrets.ZEABUR_WEBHOOK_URL }}"

  deploy-render:
    needs: build
    runs-on: ubuntu-latest
    steps:
      - name: Trigger Render redeploy
        run: |
          curl -X POST "https://api.render.com/v1/services/${{ secrets.RENDER_SERVICE_ID }}/deploys" \
            -H "Authorization: Bearer ${{ secrets.RENDER_API_KEY }}"

  deploy-hetzner:
    needs: build
    runs-on: ubuntu-latest
    steps:
      - name: Deploy to Hetzner (standby)
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.HETZNER_IP }}
          username: root
          key: ${{ secrets.HETZNER_SSH_KEY }}
          script: |
            cd /opt/linkflow
            docker compose pull
            docker compose up -d
            echo "Hetzner standby updated: $(date)"

  # ─────────────────────────────────────────
  # Step 3: Run migrations
  # ─────────────────────────────────────────
  migrate:
    needs: [deploy-azure]
    runs-on: ubuntu-latest
    steps:
      - name: Run Laravel migrations
        run: |
          az containerapp exec \
            --name linkflow-api \
            --resource-group linkflow-rg \
            --command "php artisan migrate --force"

  # ─────────────────────────────────────────
  # Step 4: Notify
  # ─────────────────────────────────────────
  notify:
    needs: [deploy-azure, deploy-fly, deploy-railway, deploy-koyeb, deploy-zeabur, deploy-render, deploy-hetzner, migrate]
    runs-on: ubuntu-latest
    if: always()
    steps:
      - name: Notify Slack
        run: |
          curl -X POST "${{ secrets.SLACK_WEBHOOK }}" \
            -H 'Content-type: application/json' \
            -d '{"text":"🚀 LinkFlow deployed to all 7 providers. Commit: ${{ github.sha }}"}'
```

> **You push code → GitHub builds images → deploys to ALL 7 providers → runs migrations → notifies you on Slack. Total effort: `git push`.**

---

## 13. Secrets Management — Doppler

### Setup

1. **Create Account** at https://www.doppler.com (free — 5 projects)
2. **Install CLI:** `brew install dopplerhq/cli/doppler`
3. **Create Project:** linkflow
4. **Create Environments:** development, staging, production
5. **Add All Secrets:**
   ```
   DATABASE_URL=postgresql://...
   REDIS_URL=rediss://...
   JWT_SECRET=...
   LINKFLOW_SECRET=...
   SENTRY_DSN=...
   R2_ACCESS_KEY_ID=...
   # ... all 30+ env vars
   ```

6. **Sync to All Providers:**
   - Doppler → Azure Container Apps (native integration)
   - Doppler → Fly.io (via CLI)
   - Doppler → GitHub Actions (native integration)

> **Change a secret in Doppler → it updates everywhere. One place to manage all secrets across 7 providers.**

---

## 14. Emergency Procedures

### 🔴 Scenario 1: A Free Compute Provider Goes Down

| Step | Action | Time |
|------|--------|------|
| 1 | UptimeRobot detects and alerts you | Automatic, ~2 min |
| 2 | Cloudflare Worker routes traffic to Hetzner | Automatic, ~1 min |
| 3 | You do nothing — Hetzner handles traffic | 0 effort |
| 4 | When provider recovers, Cloudflare routes back | Automatic |

### 🔴 Scenario 2: CockroachDB (Primary DB) Goes Down

| Step | Action | Time |
|------|--------|------|
| 1 | Sentry alerts you about DB errors | Automatic |
| 2 | Change `DATABASE_URL` in Doppler to Neon URL | 1 minute |
| 3 | Doppler syncs to all providers | Automatic |
| 4 | All services reconnect to Neon | ~2 minutes |

### 🔴 Scenario 3: Total Free Tier Meltdown (Everything Free Dies)

| Step | Action | Time |
|------|--------|------|
| 1 | Multiple alerts fire | Automatic |
| 2 | Cloudflare routes ALL traffic to Hetzner | Automatic |
| 3 | Hetzner has: all containers + Postgres + Redis | Already running |
| 4 | Your app runs 100% on Hetzner for €4.51/mo | 0 effort |
| 5 | Fix free providers when you have time | At your pace |

### 🔴 Scenario 4: Hetzner Dies (Your Last Resort Dies)

| Step | Action | Time |
|------|--------|------|
| 1 | Free providers are still running (Hetzner is backup) | No impact |
| 2 | Create new Hetzner server | 2 minutes |
| 3 | Run setup script + `docker compose up -d` | 5 minutes |
| 4 | DB syncs from CockroachDB | Automatic |
| 5 | New fallback is ready | Total: ~10 min |

### 🔴 Scenario 5: You Need to Abandon a Provider Completely

| Step | Action | Time |
|------|--------|------|
| 1 | Pick replacement from swap guide (Section 17) | 2 min |
| 2 | Deploy same Docker image to new provider | 10 min |
| 3 | Update DNS in Cloudflare | 1 min |
| 4 | Update secrets in Doppler | 1 min |
| 5 | Done | Total: ~15 min |

---

## 15. Cost Breakdown

### Monthly Recurring Cost

| Service | Cost |
|---------|------|
| Hetzner CX22 | €4.51 |
| Hetzner Snapshots (weekly) | ~€0.20 |
| **All other 31 services** | **€0.00** |
| **Total** | **€4.71/mo (~$5.20)** |

### What You Get for ~$5/month

| Feature | ✅ |
|---------|---|
| 7 compute instances across 7 providers | ✅ |
| 4 database copies across 4 providers | ✅ |
| 3 Redis/cache instances across 3 providers | ✅ |
| 25GB free file storage across 3 providers | ✅ |
| 12,000 free emails/month | ✅ |
| Full CI/CD pipeline | ✅ |
| Auto-deploy to all providers on git push | ✅ |
| Automatic failover | ✅ |
| Public status page | ✅ |
| Error tracking | ✅ |
| Log management | ✅ |
| Metrics dashboards | ✅ |
| Uptime monitoring with SMS alerts | ✅ |
| DDoS protection | ✅ |
| Free SSL/CDN | ✅ |
| Secrets management | ✅ |

---

## 16. Weekly Maintenance Checklist

> **Total time: ~15 minutes/week**

| Day | Task | Time | How |
|-----|------|------|-----|
| **Monday** | Check Slack/email for weekend alerts | 2 min | Read notifications |
| **Monday** | Glance at Grafana dashboard | 2 min | Open browser |
| **Wednesday** | Check Cronitor — all backups running? | 1 min | Open browser |
| **Wednesday** | Check free tier usage (Azure, Fly, etc.) | 5 min | Provider dashboards |
| **Friday** | Review and merge Dependabot PRs | 5 min | GitHub |
| **Monthly** | Test disaster recovery (kill Hetzner, see if free providers hold) | 15 min | SSH |
| **Monthly** | Rotate secrets in Doppler | 10 min | Doppler dashboard |

---

## 17. Provider Swap Guide

If any provider changes their free tier, removes it, or you just want to switch — here's what to swap to:

### Compute Swaps

| Current | Swap To | Setup Time |
|---------|---------|------------|
| Azure Container Apps | Render, Coolify | 10 min |
| Fly.io | Railway, Back4App | 10 min |
| Railway | Fly.io, Koyeb | 10 min |
| Koyeb | Zeabur, Fly.io | 10 min |
| Zeabur | Koyeb, Railway | 10 min |
| Render | Azure Container Apps | 10 min |

### Database Swaps

| Current | Swap To | Setup Time |
|---------|---------|------------|
| CockroachDB | Neon (already synced!) | 1 min (change env var) |
| Neon | Supabase (already synced!) | 1 min (change env var) |
| Supabase | Aiven Free Postgres | 15 min |

### Redis Swaps

| Current | Swap To | Setup Time |
|---------|---------|------------|
| Upstash | Redis on Hetzner (already running) | 1 min |
| Cloudflare KV | Upstash KV or Vercel KV | 10 min |

### How to Swap (Same for All)

1. Deploy Docker image to new provider
2. Update DNS in Cloudflare (if user-facing)
3. Update connection string in Doppler
4. Doppler syncs to all services
5. Done

---

## 18. FAQ & Troubleshooting

### Q: Won't managing 31 services be overwhelming?

**No, because:**
- You set them up ONCE
- GitHub Actions deploys to all of them automatically
- Doppler manages all secrets in one place
- UptimeRobot + Cronitor + Sentry alert you only when something needs attention
- Weekly maintenance is ~15 minutes

### Q: What if a free tier changes or gets removed?

Swap to an alternative (Section 17). Every service has 2-3 alternatives. Swap takes 10-15 minutes because everything is Docker containers — same image runs everywhere.

### Q: What happens when Azure free credits expire (after 12 months)?

Azure Container Apps is always free (not trial). Azure Postgres and Redis free tier expire after 12 months. When they expire:
- Postgres: You already have CockroachDB + Neon + Supabase (all free forever)
- Redis: You already have Upstash (free forever) + Hetzner self-hosted

### Q: Is the data safe with all these free providers?

Your data exists in 4+ places at all times:
1. CockroachDB (primary)
2. Neon (daily backup)
3. Supabase (weekly backup)
4. Hetzner local Postgres (hourly sync)
5. Backblaze B2 (dump files)

You'd need ALL 5 to fail simultaneously to lose data. That's essentially impossible.

### Q: How do I debug issues across 7 compute providers?

1. **Sentry** catches all errors with stack traces (both PHP and Go)
2. **BetterStack** aggregates logs from ALL providers in one place
3. **Grafana** shows metrics from all providers in one dashboard
4. You never SSH into individual providers — everything is in centralized tools

### Q: Can this actually handle millions of executions?

At free tier limits, realistically you can handle:
- ~500K-1M requests/month across all free compute
- If you need more, upgrade Hetzner to CX32 (€8.49) and route overflow there
- The architecture scales because everything is stateless containers — add more instances

### Q: What's my single biggest risk?

**Cloudflare.** Everything routes through Cloudflare. But Cloudflare has 99.99% uptime and is used by 20% of all websites. If Cloudflare goes down, the internet has bigger problems.

---

## Quick Reference Card

```
🌐 App URL:       https://api.yourdomain.com
📊 Status Page:   https://status.yourdomain.com
📈 Grafana:       https://your-org.grafana.net
🐛 Sentry:        https://your-org.sentry.io
📋 Logs:          https://logs.betterstack.com
🔑 Secrets:       https://dashboard.doppler.com
🚀 Deploy:        git push origin main
⏰ Monitoring:    https://uptimerobot.com/dashboard
💾 Backups:       Automated (Cronitor monitors)
🛡️ Fallback:      Hetzner — always warm, always ready
```
