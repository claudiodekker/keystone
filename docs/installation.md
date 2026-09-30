# Installation

Keystone isn't released yet. Once it is, you install it with a frontend adapter and its installer:

```shell
composer require claudiodekker/keystone claudiodekker/keystone-inertia-vue
php artisan keystone:install
php artisan migrate
```

Each sign-in method is its own package, and none is required. To offer passwords, also require `claudiodekker/keystone-password` (see [Passwords](password.md)), before or after running the installer.

The installer refuses while `laravel/fortify` is installed, since both would handle authentication, and without Pest, which Keystone's AppTests run on. Otherwise it:

- copies the adapter's files into your app (see [The Inertia-Vue adapter](#the-inertia-vue-adapter)), with a partial for each credential type you installed. It skips and lists every file your app already has; `--force` overwrites them. Run it again after installing another method to add that method's partial.
- requires `routes/keystone.php` from `routes/web.php`, makes your user model a Keystone user and sets the `web` guard's driver to `keystone`, as described below.
- sets `SESSION_COOKIE=__Host-<app name>-session`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=null` and `SESSION_PATH=/` in `.env` and `.env.example`. The `__Host-` prefix keeps the session cookie to your own host, and browsers only accept it over HTTPS, so serve your app over HTTPS locally too (for example `herd secure`); `localhost` is the one exception.
- adds the `Keystone` testsuite to `phpunit.xml` and maps the AppTests' namespaces in your `autoload-dev`, so `php artisan test` proves your copies keep Keystone's guarantees.
- adds the npm packages the pages need, keeping the versions you already pinned, then runs `npm install && npm run build`. A package your app already depends on keeps its version.

When it can't make an edit, such as a `phpunit.xml` without a `<testsuites>` element, it prints what to add yourself. It leaves `app.cipher` alone. Keystone's migrations run from the package, so there is nothing to publish; publish Keystone's config only to change a setting (see [Configuration](configuration.md)).

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

`claudiodekker/keystone-inertia-vue` gives Keystone its pages in Inertia and Vue, styled with Tailwind. It publishes files into your app that you then own. They carry no Keystone branding and sit where your own auth files would, as in Laravel's starter kits:

- `app/Http/Controllers/Auth/`: one controller per Keystone controller, such as `SignInController`. Each response hook turns one outcome into a response, so change a hook to change what users see. Keystone decides the outcome before the hook runs.
- `routes/keystone.php`: the routes, grouped under `/auth` and required from `routes/web.php`. Keystone relies on the route names, not the URLs, so you may change the URLs.
- `resources/js/pages/`: one page per step, in the folder of the feature it belongs to, as your own pages would be: signing in is `auth/Login.vue`, and pages for managing email addresses will go in `emails/`. `auth/Login.vue` shows the status message Keystone passes it, already translated, and a form per credential type. `layouts/AuthLayout.vue` wraps them.
- `resources/js/partials/`: a partial per credential type, such as `Password.vue`. Without one, a type falls back to the partial for its initiate shape in `partials/shapes/`.
- `resources/js/components/`: the pieces the pages and partials share, such as `CredentialTypeForm.vue` and `SignOutButton.vue`. A component only one type's partial uses, such as `PasswordField.vue`, arrives with that partial.
- `resources/js/types/auth.ts`: the props each page receives.
- `tests/Keystone/Assertions/`: the Inertia versions of the assertions Keystone's AppTests make about your responses. Redefine one there when you change what its hook returns.

The pages build their URLs with [Wayfinder](https://github.com/laravel/wayfinder), generated when Vite builds; don't commit its output (`resources/js/actions`, `resources/js/routes` and `resources/js/wayfinder`).

In an app without `resources/js/app.ts`, the installer also sets up Inertia and Vue: it copies `vite.config.ts`, `tsconfig.json`, `resources/js/app.ts`, the `app` root view and a `HandleInertiaRequests` middleware, which it adds to the `web` group. It keeps your `vite.config.js` and `resources/js/app.js` as `.bak` files and points `welcome.blade.php` at `app.ts`. An app that already has `resources/js/app.ts`, such as one made from the Laravel Vue starter kit, keeps its own setup.

Keystone's pages are kept encrypted in the browser's history, and signing out clears it, so Back after signing out shows none of them.
