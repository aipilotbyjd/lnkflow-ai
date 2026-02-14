# LinkFlow API Assistant Guide

This file contains instructions for AI assistants working on the LinkFlow API (Laravel).

## Context

The API is the **Control Plane** of LinkFlow. It handles:
- User Authentication & Management
- Workflow CRUD Operations
- Execution Triggering & Monitoring
- Integration Credentials Management

## Tech Stack

- **Framework**: Laravel 12
- **Language**: PHP 8.4
- **Database**: PostgreSQL 16
- **Queue**: Redis (Laravel Horizon)
- **Testing**: Pest PHP

## Key Locations

| Concept | Directory |
|---------|-----------|
| **Controllers** | `app/Http/Controllers/Api/V1/` |
| **Models** | `app/Models/` |
| **Services** | `app/Services/` |
| **Resources** | `app/Http/Resources/` |
| **Requests** | `app/Http/Requests/` |
| **Routes** | `routes/api.php` |

## Documentation

- **API Reference**: `../../docs/04-api-reference/openapi.yaml`
- **Architecture**: `../../docs/02-architecture/02-control-plane.md`
- **Guides**: `../../docs/03-guides/`

## Development Rules

1.  **Form Requests**: Always use Form Request classes for validation.
2.  **API Resources**: Always use API Resources to transform responses.
3.  **Strict Typing**: Use strict types in PHP.
4.  **Testing**: Write Feature tests for all new endpoints using Pest.
5.  **Formatting**: Run `vendor/bin/pint` before committing.
