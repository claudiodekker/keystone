# Security events

Keystone records what happens to an account's security as security events. Every event goes through one recorder, which Keystone calls from the code that made the event happen. The recorder runs three steps, each on its own, so a step that fails is reported to the exception handler and the others still run:

1. It writes a log line.
2. It writes a row of `user_security_events`, when the event is for an account.
3. It dispatches the `ClaudioDekker\Keystone\Events\SecurityEventRecorded` event with the whole entry.

An anonymous event, such as a refused sign-in for an address no account holds, is only a log line. Identical ones, with the same type, IP address and path, are logged once per 60 seconds, counted in the default cache under a hash keyed by a key derived from your app key, so the cache never holds an address. While the cache fails, every one is logged.

## Configuration

```php
'events' => [
    'enabled' => true,
],

'log_channel' => null,
```

Set `events.enabled` to `false` to turn the recorder off in any environment. Nothing is logged, written or dispatched then. Only `false` turns it off.

Set `log_channel` to the name of a channel in your `logging.php` to send the log lines there. Leave it `null` to use the app's default channel.

## Types

| Type | Recorded when |
|---|---|
| `limit.tripped` | A rate limit refused its first attempt of the window. The reason names the limit: `keystone.failed_attempt_limit` or `keystone.request_limit`. |
| `proof.rejected` | A sign-in was refused for an account that the typed address named. |
| `request.rejected` | A request was refused before it ran. The reason says why: `keystone.cross_site_request`. The event is anonymous. |
| `session.ended` | Keystone ended the session. The reason says why: `keystone.expired` when it passed its absolute lifetime. |
| `signed_in` | A session signed in. |
| `signed_out` | The user signed out. |

## Fields

Every event has the same fields, whatever its type. There is no free-form metadata.

| Field | Holds |
|---|---|
| `id` | A ULID, the same in the row, the log line and the dispatched event. |
| `occurred_at` | When the event happened, in UTC, whatever your app's timezone is. |
| `type` | One of the types above. |
| `user_id` | The account the event is for, or null for an anonymous event. The column has no foreign key, so a removed account's rows keep its id. |
| `actor` | Who made it happen: `user`, `operator` or `system`. |
| `flow` | The flow the request was in, such as `sign-in`, or null when it was in none, such as signing out. |
| `credential_type` | The name of the credential type involved, if any. |
| `credential_id` | The credential involved, if any. A rejection names a credential only when it belongs to the account the attempt named. |
| `credential_label` | The credential's label as it was at the time, when it has one. |
| `reason` | A short code of `a-z`, `0-9`, `.` and `_`, at most 64 characters, prefixed by the credential type or by `keystone.`. Anything else is stored as `<type>.invalid_reason`. |
| `ip_address` | The client's address, encrypted at rest. |
| `location` | The address's location, encrypted at rest, when it was looked up. |
| `user_agent` | The client's user agent, cleaned of invalid UTF-8 and control characters, cut to 512 characters and encrypted at rest. |
| `known_device` | Whether the browser was a known device of the account, when that was checked. |
| `request_id` | A ULID minted once per request, shared by every event the request records. |

The `ip_address`, `location` and `user_agent` fields are left out of the model's array form. Read them from the model's attributes when a page needs them.

## The log line

The message of every log line is `keystone.security_event`. Every field travels in the context array, with the IP address, location and user agent in clear, since the log is the operator's. A value that reaches a field, such as a user agent, is JSON-escaped in the context and cannot forge a second line.

```
[2026-10-17 15:04:05] local.INFO: keystone.security_event {"id":"01k7qz1g3w8ndq0y9q1ztp7s6e","occurred_at":"2026-10-17T15:04:05.000000Z","type":"signed_in","user_id":42,"actor":"user","flow":"sign-in","credential_type":"password","credential_id":7,"credential_label":null,"reason":null,"ip_address":"203.0.113.9","location":null,"user_agent":"Mozilla/5.0 (X11; Linux x86_64)","known_device":null,"request_id":"01k7qz1g3v2f0h0y9q1ztp7s6d"}
```

## The request context

Keystone adds one piece of global middleware after the app's trusted-proxy middleware. It reads the client's IP address and user agent once per request, and keeps them with the address's subnet, the path and a new request id. Every event in the request carries that context, so set your trusted proxies the way Laravel documents, and the recorded address is the client's.

## Listening

Listen for `SecurityEventRecorded` to act on events in your app. The `entry` property holds the event as a `ClaudioDekker\Keystone\Models\UserSecurityEvent`, and your listener branches on its `type`. The entry may be unsaved when its row could not be written, so read it rather than reloading it by id.

Your listener runs inside the request that recorded the event, inside the sign-in's timing floor for a refused sign-in. Queue a listener that does slow work, such as sending mail or calling an API.
