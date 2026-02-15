# 🚀 LinkFlow Production - Quick Start

## One-Command Deployment

```bash
# Validate and deploy to production
make prod-setup && ./scripts/deploy-production.sh
```

## Essential Commands

| Task | Command |
|------|---------|
| **Deploy** | `./scripts/deploy-production.sh` |
| **Health Check** | `make prod-health` |
| **View Logs** | `make prod-logs` |
| **Backup DB** | `make backup` |
| **Restore DB** | `make restore FILE=backups/file.sql.gz` |
| **Scale API** | `make prod-scale-api N=4` |
| **Scale Workers** | `make prod-scale-workers N=8` |
| **Optimize** | `make optimize` |
| **Security Check** | `make security-check` |
| **Restart** | `make restart` |

## Production URLs

- **API**: https://api.linkflow.io
- **Engine**: https://engine.linkflow.io
- **Health**: http://your-server/health

## Emergency Procedures

### Service Down
```bash
make prod-health          # Identify issue
make prod-logs           # Check logs
make restart             # Restart services
```

### Database Issue
```bash
docker exec linkflow-postgres pg_isready -U linkflow
make restore FILE=backups/latest.sql.gz
```

### High Load
```bash
make prod-scale-api N=4
make prod-scale-workers N=8
```

### Rollback
```bash
git checkout previous-commit
make prod-build
make migrate
```

## Daily Operations

**Morning:**
```bash
make prod-health
```

**After Deploy:**
```bash
make backup
make prod-health
```

**Before Weekend:**
```bash
make backup
make security-check
```

## Support

- 📖 Full Guide: `PRODUCTION.md`
- 📋 Checklist: `PRODUCTION-READY.md`
- 📚 Docs: `docs/05-deployment/`
