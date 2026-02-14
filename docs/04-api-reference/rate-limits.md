# Rate Limiting

To ensure stability, the API employs rate limiting on a per-user/per-IP basis.

## Limits

| Endpoint Scope | Limit | Window |
|----------------|-------|--------|
| **Global** | 60 | 1 minute |
| **Auth (Login/Register)** | 5 | 1 minute |
| **Workflow Execution** | 1000 | 1 minute |

*Note: Limits may vary based on your subscription plan.*

## Headers

We provide headers to help you track your usage:

| Header | Description |
|--------|-------------|
| `X-RateLimit-Limit` | The maximum number of requests allowed in the window. |
| `X-RateLimit-Remaining` | The number of requests you have left. |
| `X-RateLimit-Reset` | The Unix timestamp when the window resets. |

## Handling 429 Errors

If you exceed the limit, you will receive a `429 Too Many Requests` response.

```json
{
  "message": "Too Many Requests",
  "retry_after": 58
}
```

Your client should respect the `retry_after` field (in seconds) before making new requests.
