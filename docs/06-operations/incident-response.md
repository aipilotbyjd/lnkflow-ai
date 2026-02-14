# Incident Response Runbook

## Severity Levels

| Level | Name | Description | Response Time | Examples |
|-------|------|-------------|---------------|----------|
| **SEV1** | Critical | Complete service outage, data loss risk | Immediate (< 15 min) | All services down, database corruption, security breach |
| **SEV2** | Major | Significant functionality impaired | < 30 min | Workflow execution failing, API errors > 10%, payment failures |
| **SEV3** | Minor | Limited impact, workaround available | < 4 hours | Single microservice degraded, slow queries, minor feature broken |
| **SEV4** | Low | Minimal impact | Next business day | UI cosmetic issues, non-critical logs missing |

---

## Escalation Paths

### SEV1 - Critical

```
1. On-call Engineer (immediate)
   ↓
2. Engineering Lead (5 min if no response)
   ↓
3. CTO/VP Engineering (15 min if unresolved)
   ↓
4. Executive Team (30 min if customer-impacting)
```

### SEV2 - Major

```
1. On-call Engineer (immediate)
   ↓
2. Service Owner (15 min if no progress)
   ↓
3. Engineering Lead (1 hour if unresolved)
```

### SEV3/SEV4

```
1. On-call Engineer (during business hours)
   ↓
2. Service Owner (if specialized knowledge needed)
```

### Contact Methods

| Role | Primary | Secondary |
|------|---------|-----------|
| On-call | PagerDuty | Slack #incidents |
| Engineering Lead | Slack DM | Phone |
| Service Owners | Slack #team-channel | Email |

---

## Incident Response Workflow

### 1. Detection & Acknowledgement

```bash
# Acknowledge the incident
1. Respond to PagerDuty/alert
2. Join #incident-response Slack channel
3. Post initial assessment:
   "🚨 Incident: [Brief description]
    Severity: SEV[X]
    Impact: [What's affected]
    Investigating: [Your name]"
```

### 2. Triage

```bash
# Quick health check
./scripts/check-health.sh

# Check service status
docker-compose ps
kubectl get pods -n linkflow

# Check recent deployments
git log --oneline -10
kubectl rollout history deployment/linkflow-api
```

### 3. Mitigation

Prioritize restoring service over finding root cause:

- **Rollback** if recent deployment
- **Scale up** if capacity issue
- **Restart** if service hung
- **Failover** if infrastructure issue

### 4. Resolution & Communication

```
1. Confirm service restored
2. Update stakeholders in Slack
3. Create incident ticket with timeline
4. Schedule post-mortem (SEV1/SEV2)
```

---

## Common Issues & Quick Fixes

### Database Issues

#### Connection Pool Exhausted

**Symptoms**: `SQLSTATE[HY000] [2002] Connection refused` or `too many connections`

```bash
# Check connections
docker exec linkflow-postgres psql -U linkflow -c "SELECT count(*) FROM pg_stat_activity;"

# Kill idle connections
docker exec linkflow-postgres psql -U linkflow -c "
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE state = 'idle'
AND query_start < now() - interval '10 minutes';"

# Restart API (clears connection pool)
docker-compose restart api
```

#### Database Disk Full

**Symptoms**: Write operations failing

```bash
# Check disk usage
docker exec linkflow-postgres df -h /var/lib/postgresql/data

# Vacuum and analyze
docker exec linkflow-postgres vacuumdb -U linkflow -d linkflow --analyze

# Emergency: Clear old data (if applicable)
docker exec linkflow-postgres psql -U linkflow -c "
DELETE FROM workflow_executions WHERE completed_at < now() - interval '90 days';"
```

### Redis Issues

#### Memory Full

**Symptoms**: `OOM command not allowed` errors

```bash
# Check memory
docker exec linkflow-redis redis-cli INFO memory | grep used_memory_human

# Flush cache (if safe)
docker exec linkflow-redis redis-cli FLUSHDB

# Restart Redis
docker-compose restart redis
```

#### Connection Issues

```bash
# Test connection
docker exec linkflow-redis redis-cli ping

# Check client list
docker exec linkflow-redis redis-cli CLIENT LIST | head -20
```

### API Issues

#### High Response Times

```bash
# Check PHP-FPM status
docker exec linkflow-api php-fpm-status

# Check Laravel queue backlog
docker exec linkflow-api php artisan queue:monitor redis --max=1000

# Clear and warm cache
docker exec linkflow-api php artisan cache:clear
docker exec linkflow-api php artisan config:cache
docker exec linkflow-api php artisan route:cache
```

