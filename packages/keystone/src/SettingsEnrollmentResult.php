<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
enum SettingsEnrollmentResult
{
    /**
     * The credential was stored beside what the account held.
     */
    case ADDED;

    /**
     * The credential was stored in place of the credentials of its type the account held.
     */
    case REPLACED;

    case REFUSED;

    /**
     * The session can no longer finish the enrollment: its sudo ended while the answer was checked, or it is no longer signed in.
     */
    case SUDO_ENDED;
}
