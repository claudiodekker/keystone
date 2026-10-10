# Registration

A visitor creates an account by proving they read the inbox of the address they type. They type it on the register page, Keystone mails it a link, and opening that link and choosing Continue starts the registration in their browser, on the finish page. There they choose a name and a password, and the account exists from that moment, signed in or held for the [enrollment](enrollment.md) it owes.

## When registration is open

Registration is open while at least one credential type listed in `keystone.methods` serves the `registration` surface, as the password does. With [keystone-password](password.md) installed and `keystone.methods` left at `null`, it is open.

While no listed type serves it, every registration step, its form submissions included, sends the user to the sign-in page, which reads "Registration is not available." Nothing is validated, looked up or mailed. To close registration, narrow each type to the surfaces you keep:

```php
'methods' => [
    'password' => ['sign-in', 'enrollment'],
    'totp',
],
```

The Inertia-Vue sign-in page links to the register page only while registration is open.

## Asking for a link

The register page (`register`) asks for an email address. Its form posts to `register.submit`, which requires an address of at most 255 characters that a strict reading of RFC 5322 accepts, refusing quoted local parts, comments and IP addresses in place of a domain, and stores it the way Keystone [stores every address](installation.md#email-addresses). Invalid input goes back to the register page with its errors, and only the address is flashed back.

A valid address always sends the user on to the "check your email" step (`register.link-sent`). What happens behind it depends on who holds the address:

- When no active account holds it, Keystone mails it a registration link.
- When an active account holds it verified, or holds it unverified while holding no verified address, Keystone mails nothing to it. It records `address.claim_attempted` on that account, with flow `registration`, and [alerts its owner](security-alerts.md) that someone tried to sign up with their address. A suspended account counts as active here.
- When only a deleted or invalidated account holds it, it is free: the link is mailed and nobody is alerted.

The response is the same in every case, so the page can't tell anyone whether an address has an account. The whole step takes at least 300 ms whatever happens, and the session isn't written to. The mail or alert is queued, so this holds only while a queue worker sends it. With `queue.default` set to `sync`, the mail is sent inside the request, and the time it takes can tell a free address from a taken one.

### The delivery limit

Each address gets at most 3 registration mails in 10 minutes, links and alerts together, counted against the typed address whether or not an account holds it. Past that, the step answers exactly as before and mails nothing. The first refusal in a window records `limit.tripped` with the reason `keystone.delivery_limit`. Change the allowance with `keystone.rate_limits.deliveries_per_ten_minutes` (see [Rate limiting](rate-limiting.md#limits)). While the rate limiter's store is down, the step mails nothing and reports the failure.

## The link

The link points at your `app.url`, path included, whatever host the request that asked for it came in on, and works for 10 minutes. It carries the address encrypted, so the address never shows in the URL, in your server logs or in the browser's history.

Opening the link (`register.verify`) shows a page saying the address is verified, with one button, Continue. Opening it spends nothing, so a mail scanner that follows the link leaves it working, and the page can be opened again until the link is spent. The button posts to `register.verify.consume`, at the same URL, which spends the link: it works once, and every later use is refused. Once spent, the session gets a new id, any pending sign-in, sudo or ceremony is dropped, and the address is kept as proven for 30 minutes. The user is sent on to the finish page.

A link that no longer works sends the user to the "link expired" step (`register.link-expired`), with no reason given. That covers a link that expired, was already used, was changed, was signed before your `APP_KEY` was rotated, or came in on another host than `app.url`'s, and an address an active account came to hold since the link was mailed. Opening or spending such a link records `request.rejected` with a reason saying which check failed (see [Security events](security-events.md#types)), still without spending it or changing the session. When the address is one an active account came to hold, spending the link also records `address.claim_attempted` on that account and alerts its owner, as asking for a link would. Every response at the link's URL sends `Referrer-Policy: no-referrer`, a refusal or a throttled request included (see [Hardening](hardening.md#headers)).

Links are signed and encrypted with keys derived from your current `APP_KEY` alone, never from `APP_PREVIOUS_KEYS`, so rotating the key ends every link in flight. Keystone keeps a digest of each spent link in `used_email_links` until it expires. Its scheduled `keystone:prune-used-email-links` task deletes the expired ones every hour, on one server, so run Laravel's scheduler.

A link needs a working mailer and queue worker, and production refuses to boot without an `https` `app.url` (see [Configuration](configuration.md#boot-checks)). Behind a proxy, configure your trusted proxies, so the request's scheme, host and path match `app.url`'s. Otherwise every link is refused.

## The finish page

The finish page (`register.finish`) shows the proven address and the credential types that serve registration, each with a form. A session that hasn't spent a link, or spent it more than 30 minutes ago, is sent back to the register page.

## Finishing

The form posts to `register.finish.submit` with the type in the URL. With the password it takes a `name` of at most 255 characters and a new password typed twice, checked as every [new password](password.md#new-passwords) is. At registration the password may not contain a word of the part of the proven address before the `@` either. Invalid input goes back to the finish page with its errors, and only the name is flashed back.

Valid input creates the account in one database transaction:

1. the proven address, removed from every other account that holds it (see [Other accounts holding the address](#other-accounts-holding-the-address));
2. the users row, written by your app's [`CreateAccount`](#asking-for-more-than-a-name) action;
3. the proven address, stored verified and as the account's primary address;
4. the password;
5. an `account.registered` [security event](security-events.md#types), with flow `registration` and the new credential.

The transaction commits, then the user is either signed in or held at enrollment:

- An account that owes nothing is signed in on a new session id and sent to the page it asked for before registering, else `/`. Its session gets a new [sudo](sudo.md) grant, recorded as `sudo.granted`, and never keeps one the browser held before. The sign-in records `signed_in` with flow `registration` and no new-device alert, because the browser that created the account just proved the inbox.
- An account that owes [enrollment](enrollment.md), as it does while either mandate is on, is held with origin `registration` and sent to set up what it owes. Holding it records `sign_in.held` with flow `registration`. Once it has enrolled everything, it is signed in the same way, and Keystone also records `enrollment.completed`.

Either way, the registration ends and the session no longer holds the address. A credential the type couldn't make, such as when the hasher fails, keeps the registration, and sends the user back to the finish page with "The provided credential is invalid." on the type's field.

A finish posted after the 30 minutes, even one whose password was still being checked when they ran out, creates nothing and sends the user back to the register page, which reads "Your registration expired. Ask for a new link." A finish posted by a session that never spent a link gets the same answer.

Two finishes posted from one browser at once, such as a double-clicked button, share one session. The database still lets only one of them create the account, but the slower one may answer "That email address is already registered. Please sign in instead." after the faster one signed the browser in. This is a known limit: the account exists and the browser is signed in, and only that message is wrong.

### Other accounts holding the address

Any number of accounts may hold an address unverified, and the first account to verify it keeps it. Finishing verifies the proven address, so it deletes the address's row from every other account that holds it, in the same transaction, in order of account id. If the row was the account's primary address, another of its addresses becomes primary, a verified one first and the oldest otherwise.

Keystone does this silently: it records no event and mails no one. Those accounts never proved they own the address. Telling them it was verified would only tell whoever squatted on it that its owner just signed up. An active account always keeps a verified address here, because an account holding the address unverified and no verified address already counts as verified and stops the registration. A suspended account counts as active.

A deleted or invalidated account loses its row the same way, even when it held the address verified, so it no longer blocks the finish. Nothing else about it changes, even when the removal leaves it with no address.

### The welcome mail

`account.registered` sends the new address a welcome mail through its [notification slot](security-alerts.md#changing-or-silencing-an-alert), `keystone.notifications.account.registered`, which names `ClaudioDekker\Keystone\Notifications\Welcome` by default. The mail is queued, like every alert. It is sent only while `keystone.events.enabled` is on, so with recording off no welcome mail is sent. To change its wording, override the keys under `keystone::mail.welcome` in `lang/vendor/keystone/{locale}/mail.php`. To send your own mail, name your notification in the slot, or set it to `null` to send none. Adding the password records no `credential.added` and sends no alert.

### When someone else got there first

Between spending the link and finishing, another account may come to hold the address: an active account that verified it, or one that holds it unverified while holding no verified address. The finish then creates nothing, ends the registration and sends the user to the sign-in page, reading "That email address is already registered. Please sign in instead." The same happens to the slower of two finishes for one address that run at once, from two browsers that each spent a link: the database lets only one account hold an address verified, so the other's transaction rolls back whole. The accounts that held the address keep their rows then.

## Cancelling

The finish page's cancel button (`DELETE` to `register.finish.cancel`) ends the registration before the account exists. It forgets the proven address and every ceremony a credential type started for it, and sends the user to the register page, reading "Registration cancelled. No account was created."

Once the account exists, cancelling the enrollment it owes signs the user out and keeps the account, reading "Your account was created. Sign in to finish setting it up." The account owes the enrollment again at its next sign-in (see [Cancelling](enrollment.md#cancelling)).

## Asking for more than a name

The users row is written by `ClaudioDekker\Keystone\Actions\CreateAccount`. Its `rules()` give the fields the finish takes besides the credential, and its `handle()` creates the row from them, inside the transaction that writes the address and the credential. `handle()` receives exactly the top-level fields `rules()` names, so an override of one must match the other. Name each field plainly, with no wildcard or nested keys, and never with a name the credential type uses, such as `password` or `password_confirmation`: the finish splits the input between the two by those names. By default it asks for a `name` and fills it in on a new instance of your user model, whatever the model's `$fillable` says.

To ask for more, or to refuse sign-ups, extend it and bind your class in a service provider. This one also asks which team the user joins, and refuses anyone without an invitation:

```php
namespace App\Actions;

use App\Models\Invitation;
use App\Models\User;
use ClaudioDekker\Keystone\Actions\CreateAccount as KeystoneCreateAccount;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateAccount extends KeystoneCreateAccount
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'team' => ['required', 'string', Rule::exists('teams', 'slug')],
        ];
    }

    public function handle(array $profile): User
    {
        if (! Invitation::where('team', $profile['team'])->exists()) {
            throw ValidationException::withMessages(['team' => 'You need an invitation to join this team.']);
        }

        return User::create(['name' => $profile['name'], 'team' => $profile['team']]);
    }
}
```

```php
use App\Actions\CreateAccount;
use ClaudioDekker\Keystone\Actions\CreateAccount as KeystoneCreateAccount;

public function register(): void
{
    $this->app->bind(KeystoneCreateAccount::class, CreateAccount::class);
}
```

Add the field to the finish form in `resources/js/partials/shapes/Form.vue`, next to `name`. Only the fields `rules()` names are flashed back after a refused finish, and the stub's `sendRegistrationFinishPage` passes `name` to the page, so pass your field there too. An exception thrown from `handle()` rolls the whole account back, and a `ValidationException` sends the user back to the finish page with its errors, keeping the registration. Never write Keystone's own columns or tables from it: Keystone writes the address and the credential itself. An account it creates suspended is kept, but the registration ends and the user is sent to the sign-in page with the refusal a suspended account gets there, "These credentials do not match our records.", recording `proof.rejected` with the reason `keystone.barred`.

## Signed-in users

A signed-in user who opens or posts to any registration step is sent to `/`, a link they post is not spent, and a finish they post creates nothing.

## Changing the responses

The adapter publishes three controllers, with one hook per outcome:

- `RegistrationController`: `sendRegistrationPage`, `sendRegistrationLinkSent` and `sendRegistrationLinkSentPage`;
- `RegistrationLinkController`: `sendRegistrationLinkPage`, `sendRegistrationLinkConsumed`, `sendRegistrationLinkExpired` and `sendRegistrationLinkExpiredPage`;
- `RegistrationFinishController`: `sendRegistrationFinishPage`, `sendRegistered`, `sendRegistrationEnrollmentOwed`, `sendRegistrationRefused`, `sendAddressTaken`, `sendRegistrationBarred` and `sendRegistrationCancelled`.

The link's page renders `auth/EmailedLink`, a page with one button that posts to the `action` it is given. Later emailed links reuse it. If you change a hook, redefine its assertion in `tests/Keystone/Assertions/RegistrationAssertions.php`, `RegistrationLinkAssertions.php` or `RegistrationFinishAssertions.php`, so Keystone's AppTests check your response instead.

To change the mail's wording, override the keys under `keystone::mail.links.registration` in `lang/vendor/keystone/{locale}/mail.php`.
