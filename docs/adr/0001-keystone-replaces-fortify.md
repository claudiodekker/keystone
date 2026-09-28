# Keystone replaces Fortify

Keystone is a replacement for Laravel Fortify, not a layer on top of it: it owns every authentication flow itself and has no dependency on Fortify. Building on Fortify would tie Keystone's session state, flows and security guarantees to a package whose design it doesn't control.
