# Installation

Keystone isn't released yet. This page will explain how to install it with each frontend adapter.

## The user model

Your `User` model keeps extending `Authenticatable` and adds Keystone's interface and trait:

```php
use ClaudioDekker\Keystone\HasKeystone;
use ClaudioDekker\Keystone\KeystoneUser;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements KeystoneUser
{
    use HasKeystone;
}
```

`HasKeystone` adds soft deletes and keeps Keystone's `users` columns out of the model's array form and out of mass assignment. The model may use its own table and key name; Keystone's migration only changes `users`, so add its columns to your table yourself.

## The guard

Set your app's guard to the `keystone` driver in `config/auth.php`. Its provider must use the `eloquent` driver with the model above.

```php
'guards' => [
    'web' => [
        'driver' => 'keystone',
        'provider' => 'users',
    ],
],
```

Only Keystone signs anyone in. `Auth::attempt()`, `Auth::once()`, `Auth::onceUsingId()`, `Auth::login()`, `Auth::loginUsingId()`, `Auth::logoutOtherDevices()` and the `auth.basic` middleware sign nobody in. Sessions and remember-me cookies from before Keystone end on their next request.

Keystone's migration adds its columns to `users` and makes `email`, `password` and `remember_token` nullable strings (255, 255 and 100 characters). It never reads or drops them.

Keystone's migrations also create `user_emails`, which holds each account's email addresses, and `user_credentials`, which holds every credential of every method in one table with its identifier and secret encrypted. A third table, `user_security_events`, holds each account's audit trail; see [Security events](security-events.md).

## Email addresses

Keystone stores and matches an address trimmed, Unicode NFC-composed, lowercased as a whole and with its domain in punycode, so `José@Bücher.example` and `josé@xn--bcher-kva.example` are one address, while `rené@` and `rene@` stay two. An address signs in only when exactly one active account holds it verified, or holds it unverified while it has no verified address. Any other address is treated as unknown, with the same refusal as a wrong password.

## The Inertia-Vue adapter

`claudiodekker/keystone-inertia-vue` gives Keystone its pages in Inertia and Vue, styled with Tailwind. It publishes files into your app that you then own:

- `app/Http/Controllers/Keystone/`: one controller per Keystone controller. Each response hook turns one outcome into a response, so change a hook to change what users see. Keystone decides the outcome before the hook runs.
- `routes/keystone.php`: the routes, required from `routes/web.php`. Keystone relies on the route names, not the URLs, so you may change the URLs.
- `resources/js/pages/keystone/`: one page per step. `SignIn.vue` shows the status message Keystone passes it, already translated, and a form per credential type.
- `resources/js/components/keystone/partials/`: a component per credential type, such as `Password.vue`. Without one, a type falls back to the component for its initiate shape in `components/keystone/shapes/`.
- `resources/js/types/keystone.ts`: the props each page receives.
- `tests/Keystone/Assertions/`: the Inertia versions of the assertions Keystone's AppTests make about your responses. Redefine one there when you change what its hook returns.

The pages build their URLs with [Wayfinder](https://github.com/laravel/wayfinder), generated when Vite builds; don't commit its output. Your app needs Inertia's middleware in the `web` group and a root view, as the Laravel Vue starter kit has.

Keystone's pages are kept encrypted in the browser's history, and signing out clears it, so Back after signing out shows none of them.
