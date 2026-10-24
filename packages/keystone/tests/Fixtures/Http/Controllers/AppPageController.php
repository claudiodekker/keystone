<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

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
