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

`HasKeystone` adds soft deletes and keeps Keystone's `users` columns out of the model's array form and out of mass assignment. The model may use its own table and key name.

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

Only Keystone signs anyone in. `Auth::attempt()`, `Auth::login()`, `Auth::loginUsingId()`, `Auth::logoutOtherDevices()` and the `auth.basic` middleware sign nobody in. Sessions and remember-me cookies from before Keystone end on their next request.

Keystone's migration adds its columns to `users` and makes `email`, `password` and `remember_token` nullable. It never reads or drops them.
