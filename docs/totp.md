# TOTP

The `keystone-totp` package answers the [challenge](challenge.md) with a code from an authenticator app, and sets one up at [enrollment](enrollment.md). Install it with Composer:

    composer require claudiodekker/keystone-totp

The package registers the `totp` credential type for the challenge and for enrollment, and a code never proves two factors on its own. Your challenge page posts the `code` to the `login.challenge.submit` route of the `totp` type:

```blade
<form method="POST" action="{{ route('login.challenge.submit', 'totp') }}">
    @csrf
    <input name="code" inputmode="numeric" autocomplete="one-time-code">
    <button>Continue</button>
</form>
```

A code that isn't six digits is a validation error on the `code` field, and isn't counted as a failed attempt.

## Enrolling a key

At [enrollment](enrollment.md), Keystone starts a new 160-bit key for the user, kept in the session until its first code is right. The type's `ceremony` on the enrollment page holds the key in Base32, for typing into an authenticator app, and as an `otpauth://` URI for an app to open or a QR code to carry. The URI's label is your app's `app.name` and the account's first address that alerts go to, such as `otpauth://totp/Acme%3Aada%40example.com?secret=...&issuer=Acme`. A reload shows the same key until it expires, 10 minutes after it was started.

The user posts the `code` their app shows to the `login.enrollment.submit` route of the `totp` type. A code within the window stores the key, with that code's step accepted, so the same code can't answer the next challenge. A wrong code keeps the key, and a code after the key expired starts a new one.

## Codes

Codes follow RFC 6238 with HMAC-SHA1, six digits and 30-second steps, which every authenticator app makes by default. There is no setting for the algorithm, the digits or the step length.

By default a code from the step before now, the current step or the step after it signs in, so a code typed as it rolls over, or on a phone whose clock is up to 30 seconds off, still works. Set `totp.window_steps` in `config/keystone.php` to change how many steps either side count:

```php
'totp' => [
    'window_steps' => 1,
],
```

`0` accepts the current step's code alone. Every step you add accepts two more codes, so a window of n steps lets each guess succeed with a chance of about (2n + 1) in a million: 3 in a million at the default. A value that isn't a whole number of at least 0 refuses to boot. A wider window boots.

## Each code once

A code signs in once. Keystone keeps the step of the last accepted code with the credential, and refuses a code from that step or an earlier one as `totp.replayed`. The step is written only while the credential still holds what the code was checked against, so the same code sent from two browsers at once signs in one of them.

After a code from the step after now is accepted, the current step's code is refused until the next step begins.

## Failed attempts

A wrong code takes a failed attempt like any other answer. On top of the hourly allowance, an account gets at most 100 failed codes a day, a ceiling `rate_limits` doesn't change. See [rate limits](security.md#rate-limits).

## Importing secrets

Store a key in the `secret` of a `user_credentials` row of the type `totp`, as JSON holding the Base32 key and no accepted step yet:

```php
use ClaudioDekker\Keystone\Models\UserCredential;

UserCredential::create([
    'user_id' => $user->id,
    'type' => 'totp',
    'secret' => json_encode(['key' => $base32Key, 'last_step' => null]),
]);
```

Keystone encrypts the column, so write it through the `UserCredential` model. A key of any length works, such as the 80-bit keys Fortify made.

## Testing

The package brings test support for your AppTests. `TotpSupport` gives a user the fixture key or a key of your own, and makes the code of any step:

```php
use ClaudioDekker\Keystone\Totp\AppTests\TotpSupport;

$user = $this->createUser();
$support = (new TotpSupport)->arrangeKey($user, 'JBSWY3DPEHPK3PXP');

$this->passFirstFactor($user);
$this->post(route('login.challenge.submit', 'totp'), ['code' => $support->code(1, 'JBSWY3DPEHPK3PXP')]);
```

`code()` takes the number of steps from now, and the key, which is the fixture key unless you give one. At enrollment, read the key the page shows and make its code:

```php
$this->passFirstFactor($user);

$ceremony = $this->shownCeremony($this->get(route('login.enrollment')), 'totp');

$this->post(route('login.enrollment.submit', 'totp'), ['code' => (new TotpSupport)->code(key: $ceremony['key'])]);
```
