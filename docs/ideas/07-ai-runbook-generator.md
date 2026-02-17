# AI Runbook Generator

## Status
🟡 Planned

## Priority
Medium

## Difficulty
Medium

## Category
🤖 AI-Native

## Summary
Automatically generate operational runbooks for workflows using AI. When a workflow is created or updated, analyze its nodes, integrations, and potential failure modes to produce a comprehensive runbook with troubleshooting steps, escalation procedures, and recovery actions. Builds on the existing `RunbookService` and `ExecutionRunbook` model.

## Problem Statement
The existing `RunbookService` creates runbook entries reactively when executions fail. But proactive runbooks — documenting expected failure modes and remediation steps before they happen — don't exist yet. Operations teams need pre-built runbooks for critical workflows to reduce incident response time. Manually writing runbooks is tedious and often skipped.

## Proposed Solution
1. Analyze the workflow definition to identify all external dependencies (HTTP endpoints, APIs, databases, services).
2. For each dependency, generate common failure scenarios (timeout, auth failure, rate limit, schema change).
3. Cross-reference with `ConnectorCallAttempt` and `ConnectorMetricDaily` data to identify historically problematic integrations.
4. Use an LLM to generate structured runbook content: description, symptoms, diagnosis steps, remediation actions.
5. Store as `ExecutionRunbook` entries linked to the workflow (not a specific execution).

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/AiRunbookController.php`
- **API — New Service:** `apps/api/app/Services/AiRunbookGeneratorService.php`
- **API — Existing Service:** `apps/api/app/Services/RunbookService.php` — extend with AI-generated content
- **API — Existing Service:** `apps/api/app/Services/ConnectorReliabilityService.php` — for reliability data
- **API — Existing Models:** `ExecutionRunbook`, `Workflow`, `ConnectorCallAttempt`, `ConnectorMetricDaily`

## API Endpoints

```
POST   /api/v1/workspaces/{workspace}/workflows/{workflow}/ai/generate-runbook
  Body: { "include_historical_data": true }
  Response: {
    "runbook": {
      "title": "Runbook: Order Processing Pipeline",
      "sections": [
        {
          "integration": "Stripe API",
          "node_key": "http_stripe",
          "failure_scenarios": [
            {
              "scenario": "Authentication failure (401)",
              "symptoms": ["ExecutionNode status: failed", "Error: Invalid API key"],
              "diagnosis": ["Check Stripe credential in Credentials page", "Verify API key hasn't been rotated"],
              "remediation": ["Update credential with new API key", "Re-execute workflow"],
              "severity": "high"
            }
          ]
        }
      ],
      "escalation_matrix": {
        "low": "Notify workflow owner via email",
        "medium": "Notify workspace admins via Slack",
        "high": "Page on-call engineer"
      }
    }
  }

GET    /api/v1/workspaces/{workspace}/workflows/{workflow}/runbooks
  Response: paginated list of runbooks (existing endpoint via ExecutionRunbookController)
```

## Data Model

### Extend `execution_runbooks` table:
```sql
ALTER TABLE execution_runbooks
    ADD COLUMN workflow_id UUID REFERENCES workflows(id) ON DELETE CASCADE,
    ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'reactive',  -- 'reactive' (existing), 'proactive' (new)
    ADD COLUMN ai_generated BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN sections JSONB;
```

The existing `execution_runbooks` table already has `execution_id` (nullable for proactive runbooks), `workspace_id`, `title`, `content`, and status fields.

## Implementation Steps
1. Create migration to extend `execution_runbooks` table with `workflow_id`, `type`, `ai_generated`, `sections` columns.
2. Update `ExecutionRunbook` model with new fillables and relationship to `Workflow`.
3. Build `AiRunbookGeneratorService` with methods: `generate()`, `analyzeWorkflow()`, `fetchReliabilityData()`, `buildPrompt()`.
4. Implement `analyzeWorkflow()` to parse the workflow definition and extract all external integration nodes.
5. Implement `fetchReliabilityData()` to query `ConnectorCallAttempt` and `ConnectorMetricDaily` for historical failure patterns.
6. Build LLM prompt with workflow structure, integration details, and historical data.
7. Create `AiRunbookController` with `generate` action.
8. Register route under workflow scope.
9. Add periodic regeneration: dispatch a job to regenerate runbooks when a workflow version is published.
10. Write feature tests covering workflows with various node types.

## Dependencies
- `RunbookService` — existing runbook infrastructure.
- `ConnectorReliabilityService` — historical reliability data.
- `ExecutionRunbook` model — storage for generated runbooks.
- External LLM API key.

## Success Metrics
- **Runbook coverage:** 80% of active workflows have AI-generated runbooks.
- **Incident response time:** 30% reduction in MTTR for workflows with proactive runbooks.
- **Runbook quality:** 70% of users rate generated runbooks as "useful" or "very useful."

## Estimated Effort
2 weeks (1 backend engineer)
- Week 1: Workflow analysis, reliability data integration, prompt engineering
- Week 2: Controller, storage, periodic regeneration, testing
