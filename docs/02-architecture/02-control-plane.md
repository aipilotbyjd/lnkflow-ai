# Control Plane Architecture

The **Control Plane** is the user-facing side of LinkFlow. It is built as a monolithic Laravel application (`apps/api`) that handles all business logic not directly related to executing workflow steps.

## Responsibilities

-   **REST API**: Serves endpoints for the web frontend, CLI, and external integrations.
-   **Authentication & Authorization**: Manages users, workspaces, and permissions using Laravel Passport and Spatie Permissions.
-   **Workflow Definition**: Validates and stores workflow JSON structures.
-   **Job Queuing**: Uses Laravel Horizon (Redis) to handle asynchronous tasks like webhook processing and email notifications.
-   **Callback Handling**: Receives execution status updates from the Engine via signed HTTP webhooks.

## Tech Stack

-   **Framework**: Laravel 12
-   **Language**: PHP 8.4
-   **Database**: PostgreSQL 16
-   **Cache/Queue**: Redis 7
-   **Testing**: Pest PHP

## Key Modules

### 1. Workflow Manager
Handles CRUD operations for workflows. It validates the directed acyclic graph (DAG) structure of workflows to ensure they are executable.

### 2. Execution Dispatcher
When a workflow needs to run:
1.  The API creates an `Execution` record in PostgreSQL (status: `pending`).
2.  It sends a gRPC request to the **Engine Frontend** service to start the execution.
3.  If the Engine accepts, the status updates to `running`.

### 3. Webhook Receiver
Listens for external HTTP events. When a webhook is received:
1.  Verifies the signature (if configured).
2.  Finds matching workflows.
3.  Dispatches execution jobs to the queue.

### 4. Integration Manager
Manages credentials (OAuth2 tokens, API keys) securely. These are encrypted at rest and only decrypted when passed to the Engine for execution.

## Data Model

Key entities managed by the Control Plane:

-   `User`: System users.
-   `Workspace`: Multi-tenancy isolation boundary.
-   `Workflow`: Definition of a process (Nodes, Edges, Triggers).
-   `Execution`: Instance of a running workflow.
-   `Credential`: Encrypted secrets for integrations.
-   `Webhook`: Configuration for inbound triggers.

## Interaction with Engine

The Control Plane communicates with the Engine via:
-   **gRPC Client**: To start executions, cancel runs, or query real-time status.
-   **HTTP Callbacks**: The Engine calls back to `/api/v1/jobs/callback` to report node completion or failure.
