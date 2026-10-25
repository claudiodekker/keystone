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

## Subject

The account a typed identifier names, if any. A credential type checks a proof against the subject's credentials of its type, and gets none when there is no subject.

## Proof

What a credential type's verify step returns: proven, naming one of the credentials it was given, or rejected. A proof never names a user. Core reads the proven credential again and derives its owner itself.

A proven proof may also carry a rehash: the work that remakes the credential's secret under the type's current settings, such as a password hashed at an old cost. Core runs it only after the proof signed the account in, and writes the result only if nothing else changed the secret meanwhile. A failed rehash is reported and the sign-in stands. A rejection carries none.

## Sign-in decision

The one place core decides what a proof earns. Today a proof signs in its credential's owner when the owner is the subject; holds join it later.

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
