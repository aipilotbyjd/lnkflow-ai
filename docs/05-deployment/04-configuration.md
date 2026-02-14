# Configuration Reference

This document lists all environment variables used to configure LinkFlow services.

## Common

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_ENV` | Environment (`local`, `production`) | `local` |
| `LOG_LEVEL` | Logging verbosity (`debug`, `info`) | `info` |

## Infrastructure

| Variable | Description | Required |
|----------|-------------|----------|
| `DB_HOST` | Postgres Host | Yes |
| `DB_PORT` | Postgres Port | No (5432) |
| `DB_DATABASE` | Database Name | Yes |
| `DB_USERNAME` | Database User | Yes |
| `DB_PASSWORD` | Database Password | Yes |
| `REDIS_HOST` | Redis Host | Yes |
| `REDIS_PASSWORD`| Redis Password | No |

## API (Laravel)

| Variable | Description | Required |
|----------|-------------|----------|
| `APP_KEY` | Laravel App Key (base64) | Yes |
| `JWT_SECRET` | Secret for signing tokens | Yes |
| `LINKFLOW_SECRET`| Shared secret for Engine callbacks | Yes |
| `QUEUE_CONNECTION`| Queue driver | `redis` |

## Engine (Go)

| Variable | Description | Required |
|----------|-------------|----------|
| `HISTORY_ADDR` | Address of History Service | Yes |
| `MATCHING_ADDR`| Address of Matching Service | Yes |
| `FRONTEND_ADDR`| Address of Frontend Service | Yes |
| `NUM_WORKERS` | Worker concurrency | No (4) |
