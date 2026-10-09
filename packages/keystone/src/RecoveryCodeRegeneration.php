<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class RecoveryCodeRegeneration
{
    /**
     * The slot purpose.
     */
    protected const string PURPOSE = 'regeneration';

    /**
     * The longest a staged set lives.
     */
    protected const int CAP_SECONDS = 900;

    /**
     * Create a new recovery code regeneration instance.
     */
    public function __construct(
        protected KeystoneGuard $guard,
    ) {
        //
    }

    /**
     * Get the set staged for the account, staging a new one on the account's current epoch when none is live.
     */
    public function resolve(Model&KeystoneUser $account): StagedRecoveryCodes
    {
        $staged = $this->staged();

        if ($staged !== null) {
            return $staged;
        }

        $staged = new StagedRecoveryCodes(
            codes: (new RecoveryCodes($this->guard->userModel()))->generate(),
            epoch: (int) $account->getRawOriginal('credential_epoch'),
        );

        $this->guard->slots()->put(CredentialTypes::RECOVERY_CODE, self::PURPOSE, $staged->toSlot(), capSeconds: self::CAP_SECONDS);

        return $staged;
    }

    /**
     * Get the set staged for the session, if one is live.
     */
    public function staged(): ?StagedRecoveryCodes
    {
        $kept = $this->guard->slots()->get(CredentialTypes::RECOVERY_CODE, self::PURPOSE);

        return is_array($kept) ? StagedRecoveryCodes::fromSlot($kept) : null;
    }

    /**
     * Discard the staged set, leaving the account's stored codes alone.
     */
    public function close(): void
    {
        $this->guard->slots()->forget(CredentialTypes::RECOVERY_CODE, self::PURPOSE);
    }
}
