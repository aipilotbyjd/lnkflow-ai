# AI Workflow Reviewer

## Status
🟡 Planned

## Priority
Medium

## Difficulty
Hard

## Category
🤖 AI-Native

## Summary
Automatically review workflow definitions for best practices, security issues, performance bottlenecks, and reliability risks. Think of it as a "code review" for workflows — an AI linter that catches problems before they cause production failures.

## Problem Statement
Users build workflows without guidance on best practices. Common mistakes include: missing error handling, no retry configuration on HTTP nodes, hardcoded secrets in expressions, unnecessary sequential execution of independent nodes, and missing timeout configurations. These issues only surface when workflows fail in production. A proactive reviewer catches them at design time.

## Proposed Solution
1. Analyze the workflow definition against a set of rules covering security, performance, reliability, and best practices.
2. Use a combination of deterministic rules (heuristics) and AI-powered analysis.
3. Deterministic rules catch obvious issues (hardcoded URLs, missing retries, no error paths).
4. AI analysis catches subtle issues (inefficient data flow, over-fetching, redundant operations).
5. Return a structured review with categorized findings, severity levels, and fix suggestions.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/AiWorkflowReviewerController.php`
- **API — New Service:** `apps/api/app/Services/AiWorkflowReviewerService.php`
- **API — Existing Models:** `Workflow`, `WorkflowVersion`, `WorkflowContractSnapshot`, `WorkspacePolicy`
- **API — Existing Services:** `ContractCompilerService` — for schema validation, `CostOptimizerService` — for performance insights
- **Engine — Existing:** `apps/engine/internal/security/` — security-related checks
- **Engine — Existing:** `apps/engine/internal/chaos/` — failure mode awareness

## API Endpoints

```
POST   /api/v1/workspaces/{workspace}/workflows/{workflow}/ai/review
  Body: { "version_id": "uuid" }  -- optional, defaults to latest
  Response: {
    "score": 78,
    "grade": "B",
    "findings": [
      {
        "rule": "missing-error-handling",
        "category": "reliability",
        "severity": "warning",
        "node_key": "http_1",
        "title": "HTTP node has no error path",
        "description": "The HTTP Request node 'http_1' has no error output connected. If the request fails, the entire workflow will fail.",
        "suggestion": "Add a condition node after 'http_1' to handle error responses (status >= 400)",
        "auto_fixable": true
      },
      {
        "rule": "hardcoded-secret",
        "category": "security",
        "severity": "critical",
        "node_key": "http_2",
        "title": "Potential hardcoded API key detected",
        "description": "The Authorization header contains what appears to be a hardcoded API key. Use credentials instead.",
        "suggestion": "Move the API key to a Credential and reference it via the credential selector",
        "auto_fixable": false
      }
    ],
    "categories": {
      "security": { "score": 60, "findings_count": 2 },
      "reliability": { "score": 75, "findings_count": 3 },
      "performance": { "score": 90, "findings_count": 1 },
      "best_practices": { "score": 85, "findings_count": 1 }
    }
  }

POST   /api/v1/workspaces/{workspace}/workflows/{workflow}/ai/review/auto-fix
  Body: { "finding_ids": ["uuid1", "uuid2"] }
  Response: { "workflow_version": WorkflowVersionResource, "fixes_applied": 2 }
```

## Data Model

### New table: `workflow_reviews`
```sql
CREATE TABLE workflow_reviews (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    workflow_id     UUID NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    version_id      UUID REFERENCES workflow_versions(id) ON DELETE SET NULL,
    score           SMALLINT NOT NULL,              -- 0-100
    grade           CHAR(1) NOT NULL,               -- A, B, C, D, F
    findings        JSONB NOT NULL,
    category_scores JSONB NOT NULL,
    model_used      VARCHAR(50),
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_workflow_reviews_workflow ON workflow_reviews(workflow_id);
CREATE INDEX idx_workflow_reviews_workspace ON workflow_reviews(workspace_id);
```

## Implementation Steps
1. Create migration for `workflow_reviews` table.
2. Create `WorkflowReview` model.
3. Build `AiWorkflowReviewerService` with methods: `review()`, `runDeterministicRules()`, `runAiAnalysis()`, `computeScore()`.
4. Implement deterministic rules engine with rules for:
   - `missing-error-handling`: Nodes with no error output path
   - `hardcoded-secret`: Regex patterns for API keys, tokens in node configs
   - `missing-retry`: HTTP/webhook nodes without retry configuration
   - `missing-timeout`: Nodes without timeout settings
   - `sequential-bottleneck`: Independent nodes executed sequentially instead of parallel
   - `large-payload`: Nodes that may process very large datasets without pagination
5. Implement AI analysis for subtle issues that heuristics can't catch.
6. Implement scoring algorithm weighted by severity (critical=30, warning=10, info=2).
7. Build auto-fix pipeline for fixable issues (creates new `WorkflowVersion`).
8. Create controller and routes.
9. Optionally trigger review automatically on workflow version publish (via event listener).
10. Write comprehensive tests for each rule.

## Dependencies
- `ContractCompilerService` — for schema context.
- `CostOptimizerService` — for performance data.
- `WorkflowVersion` model — for version-specific analysis.
- External LLM API key (for AI analysis portion).

## Success Metrics
- **Pre-deploy catches:** 40% of critical issues caught before workflow activation.
- **Score improvement:** Average workflow score increases from 65 to 80 within 3 months.
- **Production failures:** 25% reduction in preventable workflow failures.

## Estimated Effort
3–4 weeks (1 senior backend engineer)
- Week 1: Deterministic rules engine (8+ rules)
- Week 2: AI analysis integration, scoring algorithm
- Week 3: Auto-fix pipeline, controller, routes
- Week 4: Testing, rule tuning, documentation
