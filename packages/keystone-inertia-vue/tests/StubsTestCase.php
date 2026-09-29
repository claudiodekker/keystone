<?php

namespace ClaudioDekker\Keystone\InertiaVue\Tests;

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Inertia\Middleware;

abstract class StubsTestCase extends AppTestCase
{
    /**
     * The adapter's stubs, laid out as they are copied into the app.
     */
    public const string STUBS = __DIR__.'/../stubs';

    /**
     * Set up the test environment, rendering the stub pages in a bare root view.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['inertia.pages.paths' => [self::STUBS.'/resources/js/pages']]);
        $this->app['view']->addLocation(__DIR__.'/Fixtures/views');
    }

    /**
     * Define the stub route file's routes in the web group, as the app's routes/web.php requires it, in place of core's fixture routes.
     *
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->setRoutes(new RouteCollection);
        $router->middleware(['web', Middleware::class])->group(self::STUBS.'/routes/keystone.php');
    }
}
