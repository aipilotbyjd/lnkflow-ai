# Visual Version Diff

## Status
🟡 Planned

## Priority
Medium

## Difficulty
Medium

## Category
🤖 AI-Native

## Summary
Provide a visual side-by-side diff view when comparing workflow versions. Instead of showing raw JSON diffs, render two workflow graphs side-by-side with added nodes highlighted in green, removed nodes in red, and modified nodes in yellow. Include a structured change summary showing exactly what changed in each node's configuration.

## Problem Statement
The existing `WorkflowVersionController::compare()` endpoint returns a raw JSON diff which is hard to interpret. Users struggle to understand what changed between versions, especially for complex workflows with many nodes. n8n has no version comparison at all. A visual diff would make version management intuitive and encourage teams to use versioning as a safety net.

## Proposed Solution
1. Enhance the existing `compare` endpoint to return a structured diff instead of raw JSON.
2. Compute node-level diffs: which nodes were added, removed, or modified.
3. For modified nodes, compute field-level diffs showing exactly which properties changed.
4. Compute edge-level diffs: which connections were added or removed.
5. Return the diff in a format that a frontend can render as two aligned workflow graphs.

## Architecture
- **API — Existing Controller:** `apps/api/app/Http/Controllers/Api/V1/WorkflowVersionController.php` — enhance `compare()` method
- **API — New Service:** `apps/api/app/Services/WorkflowVersionDiffService.php`
- **API — New Resource:** `apps/api/app/Http/Resources/VersionDiffResource.php`
- **API — Existing Models:** `WorkflowVersion`, `Workflow`
- **API — Existing Routes:** `GET /workspaces/{workspace}/workflows/{workflow}/versions/compare` (already exists)

## API Endpoints

```
GET    /api/v1/workspaces/{workspace}/workflows/{workflow}/versions/compare
  Query: ?from=version_id&to=version_id&format=structured (new format param)
  Response:
  {
    "from_version": { "id": "uuid", "number": 3, "created_at": "ISO8601" },
    "to_version": { "id": "uuid", "number": 5, "created_at": "ISO8601" },
    "summary": {
      "nodes_added": 2,
      "nodes_removed": 1,
      "nodes_modified": 3,
      "edges_added": 2,
      "edges_removed": 1
    },
    "node_diffs": [
      {
        "node_key": "http_1",
        "status": "modified",
        "field_changes": [
          { "path": "parameters.url", "from": "https://old.api.com", "to": "https://new.api.com" },
          { "path": "parameters.method", "from": "GET", "to": "POST" }
        ]
      },
      {
        "node_key": "slack_2",
        "status": "added",
        "node_definition": { ... }
      }
    ],
    "edge_diffs": [
      { "from": "http_1", "to": "slack_2", "status": "added" }
    ]
  }
```

## Data Model

No new tables required. This feature enhances existing `WorkflowVersion` queries.

The `WorkflowVersion` model already stores the full workflow definition in a `definition` JSON column. The diff is computed at query time.

## Implementation Steps
1. Create `WorkflowVersionDiffService` with methods: `computeDiff()`, `diffNodes()`, `diffEdges()`, `diffFields()`.
2. Implement `diffNodes()` using node keys as the identity — match nodes between versions by key.
3. Implement `diffFields()` for recursive JSON comparison within individual node configs.
4. Implement `diffEdges()` comparing connection arrays (source_key → target_key).
5. Create `VersionDiffResource` to format the response.
6. Enhance `WorkflowVersionController::compare()` to accept `?format=structured` query param.
7. Maintain backward compatibility: default format returns the existing raw diff.
8. Write feature tests for: identical versions, added nodes, removed nodes, modified fields, edge changes.
9. Test with complex workflows (20+ nodes) to ensure performance.

## Dependencies
- `WorkflowVersion` model — stores version definitions.
- `WorkflowVersionController::compare()` — existing endpoint to enhance.

## Success Metrics
- **Version adoption:** 50% increase in users creating workflow versions after visual diff ships.
- **Rollback confidence:** Users feel confident using `restore` endpoint because they can see exactly what changed.
- **Support reduction:** 25% fewer questions about "what changed in my workflow."

## Estimated Effort
1.5 weeks (1 backend engineer)
- Week 1: Diff service, node/edge/field comparison algorithms
- Week 1.5: Controller integration, resource formatting, tests
