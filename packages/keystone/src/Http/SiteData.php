<?php

namespace ClaudioDekker\Keystone\Http;

/**
 * A kind of data Clear-Site-Data tells the browser to clear.
 *
 * @internal
 */
enum SiteData: string
{
    case CACHE = 'cache';
    case COOKIES = 'cookies';
    case EXECUTION_CONTEXTS = 'executionContexts';
    case STORAGE = 'storage';
}
