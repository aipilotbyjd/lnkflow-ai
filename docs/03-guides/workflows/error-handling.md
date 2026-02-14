# Error Handling

Failures happen—APIs go down, quotas are exceeded, data is malformed. LinkFlow provides mechanisms to handle these gracefully.

## Node-Level Error Handling

Every action node has an "Error Handling" tab in its configuration.

### 1. Continue on Error
By default, a workflow stops immediately if a node fails (e.g., HTTP 500).
If you enable **Continue on Error**, the workflow proceeds to the next node. The failed node's output will contain:
```json
{
  "status": "error",
  "error": "Timeout waiting for response"
}
```
You can then use an **If/Else Node** to check `{{ node.status }} == 'error'` and handle it.

### 2. Retries
Transient errors (like network blips) can often be fixed by retrying.
-   **Max Attempts**: How many times to try (default: 3).
-   **Backoff**: How long to wait between attempts (exponential backoff is used).

## Global Error Handling (Coming Soon)

We are working on a global "Catch" block that can trigger a specific set of actions (like sending an alert to Slack) whenever *any* node in the workflow fails.

## Best Practices

-   **Always validate critical data**: Use If/Else nodes to ensure required fields exist before using them.
-   **Set timeouts**: Don't let a workflow hang indefinitely.
-   **Alerting**: If a critical workflow fails, make sure you send a notification (Email/Slack) so a human knows.
