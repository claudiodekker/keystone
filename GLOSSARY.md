# Glossary

## Core

The `keystone` package. It owns every flow, rule and guarantee, and it renders no markup.

## Method package

A package that adds ways to prove identity. The method packages are `keystone-password`, `keystone-webauthn`, `keystone-totp`, `keystone-magic-link` and `keystone-oauth`.

## Frontend adapter

A package that gives core its pages. The frontend adapters are `keystone-inertia-vue` and `keystone-blade`. An adapter installs controllers, pages, routes and assertion overrides into the app, and the app then owns them. Core's messages stay in its `keystone::` translations.

## Credential type

One way to prove identity, such as a password or a passkey, that a method package registers with core as a plain instance. It declares its name, the surfaces it serves with one initiate shape each, whether one proof of it proves two factors on its own, and the rules for its own fields.

## Surface

A place in the flows where a credential type can be used: sign-in, challenge, registration or enrollment.

## Initiate shape

How a page starts a credential type on a surface: `form`, `clientCeremony`, `redirect` or `delivered`. A page renders one component per shape.

## Allow-list

The `methods` config key. It names the credential types core offers and the surfaces it offers each on, and it only ever narrows the surfaces the installed types serve. A type it leaves out is offered nowhere.

## Offered

A credential type is offered on a surface when it serves that surface and the allow-list, if set, lists it there. Only an offered type appears on a page or accepts a proof.

## Credential

A row of `user_credentials`: something an account holds of one credential type, with its secret encrypted by core.

## One-time code

A credential type whose answer is a short code someone could guess their way through, such as TOTP. RFC 6238 and NIST call it a one-time password, and HTML names its field `one-time-code`. Its failures count against a fixed daily ceiling on top of the hourly failed-attempt limit.

## Subject

The account a typed identifier names, if any. A credential type checks a proof against the subject's credentials of its type, and gets none when there is no subject.

## Proof

What a credential type's verify step returns: proven, naming one of the credentials it was given; enrolled, carrying the secret of a new credential the subject doesn't hold yet; or rejected. A proof never names a user. Core reads the proven credential again and checks that the account signing in holds it, so an enrolled proof, which names none, never signs anyone in.

A proven proof may also carry a rehash: the work that remakes the credential's secret under the type's current settings, such as a password hashed at an old cost. Core runs it only after the proof signed the account in, and writes the result only if nothing else changed the secret meanwhile. A failed rehash is reported and the sign-in stands. A rejection carries none.

## Second factor

A credential of a type offered on the challenge. Whether an account holds one is read from its credentials whenever a step asks, and never stored, so a type taken off the challenge stops counting and counts again once it is offered there again.

## Recovery code

A break-glass code core keeps for an account, of its reserved `recovery-code` type, stored as the digest of the code and spent when it answers the challenge. A recovery code never counts as a second factor. While recovery codes are required, an account's last code is kept for account recovery rather than spent.

## Sign-in decision

The one place core decides what a proof earns: refused, a sign-in, or a hold at the stage the account owes. A first factor that doesn't prove two factors on its own is held at the challenge when its account holds a second factor. The decision runs again for a pending sign-in, as its account stands now, before each step is shown and before each answer is checked.

## Pending sign-in

A sign-in that passed its first factor and still owes a stage, such as the challenge. A session holds at most one, as its phase, with the account, the first factor's type, the stage, the intended URL and the account's credential epoch at the hold. It lasts a fixed 15 minutes. To every other page the session is a guest. A pending sign-in whose account's credential epoch moved, or whose account was suspended, is dropped and recorded voided; one whose account no longer owes its stage is dropped.

## Challenge

The stage where a pending sign-in answers with a second factor of another type than its first factor's. A right answer completes the sign-in. A wrong one is refused on the type's field, counted in the challenge flow and recorded, and the pending sign-in stays.

## Session phase

The one session value that says where a guest session is in the flows, such as a pending sign-in. A new phase replaces the old one, and every change of auth level forgets it: a hold, a sign-in and a cancel each give the session a new id.

## Ceremony slot

What a credential type keeps between starting a ceremony and verifying its answer, such as a WebAuthn challenge or a TOTP key being enrolled. A type that has something to start implements `StartsCeremony`, and a type with nothing to start implements nothing. Core keeps one slot per type and surface, encrypted in the session, and hands it to the type's verify. The type draws its page from the slot on each request, such as a TOTP key's QR code, so the slot keeps nothing the page can draw again. Starting again replaces it. It ends 10 minutes after its start, or with the pending sign-in it was started under when that comes first, and at every change of auth level.

## Verified address

An address an account has proven it receives mail at. It sits in `verified_address`, and one account at a time can hold an address verified.

## Counting as verified

An unverified address of an account that holds no verified address counts as verified for that account, as long as no account holds it verified. It is derived when needed and never stored.

## Timing floor

The least time, 300 ms, a refused sign-in takes, so a refusal doesn't tell whether the account exists.

## Dummy hash

The hash `keystone-password` checks a subject without a usable password against: an unknown account, an account without a password, or one whose stored secret is no hash of a known algorithm. It is made with the app's driver and cost, so the check costs what a wrong password does. It is cached forever under a fingerprint of the whole hashing config.

## Guest step

A step only a guest may take, such as the sign-in page. It refuses a signed-in user inline, without route middleware.

## Response hook

A protected `send*` method of a core controller that turns one outcome into a response. Every outcome, refusals included, leaves through one.

## Page value

The readonly value a show step hands its page, with field names both frontend adapters pass on unchanged. Its messages arrive already translated.

