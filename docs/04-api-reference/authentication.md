# Authentication (Frontend Integration)

This document is the source of truth for integrating frontend clients with LinkFlow authentication.

## Overview

- Auth provider: Laravel Passport (OAuth2 password grant for first-party clients)
- API auth header: `Authorization: Bearer <access_token>`
- Token model: short-lived access token + longer-lived refresh token
- API base path: `/api/v1`

## Token Contract

Successful `register`, `login`, and `refresh` return:

```json
{
  "access_token": "<string>",
  "refresh_token": "<string>",
  "token_type": "Bearer",
  "expires_in": 900
}
```

Field notes:

- `access_token`: send this as Bearer token on authenticated requests.
- `refresh_token`: use this to get a new token pair from `/auth/refresh`.
- `token_type`: currently always `Bearer`.
- `expires_in`: access-token TTL in seconds.

Current backend defaults:

- Access token TTL: `15` minutes (`PASSPORT_ACCESS_TOKEN_TTL_MINUTES=15`)
- Refresh token TTL: `30` days (`PASSPORT_REFRESH_TOKEN_TTL_DAYS=30`)

Important:

- Refresh tokens are rotated. After successful refresh, the previous refresh token is revoked.
- Always replace stored tokens with the latest response from `/auth/refresh`.

## Endpoint Summary

| Method | Endpoint | Auth Required | Purpose |
| --- | --- | --- | --- |
| `POST` | `/auth/register` | No | Create user, issue token pair |
| `POST` | `/auth/login` | No | Authenticate user, issue token pair |
| `POST` | `/auth/refresh` | No | Exchange refresh token for new token pair |
| `POST` | `/auth/logout` | Yes | Revoke current access token and linked refresh tokens |
| `POST` | `/auth/forgot-password` | No | Send password reset email |
| `POST` | `/auth/reset-password` | No | Reset password with reset token |
| `GET` | `/verify-email/{id}/{hash}` | No (signed URL) | Verify user email |
| `POST` | `/auth/resend-verification-email` | Yes | Resend verification email |

## Request And Response Contracts

### Register

`POST /api/v1/auth/register`

Request body:

```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

Success (`201`):

```json
{
  "message": "User registered successfully. Please check your email to verify your account.",
  "user": {
    "id": "uuid",
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com",
    "avatar": null,
    "avatar_url": null,
    "email_verified_at": null,
    "created_at": "2026-02-10T00:00:00.000000Z",
    "updated_at": "2026-02-10T00:00:00.000000Z"
  },
  "access_token": "<token>",
  "refresh_token": "<token>",
  "token_type": "Bearer",
  "expires_in": 900
}
```

Validation failure (`422`): standard validation format.

Rare backend failure (`500`):

```json
{
  "message": "User registered successfully, but token issuance failed."
}
```

Frontend handling for this rare `500`:

- Treat account as created.
- Redirect user to login.
- Show message that signup succeeded and login is needed.

### Login

`POST /api/v1/auth/login`

Request body:

```json
{
  "email": "john@example.com",
  "password": "password123"
}
```

Success (`200`):

```json
{
  "message": "Login successful.",
  "user": {
    "id": "uuid",
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com",
    "avatar": null,
    "avatar_url": null,
    "email_verified_at": null,
    "created_at": "2026-02-10T00:00:00.000000Z",
    "updated_at": "2026-02-10T00:00:00.000000Z"
  },
  "access_token": "<token>",
  "refresh_token": "<token>",
  "token_type": "Bearer",
  "expires_in": 900
}
```

Invalid credentials (`401`):

```json
{
  "message": "Invalid credentials."
}
```

### Refresh

`POST /api/v1/auth/refresh`

Request body:

```json
{
  "refresh_token": "<refresh_token>"
}
```

Success (`200`):

```json
{
  "message": "Token refreshed successfully.",
  "access_token": "<new_access_token>",
  "refresh_token": "<new_refresh_token>",
  "token_type": "Bearer",
  "expires_in": 900
}
```

Invalid or expired refresh token (`401`):

```json
{
  "message": "Invalid or expired refresh token."
}
```

Validation failure (`422`) when `refresh_token` missing:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "refresh_token": [
      "Refresh token is required."
    ]
  }
}
```

### Logout

`POST /api/v1/auth/logout` with Bearer token.

Success (`200`):

```json
{
  "message": "Logged out successfully."
}
```

After logout:

- Current access token is revoked.
- All refresh tokens linked to that access token are revoked.
- Frontend should clear both local tokens.

