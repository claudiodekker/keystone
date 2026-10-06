# Security events

Keystone records every security-relevant event itself, inline, from the code that made it happen. Nothing in your app needs to be wired up, and no listener or middleware of yours can turn recording off.

Each event is:

- written as one log line to the channel named by `keystone.log_channel`, or your app's default channel when that is null;
- added to the account's audit trail in the `user_security_events` table, when the event is about an account;
- mailed to the account's owner, when its type has an alert (see [Security alerts](security-alerts.md));
- dispatched as `ClaudioDekker\Keystone\SecurityEventRecorded`, carrying the whole entry as `$event->event`. Listen for it and branch on `$event->event->type` to react in your app.

If any of these steps throws, Keystone reports the exception to your exception handler and carries on with the others. The response never changes.

Listeners run during the request, and a refused sign-in only takes its fixed minimum time while they finish inside it. Queue any listener that does slow work, such as sending mail or calling an API, so response times can't reveal which addresses have an account.

## Turning recording off

Recording is on by default. To turn it off entirely, set `keystone.events.enabled` to `false`: nothing is then logged, stored, alerted or dispatched, and your users have no audit trail of their sign-ins. Any value other than `true` or `false` stops your app from booting (see [Configuration](configuration.md#boot-checks)).

To keep the audit trail but drop the log line, set `keystone.log_channel` to Laravel's `null` channel instead.

## Types

| Type | Recorded when |
|---|---|
| `signed_in` | a session signs in; `known_device` says whether the browser was one of the account's [known devices](security-alerts.md#new-devices), and alerts the account's owner when it wasn't |
| `sign_in.held` | a first factor is proven for an account that owes the [second-factor challenge](challenge.md) or [enrollment](enrollment.md); the reason is `keystone.challenge` or `keystone.enrollment`, and the credential is the first factor. A passed challenge whose account still owes enrollment records it too |
| `sign_in.voided` | a pending sign-in is dropped because its account was suspended or soft-deleted, or its sessions were ended, before the challenge or the enrollment was finished |
| `proof.rejected` | a sign-in naming an account, or an answer to its challenge or enrollment, is refused: a rejected proof, a proof naming a credential the account doesn't hold, an answer of the first factor's type (`keystone.first_factor`), a recovery code the account doesn't hold (`recovery-code.mismatch`) or its last one while codes are required (`keystone.last_recovery_code`), an enrollment answer that enrolls nothing (`keystone.not_enrolled`) or arrives once the account holds a second factor (`keystone.second_factor_held`) or recovery codes (`keystone.recovery_codes_held`), an answer whose account's sessions were ended meanwhile (`keystone.superseded`), or an account that is suspended (`keystone.barred`) |
| `signed_out` | the user signs out |
| `session.ended` | Keystone ended a session; the reason says why: `expired` once the [absolute lifetime](configuration.md#session-lifetime) passed, or `demoted` once its account newly owed [enrollment](enrollment.md#signed-in-sessions-that-newly-owe) |
| `sessions.terminated` | an operator ended every session of the account, or of every account with reason `keystone.every_account` (see [Operator commands](operator-commands.md#ending-sessions)); alerts the account's owner |
| `account.suspended` | an operator suspended the account (see [Operator commands](operator-commands.md#suspending-accounts)); alerts the account's owner |
| `account.unsuspended` | an operator lifted the account's suspension; alerts the account's owner |
| `credential.added` | a credential was added to the account, such as a second factor at [enrollment](enrollment.md); alerts the account's owner |
| `recovery_codes.generated` | a new set of recovery codes was saved; alerts the account's owner only when it replaced a set |
| `recovery_code.used` | a [recovery code](challenge.md#recovery-codes) answered the challenge and was spent; alerts the account's owner with how many codes are left |
| `device_cookie.reused` | a sign-in carried a [device cookie](security-alerts.md#new-devices) that a later sign-in had replaced, so two browsers held the same cookie; alerts the account's owner |
| `limit.tripped` | a rate limit refuses its first attempt in a window; the reason names the limit (`keystone.request_limit` or `keystone.failed_attempt_limit`), and a failed-attempt trip alerts the account's owner |
| `request.rejected` | a request to change something on a Keystone route is refused as cross-site; the reason is `keystone.cross_site` (see [Hardening](hardening.md)) |

A refused sign-in for an address no account holds records nothing, so a typed identifier is never stored.

## Fields

Every event has the same fields, and never typed input, secrets, codes, tokens or session ids.

| Field | Holds |
|---|---|
| `occurred_at` | when, in UTC |
| `type` | one of the types above |
| `user_id` | the account, or null |
| `actor` | `user`, `operator` or `system` |
| `operator` | who acted, as the operator command or job named them, cut to 64 characters with control characters and line and paragraph separators replaced by spaces; null otherwise |
| `flow` | where it happened, such as `sign-in`, `challenge` or `enrollment` |
| `credential_type`, `credential_id`, `credential_label` | the credential involved and its label at the time; a refusal names only a credential the account holds |
| `reason` | a short code such as `form.mismatch` or `keystone.barred`; a reason that isn't a lowercase code of at most 64 characters prefixed by its credential type or `keystone.` is stored as `<type>.invalid_reason` |
| `ip_address`, `user_agent` | the request's, the user agent cut to 512 characters with control characters and line and paragraph separators replaced by spaces |
| `location` | always null (alerts look the location up when they are sent, see [IP location](security-alerts.md#ip-location)) |
| `known_device` | for `signed_in`, whether the browser was one of the account's known devices; null otherwise |
| `request_id` | an id Keystone gives each request, shared by every event it records |

`ip_address`, `location` and `user_agent` are encrypted in the table and left out of the model's array and JSON form.

## The log line

Every event logs one `info` line with the message `keystone.security_event` and every field above in its context, `ip_address` and `user_agent` included. Events about nobody are only logged, and an identical one (same type, IP address and path) is logged at most once a minute. While your cache is down, every one is logged.

## Retention and erasure

Keystone has no retention setting and no prune command, and nothing deletes a row from `user_security_events`. The table grows until your app deletes from it. `occurred_at` is indexed, so a scheduled delete by age is cheap. In `routes/console.php`, for example:

```php
use ClaudioDekker\Keystone\SecurityEvent;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => SecurityEvent::where('occurred_at', '<', now()->subDays(90)->utc())->delete())->daily();
```

The table has no foreign key to your users table, unlike the credentials, addresses and recovery codes, which the database deletes with their account. When an account is deleted for good, its events stay with their `user_id`, and so do their encrypted `ip_address` and `user_agent`. Keystone assumes your app never reuses a deleted user's id. If you must erase an account's events with it, delete them in the code that deletes the account:

```php
SecurityEvent::where('user_id', $user->getKey())->delete();
```

That delete doesn't reach two things. The log line of each event carries the IP address and user agent in clear, so it lives as long as your log channel keeps it. And when the person you erase also ran operator commands, the name they gave `--operator` is in the `operator` column of rows about other accounts, which `user_id` doesn't find.

A wrong password for an existing account writes a `proof.rejected` row, so anyone who knows the account's address can add rows to its trail: up to 20 an hour from any IP address, plus the `limit.tripped` row when the limit trips. A wrong password for an address no account holds writes none. Only the [rate limits](rate-limiting.md) bound it.
