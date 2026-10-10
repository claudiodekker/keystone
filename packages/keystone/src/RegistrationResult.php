<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
enum RegistrationResult
{
    case SIGNED_IN;
    case ENROLLMENT_OWED;
    case REFUSED;
    case ADDRESS_TAKEN;
    case BARRED;
    case EXPIRED;
}
