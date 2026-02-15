#!/bin/bash
# LinkFlow Production Setup Script
# This script prepares your application for production deployment

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}╔════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║  LinkFlow Production Setup             ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════╝${NC}"
echo ""

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Function to print status
print_status() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓${NC} $2"
    else
        echo -e "${RED}✗${NC} $2"
    fi
}

# Function to print warning
print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

# Function to print info
print_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

# ============================================
# 1. Check Prerequisites
# ============================================
echo -e "${BLUE}[1/8] Checking Prerequisites...${NC}"

command_exists docker && print_status 0 "Docker installed" || { print_status 1 "Docker not found"; exit 1; }
command_exists docker-compose && print_status 0 "Docker Compose installed" || { print_status 1 "Docker Compose not found"; exit 1; }
command_exists openssl && print_status 0 "OpenSSL installed" || print_warning "OpenSSL not found (needed for secret generation)"

echo ""

# ============================================
# 2. Check Environment Files
# ============================================
echo -e "${BLUE}[2/8] Checking Environment Configuration...${NC}"

if [ ! -f .env ]; then
    print_warning ".env file not found. Creating from .env.example..."
    cp .env.example .env
    print_info "Please edit .env and set all required secrets"
    exit 1
fi
print_status 0 ".env file exists"

if [ ! -f apps/api/.env.docker ]; then
    print_warning "apps/api/.env.docker not found. Creating from example..."
    cp apps/api/.env.docker.example apps/api/.env.docker
fi
print_status 0 "apps/api/.env.docker exists"

echo ""

# ============================================
# 3. Validate Required Secrets
# ============================================
echo -e "${BLUE}[3/8] Validating Secrets...${NC}"

source .env

check_secret() {
    local var_name=$1
    local var_value=$2
    local min_length=$3

    if [ -z "$var_value" ]; then
        print_status 1 "$var_name is empty"
        return 1
    elif [ ${#var_value} -lt $min_length ]; then
        print_status 1 "$var_name is too short (min: $min_length chars)"
        return 1
    else
        print_status 0 "$var_name is set (${#var_value} chars)"
        return 0
    fi
}

SECRETS_OK=true
check_secret "POSTGRES_PASSWORD" "$POSTGRES_PASSWORD" 16 || SECRETS_OK=false
check_secret "REDIS_PASSWORD" "$REDIS_PASSWORD" 16 || SECRETS_OK=false
check_secret "LINKFLOW_SECRET" "$LINKFLOW_SECRET" 32 || SECRETS_OK=false
check_secret "JWT_SECRET" "$JWT_SECRET" 32 || SECRETS_OK=false
check_secret "APP_KEY" "$APP_KEY" 32 || SECRETS_OK=false

if [ "$SECRETS_OK" = false ]; then
    echo ""
    print_warning "Some secrets are missing or too short. Generate them with:"
    echo "  openssl rand -base64 24  # For passwords"
    echo "  openssl rand -base64 32  # For secrets"
    echo "  docker compose run --rm api php artisan key:generate --show  # For APP_KEY"
    exit 1
fi

echo ""

# ============================================
# 4. Check SSL Certificates
# ============================================
echo -e "${BLUE}[4/8] Checking SSL Certificates...${NC}"

SSL_PATH="${SSL_CERT_PATH:-./infra/nginx/ssl}"

if [ -f "$SSL_PATH/fullchain.pem" ] && [ -f "$SSL_PATH/privkey.pem" ]; then
    print_status 0 "SSL certificates found"

    # Check certificate expiry
    if command_exists openssl; then
        EXPIRY=$(openssl x509 -enddate -noout -in "$SSL_PATH/fullchain.pem" | cut -d= -f2)
        print_info "Certificate expires: $EXPIRY"
    fi
else
    print_warning "SSL certificates not found in $SSL_PATH"
    print_info "For production, you need:"
    echo "  - $SSL_PATH/fullchain.pem"
    echo "  - $SSL_PATH/privkey.pem"
    print_info "For testing, you can generate self-signed certificates:"
    echo "  ./scripts/generate-ssl.sh"
fi

echo ""

# ============================================
# 5. Check Database Persistence
# ============================================
echo -e "${BLUE}[5/8] Checking Database Volumes...${NC}"

if docker volume ls | grep -q "linkflow_postgres_data"; then
    print_status 0 "PostgreSQL volume exists"
    print_warning "Existing data will be preserved"
else
    print_status 0 "Fresh PostgreSQL installation"
fi

if docker volume ls | grep -q "linkflow_redis_data"; then
    print_status 0 "Redis volume exists"
else
    print_status 0 "Fresh Redis installation"
fi

echo ""

# ============================================
# 6. Production Configuration Check
# ============================================
echo -e "${BLUE}[6/8] Checking Production Settings...${NC}"

# Check Laravel environment in .env.docker
if grep -q "APP_ENV=production" apps/api/.env.docker 2>/dev/null; then
    print_status 0 "APP_ENV=production"
else
    print_warning "APP_ENV should be 'production' in apps/api/.env.docker"
fi

if grep -q "APP_DEBUG=false" apps/api/.env.docker 2>/dev/null; then
    print_status 0 "APP_DEBUG=false"
else
    print_warning "APP_DEBUG should be 'false' in apps/api/.env.docker"
fi

# Check log level
if [ "$LOG_LEVEL" = "info" ] || [ "$LOG_LEVEL" = "warning" ] || [ "$LOG_LEVEL" = "error" ]; then
    print_status 0 "LOG_LEVEL=$LOG_LEVEL (appropriate for production)"
else
    print_warning "LOG_LEVEL=$LOG_LEVEL (consider 'info' or 'warning' for production)"
fi

echo ""

# ============================================
# 7. Build Production Images
# ============================================
echo -e "${BLUE}[7/8] Building Production Images...${NC}"

print_info "This may take several minutes..."
if docker compose -f docker-compose.yml -f docker-compose.prod.yml build --no-cache; then
    print_status 0 "Production images built successfully"
else
    print_status 1 "Failed to build production images"
    exit 1
fi

echo ""

# ============================================
# 8. Pre-flight Summary
# ============================================
echo -e "${BLUE}[8/8] Pre-flight Summary${NC}"
echo ""
echo -e "${GREEN}✓ All checks passed!${NC}"
echo ""
echo "Your production stack is ready to deploy."
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "  1. Review your .env file one more time"
echo "  2. Start production stack: ${GREEN}make prod${NC}"
echo "  3. Run migrations: ${GREEN}make migrate${NC}"
echo "  4. Seed initial data: ${GREEN}make seed${NC}"
echo "  5. Check health: ${GREEN}make health${NC}"
echo ""
echo -e "${YELLOW}Production URLs:${NC}"
echo "  • API: https://api.linkflow.io"
echo "  • Engine: https://engine.linkflow.io"
echo "  • Health: http://your-server/health"
echo ""
echo -e "${YELLOW}Monitoring:${NC}"
echo "  • Logs: ${GREEN}make prod-logs${NC}"
echo "  • Status: ${GREEN}make ps${NC}"
echo "  • Metrics: http://your-server:9090 (if exposed)"
echo ""
echo -e "${RED}⚠ Important:${NC}"
echo "  • Ensure firewall rules allow only ports 80 and 443"
echo "  • Set up automated backups for PostgreSQL"
echo "  • Configure monitoring and alerting"
echo "  • Review security headers in nginx.conf"
echo ""
