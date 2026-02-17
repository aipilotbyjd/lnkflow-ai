# Conversational Trigger

## Status
🟡 Planned

## Priority
Low

## Difficulty
Hard

## Category
🤖 AI-Native

## Summary
Allow users to trigger and interact with workflows via a conversational chat interface. Users send messages like "Run the daily report workflow for last month" or "Check the status of order #12345" and the system identifies the correct workflow, extracts parameters, executes it, and returns the results in a conversational format.

## Problem Statement
Triggering workflows today requires either the API, a webhook, a scheduled cron, or the UI. For ad-hoc tasks, users must navigate to the workflow, fill in parameters, and click execute. A conversational interface (embeddable in Slack, Teams, or a web chat) makes workflow execution as easy as sending a message — ideal for operations teams and non-technical users.

## Proposed Solution
1. Build a chat endpoint that accepts natural language messages.
2. Use an LLM to match the message to available workflows using workflow names, descriptions, tags, and AI-generated summaries.
3. Extract execution parameters from the message.
4. Confirm the action with the user before executing.
5. Execute the workflow via `WorkflowDispatchService`.
6. Stream execution progress (using idea #03 Live Execution Streaming).
7. Return the execution result in a conversational format.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/ConversationalTriggerController.php`
- **API — New Service:** `apps/api/app/Services/ConversationalTriggerService.php`
- **API — Existing Service:** `apps/api/app/Services/WorkflowDispatchService.php` — for triggering execution
- **API — Existing Models:** `Workflow`, `Execution`, `Tag`, `WorkflowTemplate`
- **Engine — Existing:** `apps/engine/internal/frontend/` — receives execution requests
- **Engine — Existing:** `apps/engine/internal/matching/` — queues execution tasks

## API Endpoints

```
POST   /api/v1/workspaces/{workspace}/chat
  Body: {
    "message": "Run the daily sales report for January 2026",
    "conversation_id": "uuid"  -- optional, for multi-turn conversations
  }
  Response: {
    "reply": "I found the 'Daily Sales Report' workflow. I'll run it with the date range January 1-31, 2026. Should I proceed?",
    "conversation_id": "uuid",
    "action": {
      "type": "confirm_execution",
      "workflow_id": "uuid",
      "workflow_name": "Daily Sales Report",
      "parameters": { "start_date": "2026-01-01", "end_date": "2026-01-31" }
    }
  }

POST   /api/v1/workspaces/{workspace}/chat
  Body: {
    "message": "Yes, go ahead",
    "conversation_id": "uuid"
  }
  Response: {
    "reply": "Executing 'Daily Sales Report'... ✅ Completed in 3.2 seconds. The report shows $45,230 in total sales across 142 transactions.",
    "execution_id": "uuid",
    "conversation_id": "uuid"
  }

GET    /api/v1/workspaces/{workspace}/chat/history
  Query: ?conversation_id=uuid
  Response: paginated list of messages
```

## Data Model

### New table: `chat_conversations`
```sql
CREATE TABLE chat_conversations (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status          VARCHAR(20) NOT NULL DEFAULT 'active',   -- active, closed
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_chat_conv_workspace ON chat_conversations(workspace_id);
CREATE INDEX idx_chat_conv_user ON chat_conversations(user_id);
```

### New table: `chat_messages`
```sql
CREATE TABLE chat_messages (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    conversation_id UUID NOT NULL REFERENCES chat_conversations(id) ON DELETE CASCADE,
    role            VARCHAR(10) NOT NULL,            -- 'user', 'assistant'
    content         TEXT NOT NULL,
    action          JSONB,                           -- pending action (confirm_execution, etc.)
    execution_id    UUID REFERENCES executions(id) ON DELETE SET NULL,
    model_used      VARCHAR(50),
    tokens_used     INTEGER,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_chat_msg_conversation ON chat_messages(conversation_id);
```

## Implementation Steps
1. Create migrations for `chat_conversations` and `chat_messages` tables.
2. Create `ChatConversation` and `ChatMessage` models.
3. Build `ConversationalTriggerService` with methods: `processMessage()`, `matchWorkflow()`, `extractParameters()`, `formatResult()`.
4. Implement `matchWorkflow()` by querying `Workflow` model with name/description/tag matching, using LLM for fuzzy matching.
5. Implement `extractParameters()` using LLM to parse date ranges, IDs, and other parameters from the message.
6. Implement confirmation flow: store pending action in `ChatMessage.action` JSON column, execute on confirmation.
7. Integrate with `WorkflowDispatchService::dispatch()` for execution.
8. Format execution results as conversational responses.
9. Create controller and routes.
10. Add Slack/Teams integration endpoints (future — via webhook adapters).
11. Write feature tests for: workflow matching, parameter extraction, confirmation flow, multi-turn conversations.

## Dependencies
- `WorkflowDispatchService` — for triggering workflows.
- `Workflow` model with `Tag` relationships — for matching.
- Live Execution Streaming (idea #03) — for real-time progress in chat.
- External LLM API key.

## Success Metrics
- **Adoption:** 15% of workflow executions triggered via chat within 6 months.
- **Matching accuracy:** 90% of workflow matches are correct on first attempt.
- **User satisfaction:** Chat users report higher satisfaction than UI-only users.

## Estimated Effort
4 weeks (1 senior backend engineer)
- Week 1: Conversation model, message processing pipeline
- Week 2: Workflow matching, parameter extraction
- Week 3: Execution integration, result formatting
- Week 4: Multi-turn conversations, testing
