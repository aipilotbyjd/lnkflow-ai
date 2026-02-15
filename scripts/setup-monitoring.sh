#!/bin/bash
# Setup monitoring and alerting for LinkFlow production

set -e

BLUE='\033[0;34m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}Setting up monitoring for LinkFlow...${NC}"
echo ""

# Create monitoring directory
mkdir -p monitoring

# ============================================
# 1. Create Prometheus Configuration
# ============================================
cat > monitoring/prometheus.yml <<'EOF'
global:
  scrape_interval: 15s
  evaluation_interval: 15s

scrape_configs:
  - job_name: 'linkflow-api'
    static_configs:
      - targets: ['api:8000']
    metrics_path: '/api/v1/metrics'

  - job_name: 'linkflow-engine'
    static_configs:
      - targets: ['frontend:9090']
    metrics_path: '/metrics'

  - job_name: 'postgres'
    static_configs:
      - targets: ['postgres-exporter:9187']

  - job_name: 'redis'
    static_configs:
      - targets: ['redis-exporter:9121']

alerting:
  alertmanagers:
    - static_configs:
        - targets: ['alertmanager:9093']

rule_files:
  - 'alerts.yml'
EOF

# ============================================
# 2. Create Alert Rules
# ============================================
cat > monitoring/alerts.yml <<'EOF'
groups:
  - name: linkflow_alerts
    interval: 30s
    rules:
      - alert: ServiceDown
        expr: up == 0
        for: 2m
        labels:
          severity: critical
        annotations:
          summary: "Service {{ $labels.job }} is down"
          description: "{{ $labels.job }} has been down for more than 2 minutes"

      - alert: HighMemoryUsage
        expr: (node_memory_MemTotal_bytes - node_memory_MemAvailable_bytes) / node_memory_MemTotal_bytes > 0.9
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High memory usage detected"
          description: "Memory usage is above 90% for more than 5 minutes"

      - alert: HighDiskUsage
        expr: (node_filesystem_size_bytes - node_filesystem_free_bytes) / node_filesystem_size_bytes > 0.85
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High disk usage detected"
          description: "Disk usage is above 85%"

      - alert: DatabaseConnectionFailure
        expr: pg_up == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "Database connection failed"
          description: "Cannot connect to PostgreSQL"

      - alert: HighAPILatency
        expr: http_request_duration_seconds{quantile="0.95"} > 1
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High API latency detected"
          description: "95th percentile latency is above 1 second"

      - alert: HighErrorRate
        expr: rate(http_requests_total{status=~"5.."}[5m]) > 0.05
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "High error rate detected"
          description: "Error rate is above 5%"
EOF

# ============================================
# 3. Create Grafana Dashboard
# ============================================
mkdir -p monitoring/grafana/dashboards
cat > monitoring/grafana/dashboards/linkflow.json <<'EOF'
{
  "dashboard": {
    "title": "LinkFlow Production Dashboard",
    "panels": [
      {
        "title": "API Request Rate",
        "targets": [
          {
            "expr": "rate(http_requests_total[5m])"
          }
        ]
      },
      {
        "title": "API Latency (p95)",
        "targets": [
          {
            "expr": "histogram_quantile(0.95, rate(http_request_duration_seconds_bucket[5m]))"
          }
        ]
      },
      {
        "title": "Error Rate",
        "targets": [
          {
            "expr": "rate(http_requests_total{status=~\"5..\"}[5m])"
          }
        ]
      },
      {
        "title": "Database Connections",
        "targets": [
          {
            "expr": "pg_stat_database_numbackends"
          }
        ]
      }
    ]
  }
}
EOF

# ============================================
# 4. Create Health Check Cron Job
# ============================================
cat > monitoring/health-check-cron.sh <<'EOF'
#!/bin/bash
# Add this to crontab: */5 * * * * /path/to/monitoring/health-check-cron.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

if ! ./scripts/health-check.sh > /tmp/linkflow-health.log 2>&1; then
    # Health check failed - send alert
    echo "LinkFlow health check failed at $(date)" | mail -s "ALERT: LinkFlow Health Check Failed" admin@linkflow.io

    # Optional: Send to Slack
    # curl -X POST -H 'Content-type: application/json' \
    #   --data '{"text":"🚨 LinkFlow health check failed!"}' \
    #   YOUR_SLACK_WEBHOOK_URL
fi
EOF

chmod +x monitoring/health-check-cron.sh

# ============================================
# 5. Create Backup Cron Job
# ============================================
cat > monitoring/backup-cron.sh <<'EOF'
#!/bin/bash
# Add this to crontab: 0 2 * * * /path/to/monitoring/backup-cron.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

# Create backup
if ./scripts/backup-database.sh; then
    echo "Backup completed successfully at $(date)"

    # Optional: Upload to S3
    # aws s3 sync backups/ s3://linkflow-backups/$(date +%Y-%m)/
else
    echo "Backup failed at $(date)" | mail -s "ALERT: LinkFlow Backup Failed" admin@linkflow.io
fi
EOF

chmod +x monitoring/backup-cron.sh

# ============================================
# 6. Create Log Rotation Config
# ============================================
cat > monitoring/logrotate.conf <<'EOF'
/var/lib/docker/volumes/linkflow_nginx_logs/_data/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
    postrotate
        docker compose exec nginx nginx -s reload > /dev/null 2>&1
    endscript
}

/path/to/linkflow/apps/api/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
EOF

echo ""
echo -e "${GREEN}✓ Monitoring setup completed${NC}"
echo ""
echo "Created files:"
echo "  • monitoring/prometheus.yml"
echo "  • monitoring/alerts.yml"
echo "  • monitoring/grafana/dashboards/linkflow.json"
echo "  • monitoring/health-check-cron.sh"
echo "  • monitoring/backup-cron.sh"
echo "  • monitoring/logrotate.conf"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo ""
echo "1. Set up cron jobs:"
echo "   crontab -e"
echo "   Add:"
echo "   */5 * * * * $(pwd)/monitoring/health-check-cron.sh"
echo "   0 2 * * * $(pwd)/monitoring/backup-cron.sh"
echo ""
echo "2. Configure email alerts:"
echo "   Edit monitoring/health-check-cron.sh and set your email"
echo ""
echo "3. Optional: Deploy Prometheus + Grafana:"
echo "   docker compose -f monitoring/docker-compose.monitoring.yml up -d"
echo ""
echo "4. Set up log rotation:"
echo "   sudo cp monitoring/logrotate.conf /etc/logrotate.d/linkflow"
echo ""
