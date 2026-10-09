<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\NamesStatuses;
use ClaudioDekker\Keystone\Methods\RefusesEnrollment;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Status;

/**
 * A replacing form type with statuses of its own, which can't be enrolled unless it is listed on sign-in.
 */
class WordedType extends FormType implements NamesStatuses, RefusesEnrollment
{
    /**
     * Why the type can't be enrolled when it isn't listed on sign-in.
     */
    public const string REFUSAL = 'Worded credentials are not supported on this application.';

    /**
     * Create a new worded type instance.
     */
    public function __construct()
    {
        parent::__construct(name: 'worded', surfaces: ['sign-in', 'enrollment'], replacesExisting: true);
    }

    /**
     * Get the status shown once a worded credential replaced the one held.
     */
    public function replacedStatus(): Status
    {
        return Status::OTHER_SESSIONS_REVOKED;
    }

    /**
     * Get the status shown once a worded credential was removed.
     */
    public function removedStatus(): Status
    {
        return Status::SESSION_REVOKED;
    }

    /**
     * Get why the type can't be enrolled: it isn't listed on sign-in.
     */
    public function enrollmentRefusal(CredentialTypes $types): ?string
    {
        return $types->find($this->name(), Surface::SIGN_IN) === null ? self::REFUSAL : null;
    }
}
