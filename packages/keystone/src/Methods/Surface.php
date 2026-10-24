<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
enum Surface: string
{
    case CHALLENGE = 'challenge';
    case ENROLLMENT = 'enrollment';
    case REGISTRATION = 'registration';
    case SIGN_IN = 'sign-in';
}
