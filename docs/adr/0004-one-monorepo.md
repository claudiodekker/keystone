# One monorepo

Core, the method packages and the frontend adapters live in one repository with one root `composer.json`. Changes that cross packages, such as a response hook that core, the Inertia-Vue adapter and the Blade adapter must agree on, land in one commit.
