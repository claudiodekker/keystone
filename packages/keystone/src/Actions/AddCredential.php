<?php

namespace ClaudioDekker\Keystone\Actions;

use ClaudioDekker\Keystone\AccountWrite;
use ClaudioDekker\Keystone\Actions\Concerns\ChangesAccounts;
use ClaudioDekker\Keystone\Credentials;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class AddCredential
{
    use ChangesAccounts;

    /**
     * Store a credential of the type on the account, returning its id.
     */
    public function handle(
        Model&KeystoneUser $account,
        CredentialType $type,
        ?string $identifier,
        #[\SensitiveParameter] ?string $secret,
        ?string $label = null,
    ): int {
        return $this->changeAccount($account, function (AccountWrite $write) use ($type, $identifier, $secret, $label) {
            $credentials = new Credentials($write->account);

            return $credentials->store($write->account, $type, identifier: $identifier, secret: $secret, label: $label);
        });
    }
}
