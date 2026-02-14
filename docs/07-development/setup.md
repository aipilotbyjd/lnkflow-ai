# Developer Setup Guide

This guide helps you set up a local development environment to contribute to LinkFlow.

## Prerequisites

- **Go** 1.24+
- **PHP** 8.4+
- **Composer** 2.x
- **Node.js** 20+ (for frontend)
- **Docker** & **Docker Compose**
- **Make** (optional)

## Repository Setup

1.  **Clone the Repo**:
    ```bash
    git clone https://github.com/your-org/lnkflow.git
    cd lnkflow
    ```

2.  **Install Dependencies**:
    ```bash
    # PHP
    cd apps/api
    composer install

    # Go
    cd ../../apps/engine
    go mod download
    ```

## Running Locally (Hybrid Mode)

For the best development experience, run infrastructure in Docker and services locally.

1.  **Start Infrastructure**:
    ```bash
    docker-compose -f infra/docker-compose.yml up -d
    ```

2.  **Run API (Terminal 1)**:
    ```bash
    cd apps/api
    cp .env.example .env
    php artisan key:generate
    php artisan migrate
    php artisan serve
    ```

3.  **Run Engine Services (Terminal 2)**:
    We recommend using Air for hot reloading Go services.
    ```bash
    cd apps/engine
    # Install Air if needed: go install github.com/air-verse/air@latest
    air
    ```

## Code Style

### PHP (Laravel)
We use **Laravel Pint** for code formatting.
```bash
cd apps/api
vendor/bin/pint
```

### Go
We use **golangci-lint** for linting.
```bash
cd apps/engine
golangci-lint run
```

## Testing

### Running API Tests
```bash
cd apps/api
php artisan test
```

### Running Engine Tests
```bash
cd apps/engine
go test ./...
```

## Committing Changes

We use **Conventional Commits**. Please format your commit messages as follows:

- `feat(api): add new endpoint`
- `fix(engine): resolve race condition in worker`
- `docs: update installation guide`
- `chore: update dependencies`
