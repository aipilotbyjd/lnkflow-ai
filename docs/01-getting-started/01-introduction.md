# Introduction to LinkFlow

**LinkFlow** is an open-source, high-performance workflow automation platform designed to handle complex business logic at scale. It combines the developer-friendly ecosystem of Laravel with the raw performance of Go.

## Key Features

- **Visual Workflow Builder**: Design complex automation flows with a drag-and-drop interface.
- **Hybrid Architecture**: 
  - **Control Plane (Laravel)**: Manages users, workspaces, and API interactions.
  - **Execution Plane (Go)**: Executes workflows with high concurrency and low latency.
- **Event-Driven**: Trigger workflows via webhooks, schedules (cron), or system events.
- **Resilient Execution**: Built-in retries, timeouts, and state persistence ensures reliability.
- **Extensible**: Add custom node types and integrations easily.
- **Scalable**: Horizontally scalable execution engine designed for high throughput.

## Use Cases

- **Data Pipeline Orchestration**: ETL processes, data synchronization.
- **Business Process Automation**: Approval workflows, onboarding sequences.
- **Integration Hub**: Connecting disparate SaaS applications.
- **Scheduled Tasks**: Replacing cron jobs with managed, visible workflows.
- **AI Agents**: Chaining LLM calls and tool execution.

## How It Works

1. **Design**: Create a workflow using the visual editor or API.
2. **Trigger**: An event (HTTP request, timer, etc.) initiates an execution.
3. **Execute**: The Go Engine processes the workflow graph, executing nodes in dependency order.
4. **Monitor**: Track execution status, inspect logs, and debug failures in real-time.

## Next Steps

- [Installation Guide](./02-installation.md)
- [Quick Start Tutorial](./03-quickstart.md)
- [Architecture Overview](../02-architecture/01-overview.md)
