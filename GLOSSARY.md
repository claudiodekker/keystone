# Glossary

## Core

The `keystone` package. It owns every flow, rule and guarantee, and it renders no markup.

## Method package

A package that adds ways to prove identity. The method packages are `keystone-password`, `keystone-webauthn`, `keystone-totp`, `keystone-magic-link` and `keystone-oauth`.

## Frontend adapter

A package that gives core its pages. The frontend adapters are `keystone-inertia-vue` and `keystone-blade`. An adapter installs controllers, pages, routes and translations into the app, and the app then owns them.
