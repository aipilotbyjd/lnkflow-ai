# System Requirements

## Hardware Recommendations

| Load | CPU | RAM | Storage |
|------|-----|-----|---------|
| **Development** | 2 vCPU | 4 GB | 20 GB SSD |
| **Small Production** (< 10 executions/sec) | 4 vCPU | 8 GB | 100 GB SSD |
| **Medium Production** (10-100 executions/sec) | 8 vCPU | 16 GB | 500 GB SSD |
| **Large Production** (> 100 executions/sec) | 16+ vCPU | 32+ GB | 1 TB NVMe |

## Software Requirements

### Control Plane
- **PHP**: 8.4+
- **Extensions**: bcmath, ctype, curl, dom, fileinfo, json, mbstring, openssl, pcre, pdo, pdo_pgsql, redis, tokenizer, xml
- **Web Server**: Nginx or Apache

### Execution Plane
- **Go**: 1.24+ (for building from source)
- **Linux Kernel**: 5.4+ (recommended for container performance)

### Data Layer
- **PostgreSQL**: Version 16+ (Required for JSONB features)
- **Redis**: Version 7+ (Required for Streams)
- **Elasticsearch** (Optional): Version 8+ (For advanced visibility search)
