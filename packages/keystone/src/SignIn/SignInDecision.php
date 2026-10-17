<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Models\UserCredential;

/**
 * Decide what a proof earns: core re-reads the proven credential, derives its owner itself, and decides.
 *
 * @internal
 */
class SignInDecision
{
    /**
     * Get the account the proof signs in, or null when it signs in nobody.
     */
    public function decide(Proof $proof, CredentialType $type, ?KeystoneUser $subject): ?KeystoneUser
    {
        if ($subject === null) {
            return null;
        }

        $owner = $this->owner($proof, $type);

        if ($owner?->getAuthIdentifier() !== $subject->getAuthIdentifier()) {
            return null;
        }

        return $owner;
    }

    /**
     * Re-read the proven credential of the type and get the account that holds it; a rejection names no credential, so it has none.
     */
    protected function owner(Proof $proof, CredentialType $type): ?KeystoneUser
    {
        $credential = UserCredential::query()
            ->whereKey($proof->credential)
            ->where('type', $type->name())
            ->first();

        return $credential?->user;
    }
}
