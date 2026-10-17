# AppTests are the feature suite

Every endpoint behaviour is proven by an AppTest: a Pest test in a package's `app-tests/` that addresses the app's routes by name and goes through the app's own published controllers. AppTests are written first, as the red test of each slice. The same files run in core CI, in Testbench against `packages/keystone/tests/Fixtures`, which holds one concrete subclass per core controller, and in an app that installed Keystone. The app owns its controllers, so only a test that runs through them proves the app still keeps Keystone's guarantees.

Core's own `tests/` keeps only what no app can change. `tests/Feature` holds fault injection through fake types, storage shape, SQL races, unpublished commands and the installer. `tests/Unit` holds what the feature suite answers badly, such as the timing floor's arithmetic, boot checks, pinned formats and the class-loading parity test between response hooks and assertions.

## Consequences

- An AppTest assumes no response shape. Each outcome leaves a core controller through one `send*` hook and is checked through the matching `assert*` method of an assertion trait. The app redefines a method in its own copy of the trait, which uses Keystone's. The test body keeps only the security outcome.
- A sign-in, a sign-out or a write is proven by the user's next request, never by reading the session or a row. Each AppTest request starts like a new request from one browser: the guards are built again, the session holds only what its storage keeps for the browser's cookie, and only the cookies earlier responses set carry over. Each response keeps a copy of the session its own request left.
- The harness grows only through named helpers, each with its own test under `app-tests/Harness`. Putting an intended URL in the session and running app code inside a request are the only ways an AppTest reaches the session.
- The AppTest base case applies the suite's rules itself (no stray HTTP, no real queue push, a frozen clock, asserted reports), because apps never load Keystone's bootstrap. It refreshes the database and takes users from the app's own factory. A bootstrap AppTest fails with a clear message when the app has no user factory or no installed type that can sign in.
- Contract AppTests loop over every installed credential type that serves a surface, and skip with their reason when none does. Flow AppTests use one type per surface.
- Checks keep the line: a Feature test that makes an HTTP request fails unless an allowlist names it with its reason, an AppTest may declare no global function, and an AppTest checks no response but through an assertion trait.
- `app-tests/` is autoloaded only by the monorepo's and the app's `autoload-dev`, never by a package's `autoload`.
