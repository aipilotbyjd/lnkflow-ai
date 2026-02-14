# Recipe: Fan-Out / Fan-In

This pattern is useful when you need to process a list of items (e.g., users, orders) in parallel and then combine the results.

## Scenario
You receive a webhook with a list of 100 User IDs. You need to:
1.  Fetch details for each user from an external API (Fan-Out).
2.  Send a summary email with all the results (Fan-In).

## Workflow Steps

### 1. Trigger
-   **Type**: Webhook
-   **Payload**: `{"user_ids": [1, 2, 3, ...]}`

### 2. Fan-Out (Loop)
-   **Node**: **Loop Node** (Iterator)
-   **Input**: `{{ trigger.body.user_ids }}`
-   **Concurrency**: Set to 5 or 10 (executes in parallel).

### 3. Process (Inside Loop)
-   **Node**: **HTTP Request**
-   **URL**: `https://api.example.com/users/{{ loop.item }}`
-   **Output**: Returns user profile JSON.

### 4. Fan-In (Aggregation)
-   **Node**: **Code Node** (or dedicated Aggregate Node)
-   **Input**: `{{ loop.output }}`
-   **Logic**:
    ```javascript
    // Combine all results into a CSV string
    return $input.map(u => `${u.name},${u.email}`).join("\n");
    ```

### 5. Final Action
-   **Node**: **Send Email**
-   **Body**: "Here is the report:\n\n{{ aggregate_node.result }}"

## Key Concepts
-   **Loop Node**: Handles the splitting of the array.
-   **Aggregation**: Wait for all iterations to complete before proceeding to the next step (after the loop block).
