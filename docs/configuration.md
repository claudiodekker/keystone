# Configuration

Keystone's config is `config/keystone.php`. Your app's file merges over core's all the way down: maps merge key by key, so a key you leave out keeps its default, and so does a key a later release adds. A list in your file replaces core's list whole, so you can shorten it or empty it. Laravel's cached config is used as cached.

```php
return [
    'rate_limits' => [
        'requests_per_minute' => [
            'view' => 120,
        ],
    ],
    'clear_site_data' => ['cache', 'cookies', 'storage'],
];
```

This file raises the view allowance and keeps every other allowance at its default. It lists three kinds of site data to clear, where core lists two.

| Key | Default | Loosening it |
|---|---|---|
| `clear_site_data` | `['cache', 'storage']` | A shorter list leaves more of an ended session in the browser, and `[]` leaves all of it. Adding `cookies` signs the browser out of everything it holds a cookie for on your domain. |
| `events.enabled` | `true` | `false` keeps no trail and sends no alert. |
| `hardening.frame_ancestors` | `[]` | A listed source can frame Keystone's pages and trick a user into clicking on them. |
| `log_channel` | `null` | None. It only picks where the security event lines go. |
| `methods` | `null` | None. It only narrows: a type it leaves out is offered nowhere, and it can't offer a type on a surface the type doesn't serve. |
| `rate_limits.failed_attempts_per_hour` | `20` | A higher number gives an attacker more guesses per account. |
| `rate_limits.requests_per_minute.change` | `10` | A higher number lets more sign-outs through from one address. |
| `rate_limits.requests_per_minute.submit` | `10` | A higher number lets more sign-in submits through from one address. |
| `rate_limits.requests_per_minute.view` | `60` | A higher number lets more page views through from one address. |
| `trusted_origins` | `[]` | Any page on a listed origin can sign users in and out. |

`Cross-Origin-Opener-Policy` and `base-uri` have no setting. The [security page](security.md) says what each key does.

## Choosing the methods

`methods` is `null` by default, which offers every installed credential type on every surface it serves. Set a list to offer fewer. A bare name keeps all of a type's surfaces, and a name with a list of surfaces narrows it to those:

```php
'methods' => [
    'password' => ['registration', 'sign-in'],
],
```

The surfaces are `challenge`, `enrollment`, `registration` and `sign-in`. This list offers the password on registration and sign-in and nowhere else. A type the list leaves out is not on the sign-in page, and a proof submitted to it is answered as not found.

## What Keystone refuses

Keystone checks its config when you cache it, and on every boot of an app that has no cached config. It refuses to boot an app that fails a check, and its one exception lists every failure, so one deploy fixes them all. A config that fails can't be cached: `config:cache` and `optimize` stop with the exception and leave no cached config behind. `config:clear` and `optimize:clear` run without the checks, so a refused config can always be cleared.

An app that runs from a cached config pays nothing for the checks per request, so run `config:cache` (or `optimize`) in your deploy, as Laravel recommends. Your test suite boots without a cached config, so it runs the checks on every test boot, and your CI catches a change to your bindings or middleware that fails one.

The checks set no ceilings. In every environment, they refuse a value of the wrong type, and a `0` or `null` that would switch a control off:

| Key | Refused unless it is |
|---|---|
| `rate_limits.failed_attempts_per_hour` and every `rate_limits.requests_per_minute.*` | An integer of at least 1. |
| `events.enabled` | `true` or `false`. |
| `log_channel` | `null`, or the name of a channel in your `logging` config. |
| `clear_site_data` | A list of `cache`, `cookies`, `executionContexts` and `storage`. |
| `hardening.frame_ancestors` | A list of single CSP sources, such as `'self'` or `https://portal.example.com`. |
| `methods` | `null`, or a list of installed types by name, each alone or with a non-empty list of surfaces the type serves. No type may be named twice. |
| `trusted_origins` | A list of origins, each a scheme and a host with nothing after it. |

It also refuses two packages that register one credential type name, and a user model on a database connection other than the default one, because Keystone's tables are migrated on the default connection.
