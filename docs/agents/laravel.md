# Laravel guidance

## Guardrails

- Don't delete tests or test files without approval.
- Don't create new top-level folders under a package's `src/` (e.g. `src/Pricing`) or add dependencies without approval. Area subfolders (`Controllers/Admin`, `tests/Feature/Admin`) and the framework's standard folders (`Http/Middleware`) need no approval.
- After PHP edits: `vendor/bin/pint --dirty --format agent`.
