<?php

namespace ClaudioDekker\Keystone\Session;

/**
 * The flow a request is in, such as sign-in, which the session state derives.
 *
 * @api
 */
enum Flow: string
{
    case SIGN_IN = 'sign-in';
}
