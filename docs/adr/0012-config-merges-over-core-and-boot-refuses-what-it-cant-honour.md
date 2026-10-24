# Config merges over core's, and boot refuses what it can't honour

Keystone v3's config failed open: a `null` silently muted alerts, a `0` disabled a limit, an app's published file hid every key added after it was published, and inline fallbacks such as `config('x', [])` papered over whatever was missing. Keystone v4 treats its configuration as part of the security posture instead.

- **One shipped file per package holds every key and its default.** Code reads keys without an inline fallback, so a key missing from the shipped file is a bug a test finds, not a silent default somewhere else. A key exists only when two honest apps would set it differently; units are in the name and every duration is `_seconds`. Security numbers never come from `env()`.
- **The app's file merges over core's recursively.** Maps merge key by key, so a key added in a minor keeps its default in an app whose file predates it. Lists replace whole, so an app can shorten a list or empty it. Laravel's `mergeConfigFrom` only merges the top level and `replaceConfigRecursivelyFrom` merges lists index by index, so core merges with its own rule. A cached config is left as cached, since it was merged when it was cached.
- **Boot checks run when the config is cached, and on every boot of an app without a cached config.** They raise one exception listing every failure, so fixing a config takes one deploy rather than one per problem. A config that fails can't be cached. A check applies in every environment unless the bad value has an honest development use; those apply in production only. Sanity floors have no ceilings: a wrong type, or a `0` or `null` that would turn a control off, is refused, and the only "off" values are the ones a key's name spells out.
- **An app can't replace Keystone's internal services.** Boot refuses an app that binds one of them in the container.
- **`methods` is an allow-list that can only narrow.** `null` allows every installed type on every surface it declares; a bare entry keeps a type's surfaces; a list of surfaces narrows them. Listing a surface the type doesn't serve, a type twice, a type nobody registers, or two packages registering one name fails boot rather than a request.

## Consequences

- An app that caches its config in its deploy, as Laravel recommends, pays nothing per request. An app without a cached config pays about 50 microseconds per boot. `config:cache` and `optimize` run the checks, so a build that caches the config with `APP_ENV=production` needs production settings. `config:clear` and `optimize:clear` skip them, so a refused config can always be cleared.
- An app's test suite boots without a cached config, so its CI runs the checks on every test boot and catches a code change that fails one, such as a binding for an internal service or a dropped middleware.
- A credential type registered under a taken name doesn't throw at registration: the first one is kept and boot reports the clash with every other failure.
- Keystone's tables are migrated on the default connection, so boot refuses a user model on another connection, and the same for every swappable model Keystone reads.
- The hardening floor falls back to the strict default for a value that isn't well formed, which only a runtime `config()` call can produce.
