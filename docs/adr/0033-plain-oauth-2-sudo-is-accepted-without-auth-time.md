# Plain OAuth 2 sudo is accepted without auth_time

Sudo asks the user to prove who they are again before a sensitive change (ADR 0021, ADR 0022). With `keystone-oauth`, an account that signs in through a provider confirms sudo there too. Keystone sends the user to the provider with `prompt=login` and `max_age=0`, which ask the provider to make the user sign in again. ASVS 5.0 V7.5.1 asks that re-authentication before a sensitive change be real, and V6.8.4 asks that an app relying on an identity provider check that the provider actually authenticated the user as recently as it asked, which in OpenID Connect means reading `auth_time` from a validated ID token. A plain OAuth 2 provider, GitHub among them, ignores `prompt` and `max_age` and returns no `auth_time`, so Keystone can't tell whether the user typed anything.

- **Keystone accepts the return anyway.** A return that names a subject this account connected passes the sudo step, as a correct password does. What it proves is that the browser holds a session at the provider for that subject now. That is a deviation from V7.5.1 and V6.8.4, accepted here.
- **Sudo still asks for `prompt=login` and `max_age=0`.** A provider that honours them makes the user sign in again, and one that ignores them loses nothing. Only sudo sends them: sign-in and connecting an account don't, so a user who just signed in at the provider isn't asked twice.
- **Every other sudo rule holds.** The return must name a credential this account holds, or it is a wrong answer recorded as `sudo.failed` and alerted. The step comes from the same decision as sign-in, so an account that also holds a second factor is asked for it after the provider. The grant is bound to the subnet the return came from and lasts 15 minutes.

The alternative was to keep OAuth off the sudo page for plain OAuth 2 providers. It lost because an account whose only first factor is a provider account would then have no way to confirm sudo short of recovering the account, and the security settings that connect a second provider or add a password sit behind sudo. A second alternative, to read the provider's own session age from its API, lost because no common provider exposes it, and each would need its own code.

## Consequences

- Someone holding a user's live session at a plain OAuth 2 provider, and a copy of their session in your app, can pass sudo without knowing any secret. An account that holds a second factor still stops them there.
- A provider configured with `issuer` is an OpenID Connect provider and has its `auth_time` checked (ADR 0034). Plain OAuth 2 providers stay under this ADR.
- The OAuth and sudo docs say so where a reader decides which providers to list.
