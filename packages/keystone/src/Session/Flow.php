<?php

namespace ClaudioDekker\Keystone\Session;

/**
 * @api
 */
enum Flow: string
{
    case CHALLENGE = 'challenge';
    case ENROLLMENT = 'enrollment';
    case SIGN_IN = 'sign-in';
}
