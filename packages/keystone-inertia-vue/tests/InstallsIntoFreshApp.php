<?php

namespace ClaudioDekker\Keystone\InertiaVue\Tests;

/**
 * Point the app at a fresh copy of the Laravel skeleton.
 */
trait InstallsIntoFreshApp
{
    /**
     * The skeleton copy the app points at.
     */
    protected FreshApp $fresh;

    /**
     * Copy the skeleton and point the app at it.
     */
    protected function setUpInstallsIntoFreshApp(): void
    {
        $this->fresh = FreshApp::copy();
        $this->app->setBasePath($this->fresh->path());

        config(['auth.providers.users.model' => 'App\\Models\\User']);
    }

    /**
     * Delete the copy.
     */
    protected function tearDownInstallsIntoFreshApp(): void
    {
        $this->fresh->delete();
    }
}
