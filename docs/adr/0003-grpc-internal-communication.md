# ADR 0003: gRPC for Internal Communication

## Status
Accepted

## Date
2026-02-07

## Context
The Execution Plane consists of multiple microservices (Frontend, History, Matching, Worker) that need to communicate with high frequency and low latency.
Using HTTP/REST/JSON for internal traffic would introduce:
- Higher serialization/deserialization overhead.
- Lack of strong type safety between services.
- No support for streaming (needed for long-running watches).

## Decision
We will use **gRPC** with **Protocol Buffers (Protobuf)** for all synchronous inter-service communication within the Engine.

-   **IDL**: Service interfaces defined in `.proto` files.
-   **Transport**: HTTP/2.
-   **Serialization**: Binary Protobuf.

## Consequences

### Positive
-   **Performance**: Much faster than JSON over HTTP/1.1.
-   **Type Safety**: Code generation ensures clients and servers strictly adhere to the contract.
-   **Streaming**: Native support for bi-directional streaming.
-   **Polyglot**: Easy to generate clients if we add services in other languages (e.g., Python AI workers).

### Negative
-   **Debuggability**: Binary format is harder to inspect than JSON (requires tools like `grpcurl`).
-   **Complexity**: Requires build step to generate code (`buf generate`).
-   **Load Balancing**: Requires L7 load balancing (HTTP/2) awareness.

### Mitigations
-   Use `grpcurl` and `grpcui` for debugging.
-   Automate protobuf generation in `Makefile`.
-   Use Envoy or gRPC-aware proxies for load balancing.
