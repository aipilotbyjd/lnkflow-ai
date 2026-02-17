# AI Expression Helper

## Status
🟡 Planned

## Priority
Medium

## Difficulty
Medium

## Category
🤖 AI-Native

## Summary
Provide an inline AI assistant that helps users write and debug LinkFlow expressions. Users describe what they want in natural language (e.g., "get the email from the third item in the array") and the assistant generates the correct expression syntax, explains it, and validates it against sample data.

## Problem Statement
LinkFlow's expression language (powered by `apps/engine/internal/expression/`) supports powerful data transformations but has a learning curve. Users frequently make syntax errors, reference wrong node outputs, or struggle with array operations. Currently there's no in-context help — users must read documentation separately. An AI expression helper provides contextual, interactive guidance.

## Proposed Solution
1. Build an endpoint that accepts a natural language description and the current execution context (available node outputs).
2. Use an LLM to generate the correct expression.
3. Validate the expression by evaluating it against the provided context using the engine's expression package.
4. Return the expression, its evaluation result, and a human-readable explanation.

## Architecture
- **API — New Controller:** `apps/api/app/Http/Controllers/Api/V1/AiExpressionController.php`
- **API — New Service:** `apps/api/app/Services/AiExpressionService.php`
- **API — Existing Models:** `Node`, `ExecutionNode`, `Workflow`
- **Engine — Existing:** `apps/engine/internal/expression/` — expression parser and evaluator
- **Engine — Existing:** `apps/engine/internal/sandbox/` — safe execution environment for expression evaluation

## API Endpoints

```
POST   /api/v1/workspaces/{workspace}/ai/expression/generate
  Body: {
    "description": "Get the email address from the user object in the HTTP response",
    "context": {
      "available_nodes": {
        "http_1": { "body": { "user": { "email": "john@example.com", "name": "John" } } }
      }
    },
    "target_node_key": "email_2"
  }
  Response: {
    "expression": "{{ $node.http_1.body.user.email }}",
    "explanation": "Accesses the 'email' field from the 'user' object in the HTTP response body",
    "evaluated_result": "john@example.com",
    "alternatives": [
      { "expression": "{{ $json.http_1.body.user.email }}", "note": "Alternative syntax using $json" }
    ]
  }

POST   /api/v1/workspaces/{workspace}/ai/expression/explain
  Body: { "expression": "{{ $node.http_1.body.items | map('name') | join(', ') }}" }
  Response: {
    "explanation": "Takes the 'items' array from the HTTP response, extracts the 'name' field from each item, then joins them with a comma and space",
    "breakdown": [
      { "part": "$node.http_1.body.items", "description": "Reference to the items array" },
      { "part": "map('name')", "description": "Extract the 'name' field from each item" },
      { "part": "join(', ')", "description": "Concatenate all names with comma separator" }
    ]
  }

POST   /api/v1/workspaces/{workspace}/ai/expression/fix
  Body: {
    "expression": "{{ $node.http_1.body.user.emial }}",
    "error": "Property 'emial' does not exist",
    "context": { ... }
  }
  Response: {
    "fixed_expression": "{{ $node.http_1.body.user.email }}",
    "explanation": "Fixed typo: 'emial' → 'email'"
  }
```

## Data Model

No new tables required. This is a stateless AI helper that doesn't persist results. Usage is tracked via the existing `ai_generation_logs` table (from idea #01) with `type = 'expression'`.

## Implementation Steps
1. Build `AiExpressionService` with methods: `generate()`, `explain()`, `fix()`, `buildExpressionContext()`.
2. Create a comprehensive system prompt that includes LinkFlow expression syntax documentation, available functions, and operators.
3. Implement `buildExpressionContext()` to format available node outputs as a schema the LLM can understand.
4. Add expression validation by calling the engine's expression evaluator via gRPC or HTTP through `apps/engine/internal/frontend/`.
5. Create `AiExpressionController` with three actions.
6. Register routes under workspace-scoped group.
7. Add rate limiting: 100 expression generations per workspace per day.
8. Write tests for common expression patterns: simple access, nested objects, array operations, filters, string manipulation.

## Dependencies
- `apps/engine/internal/expression/` — expression syntax and evaluation.
- `ExecutionNode` model — for providing real context data.
- External LLM API key.

## Success Metrics
- **Expression accuracy:** 90%+ of generated expressions are syntactically correct.
- **Usage:** Average 10+ expression generations per active workspace per week.
- **Error reduction:** 40% fewer expression-related execution failures.

## Estimated Effort
2 weeks (1 backend engineer)
- Week 1: Service, prompt engineering, expression context builder
- Week 2: Controller, validation pipeline, testing
