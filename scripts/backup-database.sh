#!/bin/bash
# Backup PostgreSQL database
# Usage: ./scripts/backup-database.sh [backup-name]

set -e

BLUE='\033[0;34m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

BACKUP_DIR="backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="${1:-linkflow_backup_$TIMESTAMP}"
BACKUP_FILE="$BACKUP_DIR/${BACKUP_NAME}.sql.gz"

mkdir -p "$BACKUP_DIR"

echo -e "${BLUE}Starting database backup...${NC}"
echo ""

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

# Create backup
echo "Backing up database: ${POSTGRES_DB:-linkflow}"
docker exec linkflow-postgres pg_dump \
    -U "${POSTGRES_USER:-linkflow}" \
    -d "${POSTGRES_DB:-linkflow}" \
    --clean --if-exists \
    | gzip > "$BACKUP_FILE"

# Check if backup was successful
if [ -f "$BACKUP_FILE" ]; then
    SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
    echo ""
    echo -e "${GREEN}✓ Backup completed successfully${NC}"
    echo ""
    echo "File: $BACKUP_FILE"
    echo "Size: $SIZE"
    echo ""
    echo "To restore this backup:"
    echo "  ./scripts/restore-database.sh $BACKUP_FILE"
else
    echo -e "${RED}✗ Backup failed${NC}"
    exit 1
fi

# Clean up old backups (keep last 7 days)
echo "Cleaning up old backups (keeping last 7 days)..."
find "$BACKUP_DIR" -name "linkflow_backup_*.sql.gz" -mtime +7 -delete
echo -e "${GREEN}✓ Cleanup complete${NC}"
