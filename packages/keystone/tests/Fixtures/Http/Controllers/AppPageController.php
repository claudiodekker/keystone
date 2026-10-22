<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * Answer a page of the app that has nothing to do with Keystone.
 */
class AppPageController
{
    /**
     * Answer the page with a header the app chose.
     */
    public function show(): Response
    {
        return response('page', headers: ['X-Frame-Options' => 'SAMEORIGIN']);
    }

    /**
     * Answer the submit.
     */
    public function store(): Response
    {
        return response('stored');
    }
}
