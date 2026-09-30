<?php

namespace ClaudioDekker\Keystone\Actions;

use ClaudioDekker\Keystone\AccountWrite;
use ClaudioDekker\Keystone\Actions\Concerns\ChangesAccounts;
use ClaudioDekker\Keystone\Credentials;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class RehashCredential
{
    use ChangesAccounts;

    /**
     * Replace the credential's secret with a rehash of the same secret, only while it still holds the one that was verified.
     */
    public function handle(Model&KeystoneUser $account, StoredCredential $credential, string $type, #[\SensitiveParameter] string $secret): bool
    {
        return $this->changeAccount($account, function (AccountWrite $write) use ($credential, $type, $secret) {
            $credentials = new Credentials($write->account);

            return $credentials->replaceSecret($credential, type: $type, secret: $secret);
        });
    }
}
