<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
enum Surface: string
{
    case SIGN_IN = 'sign-in';
    case CHALLENGE = 'challenge';
    case REGISTRATION = 'registration';
    case ENROLLMENT = 'enrollment';
}
