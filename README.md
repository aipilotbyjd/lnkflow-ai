<p align="center"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="LinkFlow Logo"></p>

<p align="center">
<a href="https://github.com/aipilotbyjd/lnkflow-ai/actions"><img src="https://github.com/aipilotbyjd/lnkflow-ai/workflows/CI/CD%20Pipeline/badge.svg" alt="Build Status"></a>
<a href="https://github.com/aipilotbyjd/lnkflow-ai/security/code-scanning"><img src="https://github.com/aipilotbyjd/lnkflow-ai/workflows/CodeQL%20Security%20Analysis/badge.svg" alt="CodeQL Status"></a>
<a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License: MIT"></a>
</p>

# LinkFlow AI - Workflow Automation Platform

LinkFlow is a scalable workflow automation engine combining a Laravel API backend with a high-performance Go microservices execution engine.

## 🏗️ Architecture

- **[Infrastructure](./infra/README.md)**: PostgreSQL 16, Redis 7 (Queue/Cache), Nginx (TLS/Proxy)
- **[API](./apps/api/README.md)**: Laravel 10 backend for REST endpoints, auth, and logic
- **[Engine](./apps/engine/README.md)**: Go microservices for distributed workflow execution, scheduling, and state management

## 🚀 Quick Start

### Development
```bash
# 1. Setup environment
make setup

# 2. Start development stack
make dev

# 3. Run migrations and seed data
make migrate
make seed

# Access API at http://localhost:8000
# Access Engine at http://localhost:8080
```

### Production
```bash
# 1. Validate production setup
make prod-setup

# 2. Deploy to production
./scripts/deploy-production.sh

# 3. Verify health
make prod-health
```

📖 **See [Quick Start Guide](docs/05-deployment/11-quick-start-guide.md) for detailed instructions**

## 🛠️ Operations

### Development Commands
| Task | Command |
|------|---------|
| Start dev stack | `make dev` |
| View logs | `make logs` |
| Check status | `make ps` |
| Run migrations | `make migrate` |
| API shell | `make shell-api` |
| Database shell | `make shell-db` |

### Production Commands
| Task | Command |
|------|---------|
| Deploy | `./scripts/deploy-production.sh` |
| Health check | `make prod-health` |
| Backup database | `make backup` |
| Scale API | `make prod-scale-api N=4` |
| Scale workers | `make prod-scale-workers N=8` |
| Optimize Laravel | `make optimize` |

📖 **See [Operations Guide](docs/06-operations/) for complete reference**

## 📚 Documentation

### Getting Started
- [Introduction](docs/01-getting-started/01-introduction.md)
- [Installation](docs/01-getting-started/02-installation.md)
- [Quick Start](docs/01-getting-started/03-quickstart.md)
- [First Workflow](docs/01-getting-started/04-first-workflow.md)
- [Core Concepts](docs/01-getting-started/05-concepts.md)

### Deployment
- [Requirements](docs/05-deployment/01-requirements.md)
- [Docker Deployment](docs/05-deployment/02-docker.md)
- [Production Guide](docs/05-deployment/09-production-guide.md)
- [Production Checklist](docs/05-deployment/10-production-ready-checklist.md)
- [Quick Start Guide](docs/05-deployment/11-quick-start-guide.md)

### Operations
- [Monitoring](docs/06-operations/monitoring.md)
- [Backup & Restore](docs/06-operations/backup-restore.md)
- [Troubleshooting](docs/06-operations/troubleshooting.md)
- [Incident Response](docs/06-operations/incident-response.md)

### Development
- [Setup](docs/07-development/setup.md)
- [Testing](docs/07-development/testing.md)
- [Debugging](docs/07-development/debugging.md)
- [Code Style](docs/07-development/code-style.md)

📖 **Full documentation available in [docs/](docs/)**

## 📦 Deployment

LinkFlow is production-ready with automated deployment, monitoring, and scaling.

### Production Features
- ✅ Automated deployment scripts
- ✅ SSL/TLS with Nginx reverse proxy
- ✅ Health monitoring and alerting
- ✅ Database backup and restoration
- ✅ Horizontal scaling support
- ✅ Security hardening
- ✅ Performance optimization

### Deployment Options
- **Docker Compose**: Single-server deployment (recommended for < 10K workflows/day)
- **Kubernetes**: Multi-node with auto-scaling (Helm charts available)
- **Cloud Platforms**: AWS ECS, Google Cloud Run, Azure Container Instances

📖 **See [Production Guide](docs/05-deployment/09-production-guide.md) for complete deployment instructions**

### CI/CD Pipeline
- **Lint/Static Analysis**: Pint (PHP), golangci-lint (Go)
- **Tests**: Pest (PHP), go test -race (Go)
- **Security**: Trivy (FS scan), Govulncheck (Go), Composer Audit (PHP)
- **Build**: Multi-stage Docker builds

## 🤝 Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md) for details.

## 🔒 Security

See [SECURITY.md](./SECURITY.md) for reporting vulnerabilities.

## 📄 License

The LinkFlow framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
