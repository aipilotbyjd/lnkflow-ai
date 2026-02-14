# Data Flow & Lifecycle

This document describes how data flows through the LinkFlow system during a typical workflow execution.

## Request Lifecycle

### 1. Ingestion
A workflow execution is triggered via:
-   **Manual API Call**: `POST /api/v1/workflows/{id}/execute`
-   **Webhook**: `POST /webhooks/{uuid}`
-   **Schedule**: System cron hits the API.

**Data Flow**:
1.  Client -> Load Balancer -> **Laravel API**.
2.  API validates input and permissions.
3.  API creates `Execution` record in Postgres (Control Plane DB).
4.  API sends gRPC `StartWorkflow` command to **Engine Frontend**.

### 2. Orchestration
The execution moves into the Engine.

**Data Flow**:
1.  **Frontend** -> **History Service**.
2.  **History Service**:
    -   Loads workflow definition.
    -   Writes `WorkflowExecutionStarted` event to `history_events` table (Engine DB).
    -   Identifies the first node (Start Node).
    -   Creates a `DecisionTask` and sends it to **Matching Service**.
3.  **Matching Service**:
    -   Pushes task to Redis Queue.

### 3. Execution (The Loop)
Workers pick up tasks and execute them.

**Data Flow**:
1.  **Worker Service** long-polls **Matching Service**.
2.  Worker receives the `DecisionTask`.
3.  Worker executes the node logic:
    -   *If HTTP Node*: Worker makes outbound HTTP request.
    -   *If AI Node*: Worker calls OpenAI API.
4.  Worker reports result back to **History Service** (gRPC `RespondDecisionTaskCompleted`).
5.  **History Service**:
    -   Writes `ActivityTaskCompleted` event.
    -   Evaluates "Edges" to find the next node.
    -   Creates new task for the next node -> sends to **Matching**.
    -   *Repeat until workflow ends.*

### 4. Feedback & Status
The user needs to know what's happening.

**Data Flow**:
1.  **Worker** (upon node completion) -> **Laravel API** (Webhook Callback).
    -   Payload: `execution_id`, `node_id`, `status`, `output`, `logs`.
    -   Authentication: HMAC Signature using `LINKFLOW_SECRET`.
2.  **Laravel API**:
    -   Updates `execution_logs` table.
    -   Broadcasts real-time event via Pusher/WebSockets (optional) to UI.
3.  **Client UI**: Polls or receives socket event to update the diagram status (green checkmarks).

## Data Consistency

-   **At-Least-Once Delivery**: Tasks might be delivered multiple times in rare failure scenarios. Workers are designed to be idempotent where possible.
-   **Event Sourcing**: The `History` service is the source of truth for *what happened*. The Laravel DB is a *projection* for query/display purposes.
-   **Optimistic Locking**: Used in the History service to prevent concurrent updates to the same execution.