#### 500 Errors

```bash
# Check Laravel logs
docker exec linkflow-api tail -100 storage/logs/laravel.log

# Check PHP errors
docker logs linkflow-api --tail 100

# Verify configuration
docker exec linkflow-api php artisan config:show
```

### Engine Issues

#### Workflow Execution Stalled

```bash
# Check matching service queue
curl http://localhost:8082/health | jq

# Check worker status
docker logs linkflow-worker --tail 50

# Restart worker pool
docker-compose restart worker
```

#### gRPC Connection Failures

```bash
# Test gRPC connectivity
grpcurl -plaintext localhost:9090 list

# Check service dependencies
docker-compose ps | grep -E "(frontend|history|matching)"

# Restart affected services
docker-compose restart frontend history matching
```

### Container Issues

#### Out of Memory (OOMKilled)

```bash
# Check container stats
docker stats --no-stream

# Check OOM events
docker inspect linkflow-api | jq '.[0].State.OOMKilled'

# Increase memory limits in docker-compose.yml
# deploy:
#   resources:
#     limits:
#       memory: 1G
```

#### Container Restart Loop

```bash
# Check exit code
docker inspect linkflow-api | jq '.[0].State.ExitCode'

# View container logs
docker logs linkflow-api --tail 100

# Check health check failures
docker inspect --format='{{json .State.Health}}' linkflow-api | jq
```

---

## Rollback Procedures

### Docker Compose Rollback

```bash
# List available images
docker images | grep linkflow

# Roll back to previous image
docker-compose down
docker-compose pull  # Skip if using local images
export IMAGE_TAG=previous-version
docker-compose up -d

# Or rebuild from specific commit
git checkout <previous-commit>
docker-compose build
docker-compose up -d
```

### Kubernetes Rollback

```bash
# View rollout history
kubectl rollout history deployment/linkflow-api -n linkflow

# Rollback to previous version
kubectl rollout undo deployment/linkflow-api -n linkflow

# Rollback to specific revision
kubectl rollout undo deployment/linkflow-api -n linkflow --to-revision=3

# Monitor rollback
kubectl rollout status deployment/linkflow-api -n linkflow
```

### Database Rollback

```bash
# Roll back last migration
docker exec linkflow-api php artisan migrate:rollback

# Roll back specific number of migrations
docker exec linkflow-api php artisan migrate:rollback --step=3

# Restore from backup (emergency)
docker exec linkflow-postgres pg_restore -U linkflow -d linkflow /backups/latest.dump
```

### Feature Flag Rollback

If using feature flags, disable the problematic feature:

```bash
# Laravel
docker exec linkflow-api php artisan feature:disable problematic-feature

# Or update environment
echo "FEATURE_X_ENABLED=false" >> .env
docker-compose restart api
```

---

## Post-Incident Checklist

- [ ] Service fully restored and verified
- [ ] Stakeholders notified of resolution
- [ ] Incident ticket created with full timeline
- [ ] Monitoring/alerts verified working
- [ ] Post-mortem scheduled (SEV1/SEV2)
- [ ] Temporary workarounds documented
- [ ] Follow-up tasks created for permanent fixes

---

## Communication Templates

### Initial Incident Notice

```
🚨 INCIDENT: [Service Name] - [Brief Description]

Severity: SEV[X]
Impact: [What users/services are affected]
Status: Investigating
Lead: @[Your Name]

We are aware of the issue and actively investigating.
Next update in 15 minutes.
```

### Status Update

```
📍 UPDATE: [Service Name] Incident

Status: [Investigating/Identified/Mitigating/Resolved]
Impact: [Current impact level]
Actions: [What's being done]

[Details of progress]

Next update in [X] minutes.
```

### Resolution Notice

```
✅ RESOLVED: [Service Name] Incident

Duration: [Start time] - [End time] ([X] minutes)
Root Cause: [Brief explanation]
Resolution: [What fixed it]

Service is fully restored. Post-mortem will follow.
```

---

## Emergency Contacts

| Role | Name | Contact |
|------|------|---------|
| Primary On-call | Rotates | PagerDuty |
| Database Admin | TBD | Slack #dba |
| Infrastructure | TBD | Slack #infra |
| Security | TBD | security@company.com |
