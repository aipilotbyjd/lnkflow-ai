# HTTP Request Node

The **HTTP Request** node is the most versatile action in LinkFlow. It allows you to make API calls to any external service.

## Configuration

| Field | Description | Required | Example |
|-------|-------------|----------|---------|
| **URL** | The endpoint to call | Yes | `https://api.stripe.com/v1/charges` |
| **Method** | HTTP Verb | Yes | `GET`, `POST`, `PUT`, `DELETE` |
| **Headers** | Key-value pairs | No | `Authorization: Bearer {{ credential.token }}` |
| **Body** | Request payload (JSON) | No | `{"amount": 100, "currency": "usd"}` |
| **Timeout** | Max duration (seconds) | No | `30` (default) |

## Using Credentials

Securely inject API keys using the `{{ credential.name }}` syntax. Never hardcode secrets in the URL or Body.

## Output

The node returns a JSON object:

```json
{
  "status": 200,
  "headers": {
    "content-type": "application/json"
  },
  "data": {
    "id": "ch_123456",
    "amount": 100
  }
}
```

## Error Handling

-   **4xx/5xx Responses**: By default, the workflow stops on error. Enable "Continue on Error" in node settings to handle failures gracefully.
-   **Timeouts**: Retries are attempted automatically (default 3 times with exponential backoff).
