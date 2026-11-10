<?php

namespace ClaudioDekker\Keystone\Accounts;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;

/**
 * @internal
 */
class Holdings
{
    /**
     * Create the holdings over the offered credential types.
     */
    public function __construct(protected CredentialTypes $types)
    {
        //
    }

    /**
     * Get the names of the second-factor types the account holds a credential of, in the order the types were registered.
     *
     * @return list<string>
     */
    public function secondFactors(KeystoneUser $account): array
    {
        $offered = array_map(fn (CredentialType $type) => $type->name(), $this->types->serving(Surface::CHALLENGE));

        $held = UserCredential::query()
            ->where('user_id', $account->getAuthIdentifier())
            ->whereIn('type', $offered)
            ->distinct()
            ->pluck('type')
            ->all();

        return array_values(array_intersect($offered, $held));
    }

    /**
     * Determine if the account holds a second factor.
     */
    public function holdsSecondFactor(KeystoneUser $account): bool
    {
        return $this->secondFactors($account) !== [];
    }
}
