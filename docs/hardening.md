# Hardening

Keystone hardens its own responses and refuses cross-site requests to its own routes itself. Nothing in your routes, middleware or controllers needs to be wired up, and none of it can be turned off from a controller you published.

A route is one of Keystone's when its controller extends one of Keystone's controllers, as the published `SignInController` and `SignOutController` do. Your own routes and controllers are left alone.

## Headers

Every response from a Keystone route carries these headers, whatever produced it: the page itself, a redirect, a validation or throttle refusal, a middleware that answered early, or an exception your handler rendered.

| Header | Value |
|---|---|
| `Cache-Control` | `no-store, max-age=0, must-revalidate` |
| `Pragma` | `no-cache` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Cross-Origin-Resource-Policy` | `same-origin` |
| `X-Frame-Options` | `DENY` |

When your app or one of your middleware sets one of these headers too, Keystone's value replaces yours on Keystone's routes. `X-Frame-Options` is left out when you let other pages frame Keystone's (see [Framing](#framing)).

### Content Security Policy

Keystone keeps your `Content-Security-Policy` and adds `object-src 'none'`, `base-uri 'none'` and `frame-ancestors 'none'` to it, replacing any value you gave those three directives, so a `frame-ancestors *` doesn't let another site frame the sign-in page. When you send several policies, each gets the three. When you send none, Keystone sends only those three.

Keystone never sets `script-src`. Add your own policy, with the nonces or hashes your pages need, to restrict scripts.

### Framing

By default no page, not even one of your own, can show Keystone's pages in a frame. To let some, list their [CSP sources](https://developer.mozilla.org/docs/Web/HTTP/Headers/Content-Security-Policy/frame-ancestors) in `keystone.hardening.frame_ancestors`:

```php
'hardening' => [
    'frame_ancestors' => ["'self'", 'https://partner.example'],
],
```

Keystone then sends `frame-ancestors 'self' https://partner.example` and no `X-Frame-Options`. `'self'` is enough for a sign-in modal that frames your own login page. Letting another site frame the sign-in page lets it overlay and hide what the user clicks, so list only sites you trust. A frame from another site also gets no session: Keystone's cookies are `SameSite=Lax`, so sign-in inside a cross-site frame won't work anyway.

A value that isn't a list of single sources, such as one holding `;`, `,` or a space, is ignored, and no page may frame Keystone's.

## Cross-site requests

Keystone refuses a request that changes something (anything but `GET`, `HEAD` and `OPTIONS`) on one of its routes unless it shows it came from your app. It needs one of:

- a `Sec-Fetch-Site: same-origin` header, which every current browser sends on your own pages' requests;
- the session's CSRF token, as the `_token` field, the `X-CSRF-TOKEN` header or the encrypted `X-XSRF-TOKEN` header, as for Laravel's own check;
- an `Origin` header naming your app's own scheme and host, or an origin listed in `keystone.trusted_origins`.

To accept Keystone mutations from another of your origins, such as a sign-in form on your marketing site at `https://www.example.com` that posts to your app at `https://app.example.com`, list it:

```php
'trusted_origins' => ['https://www.example.com'],
```

An origin is a scheme, host and port, and must match exactly: `https://www.example.com` doesn't trust `http://www.example.com`, `https://www.example.com:8443` or any subdomain. List only origins you control, since any page served from one can submit to Keystone as your users.

This check runs even when you list a Keystone route in your CSRF exceptions (`$middleware->preventRequestForgery(except: [...])`). A refused request gets Laravel's usual `419 Page Expired` response and records a `request.rejected` [security event](security-events.md), on the signed-in account's audit trail when there is one.

## Clearing site data

When Keystone ends a session, because the user signed out, its other sessions were ended (for example by a password change), or the account was deleted, suspended or invalidated, the response to that request carries `Clear-Site-Data: "cache", "storage"`. The browser then drops what it cached and stored for your site, so nothing from the ended session is left behind on a shared computer. This applies to whichever response that request gets, including one from your own routes.

Clearing storage also drops what your app keeps there on purpose, such as preferences in `localStorage` or a service worker's offline cache. Choose the kinds of data to clear in `keystone.clear_site_data`, from `cache`, `cookies`, `storage` and `executionContexts`, or turn it off with an empty list:

```php
'clear_site_data' => ['cache'],
```

A list naming anything else is ignored, and Keystone clears the default `cache` and `storage`.
