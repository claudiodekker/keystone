<?php

namespace ClaudioDekker\Keystone\InertiaVue\Tests;

/**
 * Point the app at a fresh copy of the Laravel skeleton for keystone:install to install into.
 */
trait InstallsIntoFreshApp
{
    /**
     * The skeleton copy the installer installs into.
     */
    protected FreshApp $fresh;

    /**
     * Copy the skeleton and point the app at it.
     */
    protected function setUpInstallsIntoFreshApp(): void
    {
        $this->fresh = FreshApp::copy();
        $this->app->setBasePath($this->fresh->path());
    }

    /**
     * Delete the copy.
     */
    protected function tearDownInstallsIntoFreshApp(): void
    {
        $this->fresh->delete();
    }
}
