# Core Concepts

Understanding these concepts is key to mastering LinkFlow.

## Workflow
A **Workflow** is a blueprint for an automated process. It consists of a series of steps (Nodes) connected by paths (Edges).

## Node
A **Node** represents a single step in a workflow.
-   **Trigger Node**: Starts the workflow (e.g., Webhook, Schedule).
-   **Action Node**: Performs a task (e.g., HTTP Request, AI Prompt).
-   **Control Node**: Logic flow (e.g., If/Else, Loop).

## Edge
An **Edge** connects two nodes, defining the flow of execution. Edges can be conditional (e.g., "Only follow this path if the previous step succeeded").

## Execution
An **Execution** is a single run of a Workflow. It has its own unique ID, logs, and state.
-   **Status**: Pending, Running, Completed, Failed, Cancelled.

## Workspace
A **Workspace** is a container for workflows, users, and credentials. It provides isolation—workflows in Workspace A cannot access credentials in Workspace B.

## Credential
A **Credential** is a secure variable (API Key, Token) stored in the vault. Workflows reference credentials by name, and the engine injects them securely at runtime.

## Context
The **Context** is a JSON object that flows through the workflow.
-   It starts with trigger data (e.g., webhook body).
-   As nodes execute, their output is added to the context.
-   Subsequent nodes can reference this data using variables like `{{ node_id.body.field }}`.
