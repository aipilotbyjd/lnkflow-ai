# LinkFlow Documentation

Welcome to the LinkFlow documentation. This guide will help you understand, deploy, and develop on the LinkFlow workflow automation platform.

## Quick Navigation

| Section | Description |
|---------|-------------|
| [Getting Started](./01-getting-started/) | Installation, setup, and your first workflow |
| [Architecture](./02-architecture/) | System design, components, and data flow |
| [Guides](./03-guides/) | How-to guides for common tasks |
| [API Reference](./04-api-reference/) | OpenAPI spec, authentication, endpoints |
| [Deployment](./05-deployment/) | Docker, Kubernetes, and production setup |
| [Operations](./06-operations/) | Runbooks, monitoring, and incident response |
| [Development](./07-development/) | Contributing, testing, and code style |
| [ADRs](./adr/) | Architecture Decision Records |

[**🗺️ View Documentation Map**](./STRUCTURE.md) - Visual guide to the docs structure.

## What is LinkFlow?

LinkFlow is a high-performance workflow automation platform that enables you to:

- Design visual workflows with a drag-and-drop editor
- Execute workflows with triggers (manual, webhook, schedule, event)
- Integrate with APIs, AI services, databases, and more
- Monitor and debug executions in real-time
- Scale to millions of executions

## Architecture Overview

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

## Quick Start

```bash
# Clone the repository
git clone https://github.com/your-org/lnkflow.git
cd lnkflow

# Start everything with Docker
make setup
make start

# Access the services
# API: http://localhost:8000
# Engine: http://localhost:8080
```

## Technology Stack

| Layer | Technology |
|-------|------------|
| API Framework | Laravel 12 (PHP 8.4) |
| Execution Engine | Go 1.24 |
| Database | PostgreSQL 16 |
| Cache/Queue | Redis 7 |
| Inter-service | gRPC + Protobuf |
| Container | Docker + Docker Compose |

## Getting Help

- [GitHub Issues](https://github.com/your-org/lnkflow/issues) - Bug reports and feature requests
- [Discussions](https://github.com/your-org/lnkflow/discussions) - Questions and community
- [Contributing Guide](../CONTRIBUTING.md) - How to contribute
