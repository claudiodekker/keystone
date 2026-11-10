<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Accounts\Holdings;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Session\PendingSignIn;
use ClaudioDekker\Keystone\Session\Stage;

/**
 * @internal
 */
class SignInDecision
{
    /**
     * Create the decision.
     */
    public function __construct(
        protected AccountLookup $accounts,
        protected Holdings $holdings,
    ) {
        //
    }

    /**
     * Get the account the proof names, or null when it names nobody the subject could sign in as.
     */
    public function decide(Proof $proof, CredentialType $type, ?KeystoneUser $subject): ?KeystoneUser
    {
        if ($subject === null || ! $this->holds($subject, $proof, $type)) {
            return null;
        }

        return $subject;
    }

    /**
     * Get the stage a sign-in by the proven first factor is held at, or null when it owes nothing.
     */
    public function owed(KeystoneUser $account, CredentialType $firstFactor): ?Stage
    {
        if ($firstFactor->isMultiFactor()) {
            return null;
        }

        if (! $this->isOwed($account, Stage::CHALLENGE)) {
            return null;
        }

        return Stage::CHALLENGE;
    }

    /**
     * Get the account the pending sign-in still holds at its stage, or why the hold ends.
     *
     * A hold ends voided when its account's credential epoch moved or the account was barred since the hold, and unneeded when the account no longer owes the stage.
     */
    public function resume(PendingSignIn $pending): KeystoneUser|HoldEnded
    {
        $account = $this->accounts->find($pending->account);

        if ($account === null || CredentialEpoch::of($pending->account) !== $pending->epoch) {
            return HoldEnded::VOIDED;
        }

        if (! $this->isOwed($account, $pending->stage)) {
            return HoldEnded::UNNEEDED;
        }

        return $account;
    }

    /**
     * Get the account an answer to the pending sign-in's challenge proves, or null when it proves nobody: a credential of the first factor's type or of another account.
     */
    public function answer(Proof $proof, CredentialType $type, PendingSignIn $pending, KeystoneUser $account): ?KeystoneUser
    {
        if ($type->name() === $pending->firstFactor) {
            return null;
        }

        return $this->decide($proof, $type, $account);
    }

    /**
     * Determine if the account owes the stage as it stands now.
     */
    protected function isOwed(KeystoneUser $account, Stage $stage): bool
    {
        return match ($stage) {
            Stage::CHALLENGE => $this->holdings->holdsSecondFactor($account),
        };
    }

    /**
     * Determine if the account holds the proven credential of the type, read again from its row; a rejection names no credential, so it holds none.
     */
    protected function holds(KeystoneUser $account, Proof $proof, CredentialType $type): bool
    {
        return UserCredential::query()
            ->whereKey($proof->credential)
            ->where('type', $type->name())
            ->where('user_id', $account->getAuthIdentifier())
            ->exists();
    }
}
