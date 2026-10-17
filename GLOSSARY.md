# Glossary

## Core

The `keystone` package. It owns every flow, rule and guarantee, and it renders no markup.

## Method package

A package that adds ways to prove identity. The method packages are `keystone-password`, `keystone-webauthn`, `keystone-totp`, `keystone-magic-link` and `keystone-oauth`.

## Frontend adapter

A package that gives core its pages. The frontend adapters are `keystone-inertia-vue` and `keystone-blade`. An adapter installs controllers, pages, routes and translations into the app, and the app then owns them.

## Credential type

One way to prove identity, such as a password or a passkey, that a method package registers with core as a plain instance. It declares its name, the surfaces it serves with one initiate shape each, whether one proof of it proves two factors on its own, and the rules for its own fields.

## Surface

A place in the flows where a credential type can be used: sign-in, challenge, registration or enrollment.

## Initiate shape

How a page starts a credential type on a surface: `form`, `clientCeremony`, `redirect` or `delivered`. A page renders one component per shape.

## Credential

A row of `user_credentials`: something an account holds of one credential type, with its secret encrypted by core.

## Subject

The account a typed identifier names, if any. A credential type checks a proof against the subject's credentials of its type, and gets none when there is no subject.

## Proof

What a credential type's verify step returns: proven, naming one of the credentials it was given, or rejected. A proof never names a user. Core reads the proven credential again and derives its owner itself.

## Sign-in decision

The one place core decides what a proof earns. Today a proof signs in its credential's owner when the owner is the subject; holds join it later.

## Verified address

An address an account has proven it receives mail at. It sits in `verified_address`, and one account at a time can hold an address verified.

## Counting as verified

An unverified address of an account that holds no verified address counts as verified for that account, as long as no account holds it verified. It is derived when needed and never stored.

## Timing floor

The least time, 300 ms, a refused sign-in takes, so a refusal doesn't tell whether the account exists.

## Guest step

A step only a guest may take, such as the sign-in page. It refuses a signed-in user inline, without route middleware.

## Response hook

A protected `send*` method of a core controller that turns one outcome into a response. Every outcome, refusals included, leaves through one.

## Page value

The readonly value a show step hands its page, with field names both frontend adapters pass on unchanged. Its messages arrive already translated.

## AppTest

A test in a package's `app-tests/` that drives the app's own routes and published controllers, and checks each answer through an assertion the app can override. AppTests are the feature suite.

## Assertion trait

A trait of `assert*` methods, one per response hook, that AppTests check answers through. The app's copy uses Keystone's and redefines what its own responses change.

## Test support

What a credential type gives AppTests: arrange a credential, give a valid proof and give a rejected proof. Core's fake types in its `tests/` are their own test support.
