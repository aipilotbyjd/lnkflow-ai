# Code Style Guide

We strive for consistency across the codebase.

## PHP (Laravel)

We follow [PSR-12](https://www.php-fig.org/psr/psr-12/) and Laravel best practices.

### Tools
-   **Pint**: We use Laravel Pint as our code style fixer.
    ```bash
    vendor/bin/pint
    ```
-   **PHPStan**: Static analysis at level 5 (aiming for max).
    ```bash
    vendor/bin/phpstan analyse
    ```

### Conventions
-   **Strict Types**: Add `declare(strict_types=1);` to all PHP files.
-   **Return Types**: Always declare return types.
-   **Controllers**: Keep them thin. Move logic to Services or Actions.
-   **Models**: Use `$guarded = []` instead of `$fillable`.

## Go (Engine)

We follow effective Go guidelines and standard idioms.

### Tools
-   **gofmt**: Standard formatter.
-   **goimports**: Order imports (stdlib, external, internal).
-   **golangci-lint**: Comprehensive linter.
    ```bash
    golangci-lint run
    ```

### Conventions
-   **Errors**: Handle errors explicitly. Don't use `panic` in library code.
-   **Context**: Pass `context.Context` as the first argument to functions performing I/O.
-   **Interfaces**: Define interfaces where they are used, not where they are implemented.
-   **Structure**: Group code by business domain in `internal/`, not by technical layer (controllers, models).

## JavaScript/Frontend

(If applicable)
-   **Prettier**: For formatting.
-   **ESLint**: For linting.
