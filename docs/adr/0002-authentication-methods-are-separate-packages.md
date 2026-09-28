# Authentication methods are separate packages

Password, WebAuthn and TOTP each ship as their own package (`keystone-password`, `keystone-webauthn`, `keystone-totp`), like magic link, OAuth and push. In v3 they lived in core. Core stays method-agnostic, and an application installs only the methods it uses. Recovery codes are the exception and stay in core: break-glass access must not depend on a package the application may not have installed.
