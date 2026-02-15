# Architecture Documentation

Understanding LinkFlow's hybrid architecture and design decisions.

## 📚 Documentation Index

1. [Overview](01-overview.md) - High-level architecture and components
2. [Control Plane](02-control-plane.md) - Laravel API backend
3. [Execution Plane](03-execution-plane.md) - Go microservices engine
4. [Data Flow](04-data-flow.md) - How data moves through the system
5. [Security Model](05-security-model.md) - Authentication and authorization
6. [Infrastructure Plan](06-infrastructure-plan.md) - Production infrastructure design

## 🏗️ System Overview

LinkFlow uses a hybrid architecture combining Laravel (control plane) with Go microservices (execution plane):

```
┌─────────────────────────────────────────────────────────────┐
│                        Clients                               │
│              (Web App, CLI, SDKs, Webhooks)                 │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                   Control Plane (Laravel)                    │
│        REST API • Authentication • Job Queue                │
│                      Port 8000                               │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                  Execution Plane (Go)                        │
│  ┌──────────┐ ┌─────────┐ ┌────────┐ ┌───────┐ ┌─────────┐ │
│  │ Frontend │ │ History │ │Matching│ │ Worker│ │  Timer  │ │
│  │  :8080   │ │  :8081  │ │ :8082  │ │ :8083 │ │  :8084  │ │
│  └──────────┘ └─────────┘ └────────┘ └───────┘ └─────────┘ │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                     Data Layer                               │
│           PostgreSQL 16  •  Redis 7                         │
└─────────────────────────────────────────────────────────────┘
```

## 🎯 Key Design Principles

### Separation of Concerns
- **Control Plane**: User-facing API, authentication, business logic
- **Execution Plane**: High-performance workflow execution
- **Data Layer**: Persistent storage and caching

### Scalability
- Stateless services enable horizontal scaling
- Microservices architecture for independent scaling
- Event-driven communication via Redis Streams

### Reliability
- Health checks on all services
- Graceful degradation
- Circuit breakers and retry logic
- Event sourcing for workflow history

### Security
- JWT-based authentication
- HMAC-signed callbacks
- TLS encryption in production
- Role-based access control

## 📖 Recommended Reading Order

### For Developers
1. Start with [Overview](01-overview.md)
2. Understand [Control Plane](02-control-plane.md)
3. Deep dive into [Execution Plane](03-execution-plane.md)
4. Study [Data Flow](04-data-flow.md)

### For DevOps/SRE
1. Review [Overview](01-overview.md)
2. Study [Infrastructure Plan](06-infrastructure-plan.md)
3. Understand [Security Model](05-security-model.md)
4. Check [Deployment Docs](../05-deployment/)

### For Security Auditors
1. Read [Security Model](05-security-model.md)
2. Review [Infrastructure Plan](06-infrastructure-plan.md)
3. Check [Operations Guide](../06-operations/)

## 🔧 Technology Stack

| Layer | Technology | Purpose |
|-------|------------|---------|
| API Framework | Laravel 12 (PHP 8.4) | REST API, auth, business logic |
| Execution Engine | Go 1.24 | High-performance workflow execution |
| Database | PostgreSQL 16 | Persistent data storage |
| Cache/Queue | Redis 7 | Caching, sessions, job queue |
| Inter-service | gRPC + Protobuf | Microservice communication |
| Container | Docker + Compose | Deployment and orchestration |
| Reverse Proxy | Nginx | SSL termination, load balancing |

## 🚀 Architecture Highlights

### Control Plane (Laravel)
- RESTful API with OpenAPI documentation
- OAuth 2.0 authentication via Passport
- Multi-tenancy with workspace isolation
- Job queue for async processing
- Event broadcasting

### Execution Plane (Go)
- **Frontend**: API gateway and request routing
- **History**: Event store and workflow state
- **Matching**: Task queue and distribution
- **Worker**: Workflow execution engine
- **Timer**: Scheduled workflow triggers
- **Visibility**: Query service for executions

### Communication Patterns
- **Client → API**: HTTP/REST
- **API → Engine**: HTTP + Redis Streams
- **Engine Services**: gRPC
- **Engine → API**: HTTP callbacks (HMAC signed)

## 📊 Scaling Strategy

### Horizontal Scaling
- API servers: 2-8 replicas behind load balancer
- Queue workers: 2-8 replicas
- Go workers: 3-16 replicas with configurable goroutines
- Frontend gateway: 2-4 replicas

### Vertical Scaling
- Database: Increase CPU/RAM, use read replicas
- Redis: Increase memory, use Redis Cluster
- Workers: Increase goroutine count per container

### Beyond Docker Compose
- **Stage 1**: Docker Compose (< 10K workflows/day)
- **Stage 2**: Docker Swarm (multi-node)
- **Stage 3**: Kubernetes (auto-scaling, service mesh)

## 🔐 Security Architecture

### Authentication
- JWT tokens for API access
- OAuth 2.0 for third-party integrations
- API keys for webhook triggers

### Authorization
- Role-based access control (RBAC)
- Workspace-level permissions
- Resource-level policies

### Data Protection
- TLS encryption in transit
- Database encryption at rest
- Secret management via environment variables
- HMAC signing for callbacks

## 📈 Performance Characteristics

### Throughput
- API: 1000+ req/s per instance
- Engine: 10,000+ workflow executions/s
- Database: Optimized for OLTP workloads

### Latency
- API response: < 100ms (p95)
- Workflow dispatch: < 50ms
- Execution start: < 200ms

### Resource Usage
- API: ~256MB RAM per instance
- Worker: ~512MB RAM per instance
- Database: 2-4GB RAM recommended

## 🔄 Data Flow Patterns

### Workflow Execution
1. Client submits workflow via API
2. API validates and queues execution
3. Engine Frontend receives execution request
4. History service creates execution record
5. Matching service distributes tasks
6. Worker executes workflow nodes
7. Results callback to API
8. API updates execution status

### Event Sourcing
- All workflow events stored in History service
- Enables replay and debugging
- Supports time-travel debugging
- Audit trail for compliance

## 📞 Related Documentation

- [Deployment Guide](../05-deployment/) - How to deploy the architecture
- [Operations Guide](../06-operations/) - Running and maintaining the system
- [Development Guide](../07-development/) - Building on the architecture
- [ADRs](../adr/) - Architecture decision records

## 🎯 Next Steps

- Review [Infrastructure Plan](06-infrastructure-plan.md) for production setup
- Check [Deployment Documentation](../05-deployment/) for deployment guides
- Read [ADRs](../adr/) for design decisions and rationale
