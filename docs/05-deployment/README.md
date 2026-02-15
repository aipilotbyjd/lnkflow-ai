# Deployment Documentation

Complete guide to deploying LinkFlow in development, staging, and production environments.

## 📚 Documentation Index

### Getting Started
1. [Requirements](01-requirements.md) - System requirements and prerequisites
2. [Docker Deployment](02-docker.md) - Complete Docker Compose guide
3. [Kubernetes](03-kubernetes.md) - Kubernetes deployment with Helm
4. [Configuration](04-configuration.md) - Environment variables and settings
5. [Scaling](05-scaling.md) - Horizontal and vertical scaling strategies

### Production Deployment
6. [Production Checklist](06-production-checklist.md) - Pre-deployment checklist
7. [Multi-Provider HA Plan](07-multi-provider-ha-plan.md) - High availability architecture
8. [Multi-Provider Operations](08-multi-provider-operations.md) - Multi-cloud operations
9. [Production Guide](09-production-guide.md) - **Complete production deployment guide**
10. [Production Ready Checklist](10-production-ready-checklist.md) - **Deployment validation**
11. [Quick Start Guide](11-quick-start-guide.md) - **Quick reference for deployment**

## 🚀 Quick Start

### Development
```bash
make dev
make migrate
make seed
```

### Production
```bash
# 1. Validate setup
make prod-setup

# 2. Deploy
./scripts/deploy-production.sh

# 3. Verify
make prod-health
```

## 📖 Recommended Reading Order

### For First-Time Deployment
1. Start with [Requirements](01-requirements.md)
2. Follow [Docker Deployment](02-docker.md)
3. Review [Production Checklist](06-production-checklist.md)
4. Use [Quick Start Guide](11-quick-start-guide.md)

### For Production Deployment
1. Read [Production Guide](09-production-guide.md) thoroughly
2. Complete [Production Ready Checklist](10-production-ready-checklist.md)
3. Execute deployment with [Quick Start Guide](11-quick-start-guide.md)
4. Set up monitoring from [Operations Guide](../06-operations/)

### For Scaling
1. Review [Scaling](05-scaling.md)
2. Consider [Multi-Provider HA Plan](07-multi-provider-ha-plan.md)
3. Implement [Multi-Provider Operations](08-multi-provider-operations.md)

## 🎯 Deployment Paths

### Single Server (< 10K workflows/day)
- Use Docker Compose
- Follow [Docker Deployment](02-docker.md)
- See [Production Guide](09-production-guide.md)

### Multi-Server (10K-100K workflows/day)
- Use Docker Swarm or Kubernetes
- Follow [Kubernetes](03-kubernetes.md)
- Implement [Scaling](05-scaling.md)

### Enterprise (> 100K workflows/day)
- Use Kubernetes with auto-scaling
- Implement [Multi-Provider HA](07-multi-provider-ha-plan.md)
- Follow [Multi-Provider Operations](08-multi-provider-operations.md)

## 🔧 Available Scripts

All deployment scripts are located in `scripts/`:

| Script | Purpose |
|--------|---------|
| `production-setup.sh` | Validate production environment |
| `deploy-production.sh` | Automated production deployment |
| `health-check.sh` | Comprehensive health monitoring |
| `backup-database.sh` | Database backup |
| `restore-database.sh` | Database restoration |
| `generate-ssl.sh` | Generate self-signed SSL certificates |
| `setup-monitoring.sh` | Configure monitoring and alerts |

## 🛠️ Makefile Commands

### Development
- `make dev` - Start development stack
- `make dev-build` - Build and start
- `make dev-down` - Stop development stack

### Production
- `make prod` - Start production stack
- `make prod-build` - Build and deploy
- `make prod-setup` - Run production setup
- `make prod-health` - Health check
- `make prod-scale-api N=4` - Scale API servers
- `make prod-scale-workers N=8` - Scale workers

### Maintenance
- `make backup` - Create database backup
- `make restore FILE=backup.sql.gz` - Restore database
- `make optimize` - Optimize Laravel
- `make security-check` - Security validation

## 📊 Deployment Checklist

Before deploying to production:

- [ ] All secrets are strong and random
- [ ] SSL certificates are valid
- [ ] Database backups are configured
- [ ] Monitoring and alerting are set up
- [ ] Firewall rules are configured
- [ ] Health checks are passing
- [ ] Documentation is reviewed
- [ ] Team is trained on operations

## 🔐 Security Considerations

- Use strong passwords (32+ characters)
- Enable SSL/TLS for all connections
- Restrict database and Redis ports
- Configure rate limiting
- Enable security headers
- Set up automated backups
- Implement monitoring and alerting

## 📈 Performance Optimization

- Use Redis for caching and sessions
- Enable OPcache for PHP
- Configure connection pooling
- Optimize database queries
- Use CDN for static assets
- Implement horizontal scaling

## 🚨 Troubleshooting

Common issues and solutions:

1. **Services won't start**: Check logs with `make prod-logs`
2. **Database connection errors**: Verify credentials in `.env`
3. **SSL certificate issues**: Check certificate validity
4. **High resource usage**: Scale services or optimize queries
5. **Performance issues**: Review monitoring metrics

See [Troubleshooting Guide](../06-operations/troubleshooting.md) for detailed solutions.

## 📞 Support

- Documentation: [docs/](../)
- GitHub Issues: [Report issues](https://github.com/your-org/lnkflow/issues)
- Operations Guide: [docs/06-operations/](../06-operations/)

## 🎉 Next Steps

After successful deployment:

1. Set up monitoring and alerting
2. Configure automated backups
3. Test disaster recovery procedures
4. Document your specific configuration
5. Train your team on operations
6. Set up CI/CD pipeline
7. Configure log aggregation
8. Implement security scanning

**Ready to deploy? Start with the [Quick Start Guide](11-quick-start-guide.md)!**
