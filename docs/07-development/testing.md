# Testing Guide

We heavily rely on automated testing to ensure reliability.

## Test Pyramid

1.  **Unit Tests**: Test individual functions/classes in isolation.
2.  **Integration Tests**: Test interactions between components (e.g., Database, Redis).
3.  **E2E Tests**: Test full user flows (Smoke tests).

## PHP Testing (Pest)

We use **Pest PHP** for a delightful testing experience.

### Running Tests
```bash
php artisan test
php artisan test --filter=WorkflowTest
```

### Writing Tests
```php
it('can create a workflow', function () {
    $user = User::factory()->create();
    
    $response = actingAs($user)->postJson('/api/v1/workspaces/...', [...]);
    
    $response->assertCreated();
    expect(Workflow::count())->toBe(1);
});
```

## Go Testing

We use the standard `testing` package with `testify` for assertions.

### Running Tests
```bash
go test ./...
go test -v -race ./internal/worker/...
```

### Mocking
We use `gomock` to generate mocks for interfaces.
```bash
mockgen -source=internal/matching/service.go -destination=internal/matching/mocks/service.go
```

## Continuous Integration

Tests run automatically on every Push and PR via GitHub Actions.
-   **CI Workflow**: `.github/workflows/ci.yml`
