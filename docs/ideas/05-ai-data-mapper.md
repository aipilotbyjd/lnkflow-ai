# AI Data Mapper

## Status
🟡 Planned

## Priority
Medium

## Difficulty
Hard

## Category
🤖 AI-Native

## Summary
When connecting two nodes with incompatible data schemas, automatically suggest field mappings using AI. The system analyzes the output schema of the source node and the input schema of the target node, then generates mapping expressions — including type conversions, array transformations, and nested field access.

## Problem Statement
Data mapping is the most tedious part of building workflows. Users must manually write expressions like `{{ $node.http_1.body.data.items[0].name }}` to map fields between nodes. When schemas are complex or mismatched, this requires deep understanding of both the expression syntax and the data structures. n8n requires fully manual mapping. An AI-powered mapper would eliminate this friction entirely.

## Proposed Solution
1. Capture the output schema of the upstream node by analyzing past execution data from `ExecutionNode` records.
2. Capture the expected input schema of the downstream node from its node type definition in `Node` model.
3. Send both schemas to an LLM with instructions to generate optimal field mappings.
4. Return a set of mapping expressions using LinkFlow's expression syntax (powered by `apps/engine/internal/expression/`).
5. Users can accept, modify, or regenerate mappings.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/AiDataMapperController.php`
- **API — New Service:** `apps/api/app/Services/AiDataMapperService.php`
- **API — Existing Models:** `Node`, `ExecutionNode`, `Workflow`, `WorkflowVersion`
- **Engine — Existing:** `apps/engine/internal/expression/` — expression evaluator that will execute the generated expressions
- **Engine — Existing:** `apps/engine/internal/resolver/` — data resolution between nodes

## API Endpoints

```
POST   /api/v1/workspaces/{workspace}/ai/map-fields
  Body: {
    "workflow_id": "uuid",
    "source_node_key": "http_1",
    "target_node_key": "slack_2",
    "source_sample_data": { ... },      -- optional, from recent execution
    "target_schema": { ... }            -- optional, auto-inferred from node type
  }
  Response: {
    "mappings": [
      {
        "target_field": "message",
        "expression": "{{ $node.http_1.body.user.name }} just signed up!",
        "confidence": 0.91,
        "explanation": "Maps the user name from the HTTP response body"
      }
    ],
    "unmapped_fields": ["channel_id"]
  }

POST   /api/v1/workspaces/{workspace}/ai/validate-mapping
  Body: {
    "expression": "{{ $node.http_1.body.data }}",
    "sample_data": { ... },
    "expected_type": "string"
  }
  Response: { "valid": true, "result": "John Doe", "type": "string" }
```

## Data Model

### New table: `ai_mapping_suggestions`
```sql
CREATE TABLE ai_mapping_suggestions (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    workflow_id     UUID NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    source_node_key VARCHAR(100) NOT NULL,
    target_node_key VARCHAR(100) NOT NULL,
    source_schema   JSONB,
    target_schema   JSONB,
    mappings        JSONB NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'suggested',  -- suggested, accepted, rejected
    model_used      VARCHAR(50) NOT NULL,
    tokens_used     INTEGER NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_ai_mapping_workspace ON ai_mapping_suggestions(workspace_id);
CREATE INDEX idx_ai_mapping_workflow ON ai_mapping_suggestions(workflow_id);
```

## Implementation Steps
1. Create migration for `ai_mapping_suggestions` table.
2. Create `AiMappingSuggestion` model.
3. Build `AiDataMapperService` with methods: `suggestMappings()`, `inferSourceSchema()`, `inferTargetSchema()`, `validateMapping()`.
4. Implement `inferSourceSchema()` by querying recent `ExecutionNode` records for the source node and extracting JSON keys from output data.
5. Implement `inferTargetSchema()` by reading the node type definition from `Node` model's `parameters` JSON column.
6. Build LLM prompt that includes both schemas and LinkFlow expression syntax reference.
7. Implement `validateMapping()` by evaluating the expression against sample data using the engine's expression evaluator via HTTP call to `apps/engine/internal/frontend/`.
8. Create controller, form requests, and routes.
9. Write feature tests with various schema combinations.
10. Add caching for schema inference — cache `ExecutionNode` output schemas per workflow version.

## Dependencies
- `ExecutionNode` model — for inferring output schemas from past executions.
- `Node` model — for node type parameter definitions.
- `apps/engine/internal/expression/` — expression syntax that mappings must conform to.
- External LLM API key.

## Success Metrics
- **Mapping accuracy:** 85%+ of suggested mappings are correct without modification.
- **Time savings:** Data mapping time reduced by 70%.
- **Expression errors:** 50% reduction in expression-related execution failures.

## Estimated Effort
3 weeks (1 senior backend engineer)
- Week 1: Schema inference, service architecture
- Week 2: LLM integration, prompt engineering, mapping generation
- Week 3: Validation, controller, testing
