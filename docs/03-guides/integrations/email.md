# Email Integration

Send transactional emails from your workflows.

## SMTP

The most common method is using a standard SMTP server (SendGrid, Mailgun, AWS SES).

1.  **Host**: `smtp.sendgrid.net`
2.  **Port**: `587`
3.  **Username**: `apikey`
4.  **Password**: Your API Key.

Save these as credentials.

## Using the Email Node

LinkFlow has a dedicated **Send Email** node (if configured) or you can use an HTTP Request to your provider's API.

### HTTP Method (SendGrid Example)

-   **Method**: `POST`
-   **URL**: `https://api.sendgrid.com/v3/mail/send`
-   **Headers**:
    -   `Authorization`: `Bearer {{ credential.sendgrid_api_key }}`
    -   `Content-Type`: `application/json`
-   **Body**:
    ```json
    {
      "personalizations": [
        { "to": [{ "email": "customer@example.com" }] }
      ],
      "from": { "email": "noreply@yourcompany.com" },
      "subject": "Your Order #{{ trigger.body.order_id }}",
      "content": [
        { "type": "text/plain", "value": "Thanks for your order!" }
      ]
    }
    ```
