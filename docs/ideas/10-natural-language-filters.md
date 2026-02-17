# Natural Language Filters

## Status
🟡 Planned

## Priority
Low

## Difficulty
Medium

## Category
🤖 AI-Native

## Summary
Allow users to filter and search workflows, executions, and logs using natural language queries. Instead of constructing complex filter parameters, users type queries like "Show me all failed executions from last week that involved the Stripe API" and the system translates this into the appropriate database query.

## Problem Statement
The current filtering system requires users to know the exact query parameters (`?status=failed&created_after=2026-02-11&node_type=http`). This is fine for API consumers but poor for UI users who want quick answers. Natural language filtering makes data exploration accessible to non-technical users and speeds up debugging for engineers.

## Proposed Solution
1. Accept a natural language query string.
2. Parse it using an LLM to extract structured filter parameters.
3. Map extracted parameters to existing model scopes and query builders.
4. Execute the query and return standard paginated results.
5. Also return the interpreted filters so users can verify and adjust.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/NaturalLanguageSearchController.php`
- **API — New Service:** `apps/api/app/Services/NaturalLanguageFilterService.php`
- **API — Existing Controllers:** `ExecutionController`, `WorkflowController`, `ActivityLogController` — existing filter logic to reuse
- **API — Existing Models:** `Execution`, `Workflow`, `ExecutionNode`, `ExecutionLog`, `ActivityLog`

## API Endpoints

```
POST   /api/v1/workspaces/{workspace}/search
  Body: {
    "query": "failed executions from last week involving Stripe",
    "resource": "executions"   -- executions, workflows, logs, activity
  }
  Response: {
    "interpreted_filters": {
      "status": "failed",
      "created_after": "2026-02-11T00:00:00Z",
      "created_before": "2026-02-18T00:00:00Z",
      "node_contains": "stripe"
    },
    "results": { ...standard paginated response... },
    "total": 23,
    "suggestion": "Did you mean to include 'error' status as well?"
  }
```

## Data Model

No new tables required. This feature translates natural language into existing query parameters. Optionally log queries for improving the parser:

### New table: `nl_search_logs`
```sql
CREATE TABLE nl_search_logs (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    query           TEXT NOT NULL,
    resource        VARCHAR(30) NOT NULL,
    interpreted     JSONB NOT NULL,
    result_count    INTEGER NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_nl_search_workspace ON nl_search_logs(workspace_id);
```

## Implementation Steps
1. Create `NaturalLanguageFilterService` with methods: `parse()`, `executeQuery()`, `buildFilterSchema()`.
2. Build a filter schema definition for each resource type (executions, workflows, logs, activity) describing available filter fields, types, and valid values.
3. Send the user query + filter schema to the LLM, asking it to return structured JSON filter parameters.
4. Map LLM output to existing Eloquent query scopes on the respective models.
5. Execute the query using existing controller query logic (reuse `ExecutionController::index()` filter logic).
6. Create controller and routes.
7. Create migration for `nl_search_logs`.
8. Write tests for common query patterns.

## Dependencies
- Existing model query scopes on `Execution`, `Workflow`, `ActivityLog`.
- External LLM API key.

## Success Metrics
- **Adoption:** 20% of search queries use natural language within 2 months.
- **Accuracy:** 85% of parsed filters match user intent.
- **Speed:** Users find relevant data 50% faster with natural language vs. manual filters.

## Estimated Effort
2 weeks (1 backend engineer)
- Week 1: Filter schema, LLM integration, query translation
- Week 2: Controller, search logging, testing
