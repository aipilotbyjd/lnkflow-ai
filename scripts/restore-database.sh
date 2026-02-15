#!/bin/bash
# Restore PostgreSQL database from backup
# Usage: ./scripts/restore-database.sh <backup-file>

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

if [ -z "$1" ]; then
    echo -e "${RED}Usage: $0 <backup-file>${NC}"
    echo ""
    echo "Available backups:"
    ls -lh backups/*.sql.gz 2>/dev/null || echo "  No backups found"
    exit 1
fi

BACKUP_FILE="$1"

if [ ! -f "$BACKUP_FILE" ]; then
    echo -e "${RED}✗ Backup file not found: $BACKUP_FILE${NC}"
    exit 1
fi

echo -e "${YELLOW}⚠ WARNING: This will replace the current database!${NC}"
echo ""
echo "Backup file: $BACKUP_FILE"
echo ""
read -p "Are you sure you want to continue? (yes/no): " CONFIRM

if [ "$CONFIRM" != "yes" ]; then
    echo "Restore cancelled"
    exit 0
fi

echo ""
echo -e "${BLUE}Starting database restore...${NC}"

# Check if postgres container is running
if ! docker ps | grep -q linkflow-postgres; then
    echo -e "${RED}✗ PostgreSQL container is not running${NC}"
    exit 1
fi

# Load environment variables
if [ -f .env ]; then
    source .env
else
    echo -e "${RED}✗ .env file not found${NC}"
    exit 1
fi

# Restore backup
echo "Restoring to database: ${POSTGRES_DB:-linkflow}"
gunzip -c "$BACKUP_FILE" | docker exec -i linkflow-postgres psql \
    -U "${POSTGRES_USER:-linkflow}" \
    -d "${POSTGRES_DB:-linkflow}"

echo ""
echo -e "${GREEN}✓ Database restored successfully${NC}"
echo ""
echo "Next steps:"
echo "  1. Restart services: make restart"
echo "  2. Check health: make health"
