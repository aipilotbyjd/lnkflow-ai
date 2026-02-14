# Slack Integration

Send notifications, alerts, or interactive messages to Slack.

## Setup

1.  Create a Slack App in your workspace.
2.  Enable **Incoming Webhooks**.
3.  Create a Webhook URL for a specific channel.
4.  Save this URL as a Credential in LinkFlow (`slack_webhook_url`).

## Sending a Message

Use the **HTTP Request** node:

-   **Method**: `POST`
-   **URL**: `{{ credential.slack_webhook_url }}`
-   **Body**:
    ```json
    {
      "text": "Workflow completed successfully!"
    }
    ```

## Advanced Formatting (Block Kit)

For rich messages, use the `blocks` array:

```json
{
  "blocks": [
    {
      "type": "section",
      "text": {
        "type": "mrkdwn",
        "text": "*New Lead Received*\n<https://example.com|View details>"
      }
    },
    {
      "type": "section",
      "fields": [
        {
          "type": "mrkdwn",
          "text": "*Name:*\nAlice Smith"
        },
        {
          "type": "mrkdwn",
          "text": "*Email:*\nalice@example.com"
        }
      ]
    }
  ]
}
```
