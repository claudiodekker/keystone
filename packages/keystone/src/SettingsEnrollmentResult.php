<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
enum SettingsEnrollmentResult
{
    case ENROLLED;
    case REFUSED;
    case EXPIRED;

    /**
     * The session can no longer finish the enrollment: its sudo ended while the answer was checked, or it is no longer signed in.
     */
    case SUDO_ENDED;
}
