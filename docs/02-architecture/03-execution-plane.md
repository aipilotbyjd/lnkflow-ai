# Execution Plane Architecture

The **Execution Plane** (`apps/engine`) is a cluster of Go microservices responsible for the reliable, high-performance execution of workflows. It follows a design inspired by Temporal/Cadence.

## Service Breakdown

### 1. Frontend Service (Gateway)
-   **Entry Point**: All external requests to the engine go through here.
-   **Protocol**: gRPC (internal/external) and HTTP (health/metrics).
-   **Responsibilities**:
    -   Authentication (validating JWTs from Control Plane).
    -   Rate Limiting.
    -   Routing requests to History or Matching services.

### 2. History Service (State Store)
-   **Role**: The "brain" of the engine.
-   **Persistence**: Stores immutable events (e.g., `WorkflowStarted`, `TaskScheduled`, `TaskCompleted`) in PostgreSQL.
-   **Sharding**: Distributes workflow executions across shards to prevent database hotspots.
-   **Consistency**: Ensures strict ordering of events for a given execution.

### 3. Matching Service (Task Queue)
-   **Role**: The "dispatcher".
-   **Function**: Matches tasks (nodes to be executed) with available workers.
-   **Mechanism**: Uses long-polling. Workers request tasks; Matching holds the request until a task is available or timeout occurs.
-   **Storage**: Uses Redis for high-throughput task queuing.

### 4. Worker Service (Executor)
-   **Role**: The "doer".
-   **Function**: Executes the actual logic of workflow nodes.
-   **Node Types**:
    -   **HTTP**: Makes external API calls.
    -   **AI**: Calls LLMs (OpenAI, Anthropic).
    -   **Code**: Runs sandboxed JavaScript/Python (future).
    -   **Control Flow**: Conditionals, loops, branches.
-   **Scalability**: Stateless and horizontally scalable.

### 5. Timer Service (Scheduler)
-   **Role**: The "clock".
-   **Function**: Manages delayed execution, timeouts, and cron schedules.
-   **Mechanism**: Stores timers in a sorted set (Redis/DB) and fires events to the Matching service when they expire.

## Execution Flow

1.  **Start**: Frontend receives `StartWorkflow` -> Calls History.
2.  **Persist**: History creates execution record -> Generates `WorkflowStarted` event.
3.  **Schedule**: History analyzes the first node -> Generates `TaskScheduled` event -> Calls Matching.
4.  **Dispatch**: Matching adds task to queue -> Worker polls and receives task.
5.  **Execute**: Worker runs node logic (e.g., HTTP POST).
6.  **Complete**: Worker calls History with result -> History records `TaskCompleted`.
7.  **Next**: History determines next node -> Generates new `TaskScheduled`.
8.  **Repeat**: Steps 3-7 repeat until end node.

## Fault Tolerance

-   **Retries**: Workers automatically retry failed tasks with exponential backoff.
-   **Circuit Breakers**: Prevent cascading failures when external services are down.
-   **Timeouts**: Every task has a `StartToClose` and `ScheduleToStart` timeout.
-   **Dead Letter Queues**: Tasks that fail permanently are moved to a DLQ for manual inspection.
