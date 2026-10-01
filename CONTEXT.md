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
A package that gives core its pages in one frontend stack.

**Show step**:
The only kind of request that renders a page. Every submission ends in a redirect to one.

**Page value**:
The facts core hands a show step's page, named once so both frontend adapters use the same names.
_Avoid_: page props (for the core side)

**Response hook**:
The place on a published controller where the application turns one outcome into the response the user sees.
_Avoid_: responder

**Initiate shape**:
How a credential type starts on a surface: a form, a client ceremony, a redirect or an emailed delivery. A page renders one component per shape.

**Partial**:
The application's own component for one credential type, which a page renders in place of the component for that type's initiate shape.
_Avoid_: method view

### Extension

**Swappable**:
A class an application may replace with its own: any Action, Operation or model, except one that makes a security decision.
_Avoid_: sealed (for the classes that aren't)

**AppTests**:
Core-owned tests that run inside the application and fail when its wiring or swaps break one of Keystone's guarantees.

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

**Proof**:
What a credential type's verify answers: the typed input proves one stored credential, or it is rejected. It names a credential, never a user; core reads the credential's owner itself.

**Recovery codes**:
Single-use break-glass codes that stand in for a lost factor.
_Avoid_: backup codes

**Account recovery**:
Getting back into an account after losing a factor, by proving the inbox plus one second factor the account still holds, or the inbox alone when it holds none. Every other credential is removed, and a new first factor is enrolled unless the prover signs in on its own.
_Avoid_: password reset, forgot password

### Security

**Sudo**:
A short grant on a signed-in session, bound to its network, that allows changes affecting authentication. Every real sign-in brings it (never a remembered return or a recovery), and proving again what a sign-in would demand earns it back.
_Avoid_: password confirmation, step-up

**Remember-me cookie**:
Keystone's own long-lived cookie that restores a user's sign-in on return.
_Avoid_: recaller

**Known device**:
A browser that carries the device cookie an account's earlier sign-in left behind.

**Abandoned challenge**:
A sign-in that passed the first factor and never finished the second.

**Hardening floor**:
The headers core puts on every response from a Keystone route, over any value the application gave them.

**Keystone route**:
A route whose controller is one of core's controllers or the application's subclass of one. Core hardens its responses and refuses cross-site changes to it.

**Security event**:
A record that something security-relevant happened, of one type from a closed list.

**Audit trail**:
An account's own security events, shown to its user.
_Avoid_: activity log

**Security alert**:
A notification to the user about one security event.

**Notification slot**:
The config entry naming the notification one type of security event sends as its security alert, or null to silence that type alone.
_Avoid_: alert switch

### Sign-in state

**Signed in**:
A session that has proven who it is and owes nothing more. A session that comes to owe something stops being signed in.

**Pending sign-in**:
A session that has named an account but still owes a challenge, an enrollment or a recovery before it is signed in. At most one per session.
_Avoid_: park, half-authenticated, held user

**Enrollment**:
Adding a credential to an account. Owed at sign-in when a mandate requires something the account doesn't hold: a second factor (`require_second_factor`) or recovery codes (`require_recovery_codes`).
_Avoid_: setup, onboarding

**Holdings**:
Whether an account holds a second factor and recovery codes, written on its row by every account change so a request can tell what it owes without counting credentials.

**Demotion**:
Turning a signed-in session whose account newly owes an enrollment back into a pending sign-in at enrollment.
_Avoid_: downgrade, forced logout

**Credential epoch**:
A per-account counter that moves whenever the account's other sessions must end, such as on a password change, credential removal, recovery, suspension or "sign out others". A session or remember-me cookie from an older epoch is dead.

**Account change**:
One locked write to an account's credentials, addresses or credential epoch. It moves the epoch whenever it removes, replaces or ends something, and records its events only once it commits.

**Ceremony slot**:
A method's in-flight data for one step, such as a WebAuthn challenge or a TOTP secret being enrolled. It never outlives the state that owns it.

### Rate limiting

**Rate limiter**:
Core's one limiter, holding three limits. A spent limit always refuses the same way, and nothing ever resets a count; counts only expire.

**Request limit**:
How many requests one IP address and session may make to a kind of step in a short window.

**Failed-attempt limit**:
How many wrong answers an account may get per credential type, in each flow, over a long window. TOTP shares one count across flows.

**Delivery limit**:
How many emails may be sent to one person per credential type, in each flow, over a window.

### Email addresses

**Verified address**:
An email address on an account whose owner has proven control of the inbox.
_Avoid_: confirmed email

**Unverified address**:
An email address on an account whose inbox control is not yet proven. While an account has no verified address, its unverified addresses count as verified, including for blocking others.
_Avoid_: pending email

**Primary address**:
The one address an account is known by and shown as.
_Avoid_: main email, login email

**Claim conflict**:
Several accounts holding the same address that no active account has verified or counts as verified. Whichever verifies it first keeps it, and it is removed from the others.
_Avoid_: ownership contest, takeover

**Disabled account**:
A deleted or invalidated account still inside its restore window. Its addresses block no one: each yields to whoever verifies it.
_Avoid_: soft-deleted user

**Invalidated account**:
An account left with no address after losing a claim conflict. It can no longer be reached and is pruned after the restore window unless an operator restores it.
_Avoid_: orphaned account, placeholder user

**Suspended account**:
An account an operator has barred from signing in. It keeps its addresses, so they still block others, and it signs in again once unsuspended.
_Avoid_: banned user, locked account

### Moving to Keystone

**Backfill**:
A one-time copy of an application's existing authentication data, such as Fortify's, into Keystone's own tables.
_Avoid_: import, sync
