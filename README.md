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

## 🚀 Quick Start (Production/Dev)

We use a single `Makefile` to manage the entire stack.

### 1. Setup Environment
```bash
make setup
# This copies example env files (root, api, infra)
# Then edit .env to set secure passwords (POSTGRES_PASSWORD, REDIS_PASSWORD, LINKFLOW_SECRET)
```

### 2. Start Full Stack
```bash
make up
# Access API at http://localhost:8000
# Access Engine Dashboard (if enabled) at http://localhost:8080
```

### 3. Start Individual Components
To work on specific parts:

```bash
make infra-up       # Start Postgres + Redis
make api-up         # Start API + Queue workeres
make engine-up      # Start Go microservices
```

### 4. Stop
```bash
make down
```

## 🛠️ Operations

| Task | Command | Description |
|---|---|---|
| **View logs** | `make logs` | Tail logs of all services |
| **Status** | `make ps` | Check health of all containers |
| **Migrations** | `make migrate` | Run Laravel + Go migrations |
| **In-container shell** | `make shell-api` | Bash into API container |
| **Database shell** | `make shell-db` | `psql` shell |
| **Redis CLI** | `make shell-redis` | `redis-cli` shell |
| **Scale Workers** | `make scale-workers N=5` | Scale engine workers to 5 replicas |
| **Reset DB** | `make migrate-fresh` | Delete data and re-seed (⚠️ Destructive) |

## 📦 Deployment

LinkFlow is built to be deployed anywhere (AWS ECS, Kubernetes, DigitalOcean, bare metal).

### Production Mode (Nginx + SSL)
1. Add SSL certs to `infra/nginx/ssl`.
2. Run:
```bash
make prod-up
```

### CI/CD Pipeline
- **Lint/Static Analysis**: Pint (PHP), golangci-lint (Go)
- **Tests**: Pest (PHP), go test -race (Go)
- **Security**: Trivy (FS scan), Govulncheck (Go), Composer Audit (PHP)
- **Build**: Multi-stage Docker builds push to GHCR

## 🤝 Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md) for details.

## 🔒 Security

See [SECURITY.md](./SECURITY.md) for reporting vulnerabilities.

## 📄 License

The LinkFlow framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
