# Installation Guide

This guide covers how to set up LinkFlow for local development and testing.

## Prerequisites

Ensure you have the following installed:

- **Docker** & **Docker Compose** (v2.20+ required for `include` support)
- **Make** (optional, but recommended for using helper commands)
- **Git**

For local development without Docker, you will also need:
- **Go** 1.24+
- **PHP** 8.4+ & **Composer**
- **Node.js** & **NPM** (for frontend assets if applicable)

## Quick Setup (Docker)

The easiest way to get started is using the provided Docker setup.

1.  **Clone the Repository**
    ```bash
    git clone https://github.com/your-org/lnkflow.git
    cd lnkflow
    ```

2.  **Initialize Configuration**
    Run the setup command to install dependencies and configure environment variables.
    ```bash
    make setup
    ```
    *This script copies `.env.example` to `.env` and installs necessary dependencies.*

3.  **Start Services**
    Launch the entire stack (API, Engine, Database, Redis).
    ```bash
    make start
    ```

4.  **Verify Installation**
    Check if all containers are running and healthy.
    ```bash
    make ps
    ```
    You should see services like `linkflow-api`, `linkflow-frontend`, `linkflow-worker`, etc., in `Up` state.

## Access Points

| Service | URL | Credentials (Default) |
|---------|-----|-----------------------|
| **API** | `http://localhost:8000` | - |
| **Engine Frontend** | `http://localhost:8080` | - |
| **PostgreSQL** | `localhost:5432` | `linkflow` / `linkflow` |
| **Redis** | `localhost:6379` | - |

## Manual Setup (Development)

If you prefer running services directly on your host machine:

### 1. Infrastructure
Start the database and cache:
```bash
docker-compose -f infra/docker-compose.yml up -d
```

### 2. API (Laravel)
```bash
cd apps/api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8000
```

### 3. Engine (Go)
```bash
cd apps/engine
cp .env.example .env
go mod download
# Run the monolithic entry point (if available) or individual services
go run cmd/frontend/main.go
# (You will need to run other microservices like history, matching, worker in separate terminals)
```

## Troubleshooting

- **Port Conflicts**: Ensure ports 8000, 8080, 5432, and 6379 are free.
- **Docker Memory**: Allocate at least 4GB of RAM to Docker Desktop.
- **Database Connection**: Check `DB_HOST` in `.env`. Use `localhost` for host-based running, or `linkflow-postgres` for Docker networking.
