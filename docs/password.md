# Password

The `keystone-password` package signs users in with a password. Install it with Composer:

    composer require claudiodekker/keystone-password

The package registers the `password` credential type for sign-in, registration and enrollment, and a password never proves two factors on its own. It ships no controllers, routes, views or responses. Your sign-in page posts the `identifier` and the `password` to the `login.submit` route of the `password` type:

```blade
<form method="POST" action="{{ route('login.submit', 'password') }}">
    @csrf
    <input name="identifier" autocomplete="username">
    <input type="password" name="password" autocomplete="current-password">
    <button>Sign in</button>
</form>
```

## Hashing

Passwords are hashed with Laravel's `Hash` under your app's `config/hashing.php`. The package has no settings, pepper or pre-hash of its own, and Keystone encrypts the hash before it stores it.

A stored bcrypt, argon2i or argon2id hash is checked by the driver of its own algorithm. A hash made under one driver keeps signing in after you switch `hashing.driver` to another, with `HASH_VERIFY` on.

## Rehashing

After a successful sign-in, the password is hashed again under your app's driver and cost whenever `Hash::needsRehash()` says the stored hash is out of date. The new hash is written after the user is signed in, and only if nobody changed the stored password in the meantime. A hasher that fails is reported to your exception handler, and the sign-in stands with the old hash.

Turn the rehash off in `config/hashing.php`:

```php
'rehash_on_login' => false,
```

## Lengths and spaces

Sign-in takes a password of up to 1024 characters. A longer password, an empty one or an array is a validation error on the `password` field. Under bcrypt, only the first 72 bytes of a password count, as bcrypt has always read them.

Spaces are part of the password. The package never trims `password`, `current_password` or `password_confirmation`, even when your `TrimStrings` middleware drops Laravel's defaults from its `$except` list.

## Unknown accounts

An unknown account, an account without a password and an account whose stored secret is not a hash all take as long as a wrong password. Keystone checks the password against a dummy hash made with your app's driver and cost. It makes the dummy hash once and keeps it in the default cache store, under a fingerprint of your whole hashing config, so changing the driver or the cost makes a new one. Until an account signs in again and its hash is rehashed, a wrong password against a hash of an older driver or cost takes that hash's time instead.

Use a persistent cache store in production. With the `array` or `null` store, the dummy hash is made on every such request, which makes an unknown account slower than a known one. While the cache fails, the dummy hash is made on the spot.

## Importing hashes

Store a hash in the `secret` of a `user_credentials` row of the type `password`, in the format `Hash` writes: a `$2y$`, `$argon2i$` or `$argon2id$` string. Keystone encrypts the column, so write it through the `UserCredential` model.

## Testing

The package brings test support for your AppTests. `PasswordSupport` gives a user a password, a stored hash as an import writes it, or a driver that refuses every hash or fails to make one:

```php
use ClaudioDekker\Keystone\Password\AppTests\PasswordSupport;

$user = $this->createUser();
$this->addVerifiedAddress($user, 'ada@example.com');
$support = (new PasswordSupport)->arrangePassword($user, 'correct horse battery staple', 'bcrypt');

config(['hashing.driver' => 'argon2id']);
$this->post(route('login.submit', 'password'), [
    'identifier' => 'ada@example.com',
    'password' => 'correct horse battery staple',
]);

$support->refuseHashesOf('bcrypt');
```

The sign-in above rehashed the password to argon2id. A later sign-in that still works after `refuseHashesOf('bcrypt')` proves it.
