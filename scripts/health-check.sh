#!/bin/bash
# Comprehensive health check for LinkFlow production
# Can be used for monitoring and alerting

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

EXIT_CODE=0

echo -e "${BLUE}╔════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║  LinkFlow Health Check                 ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════╝${NC}"
echo ""

# Function to check HTTP endpoint
check_http() {
    local name=$1
    local url=$2
    local expected_code=${3:-200}

    if command -v curl >/dev/null 2>&1; then
        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$url" --max-time 5 || echo "000")
        if [ "$HTTP_CODE" = "$expected_code" ]; then
            echo -e "${GREEN}✓${NC} $name: HTTP $HTTP_CODE"
        else
            echo -e "${RED}✗${NC} $name: HTTP $HTTP_CODE (expected $expected_code)"
            EXIT_CODE=1
        fi
    else
        echo -e "${YELLOW}⚠${NC} $name: curl not available"
    fi
}

# Function to check container health
check_container() {
    local name=$1
    local container=$2

    if docker ps --filter "name=$container" --filter "health=healthy" | grep -q "$container"; then
        echo -e "${GREEN}✓${NC} $name: healthy"
    elif docker ps --filter "name=$container" | grep -q "$container"; then
        echo -e "${YELLOW}⚠${NC} $name: running but not healthy"
        EXIT_CODE=1
    else
        echo -e "${RED}✗${NC} $name: not running"
        EXIT_CODE=1
    fi
}

# Function to check disk space
check_disk() {
    local threshold=90
    local usage=$(df -h / | awk 'NR==2 {print $5}' | sed 's/%//')

    if [ "$usage" -lt "$threshold" ]; then
        echo -e "${GREEN}✓${NC} Disk space: ${usage}% used"
    else
        echo -e "${RED}✗${NC} Disk space: ${usage}% used (threshold: ${threshold}%)"
        EXIT_CODE=1
    fi
}

# Function to check memory
check_memory() {
    if command -v free >/dev/null 2>&1; then
        local mem_usage=$(free | grep Mem | awk '{printf "%.0f", $3/$2 * 100}')
        if [ "$mem_usage" -lt 90 ]; then
            echo -e "${GREEN}✓${NC} Memory: ${mem_usage}% used"
        else
            echo -e "${YELLOW}⚠${NC} Memory: ${mem_usage}% used"
        fi
    else
        echo -e "${YELLOW}⚠${NC} Memory: check not available"
    fi
}

# ============================================
# Infrastructure Checks
# ============================================
echo -e "${BLUE}[Infrastructure]${NC}"
check_container "PostgreSQL" "linkflow-postgres"
check_container "Redis" "linkflow-redis"
echo ""

# ============================================
# API Services
# ============================================
echo -e "${BLUE}[API Services]${NC}"
check_container "Laravel API" "linkflow-api"
check_container "Queue Worker" "linkflow-queue"
check_http "API Health" "http://localhost:8000/api/v1/health"
echo ""

# ============================================
# Engine Services
# ============================================
echo -e "${BLUE}[Engine Services]${NC}"
check_container "Frontend" "linkflow-frontend"
check_container "History" "linkflow-history"
check_container "Matching" "linkflow-matching"
check_container "Worker" "linkflow-worker"
check_container "Timer" "linkflow-timer"
check_container "Visibility" "linkflow-visibility"
check_http "Engine Health" "http://localhost:8080/health"
echo ""

# ============================================
# System Resources
# ============================================
echo -e "${BLUE}[System Resources]${NC}"
check_disk
check_memory
echo ""

# ============================================
# Database Connectivity
# ============================================
echo -e "${BLUE}[Database Connectivity]${NC}"
if docker exec linkflow-postgres pg_isready -U linkflow >/dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} PostgreSQL: accepting connections"
else
    echo -e "${RED}✗${NC} PostgreSQL: not accepting connections"
    EXIT_CODE=1
fi

if docker exec linkflow-redis redis-cli -a "${REDIS_PASSWORD:-}" ping 2>/dev/null | grep -q PONG; then
    echo -e "${GREEN}✓${NC} Redis: responding to PING"
else
    echo -e "${RED}✗${NC} Redis: not responding"
    EXIT_CODE=1
fi
echo ""

# ============================================
# Summary
# ============================================
if [ $EXIT_CODE -eq 0 ]; then
    echo -e "${GREEN}╔════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║  All systems operational ✓             ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════╝${NC}"
else
    echo -e "${RED}╔════════════════════════════════════════╗${NC}"
    echo -e "${RED}║  Some checks failed ✗                  ║${NC}"
    echo -e "${RED}╚════════════════════════════════════════╝${NC}"
fi

exit $EXIT_CODE
