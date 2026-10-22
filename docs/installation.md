# Installation

Install Keystone with Composer:

    composer require claudiodekker/keystone

## Inertia and Vue

The Inertia and Vue adapter gives your app a sign-in page and a sign-out button as files you own. Keystone runs the flow, and your controllers and pages decide what users see, so you can restyle or rewrite them freely.

### Requirements

Start from a fresh Laravel app that has Pest and does not have Laravel Fortify. Keystone's tests run on Pest, and Keystone replaces Fortify. The installer checks both before it changes anything, and stops with a list of what to fix.

You also need Node and npm, because the installer builds the frontend.

### Install

If your app does not have Pest yet, add it first:

    composer require pestphp/pest --dev --with-all-dependencies
    ./vendor/bin/pest --init

Then require Keystone, the password method and the adapter, and run the installer:

    composer require claudiodekker/keystone claudiodekker/keystone-password claudiodekker/keystone-inertia-vue
    php artisan keystone:install
    php artisan migrate

Then run Keystone's own tests against your app:

    php artisan test --testsuite=Keystone

Sign-in lives at `/login`. Browsers keep Keystone's session cookie only over HTTPS, so serve the app over HTTPS, for example with Laravel Herd, before you sign in.

### What the installer changes

The installer copies the sign-in and sign-out controllers, the Vue pages and components, the routes and the test setup into your app, in the folders a Laravel app already uses. It copies the files for each method you have installed, so a password app gets the password form.

It then wires the app up to run them. Your routes file requires the new `routes/keystone.php`. It replaces `app/Models/User.php` with a `User` model that implements `KeystoneUser`. Your default guard uses the `keystone` driver. The session cookie is named `__Host-<your app>-session` and marked secure in `.env` and `.env.example`. `phpunit.xml` gets a `Keystone` test suite, and `composer.json` maps the packages' tests so they run from `vendor`. Your Pest setup binds those tests to the test case it copies to `tests/Keystone`.

It also sets up Inertia and Vue. It copies `vite.config.ts`, `tsconfig.json`, `app.ts`, a root view and the `HandleInertiaRequests` middleware, and replaces `bootstrap/app.php` with one that registers that middleware. It deletes `vite.config.js` and `resources/js/app.js`, which those files replace, and the welcome page stops loading `app.js`.

Replacing and deleting files undoes any change you made to them. Before you commit, review the install with `git diff` and restore what you need. A later run leaves `User.php` and `bootstrap/app.php` alone once they implement `KeystoneUser` and register the middleware.

Last, it adds the npm packages the pages need to `package.json`, at the versions Keystone builds them with, then runs `composer dump-autoload`, `npm install` and `npm run build`. If one of them fails, it prints the commands to run yourself.

### What it leaves alone

The installer does not publish Keystone's config file or its migrations, which `php artisan migrate` runs from the package. Your `app.cipher` setting stays as it is. When the app already has a file the installer would copy, it keeps the app's file and lists it.

### Run it again

Running `php artisan keystone:install` again changes nothing it has already done, and finishes a run that stopped part way. After you install another method package with Composer, run it again to copy that method's files.

To replace the files the installer copied, such as to take new versions of the pages, add `--force`. That overwrites every file it copies with Keystone's version.

### When it prints an edit

An edit the installer cannot make safely, for instance because the file differs a lot from a fresh Laravel app, is left unchanged and printed with the change to make. Make that change by hand and run the installer again. The edit then shows as unchanged.

For example, the installer adds Keystone's test binding after the first `pest()->extend(...)` call in `tests/Pest.php`. When that file has no such call, it prints the line to add:

    pest()->extend(Tests\Keystone\TestCase::class)->in('../vendor/claudiodekker/keystone*/app-tests');
