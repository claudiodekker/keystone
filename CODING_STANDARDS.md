# Coding Standards

The reviewer reads this file. Apply every rule to each changed hunk in the diff. Skip anything the repo's tooling already enforces (Pint, PHPStan/Larastan, arch tests, ESLint, type coverage).

Sections 1–16 cover Laravel apps and packages in general. Where a legacy repo already has a different established convention, **follow the repo** within that area; the rule describes the intent. Section 17 applies only to packages, and section 18 only to this repo.

## 1. Sibling changes

- A fix to one member of a **sibling set** (`Create*`/`Update*`/`Delete*` actions, jobs, Form Requests, hooks or listeners of the same kind, controllers sharing a helper) must also cover the other members in the same diff. Grep for them. If a sibling is left alone, the PR description says why.
- Changing a shared value (queue name, config key, enum case, a function's signature) updates every place it is used, not just the one in the ticket.

## 2. Actions and jobs

The class type tells you where code runs: Actions run in the request, jobs run on the queue.

- Actions (`app/Actions`, or `src/Actions` in a package) are the use cases a request triggers, with one public `handle()` method. They only change state: write or update records, record activity, broadcast, dispatch jobs. No slow or failure-prone work: no provisioning, third-party writes, file processing or cleanup.
- Jobs (`app/Jobs`, or `src/Jobs` in a package) are queued, retryable work, and the job's `handle()` holds that work's logic. Don't wrap a single Action in a thin job: if the work belongs on the queue, it belongs in the job. A job may call Actions for shared state changes, not to hold its main logic.
- Jobs are safe to retry: running `handle()` twice gives the same result.
- Commands and tests run queued work with `Job::dispatchSync()` or by calling `handle()` directly. They don't need an Action for that.
- An Action may make a synchronous external call only when the request can't finish without the result (an id or credential needed to create the record, or a payment outcome the user must see). Keep that call small and queue everything after it. Read-only external calls that feed a page render may run in the request; cache them where possible.
- External results come back asynchronously (webhook, polling job, status job). A request never blocks waiting for them.
- Action names are verb then entity (`CreatePost`, `SyncTags`). CRUD uses `Create`, `Update` and `Delete`; a use case that isn't plain CRUD takes its own verb (`PublishPost`, `ArchiveTeam`).
- Actions inject other Actions through the constructor as `protected` properties.
- Actions take typed arguments (`Post $post, string $title, PostStatus $status`), and callers pass them as named arguments. An Action that edits several fields of a record, some of which a request may leave out, takes a shaped `$attributes` array instead (`array{title?: string, summary?: ?string}`), so a field that wasn't sent stays untouched and one sent as `null` is cleared. Create and Update Actions return the model.
- Any Action or job that writes more than one row or model wraps the writes in `DB::transaction()`. Remote API calls stay outside the transaction, and jobs dispatched inside one use `afterCommit()`.
- Side effects that must not fail the operation, such as broadcasts and notifications, are wrapped in `rescue()`.
- Enum-driven branching uses `match`.

## 3. HTTP layer

- Validation lives in a Form Request. Callers pass an Action each validated value through a typed accessor on its key (`$request->string('title')->value()`, `->integer()`, `->enum()`), and an `$attributes` array through `safe()->only([...])`, which leaves out keys the request didn't send.
- A Form Request is named after the Action it feeds, with the Action's verb (`CreatePostRequest` for `CreatePost`, `PublishPostRequest` for `PublishPost`), or after the flow it holds (`LoginRequest`). Not after the controller method (`StorePostRequest`).
- A method on a Form Request derives something (a `period()` that turns `'7d'` into a date), or holds a whole request-bound flow like `LoginRequest::authenticate()`. It doesn't wrap a single typed accessor such as `$this->boolean('is_draft')`.
- A Form Request keeps the stub's `rules()`, with a `//` body when it has none. A request the record's current state refuses (a post already published) fails in the Form Request's `after()`, not in a try/catch in the controller or a check in the Action. Each `after()` closure opens with `if ($validator->errors()->any()) { return; }`, so it only checks input that passed `rules()`.
- Every endpoint is authorized in exactly one place: a policy through route `->can()`, `$this->authorize()`, or Form Request `authorize()`. It has a test proving the denied case, and special account states (suspended, banned, read-only) are part of that authorization.
- A controller method resolves input, calls one Action, and returns a response: `back()` for form submits, `to_route()` for named redirects. Controllers use only resource methods or `__invoke`.
- A controller method injects its Action as `$action`: `store(CreatePostRequest $request, CreatePost $action)`.
- A name users tell records apart by (a team's name, a project's name) is unique: a unique index plus a `unique` rule on create and update, ignoring the record itself on update.
- **Update** requests accept partial payloads: fields use `sometimes`/`nullable`, and cross-field rules (`requiredIf`, uniqueness excluding self) only run when the related fields are present. Every update endpoint has a test that sends a minimal payload.
- A field gated by a feature flag is gated in both places: the Form Request rule and the value the frontend submits.
- Responses return shaped data (API Resource, `only([...])`, Data object), never a raw model or collection. Call `->values()` after filtering a collection that becomes a JSON list.
- Controllers and props do not instantiate external API clients just to build a URL or label; derive those from the model or enum.
- Query-string numbers (`page`, `limit`, `per_page`) are validated and clamped before use.
- Route names say what the page is. A single page is not `*.index`; `index` is only for a list with siblings like `create` and `store`.
- A resource's routes sit in `Route::prefix('posts')->name('posts.')->group(...)`, one verb per line. Routes on one record nest in a `Route::prefix('{post}')` group, with `/` for the record itself (`patch('/')`). A single route needs no group: `Route::get('tags/{tag}', ...)->name('tags.show')`.
- An area's controllers, pages and tests share a subfolder: `Controllers\Admin`, `pages/Admin`, `tests/Feature/Admin`.

## 4. Queries

- Every relation read by a response, Inertia prop, Data object, broadcast or loop is eager-loaded, nested paths included (`post.author.profile`). Keep `Model::preventLazyLoading()` on outside production.
- Queries with an explicit `select([...])` include every column the consumer reads. Adding a field means checking those selects.
- Queries built for a user are scoped to their tenant/organization, including inside `whereHas()` and `with()` closures. `with()` constraints don't filter the parent; use `whereHas()` for that.
- Queries in request paths are bounded by pagination, a limit or a date window.
- A loop over rows doesn't query or write per row. Load the set once, match in memory, and write the changes in one query (`whereKey($ids)->update([...])`, `upsert()`).

## 5. Models and null-safety

- Casts are declared with `protected function casts(): array`, with a documented array shape.
- Relations carry generic return types, e.g. `@return BelongsTo<User, $this>`.
- Columns holding tokens, secrets or keys are listed in `$hidden` or use `encrypted` casts.
- A new column written via `create()`/`update()` is added to `$fillable` in the same diff.
- Every nullable value is guarded (`?->`, `?? default`, early return) before it is dereferenced or passed to a non-nullable parameter. That covers optional relations, nullable enum casts, optional payload keys and framework/event properties.
- Behaviour that isn't the model's own concern (slugs, public ids) goes in a trait under `Models/Concerns`.
- Local scopes read as conditions: `wherePublished()`/`whereNotPublished()`, not `published()` or `active()`.
- A new column doesn't duplicate one the framework already keeps: no `occurred_at` next to `created_at`.
- A state users act on (archived, complete) is a column set by an explicit step, not inferred from matching timestamps. Age-based checks such as staleness stay separate from it.
- Code that touches a `SoftDeletes` model or its parent in a job, command, billing path or route binding makes an explicit choice: call `withTrashed()`, or null-guard the relation.

## 6. Types and values

- Arrays carry shapes (`array{host: string, port: int}`) or `list<T>`. Keep `mixed` out of APIs you own. A shape that keeps growing is a sign it should become a value object.
- Fixed sets of values are backed enums with UPPER_CASE cases. Each enum owns its display text through a `label()` method, usually provided by a shared trait. Status, type and queue-name literals are replaced by enum cases or constants.
- Variables and columns that carry a unit include it in the name, e.g. `$maxUploadMb`, `$sizeBytes`, `$timeoutSeconds`.
- Calls with several parameters of the same type use named arguments. Action calls always do (§2).
- Each step that does real work (reads rows, plans, writes, hashes, calls another class) gets its own statement and a named variable. Don't nest it inside another call's argument, where a reader skims past it, e.g. `$post->update(['tags' => $this->names((new SyncTags(...))->plan())])`.
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
- A job takes the domain model it works on and resolves its dependencies from it (the container, or the model's own configuration), not pre-built services.
- Queued work whose outcome users see is tracked on one record. An Action creates it as `pending` and dispatches the job with it. The job returns early if the record is already `running`, then moves it to `running`, and then to `succeeded` or `failed`. A retry runs the same record again, and `failed()` marks it failed when the queue gives up.
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
- A column that queries filter or sort on gets an index in the same migration, composite with its parent key when the query is scoped by one (`index(['team_id', 'archived_at'])`).
- A migration that may run against a column that already exists guards with `Schema::hasColumn()`.
- Data backfills go in chunked, idempotent Artisan commands (resumable, e.g. `--from-id`), not in migrations.

## 12. Tests

- Every behaviour change and every bug fix ships with a test. A fix's test fails without the fix.
- A test whose expectation flips (e.g. "cannot" becomes "can") is a behaviour change, and the PR explains it.
- A test reads as three phases, arrange, act and assert, separated by a blank line. Capture the result (`$response = $this->post(...)`) and assert on it afterwards, rather than chaining the request into its assertions.
- Data-driven cases use `->with([...])` with named dataset keys, and the test call uses named arguments when several share a type. In PHPUnit repos, match the existing style.
- A test checks real values in both directions (e.g. `-1250` renders as `-12,50` and parses back), not only that a round trip returns its input.
- Tests assert user-facing text through `__('key')`, never a copy of the translated string.
- Feature tests (`tests/Feature`) are the default: each drives one thing a user or the schedule triggers (a request, a command, a scheduled job) end to end and asserts the state it leaves. A Unit test (`tests/Unit`) covers only what a feature test can't reach (a retry, a queue failure hook, a query count, a fake's own assertions) or a very complex module, and a unit test that a feature test already covers is deleted. Both suites boot the app. A unit test file is named after the class it covers (`tests/Unit/SlugGeneratorTest.php`), not the mechanism it tests.
- Tests build data with factories and `->for()`.
- A test of an assertion helper (a fake's `assert*()`, a macro) has one failing case per condition the helper checks, as a dataset, so removing any condition fails a case. A test that would pass with the code under test removed is testing the framework.
- A test's name says the behaviour it proves ("refuses to publish a post without a title"), not the mechanism ("fails").
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

- Before writing a helper, check whether Laravel, Eloquent or an installed package already does it (`is()`, `value()`, casts, collection methods, enum serialization). When a helper is still needed, the PR says why.
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
- A magic number becomes a named constant, not a number with a comment (`protected const EXCERPT_LENGTH = 160;`). A value used once and passed straight to a framework call stays inline (`paginate(50)`).
- An array in `app/` or `src/` with more than one element and at least one key (props, `create([...])` attributes) puts one element per line. A validation rule list and test datasets stay on one line.
- A blank line separates two statements when either spans several lines.
- A guard clause stays on one line. If it doesn't fit, shorten the message rather than wrapping it.
- Multi-line `//` comments and config `|` header blocks use Laravel's **slope**: 3 lines, each 2–4 characters shorter than the one above. Count the text after the `// ` or `| ` prefix. Reword to fit rather than padding.
- Every `TODO` has an owner or a linked issue.
- Every `@phpstan-ignore` names the error identifier.
- The diff touches only code related to the change.

## 16. Domain language and user-facing text

- User-facing text is a key in a Laravel PHP lang file, grouped by a broad area (`lang/{locale}/messages.php`), in every locale. A package reads its keys through its namespace (`__('package::messages.failed')`). A new file for a narrow topic that won't grow is folded into a broader one.
- A PR that adds a domain value (an enum case, a status, a mode) whose meaning isn't in `CONTEXT.md` or an ADR adds it to `CONTEXT.md`.

## 17. Packages

- The public surface is explicit: internal classes are marked `@internal`, supported entry points `@api`.
- Every framework API used exists in the lowest supported version. Newer APIs are gated behind one compatibility check whose `@see` links the upstream change.
- User-facing changes update `CHANGELOG.md`.

## 18. Keystone review rules

- Don't split a call's arguments into local variables unless a variable is reused or names something the call hides. `new SignInAttempt(Keystone::guard(), app(AccountLookup::class))` reads fine inline: resolving a dependency isn't a step that does real work in the §6 sense.
