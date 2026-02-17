# AI Auto-Fix on Failure

## Status
🟡 Planned

## Priority
High

## Difficulty
Hard

## Category
🤖 AI-Native

## Summary
When a workflow execution fails, automatically analyze the error context (failed node, input data, error message, execution logs) and suggest or apply a fix. This turns LinkFlow from a passive executor into an intelligent self-healing system — something no competitor offers.

## Problem Statement
When workflows fail, users must manually inspect execution logs, understand the error, and figure out the fix. Common failures include malformed data mappings, expired credentials, API schema changes, and rate limits. This is time-consuming and requires technical knowledge. n8n and Zapier simply show the error and leave the user to figure it out. An AI auto-fix system would drastically reduce mean-time-to-resolution (MTTR).

## Proposed Solution
Build an AI analysis pipeline triggered on execution failure that:
1. Captures the full failure context from `Execution`, `ExecutionNode`, and `ExecutionLog` models.
2. Uses `TimeTravelDebuggerService` to reconstruct the data state at the point of failure.
3. Sends the context to an LLM with workflow-specific system prompts.
4. Returns one or more fix suggestions, each with a confidence score and a "patch" — a JSON diff that can be applied to the workflow definition.
5. Optionally auto-applies high-confidence fixes (>0.95) if the user has enabled auto-fix in `WorkspacePolicy`.
6. Creates an `ExecutionRunbook` entry via `RunbookService` with the diagnosis and fix.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/AiAutoFixController.php`
- **API — New Service:** `apps/api/app/Services/AiAutoFixService.php`
- **API — New Job:** `apps/api/app/Jobs/AnalyzeFailedExecution.php`
- **API — Existing Services:** `TimeTravelDebuggerService`, `RunbookService`, `DeterministicReplayService`
- **API — Existing Models:** `Execution`, `ExecutionNode`, `ExecutionLog`, `ExecutionRunbook`, `Workflow`, `WorkspacePolicy`
- **Engine — Existing:** `apps/engine/internal/callback/` — already sends failure callbacks to Laravel API
- **Engine — Existing:** `apps/engine/internal/observability/` — provides execution telemetry

## API Endpoints

```
POST   /api/v1/workspaces/{workspace}/executions/{execution}/ai/analyze
  Body: { "auto_apply": false }
  Response: { "diagnosis": "string", "suggestions": [{ "description": "string", "confidence": 0.87, "patch": {} }] }

POST   /api/v1/workspaces/{workspace}/executions/{execution}/ai/apply-fix
  Body: { "suggestion_index": 0 }
  Response: { "workflow_version": WorkflowVersionResource, "applied_patch": {} }

GET    /api/v1/workspaces/{workspace}/ai/fix-history
  Query: ?workflow_id=uuid
  Response: paginated list of past fixes

PUT    /api/v1/workspaces/{workspace}/policy
  Body: { "ai_auto_fix_enabled": true, "ai_auto_fix_confidence_threshold": 0.95 }
```

## Data Model

### New table: `ai_fix_suggestions`
```sql
CREATE TABLE ai_fix_suggestions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    execution_id    UUID NOT NULL REFERENCES executions(id) ON DELETE CASCADE,
    workflow_id     UUID NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    failed_node_key VARCHAR(100) NOT NULL,
    error_message   TEXT NOT NULL,
    diagnosis       TEXT NOT NULL,
    suggestions     JSONB NOT NULL,               -- array of {description, confidence, patch}
    applied_index   SMALLINT,                     -- which suggestion was applied, null if none
    model_used      VARCHAR(50) NOT NULL,
    tokens_used     INTEGER NOT NULL DEFAULT 0,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending, applied, dismissed
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_ai_fix_workspace ON ai_fix_suggestions(workspace_id);
CREATE INDEX idx_ai_fix_execution ON ai_fix_suggestions(execution_id);
CREATE INDEX idx_ai_fix_workflow ON ai_fix_suggestions(workflow_id);
```

### Extend `workspace_policies` table:
```sql
ALTER TABLE workspace_policies
    ADD COLUMN ai_auto_fix_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN ai_auto_fix_confidence_threshold DECIMAL(3,2) NOT NULL DEFAULT 0.95;
```

## Implementation Steps
1. Create migration for `ai_fix_suggestions` table and `workspace_policies` extension.
2. Create `AiFixSuggestion` model with relationships to `Execution`, `Workflow`, `Workspace`.
3. Build `AiAutoFixService` with methods: `analyze()`, `applyFix()`, `buildFailureContext()`.
4. Implement `buildFailureContext()` using `TimeTravelDebuggerService::snapshot()` and `ExecutionLog` queries.
5. Build `AnalyzeFailedExecution` job, dispatched from `JobCallbackController` when execution status is `failed`.
6. Add conditional auto-dispatch: only queue the job if `WorkspacePolicy.ai_auto_fix_enabled` is true.
7. Implement `applyFix()` to create a new `WorkflowVersion` with the patched definition.
8. Integrate with `RunbookService` to create an `ExecutionRunbook` for every AI diagnosis.
9. Create controller, form requests, and routes.
10. Add feature tests covering: successful analysis, auto-apply with high confidence, manual apply, dismissal.
11. Add rate limiting: max 50 analyses per workspace per day.

## Dependencies
- `TimeTravelDebuggerService` — for reconstructing failure state.
- `RunbookService` — for creating runbook entries.
- `DeterministicReplayService` — for replaying fixed workflows.
- `WorkspacePolicy` model — for auto-fix settings.
- External LLM API key.

## Success Metrics
- **MTTR reduction:** 60% reduction in mean-time-to-resolution for failed workflows.
- **Auto-fix success rate:** 70%+ of auto-applied fixes result in successful re-execution.
- **User trust:** Fix suggestions accepted by users 50%+ of the time.
- **Support ticket reduction:** 30% fewer support requests related to workflow debugging.

## Estimated Effort
4–5 weeks (1 senior backend engineer)
- Week 1: Data model, service architecture, failure context builder
- Week 2: LLM integration, prompt engineering, suggestion generation
- Week 3: Auto-apply pipeline, version creation, replay integration
- Week 4: Controller, routes, runbook integration
- Week 5: Testing, edge cases, confidence calibration
