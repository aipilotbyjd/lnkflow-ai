# LinkFlow AI Assistant Guide

This file contains instructions for AI assistants working on the LinkFlow codebase.

## Project Overview

**LinkFlow** is a high-performance workflow automation platform with a hybrid architecture:

| Component | Technology | Location | Purpose |
|-----------|------------|----------|---------|
| Control Plane | Laravel 12 (PHP 8.4) | `apps/api/` | REST API, Auth, User Management |
| Execution Plane | Go 1.24 | `apps/engine/` | Workflow Execution (8 microservices) |
| Infrastructure | PostgreSQL 16 + Redis 7 | `infra/` | Data Storage & Caching |

## Quick Commands

```bash
# Start everything
make start

# Stop everything
make stop

# Run all tests
make test

# Format code
make format

# Lint code
make lint
```

### API Commands (Laravel)

```bash
cd apps/api
php artisan test                    # Run tests
vendor/bin/pint                     # Format PHP code
vendor/bin/phpstan analyse          # Static analysis
php artisan migrate                 # Run migrations
php artisan route:list --path=api   # List API routes
```

### Engine Commands (Go)

```bash
cd apps/engine
make test                # Run tests with race detector
make lint                # Run golangci-lint
make build               # Build all services
make proto               # Generate protobuf code
```

## Code Locations

### API (Laravel)

| Feature | Location |
|---------|----------|
| Controllers | `apps/api/app/Http/Controllers/Api/V1/` |
| Models | `apps/api/app/Models/` |
| Routes | `apps/api/routes/api.php` |
| Migrations | `apps/api/database/migrations/` |
| Tests | `apps/api/tests/Feature/` |
| Form Requests | `apps/api/app/Http/Requests/` |
| Resources | `apps/api/app/Http/Resources/` |
| Services | `apps/api/app/Services/` |
| Jobs | `apps/api/app/Jobs/` |

### Engine (Go)

| Feature | Location |
|---------|----------|
| Service Entry Points | `apps/engine/cmd/` |
| Frontend (API Gateway) | `apps/engine/internal/frontend/` |
| History (Event Store) | `apps/engine/internal/history/` |
| Matching (Task Queue) | `apps/engine/internal/matching/` |
| Worker (Execution) | `apps/engine/internal/worker/` |
| Node Executors | `apps/engine/internal/worker/executor/` |
| Timer Service | `apps/engine/internal/timer/` |
| Protobuf Definitions | `apps/engine/api/proto/` |

## Conventions

### Git Commits

Use conventional commits:
- `feat:` - New feature
- `fix:` - Bug fix
- `chore:` - Maintenance
- `docs:` - Documentation
- `refactor:` - Code refactoring
- `test:` - Tests

Example: `feat(api): add workflow duplication endpoint`

### PHP/Laravel

- Use Form Request classes for validation
- Use API Resources for response transformation
- Use `Model::query()` instead of `DB::`
- Run `vendor/bin/pint` before committing
- Create tests for new features

### Go

- Follow standard Go conventions
- Use `context.Context` as first parameter for I/O functions
- Prefer returning errors over panicking
- Group imports: stdlib, external, internal
- Run `make lint` before committing

## Architecture

```
Client -> Laravel API (8000) -> Go Engine Frontend (8080)
                                    |
                    +---------------+---------------+
                    |               |               |
                History         Matching          Timer
                    |               |
                    +-------+-------+
                            |
                         Worker -> Execute Nodes -> Callback to Laravel
```

### Communication

| From | To | Protocol |
|------|-----|----------|
| Client | API | HTTP/REST |
| API | Engine | HTTP + Redis Streams |
| Engine Services | Engine Services | gRPC |
| Engine | API | HTTP Callbacks (HMAC signed) |

## Environment Variables

Critical secrets (must be changed in production):

| Variable | Purpose |
|----------|---------|
| `JWT_SECRET` | JWT token signing (min 32 chars) |
| `LINKFLOW_SECRET` | Engine-to-API callback authentication |
| `POSTGRES_PASSWORD` | Database password |

## Documentation

| Topic | Location |
|-------|----------|
| Getting Started | `docs/01-getting-started/` |
| Architecture | `docs/02-architecture/` |
| Guides | `docs/03-guides/` |
| API Reference | `docs/04-api-reference/` |
| Deployment | `docs/05-deployment/` |
| Operations | `docs/06-operations/` |
| Development | `docs/07-development/` |
| ADRs | `docs/adr/` |

## App-Specific Instructions

For detailed instructions when working in specific areas:
- Laravel API: See `apps/api/AGENTS.md`
- Go Engine: See `apps/engine/AGENTS.md`