### Forgot Password

`POST /api/v1/auth/forgot-password`

Request body:

```json
{
  "email": "john@example.com"
}
```

Success (`200`):

```json
{
  "message": "Password reset link sent to your email."
}
```

Validation failure (`422`):

- This endpoint currently validates `email` with `exists:users,email`.
- Unknown email returns validation error (`No account found with this email address.`).

### Reset Password

`POST /api/v1/auth/reset-password`

Request body:

```json
{
  "token": "<reset_token_from_email>",
  "email": "john@example.com",
  "password": "newpassword123",
  "password_confirmation": "newpassword123"
}
```

Success (`200`):

```json
{
  "message": "Password has been reset successfully."
}
```

Failure (`400`): reset token invalid/expired or broker failure.

### Verify Email

`GET /api/v1/verify-email/{id}/{hash}` with signed URL from email.

Responses:

- `200` with success/already verified message
- `400` for invalid verification link

### Resend Verification Email

`POST /api/v1/auth/resend-verification-email` with Bearer token.

Responses:

- `200` with `Verification email sent.`
- `400` with `Email already verified.`

## Required Headers

For JSON requests:

```http
Accept: application/json
Content-Type: application/json
```

For protected routes:

```http
Authorization: Bearer <access_token>
```

## Frontend Session Strategy

### 1. On Login/Register

- Save `access_token`, `refresh_token`, and `expires_in`.
- Calculate and store `access_token_expires_at = now + expires_in`.

### 2. Before API Calls

- Add `Authorization` header with current access token.

### 3. Refresh Policy

Use both:

- Proactive refresh: refresh 30-60 seconds before access token expiry.
- Reactive refresh: on `401` from protected routes, attempt one refresh then retry original request once.

### 4. Concurrency Control

- Only allow one refresh request at a time.
- Queue/wait other failed requests until refresh completes.
- If refresh fails, clear session and route to login.

### 5. Logout

- Call `/auth/logout` (if access token exists).
- Clear local auth state regardless of API result.

## Axios-Style Example (Pseudo-Code)

```ts
let refreshPromise: Promise<void> | null = null;

async function refreshTokens(): Promise<void> {
  if (!refreshPromise) {
    refreshPromise = (async () => {
      const res = await api.post("/auth/refresh", {
        refresh_token: authStore.refreshToken,
      });
      authStore.setTokens(res.data);
    })().finally(() => {
      refreshPromise = null;
    });
  }

  return refreshPromise;
}

api.interceptors.request.use(async (config) => {
  if (authStore.accessToken) {
    config.headers.Authorization = `Bearer ${authStore.accessToken}`;
  }
  return config;
});

api.interceptors.response.use(
  (res) => res,
  async (error) => {
    const status = error?.response?.status;
    const original = error.config;
    const isAuthRoute =
      original?.url?.includes("/auth/login") ||
      original?.url?.includes("/auth/register") ||
      original?.url?.includes("/auth/refresh");

    if (status === 401 && !original._retry && !isAuthRoute && authStore.refreshToken) {
      original._retry = true;
      try {
        await refreshTokens();
        original.headers.Authorization = `Bearer ${authStore.accessToken}`;
        return api.request(original);
      } catch {
        authStore.clear();
        redirectToLogin();
      }
    }

    throw error;
  }
);
```

## Error Handling Matrix

| Scenario | Status | Frontend Action |
| --- | --- | --- |
| Invalid login credentials | `401` | Show form error |
| Access token expired | `401` on protected route | Attempt refresh once |
| Refresh token invalid/expired | `401` on `/auth/refresh` | Clear session, force login |
| Validation errors | `422` | Map `errors.<field>[]` to form fields |
| Auth missing | `401` | Route to login |
| Backend auth failure | `500` | Show generic error + retry option |

## FE Handoff Checklist

- Login stores both tokens.
- Register stores both tokens.
- Refresh updates both tokens (token rotation safe).
- Single-flight refresh implemented.
- Failed refresh clears auth state.
- Logout clears auth state and calls API.
- Forms map `422` error payloads.
- Protected requests always send Bearer access token.

## Related Docs

- OpenAPI spec: `docs/04-api-reference/openapi.yaml`
- Postman collection: `docs/04-api-reference/postman/LinkFlow_API.postman_collection.json`
- API errors: `docs/04-api-reference/errors.md`
- Rate limits: `docs/04-api-reference/rate-limits.md`
