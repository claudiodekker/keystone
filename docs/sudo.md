# Sudo

A change to how an account signs in should rest on a proof made moments ago, not on a session that may be hours old or restored by a cookie. Sudo is that proof. A route gated by sudo is refused to a signed-in session unless it proved who it is within the last 15 minutes, from the network it proved it on. A refused session confirms it is the user on the sudo page, which asks exactly what signing in would.

## Gating a route

Gate a route with Laravel's `auth` middleware and Keystone's `sudo` middleware:

```php
use App\Http\Controllers\DeleteAccountController;
use Illuminate\Support\Facades\Route;

Route::delete('account', DeleteAccountController::class)->middleware(['auth', 'sudo']);
```

A guest is turned away as `auth` turns one away, even on a route gated by `sudo` alone. A signed-in session passes while it holds a live sudo grant from the network the request comes from. Any other session is refused:

- a browser is redirected to the sudo page, the route named `sudo`;
- a JSON request gets a `403` with `reason` set to `sudo`.

```json
{
    "message": "Please confirm it is you to continue.",
    "reason": "sudo"
}
```

The refusal carries Keystone's [hardening floor](security.md), on your own route too. Your route's other responses keep their own headers.

To answer the refusal your own way, bind your own `ClaudioDekker\Keystone\Contracts\SudoRequiredResponse`. It decides only what the refused request sees: the session is refused either way.

## Where the user goes once granted

A refusal remembers where to send the user once sudo is granted:

- a browser's `GET` goes back to its own URL, query string included;
- anything else, such as a form's `POST`, goes back to the page it came from when the `Referer` is a page of your app, and to `/` otherwise.

A refused change is never replayed. The user lands on the page they made it from and makes it again.

Every refusal in the next 15 minutes keeps the same sudo-in-progress. Only a browser's `GET` replaces where it goes, so a user refused on one page and then on another goes to the second.

## The sudo page

The sudo page asks what a sign-in of the account would. Its first step offers the types serving sign-in that the account holds a credential of, such as its password. An account holding a second factor is then asked for it at a second step, which offers its second factors of another type than the first, then its recovery codes. A type that proves two factors on its own grants sudo in one step. The page asks for no email address, since the session already names the account.

The stub routes serve it:

| Route | Name | What it does |
|---|---|---|
| `GET /sudo` | `sudo` | Shows the step. |
| `POST /sudo/{type}` | `sudo.submit` | Takes an answer with the named type. |
| `DELETE /sudo` | `sudo.end` | Ends sudo. |

A right answer at the last step grants sudo, gives the session a new id and sends the user on. A right first factor of an account that still owes its second factor grants nothing yet, gives the session a new id and returns to the page for the second step.

A wrong answer returns to the page with "The provided credential is invalid." and records `sudo.failed`, which alerts the account's owner, from a browser the account knows too. Whoever answers holds the session, or a copy of it.

Answers count against the [failed-attempt limit](configuration.md) in a `sudo` flow of their own, apart from sign-in's, so wrong passwords on the sign-in page never lock the owner out of sudo. A one-time code, such as a TOTP code, shares its count with the challenge, so a stolen session gets no fresh guesses at it.

A recovery code answers the second step and is spent. The account's last code is always kept, whatever `require_recovery_codes` says, with the message "This is your last recovery code. It is kept for account recovery and cannot be used here." The session is already signed in, so keeping the code costs it nothing, and the code may be what gets the user back into the account.

An answer is refused without being checked, and without counting against the limit, when:

- the session holds no sudo-in-progress, which sends the user on to Laravel's intended URL, else to `/`;
- the step doesn't offer its type, which answers `404`.

A sudo-in-progress lasts 15 minutes from the refusal that started it. An answer is refused, writing nothing, when it ran out while the answer was checked, when the account was suspended or came to owe [enrollment](enrollment.md), or when the account's credential epoch moved. A request whose address isn't an IP address is refused and counted as `sudo.failed` with the reason `keystone.unbindable_subnet`, since its grant could bind to no network.

When an account no longer holds a second factor other than the first factor that passed, its sudo-in-progress ends before the page is shown or an answer is checked. The user goes on to where they were going, without sudo, and the next refusal starts again at the first step.

## How long a grant lasts

```php
'sudo' => [
    'lifetime_seconds' => 15 * 60,
],
```

A grant ends `sudo.lifetime_seconds` after it was granted, however busy the session stays. Keystone applies the lifetime when it reads the grant, so lowering the setting shortens grants already held. A grant whose time is in the future is ignored.

Every sign-in that completes grants sudo, whether it completes on its first factor, at the challenge or at the end of enrollment, and records `sudo.granted` with the reason `keystone.sign_in`. A sign-in from an address that isn't an IP address completes with no grant and no `sudo.granted`. A [remembered return](remember-me.md) never grants sudo, and drops a grant the session held.

## The network a grant is bound to

A grant is bound to the network of the address it was granted to: the `/24` of an IPv4 address, an IPv4-mapped IPv6 address unwrapped first, or the `/64` of an IPv6 address. A phone that moves within its network keeps its grant. A copied session cookie used from another network doesn't carry sudo with it.

A live grant used from another network is revoked. Keystone records `sudo.network_changed`, which alerts the owner, and refuses the request. A grant that ran out is refused from any network with nothing recorded.

Keystone reads the address after your trusted-proxy middleware, as Laravel's `$request->ip()` does. Behind a load balancer whose address your app doesn't trust, every grant is bound to the balancer's network.

## Ending sudo

A `DELETE` to `sudo.end` ends the session's grant and sudo-in-progress, gives the session a new id and leaves the status "You will be asked to confirm it is you before your next sensitive change." It isn't gated by sudo, and counts against the `change` request limit. It records `sudo.revoked` only when it ended a live grant, so repeating it adds nothing to the trail.

Every change of the session's auth level also ends sudo: a sign-in, a hold, passing a second factor, a remembered return, passing sudo's first step and ending sudo. Signing out and anything that ends the session end it with the session.
