# AI Cost Predictor

## Status
🟡 Planned

## Priority
Low

## Difficulty
Medium

## Category
🤖 AI-Native

## Summary
Predict the operational cost of a workflow before execution based on historical execution data, node types, and external API pricing. Show users an estimated cost breakdown per node and total cost per run, per day, and per month — enabling informed decisions about workflow design and scheduling frequency.

## Problem Statement
Users have no visibility into how much a workflow costs to operate. Costs come from multiple sources: LinkFlow platform credits, external API calls (Stripe charges per API call, OpenAI charges per token), compute time, and data transfer. Without cost visibility, users over-provision, make excessive API calls, or run workflows more frequently than needed. The existing `CostOptimizerService` provides optimization suggestions but doesn't predict costs proactively.

## Proposed Solution
1. Build a cost model that estimates per-node costs based on node type, historical execution data, and known API pricing.
2. Use `ConnectorMetricDaily` and `ConnectorCallAttempt` data to estimate API call frequency and average response sizes.
3. Use `ExecutionNode` duration data to estimate compute costs.
4. Apply an AI layer to predict costs for new workflows that have no execution history, using similar workflow patterns.
5. Display cost breakdowns in the workflow editor before execution.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/AiCostPredictorController.php`
- **API — New Service:** `apps/api/app/Services/AiCostPredictorService.php`
- **API — Existing Service:** `apps/api/app/Services/CostOptimizerService.php` — share cost calculation logic
- **API — Existing Models:** `ExecutionNode`, `ConnectorMetricDaily`, `ConnectorCallAttempt`, `Execution`, `Workflow`, `Subscription`, `Plan`

## API Endpoints

```
POST   /api/v1/workspaces/{workspace}/workflows/{workflow}/ai/predict-cost
  Body: { "schedule": "every 15 minutes", "time_range": "monthly" }
  Response: {
    "prediction": {
      "per_execution": {
        "total_credits": 4.2,
        "total_estimated_usd": 0.042,
        "breakdown": [
          { "node_key": "http_stripe", "credits": 1.0, "estimated_usd": 0.01, "category": "api_call" },
          { "node_key": "code_transform", "credits": 0.5, "estimated_usd": 0.005, "category": "compute" },
          { "node_key": "ai_classify", "credits": 2.7, "estimated_usd": 0.027, "category": "ai_tokens" }
        ]
      },
      "per_day": { "total_credits": 403.2, "total_estimated_usd": 4.03, "executions": 96 },
      "per_month": { "total_credits": 12096, "total_estimated_usd": 120.96, "executions": 2880 },
      "confidence": 0.82,
      "optimization_tips": [
        "Consider caching the Stripe API response — 60% of calls return identical data",
        "Reduce AI node token usage by summarizing input before classification"
      ]
    }
  }

GET    /api/v1/workspaces/{workspace}/cost-summary
  Query: ?period=monthly
  Response: { "total_credits": 45000, "total_estimated_usd": 450.00, "top_workflows": [...] }
```

## Data Model

### New table: `cost_predictions`
```sql
CREATE TABLE cost_predictions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    workflow_id     UUID NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    schedule        VARCHAR(100),
    per_execution   JSONB NOT NULL,
    per_day         JSONB,
    per_month       JSONB,
    confidence      DECIMAL(3,2) NOT NULL,
    model_used      VARCHAR(50),
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_cost_pred_workspace ON cost_predictions(workspace_id);
CREATE INDEX idx_cost_pred_workflow ON cost_predictions(workflow_id);
```

## Implementation Steps
1. Create migration for `cost_predictions` table.
2. Create `CostPrediction` model.
3. Build `AiCostPredictorService` with methods: `predict()`, `calculateNodeCost()`, `estimateApiCost()`, `estimateComputeCost()`.
4. Implement `calculateNodeCost()` using historical `ExecutionNode` duration data aggregated per node type.
5. Implement `estimateApiCost()` using `ConnectorMetricDaily` data and known API pricing tables.
6. Build a pricing table config for common APIs (Stripe, OpenAI, SendGrid, Slack, etc.).
7. For new workflows without history, use AI to predict costs based on similar workflow patterns.
8. Integrate with `CostOptimizerService` to include optimization tips in predictions.
9. Create controller and routes.
10. Write feature tests with various workflow configurations.

## Dependencies
- `CostOptimizerService` — existing cost optimization logic.
- `ConnectorMetricDaily` — historical API usage data.
- `ExecutionNode` — historical compute duration data.
- `Subscription` / `Plan` — for credit pricing context.

## Success Metrics
- **Cost awareness:** 60% of active workspaces view cost predictions monthly.
- **Cost reduction:** 20% reduction in average workflow operating costs after predictions are available.
- **Prediction accuracy:** Within 15% of actual costs for workflows with historical data.

## Estimated Effort
2.5 weeks (1 backend engineer)
- Week 1: Cost calculation engine, historical data analysis
- Week 2: AI prediction for new workflows, optimization tips
- Week 2.5: Controller, testing, pricing table
