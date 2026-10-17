<?php

namespace ClaudioDekker\Keystone\Password;

use ClaudioDekker\Keystone\AppTests\Support\UseTestSupport;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Methods\Verification;
use ClaudioDekker\Keystone\Password\AppTests\PasswordSupport;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Hash;

/**
 * A password, hashed with the app's own hasher.
 *
 * @internal
 */
#[UseTestSupport(PasswordSupport::class)]
class PasswordType implements CredentialType
{
    /**
     * Create the type, reading the app's hashing config when it verifies, so a change at runtime takes effect.
     */
    public function __construct(protected Repository $config)
    {
        //
    }

    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return 'password';
    }

    /**
     * Get the form shape on every surface but the challenge.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        if ($surface === Surface::CHALLENGE) {
            return null;
        }

        return InitiateShape::FORM;
    }

    /**
     * Determine if a proof proves two factors on its own, which a password never does.
     */
    public function isMultiFactorOnItsOwn(): bool
    {
        return false;
    }

    /**
     * Get the rules for the password.
     *
     * @return array<string, mixed>
     */
    public function rules(Surface $surface): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Prove the credential whose hash the password matches.
     */
    public function verify(Verification $verification): Proof
    {
        $password = (string) $verification->input['password'];

        foreach ($verification->credentials as $credential) {
            if (Hash::check($password, (string) $credential->secret)) {
                return Proof::proven($credential);
            }
        }

        return Proof::rejected();
    }
}
