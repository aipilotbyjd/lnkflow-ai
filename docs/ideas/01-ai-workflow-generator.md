# AI Workflow Generator

## Status
🟢 Implemented

## Priority
High

## Difficulty
Hard

## Category
🤖 AI-Native

## Summary
Allow users to describe a workflow in natural language (e.g., "When a new row is added to Google Sheets, send the data to Slack and create a Jira ticket") and have an LLM generate a complete, valid workflow definition. This is a signature differentiator over n8n and Zapier, which require manual drag-and-drop construction.

## Problem Statement
Building workflows today requires knowledge of available node types, their configuration options, data mapping between nodes, and expression syntax. New users face a steep learning curve, and even experienced users spend time on routine plumbing. No competing platform offers AI-powered workflow generation from natural language — this would be a first-of-its-kind feature that dramatically reduces time-to-value.

## Proposed Solution
Introduce an AI generation pipeline that:
1. Accepts a natural language prompt from the user.
2. Retrieves the available node catalog (from `Node` and `NodeCategory` models) and the user's connected credentials (from `Credential` model) for context.
3. Sends a structured prompt to an LLM (OpenAI GPT-4 / Anthropic Claude) with the node catalog as function definitions.
4. Receives a workflow JSON definition matching the `Workflow` model schema (nodes array, edges array, trigger config).
5. Validates the generated definition against `WorkflowContractSnapshot` schema using `ContractCompilerService`.
6. Returns the workflow to the user for review and optional editing before saving.

## Architecture
Where in the codebase this would be implemented:

- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/AiWorkflowGeneratorController.php`
- **API — New Service:** `apps/api/app/Services/AiWorkflowGeneratorService.php`
- **API — New Form Request:** `apps/api/app/Http/Requests/GenerateWorkflowRequest.php`
- **API — New Resource:** `apps/api/app/Http/Resources/GeneratedWorkflowResource.php`
- **API — Existing Models:** `Workflow`, `Node`, `NodeCategory`, `Credential`, `WorkflowContractSnapshot`
- **API — Existing Services:** `ContractCompilerService` (for validation), `WorkflowDispatchService` (for optional auto-execute)
- **API — Routes:** `apps/api/routes/api.php` — new route group under workspace scope
- **Engine:** No engine changes needed for generation; engine executes the resulting workflow normally via `frontend` → `matching` → `worker`

## API Endpoints

```
POST   /api/v1/workspaces/{workspace}/ai/generate-workflow
  Body: { "prompt": "string", "credential_ids": ["uuid"], "options": { "dry_run": bool } }
  Response: { "workflow": WorkflowResource, "explanation": "string", "confidence": 0.92 }

POST   /api/v1/workspaces/{workspace}/ai/refine-workflow
  Body: { "workflow_id": "uuid", "feedback": "string" }
  Response: { "workflow": WorkflowResource, "changes": ["string"] }

GET    /api/v1/workspaces/{workspace}/ai/generation-history
  Response: paginated list of past generations

DELETE /api/v1/workspaces/{workspace}/ai/generation-history/{id}
```

## Data Model

### New table: `ai_generation_logs`
```sql
CREATE TABLE ai_generation_logs (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    prompt          TEXT NOT NULL,
    generated_json  JSONB NOT NULL,
    model_used      VARCHAR(50) NOT NULL,          -- 'gpt-4', 'claude-3-opus'
    tokens_used     INTEGER NOT NULL DEFAULT 0,
    confidence      DECIMAL(3,2),
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',  -- draft, accepted, rejected
    workflow_id     UUID REFERENCES workflows(id) ON DELETE SET NULL,
    feedback        TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_ai_gen_logs_workspace ON ai_generation_logs(workspace_id);
CREATE INDEX idx_ai_gen_logs_user ON ai_generation_logs(user_id);
```

### New model: `AiGenerationLog`

## Implementation Steps
1. Add `OPENAI_API_KEY` and `AI_MODEL` to `.env.example` and `apps/api/.env.docker.example`.
2. Install `openai-php/laravel` package in `apps/api/`.
3. Create migration for `ai_generation_logs` table.
4. Create `AiGenerationLog` model with workspace/user relationships.
5. Build `AiWorkflowGeneratorService` with methods: `generate()`, `refine()`, `buildPrompt()`.
6. Implement `buildPrompt()` to fetch node catalog from `Node::query()` and format as function definitions.
7. Implement `generate()` to call LLM API, parse response, validate against contract schema.
8. Create `AiWorkflowGeneratorController` with `generate`, `refine`, `history`, `destroyHistory` actions.
9. Create `GenerateWorkflowRequest` form request with validation rules.
10. Register routes under workspace-scoped group in `api.php`.
11. Add rate limiting middleware specific to AI endpoints (e.g., 20 generations/hour per workspace).
12. Write feature tests for generation flow, validation, and edge cases.
13. Add billing integration — count AI generations against the workspace plan using `Subscription` model.

## Dependencies
- Node catalog must be populated (`Node`, `NodeCategory` models).
- `ContractCompilerService` for validating generated output.
- External LLM API key (OpenAI or Anthropic).
- `Subscription` / `Plan` models for usage limits.

## Success Metrics
- **Adoption:** 30% of new workflows created via AI generation within 3 months.
- **Accuracy:** 80%+ of generated workflows execute successfully without manual edits.
- **Time savings:** Average workflow creation time reduced from 15 minutes to 2 minutes.
- **Retention:** Users who use AI generation have 2x higher 30-day retention.

## Estimated Effort
3–4 weeks (1 senior backend engineer)
- Week 1: Service architecture, LLM integration, prompt engineering
- Week 2: Controller, routes, form request, validation pipeline
- Week 3: Refinement loop, generation history, billing integration
- Week 4: Testing, edge cases, prompt tuning
