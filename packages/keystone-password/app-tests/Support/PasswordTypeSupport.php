<?php

namespace ClaudioDekker\Keystone\Password\AppTests\Support;

use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\PasswordType;
use Illuminate\Support\Facades\Hash;

/**
 * @api
 */
class PasswordTypeSupport implements CredentialTypeSupport
{
    /**
     * The password the arranged credential holds.
     */
    protected const string PASSWORD = 'correct horse battery staple';

    /**
     * Get the name of the password type.
     */
    public function type(): string
    {
        return 'password';
    }

    /**
     * Arrange a password credential, hashed with the app's hasher.
     */
    public function arrange(Surface $surface): array
    {
        return ['identifier' => null, 'secret' => Hash::make(self::PASSWORD), 'label' => null];
    }

    /**
     * Get the arranged password.
     */
    public function validProof(Surface $surface): array
    {
        return [PasswordType::FIELD => self::PASSWORD];
    }

    /**
     * Get a password the arranged credential rejects.
     */
    public function rejectedProof(Surface $surface): array
    {
        return [PasswordType::FIELD => 'wrong '.self::PASSWORD];
    }

    /**
     * Get the password typed twice.
     */
    public function validEnrollment(mixed $ceremony): array
    {
        return [PasswordType::FIELD => self::PASSWORD, PasswordType::FIELD.'_confirmation' => self::PASSWORD];
    }

    /**
     * Get the password typed with a confirmation that differs.
     */
    public function rejectedEnrollment(mixed $ceremony): array
    {
        return [PasswordType::FIELD => self::PASSWORD, PasswordType::FIELD.'_confirmation' => 'wrong '.self::PASSWORD];
    }

    /**
     * Get the enrolled password.
     */
    public function validProofOfEnrolled(mixed $ceremony): array
    {
        return [PasswordType::FIELD => self::PASSWORD];
    }
}
