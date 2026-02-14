# Database Nodes

Interact directly with your databases to read or write data during a workflow.

## PostgreSQL Node

Executes SQL queries against a Postgres database.

### Configuration
-   **Credential**: Select a Database Credential (host, user, password).
-   **Operation**: `Query` (Read) or `Execute` (Write).
-   **SQL**: The SQL statement.
    ```sql
    SELECT * FROM users WHERE email = $1
    ```
-   **Parameters**: Bind variables for security (prevents SQL injection).
    -   `$1`: `{{ trigger.body.email }}`

### Output
Returns an array of rows:
```json
[
  { "id": 1, "name": "Alice" },
  { "id": 2, "name": "Bob" }
]
```

## Redis Node

Perform Redis commands.

### Configuration
-   **Command**: `GET`, `SET`, `HGET`, `LPUSH`, etc.
-   **Key**: The key to operate on.
-   **Value**: The value (for SET/LPUSH).

## Best Practices
-   **Read-Only**: Prefer using a read-replica connection for heavy queries.
-   **Timeouts**: Set strict timeouts to avoid locking the workflow engine.
-   **Security**: Use a database user with minimum required privileges (e.g., only `SELECT` and `INSERT` on specific tables).
