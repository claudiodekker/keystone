# Configuration

Keystone's config is `config/keystone.php`. Your app's file merges over core's, key by key at the top level, so a key you leave out keeps its default.

| Key | Default | Loosening it |
|---|---|---|
| `clear_site_data` | `['cache', 'storage']` | A shorter list leaves more of an ended session in the browser, and `[]` leaves all of it. Adding `cookies` signs the browser out of everything it holds a cookie for on your domain. |
| `events.enabled` | `true` | `false` keeps no trail and sends no alert. |
| `hardening.frame_ancestors` | `[]` | A listed source can frame Keystone's pages and trick a user into clicking on them. |
| `log_channel` | `null` | None. It only picks where the security event lines go. |
| `rate_limits.failed_attempts_per_hour` | `20` | A higher number gives an attacker more guesses per account. |
| `rate_limits.requests_per_minute.change` | `10` | A higher number lets more sign-outs through from one address. |
| `rate_limits.requests_per_minute.submit` | `10` | A higher number lets more sign-in submits through from one address. |
| `rate_limits.requests_per_minute.view` | `60` | A higher number lets more page views through from one address. |
| `trusted_origins` | `[]` | Any page on a listed origin can sign users in and out. |

Three of them have a form that has to be right. `clear_site_data` is a list of `cache`, `cookies`, `storage` and `executionContexts`. `hardening.frame_ancestors` is a list of single CSP sources. `trusted_origins` is a list of origins, each a scheme and a host with nothing after it. A value of any other form falls back to the default in the table.

`Cross-Origin-Opener-Policy` and `base-uri` have no setting. The [security page](security.md) says what each key does.
