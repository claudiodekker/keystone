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

Keystone's migrations also create `user_emails`, which holds each account's email addresses, and `user_credentials`, which holds every credential of every method in one table with its identifier and secret encrypted.

## Email addresses

Keystone stores and matches an address trimmed, Unicode NFC-composed, lowercased as a whole and with its domain in punycode, so `José@Bücher.example` and `josé@xn--bcher-kva.example` are one address, while `rené@` and `rene@` stay two. An address signs in only when exactly one active account holds it verified, or holds it unverified while it has no verified address. Any other address is treated as unknown, with the same refusal as a wrong password.
