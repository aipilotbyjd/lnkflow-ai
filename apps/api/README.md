# LinkFlow API

The Laravel-based API backend for LinkFlow, handling REST endpoints, authentication, and job queuing.

## Architecture

- **Framework**: Laravel 10
- **Database**: PostgreSQL 16
- **Queue/Cache**: Redis 7
- **Authentication**: Laravel Passport (OAuth2)
- **Job Processing**: Redis Queue
- **Scheduler**: Dockerized Cron

## Quick Start (Docker)

### 1. Configure Environment
Copy the example file and configure secrets:

```bash
cp .env.docker.example .env.docker
# Edit .env.docker to match your infra secrets (POSTGRES_PASSWORD, etc.)
```

### 2. Run via Root Makefile (Recommended)
From the project root:

```bash
make api-up
```

### 3. Run Manually
Start the infrastructure first, then:

```bash
docker-compose up -d
```

## Development

### Running Migrations
```bash
docker-compose exec api php artisan migrate
```

### Running Tests
The CI/CD pipeline runs tests automatically. To run locally:

```bash
docker-compose exec api ./vendor/bin/pest
```

### Queue Workers
The `queue` service runs independent workers. To scale them:

```bash
docker-compose up -d --scale queue=3
```

## Production

The Dockerfile includes a `production` target that enables:
- OPcache with JIT
- `config:cache`, `route:cache`, `view:cache`
- `composer install --no-dev`
- Security hardening (hidden PHP version)

To build/run for production:

```bash
# Via Makefile
make build-prod
make prod-up
```
