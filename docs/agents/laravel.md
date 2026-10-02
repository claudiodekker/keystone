# Laravel guidance

## Tools (Laravel Boost MCP)

- `search-docs` before relying on version-specific ecosystem APIs. Pass `packages`; use several broad queries; don't put package names in queries.
- `database-schema` to inspect tables before writing migrations or models. `database-query` for read-only queries instead of tinker.
- `application-info` for PHP/Laravel/package versions instead of reading composer files.
- `browser-logs` for frontend errors (recent entries only). `get-absolute-url` before giving the user a URL.

## Guardrails

- Don't delete tests or test files without approval.
- Don't create new top-level folders under `app/` or a package's `src/` (e.g. `src/Pricing`) or add dependencies without approval. Area subfolders (`Controllers/Admin`, `tests/Feature/Admin`) and the framework's standard folders (`Http/Middleware`, `Models/Concerns`, `resources/js/components`) need no approval.
- Artisan: pass `--no-interaction` to `make:*` commands. Read config with dot keys: `config:show app.name`. Tinker: `tinker --execute '...'` in single quotes.
- After PHP edits: `vendor/bin/pint --dirty --format agent`.
