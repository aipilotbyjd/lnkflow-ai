# Release Process

We follow [Semantic Versioning](https://semver.org/).

## Versioning Strategy
-   **Major (1.0.0)**: Breaking changes to API or Engine architecture.
-   **Minor (1.1.0)**: New features, backward compatible.
-   **Patch (1.1.1)**: Bug fixes.

## Release Steps

1.  **Update Changelog**: Add entry to `CHANGELOG.md`.
2.  **Tag Release**:
    ```bash
    git tag -a v1.0.0 -m "Release v1.0.0"
    git push origin v1.0.0
    ```
3.  **CI/CD**: GitHub Actions will automatically:
    -   Run tests.
    -   Build Docker images.
    -   Push to Docker Hub (`linkflow/api:v1.0.0`).
    -   Create a GitHub Release.

## Post-Release
-   Verify production deployment.
-   Announce new features.
