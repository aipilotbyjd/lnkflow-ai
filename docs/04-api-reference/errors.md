# Errors

The LinkFlow API uses standard HTTP status codes to indicate the success or failure of a request.

## HTTP Status Codes

| Code | Description | Meaning |
|------|-------------|---------|
| `200` | OK | Request succeeded. |
| `201` | Created | Resource created successfully. |
| `202` | Accepted | Request accepted for processing (async). |
| `204` | No Content | Request succeeded, no body returned. |
| `400` | Bad Request | Invalid input or malformed JSON. |
| `401` | Unauthorized | Missing or invalid authentication token. |
| `403` | Forbidden | Authenticated, but insufficient permissions. |
| `404` | Not Found | Resource does not exist. |
| `422` | Validation Error | Input failed validation rules. |
| `429` | Too Many Requests | Rate limit exceeded. |
| `500` | Server Error | Internal system error. |

## Error Response Format

All error responses follow a consistent JSON format:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "The email field is required."
    ]
  }
}
```

-   **message**: A human-readable summary.
-   **errors**: (Optional) Detailed field-level errors (for 422).
