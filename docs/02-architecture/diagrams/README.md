# Architecture Diagrams

This document contains Mermaid definitions for LinkFlow's key architectural views. You can render these in GitHub, VS Code, or any Mermaid-compatible viewer.

## 1. System Overview

High-level view of the Control Plane and Execution Plane.

```mermaid
graph TB
    subgraph "Clients"
        Web[Web Dashboard]
        CLI[CLI Tool]
        Ext[External Webhooks]
    end

    subgraph "Control Plane (Laravel)"
        API[API Gateway :8000]
        Auth[Authentication]
        Queue[Job Queue]
        Web --> API
        CLI --> API
        Ext --> API
    end

    subgraph "Execution Plane (Go)"
        FE[Frontend :8080]
        History[History Service]
        Matching[Matching Service]
        Worker[Worker Service]
        Timer[Timer Service]
        
        API -- gRPC --> FE
        FE --> History
        FE --> Matching
        Matching --> Worker
        Timer --> Matching
        Worker --> History
        Worker -- Callback --> API
    end

    subgraph "Data Layer"
        PG[(PostgreSQL 16)]
        Redis[(Redis 7)]
        
        API --> PG
        API --> Redis
        History --> PG
        Matching --> Redis
        Timer --> Redis
        Worker --> Redis
    end
```

## 2. Request Data Flow

How a workflow execution request flows through the system.

```mermaid
sequenceDiagram
    autonumber
    participant Client
    participant API as Laravel API
    participant FE as Engine Frontend
    participant History
    participant Matching
    participant Worker
    
    Client->>API: POST /execute (JSON)
    API->>API: Validate & Authenticate
    API->>FE: StartWorkflow(ExecutionID)
    
    FE->>History: RecordWorkflowStarted
    History->>History: Persist Event
    History->>Matching: ScheduleFirstTask
    
    Matching->>Matching: Enqueue Task
    Worker->>Matching: PollTask()
    Matching-->>Worker: Task(Node 1)
    
    Worker->>Worker: Execute Logic (HTTP/AI)
    Worker->>History: CompleteTask(Result)
    
    History->>Matching: ScheduleNextTask(Node 2)
    Note over History,Worker: Repeats until workflow ends
    
    Worker->>API: Webhook Callback (Status)
    API-->>Client: Update UI (WebSocket)
```

## 3. Deployment Topology

Physical deployment view on Kubernetes or Docker.

```mermaid
graph TD
    User((User))
    LB[Load Balancer / Ingress]
    
    subgraph "K8s Namespace: linkflow"
        subgraph "Public Services"
            API_Pod[Pod: API]
            FE_Pod[Pod: Frontend]
        end
        
        subgraph "Private Services"
            Worker_Pod[Pod: Worker x4]
            History_Pod[Pod: History x2]
            Matching_Pod[Pod: Matching x2]
            Timer_Pod[Pod: Timer]
        end
        
        subgraph "Storage (Managed/StatefulSet)"
            PG[(PostgreSQL Primary)]
            Redis_M[(Redis Master)]
        end
    end
    
    User --> LB
    LB --> API_Pod
    LB --> FE_Pod
    
    API_Pod --> PG
    API_Pod --> Redis_M
    API_Pod --> FE_Pod
    
    FE_Pod --> History_Pod
    FE_Pod --> Matching_Pod
    
    Matching_Pod --> Redis_M
    Worker_Pod --> Matching_Pod
    Worker_Pod --> History_Pod
    History_Pod --> PG
```
