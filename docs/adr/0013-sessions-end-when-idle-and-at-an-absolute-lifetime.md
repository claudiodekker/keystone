# Sessions end when idle and at an absolute lifetime

ASVS 7.1.1 asks for both an inactivity timeout and an absolute maximum session lifetime, because each stops a different attack. Laravel ships only the first: `session.lifetime` slides with every request. A session hijacked from a shared computer, or its cookie stolen, stays signed in for as long as the attacker keeps it busy.

- **Idle stays Laravel's.** `session.lifetime` already ends idle sessions on every driver, and apps already tune it. Keystone adds no second idle timer.
- **Absolute is Keystone's.** `session.absolute_lifetime_seconds` (12 hours, so a working day fits) counts from the real sign-in and nothing extends it. `null` turns it off; `0` is refused at boot, because a zero that disables a control is the fail-open value v3 kept.
- **The guard checks it**, on the same read that checks the credential epoch, so no middleware, route group or `withoutMiddleware()` can skip it, and it works on every session driver with nothing published.
- **It fails closed.** A session with no sign-in time, or one in the future, counts as expired: a session Keystone didn't stamp, or one stamped by a server whose clock ran ahead, is not trusted.
- **It ends the session like any other end Keystone decides:** invalidated, `session.ended` with reason `expired`, and `Clear-Site-Data`. The expiry status is flashed into the fresh session, so the sign-in page can say why without the app wiring anything. A JSON client gets a 401 with reason `expired`, so it can tell an expiry from never having signed in.

## Consequences

- Expiry is checked when something asks for the user, not on every request. A page that never asks who is signed in keeps an expired session until one that does, the same way the epoch check works.
- A remembered return (remember-me) will restore a fresh session with its own absolute lifetime, and no expiry notice, when its cookie is still valid; expiry never kills the remember-me token.
- The HTML redirect is Laravel's own unauthenticated redirect, so the app's `redirectGuestsTo` still decides where it goes and the intended URL is kept.
- There is no tolerance for clock skew: a server whose clock runs behind the one that signed the session in ends it at once. Servers behind one load balancer need synchronised clocks.
- The JSON 401 is added through Laravel's exception handler, so an app whose handler doesn't extend `Illuminate\Foundation\Exceptions\Handler` gets its own unauthenticated response instead.
