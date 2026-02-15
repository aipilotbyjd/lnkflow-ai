# LinkFlow Production Deployment Guide

This guide will help you deploy LinkFlow to production safely and efficiently.

## Quick Start

```bash
# 1. Run production setup
./scripts/production-setup.sh

# 2. Start production stack
make prod

# 3. Run migrations
make migrate

# 4. Seed initial data
make seed

# 5. Verify health
./scripts/health-check.sh
```

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Environment Configuration](#environment-configuration)
3. [SSL Certificates](#ssl-certificates)
4. [Database Setup](#database-setup)
5. [Deployment](#deployment)
6. [Monitoring](#monitoring)
7. [Backup & Recovery](#backup--recovery)
8. [Scaling](#scaling)
9. [Security Checklist](#security-checklist)
10. [Troubleshooting](#troubleshooting)

---

## Prerequisites

### System Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| CPU | 4 cores | 8+ cores |
| RAM | 8 GB | 16+ GB |
| Disk | 50 GB SSD | 100+ GB SSD |
| OS | Ubuntu 20.04+ | Ubuntu 22.04 LTS |

### Software Requirements

- Docker 24.0+
- Docker Compose 2.20+
- OpenSSL (for certificate management)
- curl (for health checks)

### Network Requirements

- Ports 80 and 443 open for HTTPS traffic
- Ports 5432 and 6379 should NOT be exposed to the internet
- Domain names configured:
  - `api.linkflow.io` → Your server IP
  - `engine.linkflow.io` → Your server IP

---

## Environment Configuration

### 1. Copy Environment Files

```bash
cp .env.example .env
cp apps/api/.env.docker.example apps/api/.env.docker
```

### 2. Generate Secrets

```bash
# Database password
openssl rand -base64 24

# Redis password
openssl rand -base64 24

# API-Engine shared secret
openssl rand -base64 32

# JWT secret
openssl rand -base64 32

# Laravel encryption key (after first build)
docker compose run --rm api php artisan key:generate --show
```

### 3. Configure .env

Edit `.env` and set all required values:

```bash
# Database
POSTGRES_USER=linkflow
POSTGRES_PASSWORD=<generated-password>
POSTGRES_DB=linkflow

# Redis
REDIS_PASSWORD=<generated-password>

# Security
LINKFLOW_SECRET=<generated-secret>
JWT_SECRET=<generated-secret>
APP_KEY=<generated-key>

# Engine
ENGINE_PARTITION_COUNT=16
LOG_LEVEL=info

# SSL
SSL_CERT_PATH=./infra/nginx/ssl
```

### 4. Configure apps/api/.env.docker

Set production values:

```bash
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning
LOG_CHANNEL=stack

# Session & Cache
SESSION_DRIVER=redis
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

# Mail (configure your SMTP)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@linkflow.io
MAIL_FROM_NAME="LinkFlow"
```

---

## SSL Certificates

### Option 1: Let's Encrypt (Recommended)

```bash
# Install certbot
sudo apt-get update
sudo apt-get install certbot

# Generate certificates
sudo certbot certonly --standalone \
  -d api.linkflow.io \
  -d engine.linkflow.io \
  --email admin@linkflow.io \
  --agree-tos

# Copy to nginx directory
sudo cp /etc/letsencrypt/live/api.linkflow.io/fullchain.pem infra/nginx/ssl/
sudo cp /etc/letsencrypt/live/api.linkflow.io/privkey.pem infra/nginx/ssl/
sudo chmod 644 infra/nginx/ssl/fullchain.pem
sudo chmod 600 infra/nginx/ssl/privkey.pem
```

### Option 2: Self-Signed (Testing Only)

```bash
./scripts/generate-ssl.sh
```

### Certificate Renewal

Add to crontab for automatic renewal:

```bash
0 0 1 * * certbot renew --quiet && cp /etc/letsencrypt/live/api.linkflow.io/*.pem /path/to/linkflow/infra/nginx/ssl/ && docker compose -f docker-compose.yml -f docker-compose.prod.yml restart nginx
```

---

## Database Setup

### Initial Setup

```bash
# Start infrastructure only
docker compose up -d postgres redis

# Wait for healthy
docker compose ps

# Run migrations
make migrate

# Seed initial data
make seed
```

### Database Tuning

The production PostgreSQL configuration is in `infra/postgres/postgresql.prod.conf`.

Key settings for a 16GB server:

```ini
shared_buffers = 4GB
effective_cache_size = 12GB
maintenance_work_mem = 1GB
work_mem = 64MB
max_connections = 200
```

Adjust based on your server specs.

---

## Deployment

### First-Time Deployment

```bash
# 1. Run setup script
./scripts/production-setup.sh

# 2. Start production stack
make prod

# 3. Check status
make ps

# 4. Run migrations
make migrate

# 5. Seed data
make seed

# 6. Health check
./scripts/health-check.sh
```

### Updating Deployment

```bash
# 1. Pull latest code
git pull origin main

# 2. Rebuild images
make prod-build

# 3. Run new migrations
make migrate

# 4. Verify health
./scripts/health-check.sh
```

### Zero-Downtime Updates

```bash
# 1. Scale up new instances
make prod-scale-api N=4
make prod-scale-workers N=6

# 2. Wait for health checks
sleep 30

# 3. Rebuild and restart (rolling)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --no-deps --build api

# 4. Scale back down
make prod-scale-api N=2
make prod-scale-workers N=3
```

---

## Monitoring

### Health Checks

```bash
# Comprehensive health check
./scripts/health-check.sh

# Quick status
make health

# View logs
make prod-logs

# Follow specific service
docker compose logs -f api
```

### Metrics Endpoints

| Service | Endpoint | Port |
|---------|----------|------|
| API | `/api/v1/health` | 8000 |
| Engine | `/health` | 8080 |
| Prometheus | `/metrics` | 9090 |

### Log Aggregation

Production logs are written to:
- API: `apps/api/storage/logs/laravel.log`
- Engine: stdout (captured by Docker)
- Nginx: `nginx_logs` volume

Consider setting up:
- ELK Stack (Elasticsearch, Logstash, Kibana)
- Grafana + Loki
- CloudWatch (AWS)
- Datadog

### Alerting

Set up alerts for:
- Container health failures
- High memory usage (>90%)
- High disk usage (>90%)
- Database connection failures
- API response time >1s
- Error rate >1%

Example with cron:

```bash
# Add to crontab
*/5 * * * * /path/to/linkflow/scripts/health-check.sh || echo "LinkFlow health check failed" | mail -s "Alert: LinkFlow Down" admin@linkflow.io
```

---

## Backup & Recovery

### Automated Backups

```bash
# Manual backup
./scripts/backup-database.sh

# Automated daily backup (add to crontab)
0 2 * * * /path/to/linkflow/scripts/backup-database.sh
```

Backups are stored in `backups/` directory and automatically cleaned up after 7 days.

### Restore from Backup

```bash
# List available backups
ls -lh backups/

# Restore specific backup
./scripts/restore-database.sh backups/linkflow_backup_20260215_020000.sql.gz
```

### Disaster Recovery Plan

1. **Database Backup**: Daily automated backups
2. **Volume Backup**: Weekly snapshots of Docker volumes
3. **Configuration Backup**: Git repository for all configs
4. **Recovery Time Objective (RTO)**: < 1 hour
5. **Recovery Point Objective (RPO)**: < 24 hours

### Off-site Backup

```bash
# Sync backups to S3
aws s3 sync backups/ s3://linkflow-backups/$(date +%Y-%m)/

# Or use rsync to remote server
rsync -avz backups/ backup-server:/backups/linkflow/
```

---

## Scaling

### Horizontal Scaling

```bash
# Scale API servers
make prod-scale-api N=4

# Scale queue workers
make prod-scale-queue N=4

# Scale engine workers
make prod-scale-workers N=8
```

### Vertical Scaling

Update resource limits in `docker-compose.prod.yml`:

```yaml
services:
  api:
    deploy:
      resources:
        limits:
          memory: 1G
          cpus: "2.0"
```

### Load Balancing

For multi-server deployments:

1. **Docker Swarm**: Use `docker stack deploy`
2. **Kubernetes**: Use provided Helm charts (coming soon)
3. **External LB**: HAProxy, AWS ALB, or Nginx upstream

### Database Scaling

- **Read Replicas**: Configure PostgreSQL streaming replication
- **Connection Pooling**: Use PgBouncer
- **Managed Database**: AWS RDS, Google Cloud SQL, or Azure Database

---

## Security Checklist

### Before Going Live

- [ ] All secrets are strong and random (32+ characters)
- [ ] `APP_DEBUG=false` in production
- [ ] SSL/TLS certificates are valid and trusted
- [ ] Database ports (5432, 6379) are NOT exposed to internet
- [ ] Firewall rules allow only ports 80 and 443
- [ ] Security headers configured in nginx.conf
- [ ] Rate limiting enabled
- [ ] CORS configured properly
- [ ] Database backups automated
- [ ] Monitoring and alerting set up
- [ ] Log rotation configured
- [ ] OS security updates enabled
- [ ] Docker images are up to date
- [ ] Secrets are not in Git history

### Security Headers

Already configured in `infra/nginx/nginx.conf`:

- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `X-XSS-Protection: 1; mode=block`
- `Strict-Transport-Security: max-age=31536000`
- `Content-Security-Policy`
- `Permissions-Policy`

### Rate Limiting

Configured in nginx:
- API endpoints: 100 req/s per IP
- Auth endpoints: 5 req/s per IP
- Webhook endpoints: Higher limits

### Firewall Configuration

```bash
# UFW example
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp    # SSH
sudo ufw allow 80/tcp    # HTTP
sudo ufw allow 443/tcp   # HTTPS
sudo ufw enable
```

---

## Troubleshooting

### Services Won't Start

```bash
# Check logs
make prod-logs

# Check specific service
docker compose logs api

# Verify environment
cat .env | grep -v "^#" | grep -v "^$"

# Check disk space
df -h

# Check memory
free -h
```

### Database Connection Errors

```bash
# Test database connectivity
docker exec linkflow-postgres pg_isready -U linkflow

# Check password
grep POSTGRES_PASSWORD .env

# Verify SSL mode
docker compose config | grep DATABASE_URL
```

### SSL Certificate Errors

```bash
# Verify certificates exist
ls -la infra/nginx/ssl/

# Check certificate validity
openssl x509 -in infra/nginx/ssl/fullchain.pem -text -noout

# Check nginx config
docker compose exec nginx nginx -t
```

### High Memory Usage

```bash
# Check container stats
docker stats

# Restart specific service
docker compose restart api

# Scale down if needed
make prod-scale-workers N=2
```

### Performance Issues

```bash
# Check database connections
docker exec linkflow-postgres psql -U linkflow -c "SELECT count(*) FROM pg_stat_activity;"

# Check Redis memory
docker exec linkflow-redis redis-cli -a "$REDIS_PASSWORD" INFO memory

# Optimize Laravel
docker compose exec api php artisan optimize
docker compose exec api php artisan config:cache
docker compose exec api php artisan route:cache
docker compose exec api php artisan view:cache
```

---

## Production Maintenance

### Regular Tasks

**Daily:**
- Monitor health checks
- Review error logs
- Check disk space

**Weekly:**
- Review performance metrics
- Update Docker images
- Test backup restoration

**Monthly:**
- Security updates
- Certificate renewal check
- Capacity planning review

### Maintenance Window

```bash
# 1. Enable maintenance mode
docker compose exec api php artisan down --message="Scheduled maintenance"

# 2. Perform updates
git pull
make prod-build
make migrate

# 3. Disable maintenance mode
docker compose exec api php artisan up
```

---

## Support

For issues and questions:
- Documentation: `docs/`
- GitHub Issues: [your-repo]/issues
- Email: support@linkflow.io

---

## Next Steps

After successful deployment:

1. Set up monitoring and alerting
2. Configure automated backups
3. Test disaster recovery procedures
4. Document your specific configuration
5. Train your team on operations
6. Set up CI/CD pipeline
7. Configure log aggregation
8. Implement security scanning

**Congratulations! Your LinkFlow instance is production-ready! 🚀**
