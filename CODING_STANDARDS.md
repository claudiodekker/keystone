# Coding Standards

The reviewer reads this file. Apply every rule to each changed hunk in the diff. Skip anything the repo's tooling already enforces (Pint, PHPStan/Larastan, arch tests, ESLint, type coverage).

The general rules for Laravel apps and packages live in `references/laravel-standards.md` of the `skills:claudio-mode` skill, from the `skills@claudiodekker` plugin this repo enables. Read that file and apply it too. Without the Skill tool, read it at `../skills/skills/claudio-mode/references/laravel-standards.md` (relative to this repo's root) in a cloud session, or at `~/.claude/plugins/marketplaces/claudiodekker/skills/claudio-mode/references/laravel-standards.md` on a laptop. If neither works, say so in the review rather than reviewing against this file alone. The rules below are Keystone's own and win where the two differ.

## Keystone rules

- Don't split a call's arguments into local variables unless a variable is reused or names something the call hides. `new SignInAttempt(Keystone::guard(), app(AccountLookup::class))` reads fine inline.
- A class that makes a security decision (a credential type, a sign-in decision) isn't Swappable: register a plain instance and never bind it in the container, so an app can't put its own in its place.
- A command runs its job with `dispatchSync()`, never by calling `handle()` on it. A job that refuses (the account is already in the state it would set) throws, and the command catches that; the job doesn't return a value for the command to branch on.
- The AppTests run against the app developer's own app, so they never assume a response shape (redirect, JSON, RPC). Each check of how the app answers goes through an overridable `assert*` helper, and the test body keeps only the security outcome.
- An app customises the adapter's responses through one protected method per outcome on the controllers it owns, not through `*Using()` hooks: there are too many outcomes for static hooks.
- Credential types are optional packages. Core and the frontend adapters work with no `keystone-password` installed.
- An expected failure that reaches the exception handler, such as a refusal rendered as a response, implements `ShouldntReport` instead of overriding `report()`. A `report()` returning `false` sends the exception on to the default logging, and `Exceptions::fake()` records it whatever `report()` does.
