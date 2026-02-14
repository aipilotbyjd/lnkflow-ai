# OAuth 2.0 Setup

Many integrations (Google Sheets, Slack, Salesforce) require OAuth 2.0. LinkFlow handles the complexity of the handshake and token refreshing.

## Adding an OAuth App

1.  Go to **Settings > App Integrations**.
2.  Click **Add Integration**.
3.  **Client ID**: From the provider's developer portal.
4.  **Client Secret**: From the provider.
5.  **Scopes**: Space-separated list of permissions (e.g., `https://www.googleapis.com/auth/spreadsheets`).
6.  **Auth URL**: `https://accounts.google.com/o/oauth2/auth`
7.  **Token URL**: `https://oauth2.googleapis.com/token`

## Connecting an Account

1.  Go to **Settings > Credentials**.
2.  Click **New Credential**.
3.  Type: **OAuth 2.0**.
4.  Select the Integration you created above.
5.  Click **Connect**.
6.  You will be redirected to the provider to grant permission.
7.  LinkFlow stores the `access_token` and `refresh_token`.

## Automatic Refreshing

The Worker service automatically checks token expiration. If a token is expired, it uses the refresh token to get a new one before executing the node. You don't need to handle this logic.
