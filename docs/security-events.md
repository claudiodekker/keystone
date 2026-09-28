# Security events

Keystone records every security-relevant event itself, inline, from the code that made it happen. Nothing in your app needs to be wired up, and nothing your app does can turn recording off.

Each event is:

- written as one log line to the channel named by `keystone.log_channel`, or your app's default channel when that is null;
- added to the account's audit trail in the `user_security_events` table, when the event is about an account;
- dispatched as `ClaudioDekker\Keystone\SecurityEventRecorded`, carrying the whole entry as `$event->event`. Listen for it and branch on `$event->event->type` to react in your app.

If any of these steps throws, Keystone reports the exception to your exception handler and carries on with the others. The response never changes.

## Types

| Type | Recorded when |
|---|---|
| `signed_in` | a session signs in |
| `proof.rejected` | a sign-in naming an account is refused: a rejected proof, a proof naming a credential the account doesn't hold, or an account that is suspended |
| `signed_out` | the user signs out |

A refused sign-in for an address no account holds records nothing, so a typed identifier is never stored.

## Fields

Every event has the same fields, and never typed input, secrets, codes, tokens or session ids.

| Field | Holds |
|---|---|
| `occurred_at` | when, in UTC |
| `type` | one of the types above |
| `user_id` | the account, or null |
| `actor` | `user`, `operator` or `system` |
| `flow` | where it happened, such as `sign-in` |
| `credential_type`, `credential_id`, `credential_label` | the credential involved and its label at the time; a refusal names only a credential the account holds |
| `reason` | a short code such as `form.mismatch` or `keystone.barred`; a reason that isn't a lowercase code of at most 64 characters prefixed by its credential type or `keystone.` is stored as `<type>.invalid_reason` |
| `ip_address`, `user_agent` | the request's, the user agent cut to 512 characters |
| `location` | reserved for a later release; always null |
| `known_device` | reserved for a later release; always null |
| `request_id` | an id Keystone gives each request, shared by every event it records |

`ip_address`, `location` and `user_agent` are encrypted in the table and left out of the model's array and JSON form.

## The log line

Every event logs one `info` line with the message `keystone.security_event` and every field above in its context, `ip_address` and `user_agent` included. Events about nobody are only logged, and an identical one (same type, IP address and path) is logged at most once a minute. While your cache is down, every one is logged.

When an account is deleted for good, its events stay in the table with `user_id` set to null.
