<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * A place in the flows where a credential type can be used.
 *
 * @internal
 */
enum Surface: string
{
    case CHALLENGE = 'challenge';
    case ENROLLMENT = 'enrollment';
    case REGISTRATION = 'registration';
    case SIGN_IN = 'sign-in';
}
