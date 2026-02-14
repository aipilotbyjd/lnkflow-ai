# Contributing to LinkFlow

Thank you for your interest in contributing to LinkFlow!

## Development Setup

### Prerequisites

- Docker & Docker Compose v2.20+
- Go 1.24+
- PHP 8.4+ & Composer
- Node.js 20+
- Pre-commit (for git hooks)

### Quick Start

```bash
# Clone and setup
git clone https://github.com/aipilotbyjd/lnkflow.git
cd lnkflow

# Install dependencies and tools
make setup
make install-tools

# Start the stack
make start

# Verify everything works
make test
```

### Install Pre-commit Hooks

```bash
pre-commit install
pre-commit install --hook-type commit-msg
```

## Development Workflow

### Making Changes

1. Create a feature branch from `main`
2. Make your changes
3. Run linting: `make lint`
4. Run tests: `make test`
5. Commit with conventional commit messages
6. Open a pull request

### Commit Message Format

We use [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <description>

[optional body]

[optional footer]
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`, `ci`

Examples:
- `feat(api): add workflow versioning endpoint`
- `fix(engine): resolve race condition in worker dispatch`
- `docs: update architecture documentation`

### Code Style

**PHP (Laravel API):**
- Follow PSR-12 coding standards
- Use Laravel Pint for formatting: `cd apps/api && ./vendor/bin/pint`
- Run PHPStan for static analysis: `cd apps/api && ./vendor/bin/phpstan analyse`

**Go (Engine):**
- Follow standard Go conventions
- Use gofmt for formatting
- Run golangci-lint: `cd apps/engine && golangci-lint run`

## Testing

### Running Tests

```bash
# All tests
make test

# Only Go tests
make test-go

# Only PHP tests
make test-php

# With coverage
make test-go-cover
make test-php-cover

# Integration tests (requires Docker)
make test-integration
```

### Writing Tests

- **PHP**: Use Pest framework, place in `apps/api/tests/`
- **Go**: Use standard testing package, name files `*_test.go`
- Use table-driven tests where appropriate
- Mock external dependencies

## Pull Request Guidelines

1. **Keep PRs focused** - One feature/fix per PR
2. **Include tests** - All new features need tests
3. **Update documentation** - Keep docs in sync with code
4. **Pass CI checks** - All tests and linting must pass
5. **Request review** - Tag relevant maintainers

## Security

Report security vulnerabilities privately to the maintainers. Do not open public issues for security problems.

## Getting Help

- Check existing issues and documentation
- Open a discussion for questions
- Open an issue for bugs or feature requests

## License

By contributing, you agree that your contributions will be licensed under the project's MIT License.
