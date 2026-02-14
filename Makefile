# LinkFlow - Operations Makefile
# Easy commands for development and deployment

.PHONY: help setup up down restart logs ps migrate clean build

# Default
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# ============================================
# Setup
# ============================================
setup: ## First-time setup (copy env files, generate secrets)
	@echo "📋 Setting up LinkFlow..."
	@[ -f .env ] || (cp .env.example .env && echo "✅ Created .env" || echo "⚠️  .env.example not found")
	@[ -f apps/api/.env.docker ] || (cp apps/api/.env.example apps/api/.env.docker 2>/dev/null && echo "✅ Created apps/api/.env.docker" || echo "⚠️  Copy apps/api/.env.example manually")
	@echo ""
	@echo "🔑 Generate secrets with:"
	@echo "  openssl rand -base64 24   # For POSTGRES_PASSWORD, REDIS_PASSWORD"
	@echo "  openssl rand -base64 32   # For LINKFLOW_SECRET, JWT_SECRET"
	@echo ""
	@echo "📝 Fill in .env before starting!"

# ============================================
# Full Stack
# ============================================
up: ## Start everything (infra + engine + api)
	docker compose up -d

down: ## Stop everything (keeps data)
	docker compose down

restart: ## Restart everything
	docker compose restart

logs: ## Tail all logs
	docker compose logs -f

ps: ## Show status of all services
	docker compose ps

# ============================================
# Individual Stacks
# ============================================
infra-up: ## Start only infrastructure (Postgres + Redis)
	docker compose -f infra/docker-compose.yml up -d

api-up: ## Start only API (requires infra running)
	docker compose -f apps/api/docker-compose.yml up -d

engine-up: ## Start only Engine (requires infra running)
	docker compose -f apps/engine/docker-compose.yml up -d

# ============================================
# Production
# ============================================
prod-up: ## Start everything with Nginx (production)
	docker compose --profile production up -d

build-prod: ## Build production images
	docker compose -f apps/api/docker-compose.yml build --build-arg target=production
	docker compose -f apps/engine/docker-compose.yml build

# ============================================
# Database
# ============================================
migrate: ## Run all migrations (Laravel + Go Engine)
	docker compose exec api php artisan migrate --force
	docker compose --profile migrate up migrate

migrate-fresh: ## Reset database and re-migrate (DESTRUCTIVE)
	docker compose exec api php artisan migrate:fresh --force --seed

seed: ## Seed the database
	docker compose exec api php artisan db:seed --force

# ============================================
# Scaling
# ============================================
scale-workers: ## Scale Go workers (usage: make scale-workers N=3)
	docker compose up -d --scale worker=$(N)

scale-queue: ## Scale Laravel queue workers (usage: make scale-queue N=2)
	docker compose up -d --scale queue=$(N)

# ============================================
# Maintenance
# ============================================
clean: ## Stop everything and remove volumes (DESTRUCTIVE)
	docker compose down -v --remove-orphans

shell-api: ## Open shell in API container
	docker compose exec api bash

shell-db: ## Open PostgreSQL shell
	docker compose exec postgres psql -U $${POSTGRES_USER:-linkflow} -d $${POSTGRES_DB:-linkflow}

shell-redis: ## Open Redis CLI
	docker compose exec redis redis-cli -a $${REDIS_PASSWORD}

api-cache: ## Clear and rebuild Laravel caches
	docker compose exec api php artisan optimize:clear
	docker compose exec api php artisan optimize

health: ## Check health of all services
	@echo "🏥 Checking service health..."
	@docker compose ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}"
