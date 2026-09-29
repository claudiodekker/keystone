# Frontend adapters publish stubs the app owns

Core renders no markup. It runs every flow in abstract controllers that end in one response hook per outcome, and it hands each show step a readonly page value whose field names both adapters pass on unchanged. A frontend adapter is a folder of stubs laid out like the application (controllers, route file, pages, shape components, partials, page-value types, assertion overrides) that the installer copies in; from then on the application owns them. The adapter's own code stays small: what the stubs lean on and what AppTests need to read its responses.

Owning the files, rather than configuring a package's views, lets an application restyle or restructure any page without Keystone having to foresee it, and it keeps the security decisions out of reach: a stub only turns an outcome core already decided into a response. AppTests then prove the application's copies still keep Keystone's guarantees.

## Consequences

- The stubs install as the application's own auth files, unbranded and where a starter kit would put them: controllers in `app/Http/Controllers/Auth/`, pages in the folder of the feature they belong to (`pages/auth/Login.vue` for signing in, `pages/emails/` for managing email addresses), partials in `partials/` and shared components in `components/`. Two things keep Keystone's name: the route file, `routes/keystone.php`, which `routes/web.php` requires, and the AppTest assertion overrides under `tests/Keystone/`, which exist for Keystone's AppTests.
- Inertia-Vue renders a show step as the page component at that path (`auth/Login`), with the page value's fields as its props. Every other outcome redirects, as in core's own fixtures.
- Inertia-Vue encrypts the browser's history on every Keystone show step and clears it when Keystone ends the session, so Back after signing out shows no Keystone page value.
- A page renders one component per credential type: the partial named after the type (`partials/Password.vue` for `password`) when the application has one, else the partial for the type's initiate shape in `partials/shapes/`. The adapter ships a partial for every first-party type and one for each shape a built flow uses; the form shape is the first.
- The stubs' own labels ("Sign in", "Password") are plain English in the markup, as in Laravel's starter kits: the application owns and translates them. Everything core says, such as a status or a refusal, arrives already translated from `keystone::`.
- Page-value TypeScript types are written by hand in the stubs, and `vue-tsc` checks the pages against them.
- The stubs use stable Wayfinder for URLs. It is generated at build, never committed; in this repository it is generated into the stubs folder, which ignores it.
- The adapter's AppTest assertions (`ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions`) override core's where Inertia responses differ, and the stub copy the application owns uses them, so an application's copy may reach core's trait through the adapter's.
- The stub route file carries no `guest` or `auth` middleware: core refuses the wrong state inline, and the AppTests expect its refusals, such as a guest's throttled sign-out.
- The root workbench serves the Inertia-Vue stubs outside the test suite. When the Blade adapter arrives it has to choose one adapter per workbench run, since the two share route names and controller classes.
