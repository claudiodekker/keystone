<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Methods\Surface;

class FormTypeSupport implements CredentialTypeSupport
{
    /**
     * The secret the arranged credential holds.
     */
    protected const string SECRET = 'correct horse battery staple';

    /**
     * Create a new form type support instance.
     */
    public function __construct(
        protected string $type = 'form',
    ) {
        //
    }

    /**
     * Get the name of the form type.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Arrange a form credential holding the known secret.
     */
    public function arrange(Surface $surface): array
    {
        return ['identifier' => null, 'secret' => FormType::hash(self::SECRET), 'label' => null];
    }

    /**
     * Get the known secret.
     */
    public function validProof(Surface $surface): array
    {
        return ['secret' => self::SECRET];
    }

    /**
     * Get a secret the arranged credential rejects.
     */
    public function rejectedProof(Surface $surface): array
    {
        return ['secret' => 'wrong '.self::SECRET];
    }

    /**
     * Get the secret the ceremony made.
     */
    public function validEnrollment(mixed $ceremony): array
    {
        return ['secret' => (string) $ceremony];
    }

    /**
     * Get a secret the ceremony didn't make.
     */
    public function rejectedEnrollment(mixed $ceremony): array
    {
        return ['secret' => 'wrong '.$ceremony];
    }
}
