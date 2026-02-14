# ADR 0001: Hybrid Laravel + Go Architecture

## Status
Accepted

## Date
2026-02-05

## Context
We need to build a workflow automation platform that is:
- Easy to develop and maintain for typical web features (auth, UI, API)
- High-performance for workflow execution at scale
- Capable of handling concurrent workflow executions efficiently

## Decision
We will use a hybrid architecture:
- **Laravel (PHP)** for the API layer, authentication, user management, and job queuing
- **Go microservices** for the execution engine (history, matching, worker, timer services)

## Consequences

### Positive
- Laravel provides rapid development for standard web features
- Go provides excellent concurrency and performance for execution
- Clear separation of concerns between control plane (Laravel) and data plane (Go)
- Each component can scale independently

### Negative
- Two language ecosystems to maintain
- Requires clear API contracts between services
- More complex deployment and monitoring
- Team needs expertise in both PHP and Go

### Mitigations
- Use gRPC/protobuf for type-safe inter-service communication
- Shared secret for secure callbacks between services
- Unified observability stack (OpenTelemetry)
