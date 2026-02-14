# Webhooks

Webhooks allow external systems to trigger your workflows in real-time.

## Creating a Webhook Trigger

1.  Add a **Webhook Trigger** node to your workflow.
2.  Select the **Method** (usually `POST`).
3.  Save and Activate the workflow.
4.  Copy the URL: `https://api.linkflow.io/webhooks/{uuid}`.

## Security

### 1. Secret Token (Recommended)
Add a query parameter or header to verify the source.
-   URL: `.../webhooks/{uuid}?token=my-secret`
-   In workflow: Add **If/Else** node to check `{{ trigger.query.token }} == 'my-secret'`.

### 2. Signature Verification
For providers like Stripe or GitHub that sign requests:
1.  Get the signature from headers (`Stripe-Signature`).
2.  Get the raw body.
3.  Use a **Code Node** to verify the signature (HMAC-SHA256).

## Testing

You can use `curl` to test your webhook:

```bash
curl -X POST https://your-linkflow-instance/webhooks/1234-5678 \
  -H "Content-Type: application/json" \
  -d '{"event": "test", "data": 123}'
```

The response will be `200 OK` with the `execution_id` if successful.
