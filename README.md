# LinkFlow

**LinkFlow** is a high-performance, scalable workflow automation platform built with a hybrid microservices architecture. It combines the ease of use of a Laravel-based API with the raw performance and concurrency of a Go-based execution engine.

## 🏗 Architecture

This project is a Monorepo containing the following distinct applications:

| Component | Path | Description |
| :--- | :--- | :--- |
| **API** | [`apps/api`](apps/api) | **Laravel** application serving the REST API, Authentication, and Job Queue. Acts as the control plane for users. |
| **Engine** | [`apps/engine`](apps/engine) | **Go** microservices cluster (History, Matching, Worker, Timer) responsible for high-performance workflow execution. |
| **Infrastructure** | [`infra`](infra) | Shared infrastructure definitions (PostgreSQL, Redis) and initialization scripts. |

## 🚀 Getting Started

### Prerequisites

-   **Docker** & **Docker Compose** (v2.20+ is required for `include` support)
-   **Go** 1.23+ (for engine development)
-   **PHP** 8.2+ & **Composer** (for API development)

### Quick Start with Make

We provide a unified `Makefile` to manage the entire stack effortlessly.

1.  **Initialize the Project**
    Installs dependencies for both PHP and Go applications.
    ```bash
    make setup
    ```

2.  **Start the Stack**
    Brings up the Infrastructure (Postgres/Redis), API, and all 8 Engine Microservices.
    ```bash
    make start
    ```
    > The stack will be available at:
    > - **API**: `http://localhost:8000`
    > - **Engine Frontend**: `http://localhost:8080`

3.  **Check Status**
    See all running services.
    ```bash
    make ps
    ```

3.  **Use the Application**
    - Access the API documentation or endpoints at `http://localhost:8000/api/documentation` (if configured) or standard API routes.
    - The Engine is headless but accepts gRPC/HTTP requests from the API.

4.  **Stop the Stack**
    ```bash
    make stop
    ```

## 🛠 Configuration

The project uses a root `.env` file to manage shared configuration across the monorepo.
A default `.env` is created during setup, but you should verify critical variables:

-   `LINKFLOW_SECRET`: A shared secret key used to secure callbacks from the Go Engine to the Laravel API.
-   `JWT_SECRET`: Secret key for signing authentication tokens.

## 📦 Microservices Overview

The **Go Engine** is composed of several specialized services:

-   **Frontend**: API Gateway and request router.
-   **History**: Event sourcing store and state management.
-   **Matching**: Task queuing and worker dispatching.
-   **Worker**: Executors for workflow nodes (HTTP, AI, etc.).
-   **Timer**: Scheduling and delayed execution.
-   **Visibility**: Search and listing capabilities.
-   **Control Plane**: Cluster management and configuration.

## 🧪 Testing

To run the test suites for both applications:

```bash
make test
```
This runs `go test ./...` for the engine and `php artisan test` for the API.
