<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\RefusesEnrollment;
use ClaudioDekker\Keystone\Methods\Surface;

/**
 * A replacing form type that can't be enrolled unless it is listed on sign-in.
 */
class WordedType extends FormType implements RefusesEnrollment
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
     * Get why the type can't be enrolled: it isn't listed on sign-in.
     */
    public function enrollmentRefusal(CredentialTypes $types): ?string
    {
        return $types->find($this->name(), Surface::SIGN_IN) === null ? self::REFUSAL : null;
    }
}
