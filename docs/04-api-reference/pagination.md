# Pagination

Endpoints that return lists of resources are paginated to improve performance.

## Query Parameters

Use the `page` and `per_page` query parameters to navigate results.

```http
GET /api/v1/workspaces?page=2&per_page=15
```

-   **page**: The page number (default: 1).
-   **per_page**: Items per page (default: 15, max: 100).

## Response Structure

Paginated responses include `data` and `meta` objects.

```json
{
  "data": [
    { "id": 1, "name": "Workspace A" },
    { "id": 2, "name": "Workspace B" }
  ],
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "path": "https://api.linkflow.io/v1/workspaces",
    "per_page": 15,
    "to": 15,
    "total": 73
  },
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  }
}
```
