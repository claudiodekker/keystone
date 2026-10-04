# A known device is a re-keyed cookie that only splits failed attempts

A sign-in from a browser the account hasn't used should alert its owner, and failures from an attacker's browsers shouldn't lock the owner out of their own. Both need Keystone to recognise the owner's browser, and an IP address or user agent can't: the attacker can copy a user agent and share a network.

- **A known device is a cookie.** Each sign-in hands the browser `__Host-keystone_device`, 256 random bits, and stores its SHA-256 digest per account with the user agent and IP address as encrypted display labels. Nothing else decides: a new IP on a known browser doesn't alert, and a new browser on a known IP does. A plain digest is enough for a random value nobody can guess, so `keystone:rekey` has nothing to recompute for it. Laravel doesn't encrypt the cookie, since its digest is all the server checks and a value it never handed out matches nothing.
- **Every sign-in re-keys.** The browser gets a fresh value at each sign-in, and every account row holding the old digest moves to the new one. A copied or planted value is known only until that browser's next sign-in, after which it matches nothing and its sign-ins alert. Accounts sharing a browser keep knowing it, because they move with it.
- **Known means seen within the retention.** A device unseen for `retention.known_devices_seconds` (90 days) is new again, whether or not the nightly prune has deleted its row yet, so the alert never waits on the scheduler.
- **It only splits failed attempts.** The failed-attempt count of a known device's browser is keyed by its digest, the rest by `other`, each with the full allowance. That is lockout immunity and nothing more: a known device is challenged, limited and alerted about like any other browser. Being known is checked against the account the attempt names, so a value another account knows counts as `other`.
- **The guard runs the check.** `KeystoneGuard::signIn()` reads the cookie, decides whether it was known, re-keys and queues the new cookie before it touches the session, and returns the verdict. Every flow that completes a sign-in records it as `signed_in`'s `known_device`, and the recorder alerts only when it is false. A failure to write the device row fails the sign-in rather than signing in without an alert.

## Consequences

- A failed-attempt trip alerts the account's owner once per window and count, through the `limit.tripped` slot. A request-limit trip records the event and alerts nobody, since it names no credential and a busy shared network would mail the owner for nothing.
- Each known device adds one indexed lookup to a sign-in attempt that names an account, inside the timing floor.
- Ending an account's sessions forgets its devices, and `--all` forgets every account's, so a cookie an attacker held stops counting apart and the owner's next sign-ins alert. Suspension ends sessions the same way.
- The sign-in that ends registration or recovery will be exempt from the alert when those flows land; until then every new device alerts.
