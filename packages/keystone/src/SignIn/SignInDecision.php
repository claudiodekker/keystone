<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Accounts\AccountLookup;
use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Accounts\Holdings;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Session\PendingSignIn;
use ClaudioDekker\Keystone\Session\Stage;
use Illuminate\Contracts\Config\Repository as Config;

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
        protected CredentialTypes $types,
        protected Config $config,
    ) {
        //
    }

    /**
     * Determine if the proof proves a credential of the type that the account holds, read again from its row; a rejection names none.
     */
    public function proves(Proof $proof, CredentialType $type, KeystoneUser $account): bool
    {
        return UserCredential::query()
            ->whereKey($proof->credential)
            ->where('type', $type->name())
            ->where('user_id', $account->getAuthIdentifier())
            ->exists();
    }

    /**
     * Get the stage a sign-in by the proven first factor is held at, or null when it owes nothing.
     */
    public function owed(KeystoneUser $account, CredentialType $firstFactor): ?Stage
    {
        if (! $firstFactor->isMultiFactor() && $this->isOwed($account, Stage::CHALLENGE)) {
            return Stage::CHALLENGE;
        }

        if ($this->isOwed($account, Stage::ENROLLMENT)) {
            return Stage::ENROLLMENT;
        }

        return null;
    }

    /**
     * Determine if the account must enroll a second factor before it signs in, as it stands now.
     */
    public function owesSecondFactor(KeystoneUser $account): bool
    {
        return $this->config->boolean('keystone.require_second_factor')
            && ! $this->holdings->holdsSecondFactor($account);
    }

    /**
     * Determine if the account must save recovery codes before it signs in, as it stands now.
     */
    public function owesRecoveryCodes(KeystoneUser $account): bool
    {
        return $this->config->boolean('keystone.require_recovery_codes')
            && ! $this->holdings->holdsRecoveryCodes($account);
    }

    /**
     * Get the types an account can enroll as its second factor: those offered on enrollment that also answer the challenge.
     *
     * @return list<CredentialType>
     */
    public function enrollable(): array
    {
        $answering = $this->types->serving(Surface::CHALLENGE);

        return array_values(array_filter(
            $this->types->serving(Surface::ENROLLMENT),
            fn (CredentialType $type) => in_array($type, $answering, true),
        ));
    }

    /**
     * Get the account the pending sign-in still holds at its stage, or why the hold ends.
     *
     * A hold ends voided when its account's credential epoch moved or the account was barred since the hold, and unneeded when the account no longer owes the stage.
     * A hold at enrollment also ends once its account holds a second factor the pending sign-in never proved, so the next sign-in answers the challenge for it. A first factor that proves two factors on its own proved it.
     */
    public function resume(PendingSignIn $pending): KeystoneUser|HoldEnded
    {
        $account = $this->accounts->find($pending->account);

        if ($account === null || CredentialEpoch::of($pending->account) !== $pending->epoch) {
            return HoldEnded::VOIDED;
        }

        $isOwed = match ($pending->stage) {
            Stage::CHALLENGE => $this->isOwed($account, Stage::CHALLENGE),
            Stage::ENROLLMENT => $this->owesEnrollment($pending, $account),
        };

        if (! $isOwed) {
            return HoldEnded::UNNEEDED;
        }

        return $account;
    }

    /**
     * Determine if the proof answers the pending sign-in's challenge: a credential the held account holds, of another type than the first factor's.
     */
    public function answers(Proof $proof, CredentialType $type, PendingSignIn $pending, KeystoneUser $account): bool
    {
        return $type->name() !== $pending->firstFactor && $this->proves($proof, $type, $account);
    }

    /**
     * Determine if the pending sign-in, resumed at enrollment, enrolls its second factor next rather than its recovery codes.
     *
     * Resuming dropped every hold whose account holds a second factor the pending sign-in didn't prove, so one that proved none holds none. A first factor that proved two factors on its own is never dropped for it, so its account's holding is read.
     */
    public function enrollsSecondFactor(PendingSignIn $pending, KeystoneUser $account): bool
    {
        if (! $this->config->boolean('keystone.require_second_factor')) {
            return false;
        }

        if ($this->provedTwoFactors($pending)) {
            return ! $this->holdings->holdsSecondFactor($account);
        }

        return $pending->secondFactor === null;
    }

    /**
     * Determine if the pending sign-in still owes enrollment, reading each of its account's holdings once: never once its account holds a second factor the pending sign-in didn't prove.
     */
    protected function owesEnrollment(PendingSignIn $pending, KeystoneUser $account): bool
    {
        $holdsSecondFactor = $this->holdings->holdsSecondFactor($account);

        if ($holdsSecondFactor
            && $pending->secondFactor === null
            && ! $this->provedTwoFactors($pending)
        ) {
            return false;
        }

        if ($this->config->boolean('keystone.require_second_factor') && ! $holdsSecondFactor) {
            return true;
        }

        return $this->owesRecoveryCodes($account);
    }

    /**
     * Determine if the pending sign-in's first factor proved two factors on its own, so it was never owed a challenge.
     */
    protected function provedTwoFactors(PendingSignIn $pending): bool
    {
        return $this->types->find($pending->firstFactor, Surface::SIGN_IN)?->isMultiFactor() === true;
    }

    /**
     * Determine if the account owes the stage as it stands now.
     */
    public function isOwed(KeystoneUser $account, Stage $stage): bool
    {
        return match ($stage) {
            Stage::CHALLENGE => $this->holdings->holdsSecondFactor($account),
            Stage::ENROLLMENT => $this->owesSecondFactor($account) || $this->owesRecoveryCodes($account),
        };
    }
}
