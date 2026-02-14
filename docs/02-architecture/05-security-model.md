# Security Model

## Authentication

### User Authentication
-   **Method**: Laravel Passport (OAuth2) or Sanctum (Tokens).
-   **Storage**: BCrypt hashed passwords in PostgreSQL (`users` table).
-   **Session**: Stateful sessions for Web UI, Bearer tokens for API.

### Service-to-Service Authentication
-   **API -> Engine**: JWT signed with `JWT_SECRET`. The Engine validates this token on every gRPC request.
-   **Engine -> API**: HMAC-SHA256 signature using `LINKFLOW_SECRET`. The API validates the `X-LinkFlow-Signature` header.
-   **Internal Engine**: Mutual TLS (mTLS) is recommended for production deployment between Go microservices.

## Authorization
-   **RBAC**: Role-Based Access Control via a custom `WorkspacePermissionService` backed by `workspace_members.role`.
-   **Scopes**:
    -   `Workspace Owner`: Full workspace access, including billing and membership management.
    -   `Workspace Admin`: Manage members, workflows, credentials, and executions.
    -   `Workspace Member`: Create/update workflows and credentials, execute workflows.
    -   `Workspace Viewer`: Read-only workspace access.

## Data Security

### Encryption at Rest
-   **Credentials**: Third-party API keys (e.g., OpenAI Key, Stripe Secret) are encrypted using Laravel's `Crypt` facade (AES-256-CBC) before storage in the `credentials` table.
-   **Decryption**: Only occurs inside the **Worker** service at the exact moment of execution.

### Encryption in Transit
-   **External**: All HTTP traffic should be over HTTPS (TLS 1.2+).
-   **Internal**: gRPC traffic can be secured via TLS.

## Network Security

-   **Isolation**: The Engine microservices should run in a private subnet, inaccessible from the public internet.
-   **Gateway**: Only the **Laravel API** and **Engine Frontend** (if using Edge features) should be exposed publicly (via Load Balancer).
-   **Database**: PostgreSQL and Redis should typically not be exposed publicly.

## Secrets Management
-   **Environment Variables**: `JWT_SECRET`, `LINKFLOW_SECRET`, `APP_KEY`, `DB_PASSWORD` are injected via environment variables.
-   **Production**: Use a secrets manager (AWS Secrets Manager, HashiCorp Vault) to inject these at runtime.
