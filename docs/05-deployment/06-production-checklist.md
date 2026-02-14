# Production Checklist

Before going live, ensure you have addressed the following:

## Security
- [ ] **Secrets**: All secrets (`APP_KEY`, `JWT_SECRET`, `DB_PASSWORD`) are strong and random.
- [ ] **HTTPS**: TLS is enabled for all public endpoints.
- [ ] **Firewall**: Database and Redis ports are NOT exposed to the internet.
- [ ] **Debug Mode**: `APP_DEBUG=false` for Laravel.

## Reliability
- [ ] **Backups**: Automated daily backups for PostgreSQL.
- [ ] **Persistence**: Redis is configured with `appendonly yes` (AOF) for durability.
- [ ] **Health Checks**: Load balancer is configured to check `/health` endpoints.
- [ ] **Monitoring**: Metrics (Prometheus) and Logs (ELK/CloudWatch) are collected.

## Performance
- [ ] **Caching**: Route and Config caching enabled for Laravel (`php artisan optimize`).
- [ ] **Resources**: CPU/RAM limits set for Docker containers.
- [ ] **Database**: Connection pool size tuned (e.g., PgBouncer).

## Compliance
- [ ] **Logs**: PII is redacted from logs (Enable `REDACT_LOGS=true`).
- [ ] **Retention**: Data retention policies configured.
