# Docker Deployment

LinkFlow is designed to be deployed as a set of Docker containers. We provide a production-ready `docker-compose.yml` for orchestration.

## Container Images

We maintain official images for all services:

| Service | Image Name |
|---------|------------|
| API | `linkflow/api:latest` |
| Queue Worker | `linkflow/queue:latest` |
| Engine Frontend | `linkflow/engine-frontend:latest` |
| Engine History | `linkflow/engine-history:latest` |
| Engine Matching | `linkflow/engine-matching:latest` |
| Engine Worker | `linkflow/engine-worker:latest` |
| Engine Timer | `linkflow/engine-timer:latest` |

## Production Docker Compose

Create a `docker-compose.prod.yml` file:

```yaml
version: '3.8'

services:
  # --- Data Layer ---
  postgres:
    image: postgres:16-alpine
    volumes:
      - pgdata:/var/lib/postgresql/data
    environment:
      POSTGRES_USER: ${POSTGRES_USER}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}

  redis:
    image: redis:7-alpine
    command: redis-server --appendonly yes
    volumes:
      - redisdata:/data

  # --- Control Plane ---
  api:
    image: linkflow/api:latest
    ports:
      - "8000:8000"
    environment:
      APP_ENV: production
      DB_HOST: postgres
      REDIS_HOST: redis
    depends_on:
      - postgres
      - redis

  worker:
    image: linkflow/queue:latest
    environment:
      APP_ENV: production
      DB_HOST: postgres
      REDIS_HOST: redis

  # --- Execution Plane ---
  engine-frontend:
    image: linkflow/engine-frontend:latest
    ports:
      - "8080:8080"
      - "9090:9090"
    environment:
      JWT_SECRET: ${JWT_SECRET}
      HISTORY_ADDR: engine-history:7234
      MATCHING_ADDR: engine-matching:7235

  engine-history:
    image: linkflow/engine-history:latest
    environment:
      DATABASE_URL: postgres://${POSTGRES_USER}:${POSTGRES_PASSWORD}@postgres:5432/linkflow

  engine-matching:
    image: linkflow/engine-matching:latest
    environment:
      REDIS_URL: redis://redis:6379

  engine-worker:
    image: linkflow/engine-worker:latest
    environment:
      MATCHING_ADDR: engine-matching:7235
      HISTORY_ADDR: engine-history:7234
      LINKFLOW_SECRET: ${LINKFLOW_SECRET}

volumes:
  pgdata:
  redisdata:
```

## Deployment Steps

1.  **Configure Environment**:
    Create a `.env` file with production secrets.
    ```bash
    cp .env.example .env
    # Edit .env with real secrets
    ```

2.  **Pull Images**:
    ```bash
    docker-compose -f docker-compose.prod.yml pull
    ```

3.  **Run Migrations**:
    ```bash
    docker-compose -f docker-compose.prod.yml run --rm api php artisan migrate --force
    # Engine migrations (if separate command exists)
    ```

4.  **Start Services**:
    ```bash
    docker-compose -f docker-compose.prod.yml up -d
    ```

5.  **Verify**:
    Check logs to ensure all services started correctly.
    ```bash
    docker-compose -f docker-compose.prod.yml logs -f
    ```