## Security event

One entry of the audit trail: something that happened to an account's security, such as a sign-in, a refused proof or a sign-out. Every event has the same fixed fields and a type from one closed list. It is a row of `user_security_events` when it is for an account, and a log line only when it is anonymous.

## Recorder

The one place that records security events. Core calls it inline from the code that made the event happen, and it writes the log line, the row and the `SecurityEventRecorded` event, each rescued on its own.

## Alert

A mail that tells an account's owner something happened to its security. The recorder sends one for an event about an account when the event's slot holds a notification, to the account's verified addresses, or to the ones counting as verified when it holds none, read before the change that caused the event.

## Slot

The notification class `keystone.notifications` names for one type of security event, or null to send no alert of that type.

## Request context

Who is asking: the request's IP address, its subnet, its cleaned user agent, its path and a request id, captured once per request by core's global middleware after the app's trusted-proxy middleware. It is the only code in core that reads the client's address or user agent.

## Flow

The part of the flows a request is in, such as sign-in, derived from the session's phase and the surface. No caller passes it.

## Rate limiter

The one place core counts requests and failed attempts against their allowances, in Laravel's rate-limiter store under hashed keys. Methods and controllers never see a key, a count or an allowance. A spent limit refuses as throttled.

## Request limit

The rate limiter's count of requests per step kind, taken by controller middleware before validation, per IP address and, separately, per signed-in account.

## Failed-attempt limit

The rate limiter's count of attempts per account, credential type and flow, taken before the type verifies anything and given back only when the attempt signs in or the type throws, inside the window it was taken in. A refused attempt stays counted, and nothing ever resets a count.

## Step kind

What the request limit counts an endpoint as: `view` for a page, `submit` for a proof, `change` for signing out. Each kind has its own allowance a minute.

## Throttled

The one refusal every spent limit gives: 429, `Retry-After` in seconds and one message, the same for a real account, a made-up one and an unknown credential type. It renders through the exception handler, never through a response hook.

## Keystone route

A route whose controller implements `KeystoneController`: one of core's controllers, or the app's subclass of one. The hardening floor and the cross-site check cover exactly these routes, whatever the subclass overrides.

## Hardening floor

The headers core sets over the app's on every response of a Keystone route, redirects and refusals included. The `Content-Security-Policy` merges by directive instead, closing `object-src`, `base-uri` and `frame-ancestors`. Only `hardening.frame_ancestors` loosens it.

## Cross-site check

Core's refusal of a request that changes something on a Keystone route and doesn't come from the app's own pages. It runs after Laravel's CSRF check, reads none of its exemptions and skips nothing in tests. A refusal is an anonymous `request.rejected` event and Laravel's 419.

## Clear-Site-Data

The header the response carries when Keystone ended the session during the request, on any route. The kinds come from `clear_site_data`.

## Sign-in time

The Unix second a session's sign-in completed, stamped in the session at every sign-in whether or not the absolute lifetime is on.

## Absolute lifetime

How long a session lasts after its sign-in time, however busy it stays: `session.absolute_lifetime_seconds`. The guard checks it when something asks for the user. A missing or future sign-in time counts as expired. Laravel's `session.lifetime` stays the idle timeout.

## Session expiry response

The response for a request that needs a signed-in user and whose session expired during it. The app binds its own `SessionExpiredResponse`. Core has already ended and recorded the session when it runs.

## Credential epoch

A counter on each account's `users` row. Every sign-in stamps the session with the account's epoch, and the guard ends a session stamped with another, or with none, when something asks for the user. Moving the epoch ends every session of the account on every session driver. A change moves it when it removed, replaced or ended anything.

## Account change

The one way core writes an account's credentials, addresses, suspension or credential epoch. It locks the account's row, applies the change, moves the epoch when the change removed, replaced or ended anything, and records the change's security events once the outermost transaction commits, so a change that rolls back records nothing. A change it can't make, such as a rehash over a secret another write changed, is refused.

## Operator

Whoever runs an operator command or dispatches its job, such as an admin ending an account's sessions. Keystone doesn't authorize them. It records the name the command or job gives on the security event, with actor `operator`.

## Suspended account

An account an operator barred from signing in. Sign-in refuses it exactly like a wrong credential, and the guard ends its sessions. It keeps its addresses, so they still block other accounts, and it signs in again with the credentials it already had once an operator lifts the suspension.

## AppTest

A test in a package's `app-tests/` that drives the app's own routes and published controllers, and checks each answer through an assertion the app can override. AppTests are the feature suite.

## Assertion trait

A trait of `assert*` methods, one per response hook and swappable response, that AppTests check answers through. The app's copy uses Keystone's and redefines what its own responses change.

## Test support

What a credential type gives AppTests: arrange a credential, give a valid proof and give a rejected proof. Core's fake types in its `tests/` are their own test support. A method package's type has its test support in the `AppTests` namespace beside it, named after it, as `PasswordType` has `AppTests\PasswordSupport`. AppTests find it by that name, as Laravel finds a model's factory.

## Workbench

The one Testbench app at the repository root that wires core, every installed method package and the inertia-vue stubs together, as `keystone:install` wires an app. `KEYSTONE_ADAPTER` names the adapter whose stub test case the AppTests then run on.

## Boot checks

The checks core runs when the config is cached, and on every boot of an app that has no cached config, except for `config:clear` and `optimize:clear`. They refuse what core can't run with or would read wrongly, and apply the same in every environment. The one exception they raise lists every failure, and a config that fails can't be cached.
