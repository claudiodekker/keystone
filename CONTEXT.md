# Keystone

A security-first authentication package suite for Laravel that replaces Fortify: it owns every sign-in, recovery and account-security flow, held to ASVS Level 2.

## Language

### Packages

**Core**:
The `keystone` package: every flow, rule and security guarantee, with no pages of its own.

**Method package**:
A package that adds one way to prove who you are, such as password, WebAuthn, TOTP, magic link or OAuth.
_Avoid_: plugin, driver, provider

**Frontend adapter**:
A package that gives core its pages: Inertia-Vue first, Blade second.

**Show step**:
The only kind of request that renders a page. Every submission ends in a redirect to one.

**Page value**:
The facts core hands a show step's page, named once so both frontend adapters use the same names.
_Avoid_: page props (for the core side)

**Response hook**:
A method on a core controller that the app's published copy implements, turning one outcome into the response the user sees.
_Avoid_: responder

### Extension

**Swappable**:
A class an application may replace: an Action or Operation through the container with a subclass, unless it makes a security decision; a model with any class meeting that model's contract.
_Avoid_: sealed (for the classes that aren't)

**AppTests**:
Core-owned tests that run inside the application and fail when its wiring or swaps break one of Keystone's guarantees. An application may skip one to accept that deviation.

**Accepted deviation**:
An AppTest the application has chosen to skip, recorded with its reason in the application's own repository.

### Credentials

**Credential type**:
One kind of credential a method package offers, such as passkey, security key or TOTP. A package may offer several.
_Avoid_: method (for the kind), factor type

**Surface**:
A place in a flow where a credential type can be used: sign-in, challenge, registration or enrollment.
_Avoid_: context, stage

**Passkey**:
A discoverable WebAuthn credential that signs a user in on its own.

**Security key**:
A non-discoverable WebAuthn credential, used after the user has named their account.

**User handle**:
The random value, one per account, that every WebAuthn credential of that account carries.
_Avoid_: user id

**Registration**:
Creating an account. The account is real from that moment, even while it still owes an enrollment.
_Avoid_: claimed user, signup placeholder

**Recovery codes**:
Single-use break-glass codes that stand in for a lost factor.
_Avoid_: backup codes

**Account recovery**:
Getting back into an account after losing a factor, by proving the inbox plus one second factor the account still holds, or the inbox alone when it holds none. Every credential except the one that proved it is replaced.
_Avoid_: password reset, forgot password

### Security

**Sudo**:
A short, time-limited grant on a signed-in session, earned by proving again what a sign-in would demand, that allows changes affecting authentication.
_Avoid_: password confirmation, step-up

**Remember-me cookie**:
Keystone's own long-lived cookie that restores a user's sign-in on return.
_Avoid_: recaller

**Known device**:
A browser that carries the device cookie an account's earlier sign-in left behind.

**Abandoned challenge**:
A sign-in that passed the first factor and never finished the second.

**Security event**:
A record that something security-relevant happened, of one type from a closed list.

**Audit trail**:
An account's own security events, shown to its user.
_Avoid_: activity log

**Security alert**:
A notification to the user about one security event.

### Sign-in state

**Signed in**:
A session that has proven who it is and owes nothing more. A session that comes to owe something stops being signed in.

**Pending sign-in**:
A session that has named an account but still owes a challenge, an enrollment or a recovery before it is signed in. At most one per session.
_Avoid_: park, half-authenticated, held user

**Credential epoch**:
A per-account counter that moves whenever the account's credentials change in a way that should end its other sessions. A session or remember-me cookie from an older epoch is dead.

**Ceremony slot**:
A method's in-flight data for one step, such as a WebAuthn challenge or a TOTP secret being enrolled. It never outlives the state that owns it.

### Rate limiting

**Rate limiter**:
Core's one limiter, holding three limits. A spent limit always refuses the same way, and nothing ever resets a count; counts only expire.

**Request limit**:
How many requests one IP address and session may make to a kind of step in a short window.

**Failed-attempt limit**:
How many wrong answers an account may get per credential type, in each flow, over a long window.

**Delivery limit**:
How many emails or prompts may be sent to one person per credential type, in each flow, over a window.

### Email addresses

**Verified address**:
An email address on an account whose owner has proven control of the inbox.
_Avoid_: confirmed email

**Unverified address**:
An email address on an account whose inbox control is not yet proven. While an account has no verified address, its unverified addresses count as verified.
_Avoid_: pending email

**Primary address**:
The one address an account is known by and shown as.
_Avoid_: main email, login email

**Claim conflict**:
Several accounts holding the same address that no active account has verified. Whichever verifies it first keeps it, and it is removed from the others. Only an active account's verified address blocks anyone else.
_Avoid_: ownership contest, takeover

**Disabled account**:
A deleted or invalidated account still inside its restore window. Its addresses block no one: each yields to whoever verifies it.
_Avoid_: soft-deleted user

**Invalidated account**:
An account left with no address after losing a claim conflict. It can no longer be reached and is pruned.
_Avoid_: orphaned account, placeholder user

**Suspended account**:
An account an operator has barred from signing in. It keeps its addresses, so they still block others, and it signs in again once unsuspended.
_Avoid_: banned user, locked account

### Moving to Keystone

**Backfill**:
A migration a package's installer publishes, only when the application already holds that package's kind of data, that copies it into Keystone's own tables.
_Avoid_: import, sync
