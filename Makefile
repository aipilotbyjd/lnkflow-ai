# LinkFlow - Operations Makefile
# Layered Docker Compose: base (docker-compose.yml) + environment override
#
# Development: docker compose up          (auto-loads docker-compose.override.yml)
# Production:  docker compose -f ... -f docker-compose.prod.yml up
# CI/Testing:  docker compose -f ... -f docker-compose.test.yml up

.PHONY: help setup dev dev-build dev-down dev-full prod prod-build prod-down prod-full \
        test-up test-down logs ps migrate migrate-fresh seed clean health \
        shell-api shell-db shell-redis api-cache \
        prod-scale-api prod-scale-workers prod-scale-queue \
        prod-setup prod-health backup restore optimize security-check

COMPOSE_PROD := docker compose -f docker-compose.yml -f docker-compose.prod.yml
COMPOSE_TEST := docker compose -f docker-compose.yml -f docker-compose.test.yml

# Default
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-25s\033[0m %s\n", $$1, $$2}'

# ============================================
# Setup
# ============================================
setup: ## First-time setup (copy env files, generate secrets)
	@echo "📋 Setting up LinkFlow..."
	@[ -f .env ] || (cp .env.example .env && echo "✅ Created .env")
	@[ -f apps/api/.env.docker ] || (cp apps/api/.env.docker.example apps/api/.env.docker && echo "✅ Created apps/api/.env.docker")
	@echo ""
	@echo "🔑 Generate secrets:"
	@echo "  openssl rand -base64 24   # POSTGRES_PASSWORD, REDIS_PASSWORD"
	@echo "  openssl rand -base64 32   # LINKFLOW_SECRET, JWT_SECRET"
	@echo "  docker compose run --rm api php artisan key:generate --show   # APP_KEY"
	@echo ""
	@echo "📝 Fill in .env before running 'make dev'!"

# ============================================
# Development (auto-loads docker-compose.override.yml)
# ============================================
dev: ## Start dev stack (auto-loads override.yml)
	docker compose up -d

dev-build: ## Build and start dev stack
	docker compose up -d --build

dev-down: ## Stop dev stack
	docker compose down

dev-full: ## Start dev with scheduler + edge services
	docker compose --profile scheduler --profile edge up -d

dev-logs: ## Tail dev logs
	docker compose logs -f

# ============================================
# Production (explicit -f, skips override.yml)
# ============================================
prod: ## Start production stack
	$(COMPOSE_PROD) up -d

prod-build: ## Build and start production stack
	$(COMPOSE_PROD) up -d --build

prod-down: ## Stop production stack
	$(COMPOSE_PROD) down

prod-full: ## Start production with all optional services
	$(COMPOSE_PROD) --profile scheduler --profile edge --profile production up -d

prod-logs: ## Tail production logs
	$(COMPOSE_PROD) logs -f

# ============================================
# Scaling (Production)
# ============================================
prod-scale-api: ## Scale API replicas (usage: make prod-scale-api N=3)
	$(COMPOSE_PROD) up -d --scale api=$(N) --no-recreate

prod-scale-workers: ## Scale Go workers (usage: make prod-scale-workers N=5)
	$(COMPOSE_PROD) up -d --scale worker=$(N) --no-recreate

prod-scale-queue: ## Scale queue workers (usage: make prod-scale-queue N=3)
	$(COMPOSE_PROD) up -d --scale queue=$(N) --no-recreate

# ============================================
# CI / Testing
# ============================================
test-up: ## Start test infrastructure (ephemeral)
	$(COMPOSE_TEST) up -d postgres redis

test-down: ## Stop test infrastructure and remove volumes
	$(COMPOSE_TEST) down -v

test-stack: ## Start full test stack
	$(COMPOSE_TEST) up -d

# ============================================
# Database
# ============================================
migrate: ## Run all migrations
	docker compose exec api php artisan migrate --force
	docker compose --profile migrate up migrate

migrate-fresh: ## Reset database and re-migrate (DESTRUCTIVE)
	docker compose exec api php artisan migrate:fresh --force --seed

seed: ## Seed the database
	docker compose exec api php artisan db:seed --force

# ============================================
# Status & Logs
# ============================================
ps: ## Show status of all services
	docker compose ps

logs: ## Tail all logs (dev)
	docker compose logs -f

health: ## Check health of all services
	@echo "🏥 Checking service health..."
	@docker compose ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}"

# ============================================
# Shells
# ============================================
shell-api: ## Open shell in API container
	docker compose exec api bash

shell-db: ## Open PostgreSQL shell
	docker compose exec postgres psql -U $${POSTGRES_USER:-linkflow} -d $${POSTGRES_DB:-linkflow}

shell-redis: ## Open Redis CLI
	docker compose exec -e REDISCLI_AUTH=$${REDIS_PASSWORD} redis redis-cli

# ============================================
# Maintenance
# ============================================
api-cache: ## Clear and rebuild Laravel caches
	docker compose exec api php artisan optimize:clear
	docker compose exec api php artisan optimize

clean: ## Stop everything and remove volumes (DESTRUCTIVE)
	docker compose down -v --remove-orphans

restart: ## Restart all services
	docker compose restart

# ============================================
# Production Operations
# ============================================
prod-setup: ## Run production setup script
	@./scripts/production-setup.sh

prod-health: ## Comprehensive production health check
	@./scripts/health-check.sh

backup: ## Backup database
	@./scripts/backup-database.sh

restore: ## Restore database (usage: make restore FILE=backups/backup.sql.gz)
	@./scripts/restore-database.sh $(FILE)

optimize: ## Optimize Laravel for production
	docker compose exec api php artisan config:cache
	docker compose exec api php artisan route:cache
	docker compose exec api php artisan view:cache
	docker compose exec api php artisan event:cache
	@echo "✅ Laravel optimized for production"

security-check: ## Run security checks
	@echo "🔒 Running security checks..."
	@echo ""
	@echo "Checking secrets strength..."
	@grep -E "^(POSTGRES_PASSWORD|REDIS_PASSWORD|LINKFLOW_SECRET|JWT_SECRET|APP_KEY)=" .env | while read line; do \
		key=$$(echo $$line | cut -d= -f1); \
		val=$$(echo $$line | cut -d= -f2); \
		len=$${#val}; \
		if [ $$len -lt 32 ]; then \
			echo "⚠️  $$key is too short ($$len chars, min 32)"; \
		else \
			echo "✅ $$key is strong ($$len chars)"; \
		fi; \
	done
	@echo ""
	@echo "Checking exposed ports..."
	@if docker compose ps --format json | grep -q '"PublishedPort":5432'; then \
		echo "⚠️  PostgreSQL port 5432 is exposed"; \
	else \
		echo "✅ PostgreSQL port is not exposed"; \
	fi
	@if docker compose ps --format json | grep -q '"PublishedPort":6379'; then \
		echo "⚠️  Redis port 6379 is exposed"; \
	else \
		echo "✅ Redis port is not exposed"; \
	fi
	@echo ""
	@echo "Checking Laravel debug mode..."
	@if grep -q "APP_DEBUG=false" apps/api/.env.docker; then \
		echo "✅ APP_DEBUG is false"; \
	else \
		echo "⚠️  APP_DEBUG should be false in production"; \
	fi
