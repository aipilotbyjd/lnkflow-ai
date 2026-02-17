# AI Workflow Summarizer

## Status
🟡 Planned

## Priority
Low

## Difficulty
Easy

## Category
🤖 AI-Native

## Summary
Automatically generate human-readable summaries and documentation for workflows. Given a workflow definition, produce a plain-English description of what the workflow does, its trigger, data flow, and expected outcomes. Useful for onboarding, knowledge transfer, and compliance documentation.

## Problem Statement
Workflows often lack documentation. When team members change, new engineers must reverse-engineer workflow logic by reading node configurations. Complex workflows with 20+ nodes are especially hard to understand. Auto-generated summaries solve this by providing always-up-to-date documentation that stays in sync with the workflow definition.

## Proposed Solution
1. Parse the workflow definition to extract the trigger type, node sequence, data flow, and integrations.
2. Send the structured analysis to an LLM to generate a natural language summary.
3. Generate multiple summary levels: one-liner, paragraph, and detailed step-by-step documentation.
4. Optionally generate Mermaid diagram code for visual documentation.
5. Cache summaries per workflow version — regenerate only when the version changes.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/AiWorkflowSummaryController.php`
- **API — New Service:** `apps/api/app/Services/AiWorkflowSummaryService.php`
- **API — Existing Models:** `Workflow`, `WorkflowVersion`, `Node`

## API Endpoints

```
GET    /api/v1/workspaces/{workspace}/workflows/{workflow}/ai/summary
  Query: ?level=detailed&version_id=uuid
  Response: {
    "one_liner": "Processes new Stripe payments by enriching customer data and notifying the sales team via Slack.",
    "paragraph": "This workflow triggers when a new payment is received via a Stripe webhook...",
    "detailed": [
      { "step": 1, "node_key": "webhook_trigger", "description": "Listens for Stripe payment.succeeded webhook events" },
      { "step": 2, "node_key": "http_enrich", "description": "Fetches customer details from internal CRM API" },
      { "step": 3, "node_key": "condition_1", "description": "Checks if payment amount exceeds $1000" },
      { "step": 4, "node_key": "slack_notify", "description": "Sends a notification to #sales channel with payment details" }
    ],
    "mermaid": "graph TD\n  A[Stripe Webhook] --> B[Fetch CRM Data]\n  B --> C{Amount > $1000?}\n  C -->|Yes| D[Slack Notification]\n  C -->|No| E[Log & Skip]",
    "integrations": ["Stripe", "Internal CRM", "Slack"],
    "trigger_type": "webhook",
    "version_id": "uuid"
  }
```

## Data Model

### Extend `workflow_versions` table (or use cache):
```sql
ALTER TABLE workflow_versions
    ADD COLUMN ai_summary JSONB;
```

Alternatively, use Redis cache with key `workflow_summary:{version_id}` and TTL of 7 days.

## Implementation Steps
1. Build `AiWorkflowSummaryService` with methods: `summarize()`, `parseWorkflowStructure()`, `generateMermaid()`.
2. Implement `parseWorkflowStructure()` to extract nodes, edges, trigger type, and integration names from the workflow definition.
3. Build LLM prompt with the parsed structure, requesting summaries at three detail levels.
4. Implement `generateMermaid()` to create Mermaid diagram syntax from the node/edge graph.
5. Add caching: store summary in `workflow_versions.ai_summary` column or Redis cache.
6. Create controller with a single `show` action.
7. Register route under workflow scope.
8. Write tests for various workflow patterns (linear, branching, parallel).

## Dependencies
- `WorkflowVersion` model — for version-specific definitions.
- `Node` model — for node type display names.
- External LLM API key.

## Success Metrics
- **Documentation coverage:** 90% of active workflows have auto-generated summaries.
- **Onboarding time:** New team members understand workflows 60% faster.
- **Usage:** Summaries viewed at least once per workflow per month.

## Estimated Effort
1 week (1 backend engineer)
- Days 1-3: Service, prompt engineering, parsing
- Days 4-5: Controller, caching, testing
