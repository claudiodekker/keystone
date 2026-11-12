# Security events

Keystone records what happens to an account's security as security events. Every event goes through one recorder, which Keystone calls from the code that made the event happen. The recorder runs four steps, each on its own, so a step that fails is reported to the exception handler and the others still run:

1. It writes a log line.
2. It writes a row of `user_security_events`, when the event is for an account.
3. It mails the account's owner an [alert](alerts.md), when the event is for an account and its type's slot holds a notification.
4. It dispatches the `ClaudioDekker\Keystone\Events\SecurityEventRecorded` event with the whole entry.

An anonymous event, such as a refused sign-in for an address no account holds, is only a log line. Identical ones, with the same type, IP address and path, are logged once per 60 seconds, counted in the default cache under a hash keyed by a key derived from your app key, so the cache never holds an address. While the cache fails, every one is logged. An operator's anonymous event, such as ending every account's sessions, is logged every time.

## Configuration

```php
'events' => [
    'enabled' => true,
],

'log_channel' => null,
```

Set `events.enabled` to `false` to turn the recorder off in any environment. Nothing is logged, written, mailed or dispatched then. Only `false` turns it off.

Set `log_channel` to the name of a channel in your `logging.php` to send the log lines there. Leave it `null` to use the app's default channel.

## Types

