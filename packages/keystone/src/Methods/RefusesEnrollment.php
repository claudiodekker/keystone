<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
interface RefusesEnrollment
{
    /**
     * Get why the app can't enroll a credential of the type, worded for the user, or null when it can.
     */
    public function enrollmentRefusal(CredentialTypes $types): ?string;
}
