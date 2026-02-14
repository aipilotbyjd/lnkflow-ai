# Workflow Triggers

Triggers determine *when* a workflow runs. LinkFlow supports several trigger types.

## 1. Manual Trigger (`trigger_manual`)
-   **Use Case**: Testing, on-demand execution via UI button.
-   **Input**: JSON payload provided at runtime.

## 2. Webhook Trigger (`trigger_webhook`)
-   **Use Case**: Reacting to external events (Stripe payment, GitHub push).
-   **Configuration**:
    -   **Method**: GET, POST, PUT, DELETE.
    -   **Path**: Unique URL path (e.g., `/webhooks/{uuid}`).
-   **Payload**: The entire HTTP request body/headers are available as output.

## 3. Schedule Trigger (`trigger_schedule`)
-   **Use Case**: Periodic tasks (Daily report, Hourly sync).
-   **Configuration**: Cron expression (e.g., `0 0 * * *` for daily at midnight).
-   **Timezone**: Defaults to UTC.

## 4. Event Trigger (`trigger_event`)
-   **Use Case**: Internal system events (User Signed Up).
-   **Configuration**: Event name to listen for.
-   **Payload**: Event data payload.

## access Trigger Data

In subsequent nodes, you can access trigger data using expressions:

-   `{{ trigger.body }}` - The webhook body.
-   `{{ trigger.headers }}` - The webhook headers.
-   `{{ trigger.query }}` - The URL query parameters.
