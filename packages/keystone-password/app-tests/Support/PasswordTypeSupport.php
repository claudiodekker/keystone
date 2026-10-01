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

    public function type(): string
    {
        return 'password';
    }

    public function arrange(Surface $surface): array
    {
        return ['identifier' => null, 'secret' => Hash::make(self::PASSWORD), 'label' => null];
    }

    public function validProof(Surface $surface): array
    {
        return [PasswordType::FIELD => self::PASSWORD];
    }

    public function rejectedProof(Surface $surface): array
    {
        return [PasswordType::FIELD => 'wrong '.self::PASSWORD];
    }

    public function validEnrollment(mixed $ceremony): array
    {
        return [PasswordType::FIELD => self::PASSWORD, PasswordType::FIELD.'_confirmation' => self::PASSWORD];
    }

    public function rejectedEnrollment(mixed $ceremony): array
    {
        return [PasswordType::FIELD => self::PASSWORD, PasswordType::FIELD.'_confirmation' => 'wrong '.self::PASSWORD];
    }
}
