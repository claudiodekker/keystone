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

**Connected account**:
An account a user holds at an OAuth provider, linked to their Keystone account from the security settings and stored as a credential naming the provider and the provider's subject for them. One connected account belongs to one Keystone account.
_Avoid_: social login, linked identity

**Blind index**:
The keyed hash a private credential identifier, such as a provider's subject, is stored and found by: an HMAC under its own subkey of the app key, so the database alone can't tell whose account at the provider it is.
_Avoid_: lookup hash

**Registration**:
Creating an account. The account is real from that moment, even while it still owes an enrollment.
_Avoid_: claimed user, signup placeholder

**Proof**:
What a credential type's verify answers: the typed input proves one stored credential, or it is rejected. It names a credential, never a user; core reads the credential's owner itself.

**Recovery codes**:
Single-use break-glass codes that stand in for a lost factor.
_Avoid_: backup codes

**Blocklist**:
What a new password may not contain or be: a context word, one of the most common passwords, or a password seen in a known breach (NIST SP 800-63B's term). An app's own password rules replace it, with the minimum length.
_Avoid_: denylist, banned passwords

**Context word**:
A word of 4 or more characters taken from the app's name, the host of its URL, its configured `context_words`, the part before the `@` of an address the user holds, or the user's username.

**Account recovery**:
Getting back into an account after losing a factor, by proving the inbox plus one second factor the account still holds, or the inbox alone when it holds none. Every other credential is removed, and a new first factor is enrolled unless the prover signs in on its own.
_Avoid_: password reset, forgot password

**Prover**:
The second factor or recovery code that proved an account recovery. The recovery keeps it while it removes every other credential, and it stands in for the new first factor when it signs in on its own.

### Security

**Sudo**:
A short grant on a signed-in session, bound to the subnet it was earned from, that allows changes affecting authentication. Every real sign-in brings it (never a remembered return or a recovery), and proving again what a sign-in would demand earns it back.
_Avoid_: password confirmation, step-up

**Sudo-in-progress**:
A signed-in session the sudo gate refused, which owes the steps a sign-in of its account would take before it gets sudo, and remembers where to go afterwards. It lasts a fixed 15 minutes from the refusal that started it, and a session holds either it or a grant, never both.
_Avoid_: sudo challenge, pending sudo

**Remember-me cookie**:
Keystone's own long-lived cookie that restores a user's sign-in on return. A dead one never restores. A cookie is dead once its fixed lifetime, counted from its sign-in, has passed, or once the credential epoch has moved. It is also dead while its account is suspended, invalidated or deleted, or newly owes enrollment.
_Avoid_: recaller

**Remembered return**:
A request that a live remember-me cookie signs in again, because its browser holds no signed-in session or one that outlived its absolute lifetime. It proves no factor.

**Known device**:
A browser that carries the device cookie an account's earlier sign-in left behind.

**Pending challenge**:
Keystone's note that a pending sign-in from a browser that isn't a known device of its account is held at the challenge. Only passing the challenge forgets it.

**Abandoned challenge**:
A sign-in that passed the first factor and never finished the second.

**Hardening floor**:
The headers core puts on every response from a Keystone route, over any value the application gave them.

**Keystone route**:
A route whose controller is one of core's controllers or the application's subclass of one. Core hardens its responses and refuses cross-site changes to it.

**Emailed link**:
A single-use link Keystone mails to prove its reader holds an inbox, built on `app.url` and working for 10 minutes. Opening it shows a page with one button and spends nothing; only that button's POST spends it. A magic link is the exception and has no button: opening it spends it in the browser that asked for it, and it is refused, unspent, anywhere else.
_Avoid_: magic link (for the kind in general), signed URL

**Magic link**:
The emailed link that signs a user in, or answers their sudo, with a credential type that delivers its proof by mail, such as `magic-link`. It comes in two kinds, the sign-in link and the sudo link. Each works only in the browser whose ceremony slot holds a digest of its token, so a browser that asked for no link, or for a newer one, is refused it without spending it.

**Sign-in link**:
The kind of magic link a guest asks for by typing an address on the sign-in page. Opened in the browser that asked for it, it signs the account in, or holds the sign-in for the challenge or enrollment the account owes.

**Sudo link**:
The kind of magic link a signed-in user asks for at the sudo step, mailed to each of the account's alert recipients. Opened in the browser that asked for it while that browser still owes sudo, it answers the step's first factor.

**Security event**:
A record that something security-relevant happened, of one type from a closed list.

**Audit trail**:
An account's own security events, shown to its user on the activity page, newest first and none folded together.
_Avoid_: activity log

**Security alert**:
A notification to the user about one security event.

**Notification slot**:
The config entry naming the notification one type of security event sends as its security alert, or null to silence that type alone. The slot of `account.registered` names the welcome mail a new account gets, which is not an alert.
_Avoid_: alert switch

### Sign-in state

**Signed in**:
A session that has proven who it is and owes nothing more. A session that comes to owe something stops being signed in.

**Registering**:
A guest session holding the address a spent registration link proved, for 30 minutes, until the account is created or the registration ends. Every auth-level change ends it.
_Avoid_: pending registration, claimed address

**Pending sign-in**:
A session that has named an account but still owes a challenge, an enrollment or a recovery before it is signed in. At most one per session.
_Avoid_: park, half-authenticated, held user

**Recovery stage**:
The stage a pending sign-in is held at between a spent recovery link and the recovery's commit: the session names the account whose inbox the link proved and owes, in order, a second-factor proof unless the inbox alone recovers the account, a replacement first factor unless the prover signs in on its own, and a new set of recovery codes when the app requires them or the account held some. It lasts 15 minutes, like any pending sign-in, and ends when the recovery commits or the account's credential epoch moves.
_Avoid_: reset session, recovery session

**Inbox proof**:
An emailed link, bound to the browser that asked for it, that a pending sign-in from an unknown browser must spend before it may enroll a second factor. It marks the pending sign-in inbox-proven and is owed only by the login origin.
_Avoid_: email verification (for this step)

**Recovery origin**:
What opened a pending sign-in as a recovery: the recovery stage, and the enrollment a recovered account still owes. It signs in without sudo and without a new-device alert, and cancelling its enrollment says the account was recovered.
_Avoid_: recovery flow

**Session phase**:
The one time-limited record a session holds: a pending sign-in, a registration, a sudo-in-progress or a sudo grant. Holding one replaces the one before, so a session never holds two, and every auth-level change drops it.

**Entry**:
What core does once a proof is accepted for an account: signs it in, or holds its pending sign-in for what it still owes. A remembered return is not an entry.

**Mandate**:
A setting that requires every account to hold something: a second factor (`require_second_factor`) or recovery codes (`require_recovery_codes`). An account that doesn't owes enrollment.
_Avoid_: requirement, policy

**Enrollment**:
Adding a credential to an account through its type's ceremony. An account that lacks what a mandate requires (a second factor or a set of recovery codes) owes it at sign-in, after the first factor and the challenge. A signed-in user starts one from the security settings, behind sudo, in the `settings` flow.
_Avoid_: setup, onboarding

**Leftover credential**:
A stored credential of a type the application no longer lists, or no installed package registers. It counts as no factor, and counts again if the type is listed again.
_Avoid_: orphaned credential

**Holdings**:
Whether an account holds a second factor and recovery codes, read from its credentials and codes whenever a request needs to know what it owes.

**Demotion**:
Ending a signed-in session whose account newly owes an enrollment, so that its next sign-in proves the account again and enrolls it.
_Avoid_: downgrade, forced logout

**Credential epoch**:
A per-account counter that moves whenever the account's other sessions must end, such as on a password change, credential removal, recovery, suspension or "sign out others". A session or remember-me cookie from an older epoch is dead.

**Session handle**:
The opaque name a listed session goes by outside Keystone: an HMAC of its session id under its own subkey of the app key. It changes whenever the session gets a new id, and the session id itself never leaves Keystone.

**Account change**:
One locked write to an account's credentials, addresses or credential epoch. It moves the epoch whenever it removes, replaces or ends something, and records its events only once it commits.

**Ceremony slot**:
A method's in-flight data for one step, such as a WebAuthn challenge, a TOTP secret being enrolled, or the digests a magic link is checked against. It never outlives the state that owns it: the pending sign-in, or the sudo a signed-in session holds. A guest's request for a sign-in link opens one with no such state, which lasts as long as the link, 10 minutes, and ends with any change of sign-in state.

### Rate limiting

**Rate limiter**:
Core's one limiter, holding three limits. A spent limit always refuses the same way, and nothing ever resets a count; counts only expire.

**Request limit**:
How many requests one IP address, and separately the account the session names, may make to a kind of step in a short window.

**Failed-attempt limit**:
How many wrong answers an account may get per credential type, in each flow, over a long window. Each known device of the account counts apart from every other browser. TOTP shares one count across flows.

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

**Username**:
The name an account picks at registration while `username_based` is on, stored lowercased on its users row. The account can change it from its security settings. The column holds only the current name, so a changed-away name is free at once, but a name stays taken until its row is hard-deleted, so a disabled account's username still blocks others.
_Avoid_: handle, login

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
