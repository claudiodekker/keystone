# One inline recorder for security events

Every security event goes through one recorder, which core calls inline from the code that made the event happen: the sign-in attempt, the sign-out controller, and later every action, refusing controller, command and scheduled job. Core registers no listeners. The recorder runs four steps, each in its own `try` that reports the failure and moves on: a log line to `keystone.log_channel` (the app's default channel when null), a row in `user_security_events` when the event is about an account, the security alert (a later step), and the `SecurityEventRecorded` event with the whole entry. Types come from one closed enum and every entry has the same fixed fields, with no free-form metadata.

Recording inline, rather than from listeners on Laravel's auth events, means an app can't turn the audit trail off by detaching a listener or forgetting to register one, and rescuing each step means a broken mailer, log channel or app listener never changes a response or skips the others. Building the entry is rescued too: if even that fails (a lost encryption key), the failure is reported and nothing is recorded. The row is written with `saveQuietly()`, so a model listener can't cancel it either. Fixed fields replace v3's free-form metadata, which let typed input reach the trail.

## Consequences

- The log line's message is always `keystone.security_event` and every field travels in its context array, so typed input that reaches a field (a user agent) is JSON-escaped and can't forge a second line.
- Events about nobody are log lines only. Identical ones (same type, IP address and path) are logged once per 60 seconds, counted in the default cache; while the cache fails every one is logged.
- IP address, location and user agent are encrypted at rest and hidden from the model's array form. The log line carries them in clear, since the log is the operator's and is the record of events about nobody.
- A reason is a short code of `a-z`, `0-9`, `.` and `_`, at most 64 characters, prefixed by the event's credential type or by `keystone.`; anything else is stored as `<type>.invalid_reason`, so a method can't smuggle typed input through it.
- Core's global middleware captures the request's IP address, user agent, path and a new ULID request id once per request, after the app's trusted-proxy middleware, into a scoped instance that resets between Octane requests.
- A refused sign-in naming an account records inside the sign-in's 300 ms timing floor, and one naming nobody records nothing, so the floor hides the difference only while logging, the insert and the app's synchronous listeners finish inside it. Listeners doing slow work (mail, HTTP) should be queued; the docs say so.
- Control characters in the user agent and credential label are replaced by spaces before they reach any field, because Laravel's default log formatter turns escaped newlines in a JSON context back into real ones.
