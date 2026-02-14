# Managing Credentials

LinkFlow provides a secure vault for storing API keys, tokens, and passwords used in your workflows.

## Adding a Credential

1.  Navigate to **Settings > Credentials**.
2.  Click **New Credential**.
3.  **Name**: Give it a descriptive name (e.g., `Stripe Production`).
4.  **Type**: Select the service (e.g., `OpenAI`, `HTTP Header`, `Bearer Token`).
5.  **Value**: Paste the secret key.
6.  Click **Save**.

## Using Credentials in Workflows

In any node configuration field (URL, Headers, Body), use the double curly brace syntax:

```
{{ credential.my_stripe_key }}
```

At runtime, the Worker service resolves this variable, decrypts the value, and injects it into the request.

## Security

-   **Encryption**: Credentials are encrypted using AES-256-CBC before storage in the database.
-   **Decryption**: Only decrypted in memory during execution.
-   **Logs**: Credential values are automatically redacted from execution logs.
-   **Access**: Only Workspace Owners can view/edit credentials. Members can *use* them but not see the values.
