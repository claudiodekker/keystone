# Laravel guidance

## Tools (Laravel Boost MCP)

- `search-docs` before relying on version-specific ecosystem APIs. Pass `packages`; use several broad queries; don't put package names in queries.
- `database-schema` to inspect tables before writing migrations or models. `database-query` for read-only queries instead of tinker.
- `application-info` for PHP/Laravel/package versions instead of reading composer files.
- `browser-logs` for frontend errors (recent entries only). `get-absolute-url` before giving the user a URL.

## Guardrails

- Don't delete tests or test files without approval.
- Don't create new folders (e.g. `app/Pricing`) or add dependencies without approval.
- Artisan: pass `--no-interaction` to `make:*` commands. Read config with dot keys: `config:show app.name`. Tinker: `tinker --execute '...'` in single quotes.
- After PHP edits: `vendor/bin/pint --dirty --format agent`.

## Laravel

- Local scopes over global scopes. A global scope also applies to route model binding and every relation.
- No new Action/Service/FormRequest for trivial code (e.g. a single inline validation rule).
- Typed request accessors: `$request->integer()`, `->boolean()`, `->string()`. No `(int)` casts.
- Build URLs with `Uri::of($base)->withQuery([...])`, not string concatenation or `http_build_query`.
- Scheduled tasks that share constraints: `Schedule::hourly()->onOneServer()->group(fn () => ...)`. Keep `onOneServer()` whenever more than one server can run the scheduler.
- One complex query vs. two simple ones: depends on selectivity; measure.

## Tests (Pest)

Coverage

- Cover every decision: each branch, validation rule, calculation and authorization, including boundaries (e.g. 8 vs 8.25 hours).
- Authorization: full role × ability matrix in the policy test (dataset). Each endpoint gets one HTTP test for one refused role.
- Validation: one empty-payload test for all required fields. Test rules through the endpoint, never by asserting on `rules()`.
- If a unit test and a feature test catch the same defect, trim the feature test to one case (it proves the wiring). Never remove the last case.
- Escaping: assert the escaped value is present and the raw value is absent. Don't assert an exact quote entity.
- No real-browser tests (`pest-plugin-browser`, Dusk) unless the user asks.

Assertions

- `assertModelExists` / `assertModelMissing` / `assertDatabaseCount`, not `->exists` or `Model::count()`.
- Assert each fact once: no `assertOk()` before `assertSee`/`assertInertia`.
- `Http::fake()` with the exact endpoint URL, never a bare or wildcard fake.

Data

- Create records inside the test that uses them; `beforeEach` is for configuration only.
- `sequence()` for several records with different attributes.
- `make()` when the test doesn't need the database. It still creates `belongsTo` parents unless you pass them in.
- `recycle($team)` to share one parent across factories instead of setting foreign keys by hand.

Structure

- `it()` for behaviour, `test()` for declarative facts (policy grants, enum labels, serialized shape).
- `describe()` per action when one file covers several (index/store/destroy); not for input variants.
- `use function Pest\Laravel\mock;` before calling `mock()`.

Suite setup: only when creating or explicitly asked to tune `tests/Pest.php`, never as part of a feature task.

- `LazilyRefreshDatabase` over `RefreshDatabase`.
- `WithCachedConfig` and `WithCachedRoutes` traits.
- Global `beforeEach`: `Http::preventStrayRequests()`, `Sleep::fake(syncWithCarbon: true)`, `Exceptions::fake()`.
- Fast local runs: `vendor/bin/pest --parallel --tia`. CI: `--update-shards`, commit `tests/.pest/shards.json`, `--shard=N/M` per job.

## Fortify

- 2FA: `TwoFactorAuthenticatable` on User, `Features::twoFactorAuthentication()`. If the columns are missing: `php artisan vendor:publish --tag=fortify-migrations`.
- Passkeys are built in; no third-party WebAuthn package. `Features::passkeys()`, `PasskeyAuthenticatable` trait + `PasskeyUser` interface on User, frontend with `@laravel/passkeys`.
- Passkey config lives in `config/fortify.php`: `relying_party_id`, `allowed_origins`, `user_handle_secret`, `timeout`.
- Routes: `GET /passkeys/login/options` → `POST /passkeys/login`; `GET /passkeys/confirm/options` → `POST /passkeys/confirm`; `GET /user/passkeys/options` → `POST /user/passkeys`; `DELETE /user/passkeys/{passkey}`.

## Frontend

- Tailwind: `gap-*` for spacing between siblings, not margins or `space-*`.
- Inertia v3 layout props: `setLayoutProps()` from `@inertiajs/vue3`.