| Type | Recorded when |
|---|---|
| `account.suspended` | An operator suspended the account. |
| `account.unsuspended` | An operator lifted the account's suspension. |
| `challenge.abandoned` | A browser that isn't a [known device](alerts.md#new-devices) passed the account's first factor and never passed the challenge or signed in. A [sweep](alerts.md#abandoned-challenges) records it once the challenge has waited 7 minutes, at the time of the hold and with that request's IP address and user agent, as the `system`. |
| `credential.added` | A credential was added to the account, such as a second factor enrolled while a sign-in was held at enrollment. |
| `device_cookie.reused` | A browser signed in with the device cookie of a [known device](alerts.md#new-devices) whose value a later sign-in replaced, so two browsers may share it. |
| `limit.tripped` | A rate limit refused its first attempt of the window. The reason names the limit: `keystone.daily_failed_attempt_limit`, `keystone.failed_attempt_limit` or `keystone.request_limit`. |
| `proof.rejected` | A sign-in was refused for an account that the typed address named, or a challenge answer was refused for the held account. A TOTP code from a step already accepted has the reason `totp.replayed`, and an account's last recovery code, kept while recovery codes are required, has the reason `recovery-code.last_code`. |
| `recovery_code.used` | A recovery code answered the challenge and was spent. |
| `recovery_codes.generated` | A set of recovery codes was stored. A first set alerts nobody. |
| `request.rejected` | A request was refused before it ran. The reason says why: `keystone.cross_site_request` for a cross-site request, which is anonymous, `keystone.dead_remember_cookie` for a [remember-me](remember-me.md) cookie that can no longer sign in, against its account when its token was found, and `keystone.enrollment_owed` for a remembered return whose account owes enrollment. |
| `session.ended` | Keystone ended the session. The reason says why: `keystone.expired` when it passed its absolute lifetime, `keystone.epoch` when an operator ended the account's sessions or suspended it after it signed in, `keystone.demoted` when the account came to owe [enrollment](enrollment.md#signed-in-sessions-that-newly-owe). |
| `sessions.terminated` | An operator ended every session of the account. Ending every account's sessions records one about nobody, with reason `keystone.every_account`. |
| `sign_in.held` | A right first factor was held for what the account owes. The reason names the stage: `keystone.challenge` or `keystone.enrollment`. A challenge answer that moves on to enrollment records it again. |
| `sign_in.voided` | A pending sign-in was dropped because its account's credential epoch moved or the account was suspended since the hold. The reason is `keystone.epoch`. |
| `signed_in` | A session signed in, on its first factor, at the challenge or at the end of enrollment, or a [remember-me](remember-me.md) cookie signed it in again, with the reason `keystone.remembered`. `known_device` says whether the browser was a [known device](alerts.md#new-devices) of the account. |
| `signed_out` | The user signed out. |
| `sudo.failed` | An answer on the [sudo page](sudo.md) was refused. The reason is the type's own, `keystone.barred` for an account suspended or owing enrollment, `keystone.unbindable_subnet` for a request whose address isn't an IP address, or `recovery-code.last_code` for the account's last recovery code. |
| `sudo.granted` | The session was granted [sudo](sudo.md), with the reason `keystone.sign_in` when a sign-in granted it, or with the credential that answered on the sudo page. |
| `sudo.network_changed` | A live sudo grant was used from another network than the one it was granted on, so Keystone revoked it. |
| `sudo.revoked` | The user ended a live sudo grant. |

## Fields

Every event has the same fields, whatever its type. There is no free-form metadata.

| Field | Holds |
|---|---|
| `id` | A ULID, the same in the row, the log line and the dispatched event. |
| `occurred_at` | When the event happened, in UTC, whatever your app's timezone is. |
| `type` | One of the types above. |
| `user_id` | The account the event is for, or null for an anonymous event. The column has no foreign key, so a removed account's rows keep its id. |
| `actor` | Who made it happen: `user`, `operator` or `system`. An event an operator command or job records is the operator's, and one a scheduled sweep records is the system's. |
| `flow` | The flow the request was in, `sign-in`, `challenge`, `enrollment` or `sudo`, or null when it was in none, such as signing out. |
| `credential_type` | The name of the credential type involved, if any. |
| `credential_id` | The credential involved, if any. A rejection names a credential only when it belongs to the account the attempt named. |
| `credential_label` | The credential's label as it was at the time, when it has one. |
| `reason` | A short code of `a-z`, `0-9`, `.` and `_`, at most 64 characters, prefixed by the credential type or by `keystone.`. Anything else is stored as `<type>.invalid_reason`. |
| `ip_address` | The client's address, encrypted at rest. |
| `location` | The address's location, encrypted at rest, when it was looked up. |
| `user_agent` | The client's user agent, cleaned of invalid UTF-8 and control characters, cut to 512 characters and encrypted at rest. |
| `known_device` | Whether the browser was a [known device](alerts.md#new-devices) of the account, on `signed_in`. Null on every other type. |
| `request_id` | A ULID minted once per request, shared by every event the request records. Null on an event a scheduled sweep records. |
| `operator` | The operator a command or job names, cut to 64 characters with control characters replaced by spaces, or null when no operator acted. |

The `ip_address`, `location` and `user_agent` fields are left out of the model's array form. Read them from the model's attributes when a page needs them.

## The log line

The message of every log line is `keystone.security_event`. Every field travels in the context array, with the IP address, location and user agent in clear, since the log is the operator's. A value that reaches a field, such as a user agent, is JSON-escaped in the context and cannot forge a second line.

```
[2026-10-17 15:04:05] local.INFO: keystone.security_event {"id":"01k7qz1g3w8ndq0y9q1ztp7s6e","occurred_at":"2026-10-17T15:04:05.000000Z","type":"signed_in","user_id":42,"actor":"user","flow":"sign-in","credential_type":"password","credential_id":7,"credential_label":null,"reason":null,"ip_address":"203.0.113.9","location":null,"user_agent":"Mozilla/5.0 (X11; Linux x86_64)","known_device":null,"request_id":"01k7qz1g3v2f0h0y9q1ztp7s6d","operator":null}
```

## The request context

Keystone adds one piece of global middleware after the app's trusted-proxy middleware. It reads the client's IP address and user agent once per request, and keeps them with the address's subnet, the path and a new request id. Every event in the request carries that context, so set your trusted proxies the way Laravel documents, and the recorded address is the client's.

## Alerts

An event can also mail the account's owner an alert. The [alerts page](alerts.md) covers which types alert, who gets each mail, what it says, new devices and abandoned challenges, and how to send your own.

## Listening

Listen for `SecurityEventRecorded` to act on events in your app. The `entry` property holds the event as a `ClaudioDekker\Keystone\Models\UserSecurityEvent`, and your listener branches on its `type`. The entry may be unsaved when its row could not be written, so read it rather than reloading it by id.

Your listener runs inside the request that recorded the event, inside the sign-in's timing floor for a refused sign-in. Queue a listener that does slow work, such as sending mail or calling an API.
