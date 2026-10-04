# One installer that edits the app in place

Each frontend adapter ships a `keystone:install` command built on core's `Installer`, which holds the steps every adapter shares: the refusals, copying stubs, the route require, the user model, the guard, the session cookie, the AppTests and npm packages. The adapter decides which stubs to copy and what its frontend needs on top.

The installer edits the application's own files (`routes/web.php`, the user model, `config/auth.php`, `.env`, `phpunit.xml`, `composer.json`, `package.json`) rather than asking the application to do it by hand, because an application that misses one of those steps runs unprotected or runs no AppTests, and nothing would tell it. Every edit is idempotent, so the command is safe to run again, and running it again is how an application picks up the partial of a method it installed later.

## Consequences

- It refuses while `laravel/fortify` is installed and without Pest, before changing anything.
- It never overwrites a file the application has unless `--force` is passed, and it lists what it skipped.
- Each edit matches the shape of a fresh Laravel skeleton. When a file has drifted too far to match, the installer leaves it unchanged and prints the edit to make by hand, rather than guessing.
- AppTests are mapped in `autoload-dev` only, and they run from `vendor/`, so they update with Keystone while the assertions the application overrides stay in `tests/Keystone/Assertions/`.
- It leaves `app.cipher` alone: Keystone's encrypted columns use whatever cipher the application already has.
- Keystone has no config file and runs its migrations from the package, so the installer publishes neither. Publishing the migrations as well would run them twice.
- The Inertia-Vue installer sets up Inertia and Vue itself in a bare `laravel new` application, one without `resources/js/app.ts` whose `resources/js/app.js` is the skeleton's and which no other view loads. It keeps the old Vite entry files as `.bak`, so the application reaches a working sign-in page in one command. An application with its own setup, such as the Vue starter kit, Livewire or its own Blade layouts, keeps it, and the installer prints what to set up instead.
- A run that stops part way is finished by running it again. The Inertia-Vue installer takes an `app.ts` identical to its stub as the one an earlier run copied, so it still wires the middleware and the welcome view.
- It runs `composer dump-autoload`, `npm install` and `npm run build`, and when one fails it prints the commands to run instead.
