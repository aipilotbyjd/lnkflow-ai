# Recipe: Chatbot Chain

Build a smart conversational agent by chaining multiple AI steps.

## Scenario
A customer support bot that first classifies the intent, then looks up relevant knowledge, and finally generates a polite response.

## Workflow Steps

### 1. Trigger
-   **Type**: Webhook (from Chat Widget)
-   **Payload**: `{"message": "How do I reset my password?"}`

### 2. Classify Intent
-   **Node**: **OpenAI (GPT-3.5)**
-   **Prompt**:
    ```
    Classify the following message into: Billing, Technical, or General.
    Message: {{ trigger.body.message }}
    Output just the category.
    ```

### 3. Route (Switch)
-   **Node**: **If/Else** based on intent.
    -   *If Technical*: Search Docs.
    -   *If Billing*: Search Invoice DB.

### 4. Context Retrieval (Technical Path)
-   **Node**: **Vector DB Query** (or HTTP Search)
-   **Query**: `{{ trigger.body.message }}`
-   **Output**: Top 3 relevant documentation snippets.

### 5. Generate Response
-   **Node**: **OpenAI (GPT-4)**
-   **Prompt**:
    ```
    You are a helpful support agent.
    User Question: {{ trigger.body.message }}
    Context: {{ search_docs.results }}
    
    Answer the question using ONLY the provided context.
    ```

### 6. Reply
-   **Node**: **HTTP Response** (or Webhook back to Chat Widget)
-   **Body**: `{{ generate_response.text }}`

## Advanced: Memory
To add memory (history), you would need to:
1.  Fetch previous chat history from a database (using a DB Node) at the start.
2.  Inject `{{ history }}` into the prompt in Step 5.
3.  Save the new Q&A pair back to the DB at the end.
