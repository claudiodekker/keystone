<?php

namespace ClaudioDekker\Keystone\AppTests\Support;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;

/**
 * Let AppTests drive a credential type on a surface it serves: arrange a credential, and give a valid or a rejected proof.
 *
 * @internal
 */
interface CredentialTypeSupport
{
    /**
     * Get the type this supports.
     */
    public function type(): CredentialType;

    /**
     * Give the user a credential of the type that answers on the surface.
     */
    public function arrangeCredential(Surface $surface, KeystoneUser $user): void;

    /**
     * Get the fields that prove the user's arranged credential on the surface.
     *
     * @return array<string, mixed>
     */
    public function validProof(Surface $surface, KeystoneUser $user): array;

    /**
     * Get fields of the same shape that the type rejects.
     *
     * @return array<string, mixed>
     */
    public function rejectedProof(Surface $surface): array;
}
