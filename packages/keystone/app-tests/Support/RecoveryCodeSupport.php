<?php

namespace ClaudioDekker\Keystone\AppTests\Support;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserRecoveryCode;
use ClaudioDekker\Keystone\RecoveryCodes\RecoveryCode;
use ClaudioDekker\Keystone\RecoveryCodes\RecoveryCodeType;
use PHPUnit\Framework\Assert;

/**
 * @api
 */
class RecoveryCodeSupport implements CredentialTypeSupport
{
    /**
     * The codes arranged for the user, as they were shown.
     *
     * @var list<string>
     */
    protected array $codes = [];

    /**
     * Get core's recovery code type.
     */
    public function type(): CredentialType
    {
        return app(RecoveryCodeType::class);
    }

    /**
     * Give the user a set of recovery codes, as a user who saved them holds.
     */
    public function arrangeCredential(Surface $surface, KeystoneUser $user): static
    {
        $codes = array_map(fn (RecoveryCode $code) => $code->format(), RecoveryCode::generateSet());

        return $this->arrangeCodes($user, $codes);
    }

    /**
     * Give the user the given recovery codes.
     *
     * @param  list<string>  $codes
     */
    public function arrangeCodes(KeystoneUser $user, array $codes): static
    {
        foreach ($codes as $code) {
            UserRecoveryCode::factory()
                ->for($user, 'user')
                ->code($code)
                ->create();
        }

        $this->codes = [...$this->codes, ...$codes];

        return $this;
    }

    /**
     * Give the user a recovery code Laravel Fortify generated, stored as an imported Fortify code is.
     */
    public function arrangeFortifyCode(KeystoneUser $user, string $code): static
    {
        UserRecoveryCode::factory()
            ->for($user, 'user')
            ->fortifyCode($code)
            ->create();

        $this->codes[] = $code;

        return $this;
    }

    /**
     * Get the fields that prove the first of the user's arranged codes, as it was shown.
     *
     * @return array<string, mixed>
     */
    public function validProof(Surface $surface, KeystoneUser $user): array
    {
        return ['recovery_code' => $this->code(0)];
    }

    /**
     * Get a code of the same shape that no account holds.
     *
     * @return array<string, mixed>
     */
    public function rejectedProof(Surface $surface): array
    {
        return ['recovery_code' => RecoveryCode::generate()->format()];
    }

    /**
     * Get the arranged code at the position, as it was shown.
     */
    public function code(int $position): string
    {
        return $this->codes[$position] ?? Assert::fail("No recovery code was arranged at position [{$position}].");
    }
}
