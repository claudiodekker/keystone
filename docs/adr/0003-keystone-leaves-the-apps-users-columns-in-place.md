# Keystone leaves the app's users columns in place

Keystone never authenticates against the app's `users.email`, `password` or `remember_token` columns; it keeps addresses and credentials in its own tables. Its install migration makes those columns nullable but never drops them, so the app keeps the data it migrates from and decides when to drop them.

## Consequences

Each backfill nulls the `password` and `remember_token` values it copied. A secret left behind would stay a second sign-in path, through `Auth::attempt`, `auth.basic` or an app's own `Hash::check`, that skips the second factor, the rate limiter and the credential epoch.
