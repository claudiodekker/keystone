<?php

namespace ClaudioDekker\Keystone\Http;

/**
 * @internal
 */
enum SiteData: string
{
    case CACHE = 'cache';
    case COOKIES = 'cookies';
    case EXECUTION_CONTEXTS = 'executionContexts';
    case STORAGE = 'storage';
}
