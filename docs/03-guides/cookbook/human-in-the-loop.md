# Recipe: Human-in-the-Loop

Automate the process, but keep a human in control for critical decisions.

## Scenario
A user submits a high-value expense report (> $1000). It requires manager approval before processing payment.

## Workflow Steps

### 1. Trigger
-   **Type**: Webhook (from Expense System)
-   **Payload**: `{"amount": 1500, "employee": "Alice"}`

### 2. Check Amount
-   **Node**: **If/Else**
-   **Condition**: `{{ trigger.body.amount }} > 1000`

### 3. Request Approval (True Path)
-   **Node**: **Wait for Event** (or "Pause" node)
-   **Event Name**: `expense_approval`
-   **Key**: `{{ trigger.body.report_id }}`
-   **Timeout**: 7 days.

### 4. Notify Manager (Parallel)
-   **Node**: **Send Email** / **Slack**
-   **Body**: "Please approve expense #{{ ... }}. Click here: https://linkflow.app/approve/..."
    -   *The approval link triggers an API call to resume the workflow.*

### 5. Resume & Decision
-   The workflow sleeps until the manager clicks "Approve" or "Reject".
-   The `Wait` node outputs the decision payload.

### 6. Final Action
-   **Node**: **If/Else** (Approved?)
    -   **True**: HTTP Request -> Pay.
    -   **False**: Email -> "Rejected".

## How to Resume
To approve the request, your external system (or a simple UI) calls:
```http
POST /api/v1/events/expense_approval
{
  "key": "report_123",
  "data": { "decision": "approved" }
}
```
