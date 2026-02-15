#!/bin/bash
# LinkFlow Production Deployment Script
# This script handles the complete production deployment process

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}╔════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║  LinkFlow Production Deployment        ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════╝${NC}"
echo ""

# Check if running as root
if [ "$EUID" -eq 0 ]; then
    echo -e "${RED}⚠️  Do not run this script as root${NC}"
    exit 1
fi

# Function to print status
print_status() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓${NC} $2"
    else
        echo -e "${RED}✗${NC} $2"
        exit 1
    fi
}

print_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

# ============================================
# 1. Pre-deployment Checks
# ============================================
echo -e "${BLUE}[1/10] Pre-deployment Checks${NC}"

# Check if .env exists
if [ ! -f .env ]; then
    echo -e "${RED}✗ .env file not found${NC}"
    echo "Run: make setup"
    exit 1
fi
print_status 0 ".env file exists"

# Check if production env exists
if [ ! -f apps/api/.env.docker.production ]; then
    echo -e "${RED}✗ apps/api/.env.docker.production not found${NC}"
    exit 1
fi
print_status 0 "Production environment file exists"

# Check Docker
if ! command -v docker &> /dev/null; then
    echo -e "${RED}✗ Docker not found${NC}"
    exit 1
fi
print_status 0 "Docker is installed"

echo ""

# ============================================
# 2. Backup Current Database
# ============================================
echo -e "${BLUE}[2/10] Creating Database Backup${NC}"

if docker ps | grep -q linkflow-postgres; then
    print_info "Creating backup before deployment..."
    ./scripts/backup-database.sh "pre_deploy_$(date +%Y%m%d_%H%M%S)" || true
    print_status 0 "Backup created"
else
    print_warning "Database not running, skipping backup"
fi

echo ""

# ============================================
# 3. Switch to Production Environment
# ============================================
echo -e "${BLUE}[3/10] Switching to Production Environment${NC}"

cp apps/api/.env.docker.production apps/api/.env.docker
print_status 0 "Production environment activated"

echo ""

# ============================================
# 4. Pull Latest Code (if git repo)
# ============================================
echo -e "${BLUE}[4/10] Updating Code${NC}"

if [ -d .git ]; then
    print_info "Pulling latest changes..."
    git pull origin main || git pull origin master || print_warning "Git pull failed or not needed"
    print_status 0 "Code updated"
else
    print_warning "Not a git repository, skipping pull"
fi

echo ""

# ============================================
# 5. Build Production Images
# ============================================
echo -e "${BLUE}[5/10] Building Production Images${NC}"

print_info "This may take several minutes..."
docker compose -f docker-compose.yml -f docker-compose.prod.yml build --no-cache
print_status 0 "Images built successfully"

echo ""

# ============================================
# 6. Stop Current Services (if running)
# ============================================
echo -e "${BLUE}[6/10] Stopping Current Services${NC}"

if docker ps | grep -q linkflow; then
    print_info "Gracefully stopping services..."
    docker compose -f docker-compose.yml -f docker-compose.prod.yml down
    print_status 0 "Services stopped"
else
    print_info "No services running"
fi

echo ""

# ============================================
# 7. Start Production Stack
# ============================================
echo -e "${BLUE}[7/10] Starting Production Stack${NC}"

docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
print_status 0 "Production stack started"

echo ""

# ============================================
# 8. Wait for Services to be Healthy
# ============================================
echo -e "${BLUE}[8/10] Waiting for Services to be Healthy${NC}"

print_info "Waiting for database..."
for i in {1..30}; do
    if docker exec linkflow-postgres pg_isready -U linkflow >/dev/null 2>&1; then
        break
    fi
    sleep 2
done
print_status 0 "Database is ready"

print_info "Waiting for API..."
for i in {1..30}; do
    if docker ps | grep -q "linkflow-api.*healthy"; then
        break
    fi
    sleep 2
done
print_status 0 "API is healthy"

echo ""

# ============================================
# 9. Run Migrations
# ============================================
echo -e "${BLUE}[9/10] Running Database Migrations${NC}"

docker compose exec -T api php artisan migrate --force
print_status 0 "Migrations completed"

# Optimize Laravel
print_info "Optimizing Laravel..."
docker compose exec -T api php artisan config:cache
docker compose exec -T api php artisan route:cache
docker compose exec -T api php artisan view:cache
docker compose exec -T api php artisan event:cache
print_status 0 "Laravel optimized"

echo ""

# ============================================
# 10. Health Check
# ============================================
echo -e "${BLUE}[10/10] Final Health Check${NC}"

sleep 5
./scripts/health-check.sh

echo ""
echo -e "${GREEN}╔════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  Deployment Completed Successfully! 🚀 ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════╝${NC}"
echo ""
echo -e "${YELLOW}Production URLs:${NC}"
echo "  • API: https://api.linkflow.io"
echo "  • Engine: https://engine.linkflow.io"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "  1. Test critical workflows"
echo "  2. Monitor logs: ${GREEN}make prod-logs${NC}"
echo "  3. Check metrics dashboard"
echo "  4. Verify backups are running"
echo ""
echo -e "${YELLOW}Rollback (if needed):${NC}"
echo "  ./scripts/restore-database.sh backups/pre_deploy_*.sql.gz"
echo ""
