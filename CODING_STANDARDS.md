# Coding Standards

The reviewer reads this file. Apply every rule to each changed hunk in the diff. Skip anything the repo's tooling already enforces (Pint, PHPStan/Larastan, arch tests, ESLint, type coverage).

Sections 1–16 cover Laravel apps and packages in general. Where a legacy repo already has a different established convention, **follow the repo** within that area; the rule describes the intent. Section 16 applies only to packages.

## 1. Sibling changes

- A fix to one member of a **sibling set** (`Create*`/`Update*`/`Delete*` actions, jobs, Form Requests, hooks or listeners of the same kind, controllers sharing a helper) must also cover the other members in the same diff. Grep for them. If a sibling is left alone, the PR description says why.
- Changing a shared value (queue name, config key, enum case, a function's signature) updates every place it is used, not just the one in the ticket.

## 2. Actions

- Business logic lives in an Action class under `app/Actions`, with one public `handle()` method. Controllers, jobs and commands call Actions.
- Action names are verb then entity (`CreatePost`, `SyncTags`). Verbs come from a small fixed set: `Create`, `Update`, `Delete`, `Sync`, `Attach`, `Detach`, `Ensure`.
- Actions inject other Actions through the constructor as `protected` properties.
- Any Action that writes more than one row or model wraps the writes in `DB::transaction()`. Remote API calls stay outside the transaction, and jobs dispatched inside one use `afterCommit()`.
- Side effects that must not fail the operation, such as broadcasts and notifications, are wrapped in `rescue()`.
- Enum-driven branching uses `match`.

## 3. HTTP layer

- Validation lives in a Form Request. Actions receive validated data (`validated()` or `safe()->only([...])`).
- Every endpoint is authorized in exactly one place: a policy through route `->can()`, `$this->authorize()`, or Form Request `authorize()`. It has a test proving the denied case, and special account states (suspended, banned, read-only) are part of that authorization.
- A controller method resolves input, calls one Action, and returns a response: `back()` for form submits, `to_route()` for named redirects. Controllers use only resource methods or `__invoke`.
- **Update** requests accept partial payloads: fields use `sometimes`/`nullable`, and cross-field rules (`requiredIf`, uniqueness excluding self) only run when the related fields are present. Every update endpoint has a test that sends a minimal payload.
- A field gated by a feature flag is gated in both places: the Form Request rule and the value the frontend submits.
- Responses return shaped data (API Resource, `only([...])`, Data object), never a raw model or collection. Call `->values()` after filtering a collection that becomes a JSON list.
- Controllers and props do not instantiate external API clients just to build a URL or label; derive those from the model or enum.
- Query-string numbers (`page`, `limit`, `per_page`) are validated and clamped before use.

## 4. Queries

- Every relation read by a response, Inertia prop, Data object, broadcast or loop is eager-loaded, nested paths included (`post.author.profile`). Keep `Model::preventLazyLoading()` on outside production.
- Queries with an explicit `select([...])` include every column the consumer reads. Adding a field means checking those selects.
- Queries built for a user are scoped to their tenant/organization, including inside `whereHas()` and `with()` closures. `with()` constraints don't filter the parent; use `whereHas()` for that.
- Queries in request paths are bounded by pagination, a limit or a date window.

## 5. Models and null-safety

- Casts are declared with `protected function casts(): array`, with a documented array shape.
- Relations carry generic return types, e.g. `@return BelongsTo<User, $this>`.
- Columns holding tokens, secrets or keys are listed in `$hidden` or use `encrypted` casts.
- A new column written via `create()`/`update()` is added to `$fillable` in the same diff.
- Every nullable value is guarded (`?->`, `?? default`, early return) before it is dereferenced or passed to a non-nullable parameter. That covers optional relations, nullable enum casts, optional payload keys and framework/event properties.
- Code that touches a `SoftDeletes` model or its parent in a job, command, billing path or route binding makes an explicit choice: call `withTrashed()`, or null-guard the relation.

## 6. Types and values

- Arrays carry shapes (`array{host: string, port: int}`) or `list<T>`. Keep `mixed` out of APIs you own. A shape that keeps growing is a sign it should become a value object.
- Fixed sets of values are backed enums with UPPER_CASE cases. Each enum owns its display text through a `label()` method, usually provided by a shared trait. Status, type and queue-name literals are replaced by enum cases or constants.
- Variables and columns that carry a unit include it in the name, e.g. `$maxUploadMb`, `$sizeBytes`, `$timeoutSeconds`.
- Calls with several parameters of the same type use named arguments.
- Money is a `Brick\Money\Money`, never a float or an int.
- In apps, collections are preferred over manual loops for transformations.
- `json_encode()` on external or user data uses `JSON_THROW_ON_ERROR`, plus `JSON_INVALID_UTF8_SUBSTITUTE` where binary data is possible.
- Strings written to length-limited columns are truncated at the boundary.
- External input is parsed defensively: check that a delimiter exists before `explode()` indexing, and encode values interpolated into URLs (`rawurlencode`, `route()`, `encodeURIComponent`).

## 7. Errors and external services

- Each integration sits behind a contract with two implementations: a real one and a fake that is bound in tests. The fake accepts Mockery expectations through a shared trait, so tests use one kind of test double rather than ad-hoc mocks. `Http::preventStrayRequests()` is on in the test bootstrap.
- Fakes return payloads copied from real API responses (fixtures). Enum-typed fields in fakes return the enum, not a string.
- An HTTP-backed service receives its configuration (credentials, base URL) through constructor properties. It builds every request from a single protected method that returns a `PendingRequest` with:
    - the base URL and auth
    - `retry()` whose `when:` callback only retries transient failures such as `ConnectionException`
    - `throw()` that turns a failed response into the service's own exception, which carries the response
- A `throw()` callback must actually `throw` its exception (`fn (Response $response) => throw new …`). If the callback only returns the exception, Laravel ignores it and throws its generic `RequestException`.
- Known API error codes become specific exceptions, e.g. `SlugAlreadyTakenException`.
- Catch the specific exception. Catch `Throwable` only at a boundary that must not break its host (listeners, middleware, package hooks, log handlers), and `report()` it there.
- Calls to external providers check for empty input first, e.g. no recipients to notify or a blank API key.
- List and batch calls to external APIs follow pagination tokens and chunk to the provider's batch limit, with no hard-coded iteration cap.

## 8. Jobs and long-running processes

- Job settings (`$tries`, `$timeout`, `$backoff`, `$maxExceptions`) are `public`; the worker ignores protected ones.
- Jobs that take a model set `public bool $deleteWhenMissingModels = true`, so a model deleted before the job runs drops the job instead of failing it.
- Queued jobs that call external services use a shared retry concern (escalating `backoff()` plus `retryUntil()`) rather than setting their own `$tries`/`$backoff`.
- Delete and cleanup jobs are idempotent: a remote resource that is already gone counts as success.
- Static properties and singletons that hold request- or job-specific data are reset between executions, because Octane and queue workers reuse the process.

## 9. Configuration and feature flags

- `env()` is called only in `config/`.
- Config values are cacheable: class-strings and scalars, never objects or closures.
- Config is read with dot notation (`config('a.b')`).
- New feature flags default to off.

## 10. Broadcasting and realtime

- Every state change the UI shows (status, create, delete) dispatches its broadcast event with `->toOthers()`. Deletes have their own event.
- `broadcastWith()` returns a minimal set of scalar fields (`$model->only([...])`), keyed by the entity the event is named for. Whole models and third-party payloads are not broadcast.
- Frontend listeners guard every event field they read.
- Channel names are built only from a truthy id. Otherwise pass `null` to the subscribe hook, so a channel like `private-posts.undefined` never happens.

## 11. Database

- Migrations are forward-only: no `down()` method.
- Foreign keys use `->constrained()` with an explicit `cascadeOnDelete()` or `nullOnDelete()`.
- A migration that may run against a column that already exists guards with `Schema::hasColumn()`.
- Data backfills go in chunked, idempotent Artisan commands (resumable, e.g. `--from-id`), not in migrations.

## 12. Tests

- Every behaviour change and every bug fix ships with a test. A fix's test fails without the fix.
- A test whose expectation flips (e.g. "cannot" becomes "can") is a behaviour change, and the PR explains it.
- In Pest, tests use `it('does something')`, group related cases in `describe()`, and use `->with([...])` for data-driven cases. In PHPUnit repos, match the existing style.
- Tests build data with factories and `->for()`.
- Deterministic tests use:
    - `fake()->unique()` for unique columns
    - order-insensitive assertions for sets (`toEqualCanonicalizing()`)
    - `->fresh()`/`->refresh()` after an Action has mutated a model
    - a frozen or faked clock (`travelTo()`) rather than wall-clock time or loop-count thresholds
- Global state a test changes (env, statics, config) is restored in teardown.
- Event and queue side effects are asserted with `Event::fake([...])`/`Queue::fake()` plus `assertDispatched()`.

## 13. Frontend (Inertia)

- Imports use the absolute `@/` alias, not relative paths.
- On an edit form, `useForm()` and local state start from the existing model's values. The form's reset handler resets every piece of local state.
- Components use `<script setup lang="ts">`. Pages set their layout with `defineOptions({ layout })`, and forms use Inertia's `useForm()`.
- Conditional classes go through `cn()` from `@/lib/utils` (`twMerge(clsx(...))`). Component variants use `class-variance-authority`.

## 14. Methods and classes

- Guard clauses handle edge cases first and return early; the happy path comes last.
- An orchestrating method reads as a short list of named steps. A phase that needs a comment to explain it becomes a named method.
- Callers get named variants (`findOrFail()`, `firstOrCreate()`) instead of a `null` return they must branch on.
- Verbs keep the framework's meaning: `make` builds without saving, `create` saves; `get`/`has`/`is`/`forget`/`flush` behave as they do in the framework.
- Parameters are ordered subject first, then options, with the `$default` argument, callbacks and variadics last.
- Classes stay open to extension: no `final`, and members that aren't public are `protected` rather than `private`, so subclasses can override them.
- An empty constructor body holds a single `//` line, as in Laravel's own stubs.
- Builders and configurators return `$this`. Value objects are immutable and return `new static(...)` from each transform.
- Exceptions carry state in public properties with fluent setters, and keep a short message. An HTTP status goes in a `$status` property, never the SPL `$code` argument.

## 15. Code hygiene

- Delete code rather than commenting it out. Temporary disables ("re-enable after X") are not merged.
- Method docblocks are one imperative line ending in a period (`Determine if…`, `Get the…`), then tags. Property docblocks are a noun phrase (`The event dispatcher instance.`). Class docblocks hold only tags (`@template`, `@mixin`, `@method`).
- `@param`/`@return` carry the type. Add a description only for a constraint the name can't express.
- Inline `//` comments are kept only for a vendor quirk, a gotcha or a cross-reference. A comment that restates the next line is deleted.
- Comments describe the domain. Comments aimed at tools or reviewers ("kills the mutant", "proves the X branch", "why this ignore exists") are removed; that belongs in the commit message.
- A magic number becomes a named constant, not a number with a comment (`private const EXCERPT_LENGTH = 160;`).
- Multi-line `//` comments and config `|` header blocks use Laravel's **slope**: 3 lines, each 2–4 characters shorter than the one above. Count the text after the `// ` or `| ` prefix. Reword to fit rather than padding.
- Every `TODO` has an owner or a linked issue.
- Every `@phpstan-ignore` names the error identifier.
- The diff touches only code related to the change.

## 16. Packages

- The public surface is explicit: internal classes are marked `@internal`, supported entry points `@api`.
- Every framework API used exists in the lowest supported version. Newer APIs are gated behind one compatibility check whose `@see` links the upstream change.
- User-facing changes update `CHANGELOG.md`.

## 17. Keystone review rules

- Don't split a call's arguments into local variables unless a variable is reused or names something the call hides. `new SignInAttempt(Keystone::guard(), app(AccountLookup::class))` reads fine inline.
- A class that makes a security decision (a credential type, a sign-in decision) isn't Swappable: register a plain instance and never bind it in the container, so an app can't put its own in its place.
- Slow work, such as making a hash, gets its own statement. Don't hide it inside another call's argument.
- In a test, keep the act apart from the assert: the call under test never sits inside `expect()`.
- Test what a request can show through the request (sign-in, validation, stored rows). Unit tests cover only what a request can't show, such as timing work or a surface with no endpoint yet; when a unit and a feature test catch the same defect, drop the unit case.
- Name a test file after the class it covers (`KeystoneServiceProviderTest`), not after one behaviour.
