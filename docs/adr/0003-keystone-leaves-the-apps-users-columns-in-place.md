# Keystone leaves the app's users columns in place

The `users` table belongs to the application until it installs Keystone. Keystone keeps its own data (email addresses, credentials) in its own tables and never authenticates against the app's `email`, `password` or `remember_token` columns. Its install migration makes those columns nullable so users can still be created, but doesn't drop them. Dropping them is the app's call. The old v4 dropped them on install.

The one write: each backfill nulls the `password` and `remember_token` values it copied into Keystone's tables. A secret left behind would stay a second sign-in path that skips the second factor, the rate limiter and the credential epoch, through `Auth::attempt`, `auth.basic` or an app's own `Hash::check`. The columns and the non-secret `email` data stay.
