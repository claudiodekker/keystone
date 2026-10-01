<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
class SignInDecision
{
    /**
     * The columns, besides the soft-delete column, that bar an account from signing in when set.
     *
     * @var list<string>
     */
    protected const array BARRING_COLUMNS = ['invalidated_at', 'suspended_at'];

    /**
     * Decide what an account proven by a credential of the type gets: refused, held for a challenge or an enrollment, or signed in.
     */
    public function demand(Model&KeystoneUser $account, CredentialType $proven): Demand
    {
        return match (true) {
            $this->isBarred($account) => Demand::REFUSE,
            ! $proven->representsMultipleFactors() && $this->holdsSecondFactor($account, $proven->name()) => Demand::CHALLENGE,
            $this->owesEnrollment($account, multiFactorProven: $proven->representsMultipleFactors()) => Demand::ENROLLMENT,
            default => Demand::SIGN_IN,
        };
    }

    /**
     * Decide what a pending sign-in still owes, as its account stands now: the challenge, an enrollment, or nothing.
     */
    public function next(PendingSignIn $pending): Demand
    {
        $account = $pending->account;
        $multiFactorProven = $this->provesMultipleFactors($pending->firstFactor);

        return match (true) {
            $this->isBarred($account) => Demand::REFUSE,
            ! $pending->secondFactorPassed && ! $multiFactorProven && $this->holdsSecondFactor($account, $pending->firstFactor) => Demand::CHALLENGE,
            $this->pendingOwesEnrollment($pending) => Demand::ENROLLMENT,
            default => Demand::SIGN_IN,
        };
    }

    /**
     * Determine if the pending sign-in's account must enroll before it gets in, given the first factor it passed.
     */
    public function pendingOwesEnrollment(PendingSignIn $pending): bool
    {
        return $this->owesEnrollment($pending->account, multiFactorProven: $this->provesMultipleFactors($pending->firstFactor));
    }

    /**
     * Determine if the pending sign-in's account must enroll a second factor before it gets in, given the first factor it passed.
     */
    public function pendingOwesSecondFactor(PendingSignIn $pending): bool
    {
        return ! $this->provesMultipleFactors($pending->firstFactor) && $this->owesSecondFactor($pending->account);
    }

    /**
     * Get the listed challenge types the account can answer with, leaving out the type of its first factor, then recovery codes while it holds one.
     *
     * @return list<CredentialType>
     */
    public function challengeOffer(Model&KeystoneUser $account, ?string $firstFactor): array
    {
        $held = (new Credentials($account))->typesOf($account->getKey());
        $types = app(CredentialTypes::class)->serving(Surface::CHALLENGE);
        $remainingCodes = (new RecoveryCodes($account))->remaining($account->getKey());

        $offered = array_filter($types, fn (CredentialType $type) => $type->name() !== $firstFactor && in_array($type->name(), $held, true));

        return $remainingCodes > 0 ? [...array_values($offered), new RecoveryCodeType] : array_values($offered);
    }

    /**
     * Get the listed types an account can enroll to hold a second factor: those serving enrollment that answer a challenge or prove two factors on their own.
     *
     * @return list<CredentialType>
     */
    public function enrollmentOffer(CredentialTypes $types): array
    {
        $enrollable = array_filter(
            $types->serving(Surface::ENROLLMENT),
            fn (CredentialType $type) => $type->representsMultipleFactors() || $types->find($type->name(), Surface::CHALLENGE) !== null,
        );

        return array_values($enrollable);
    }

    /**
     * Determine if some listed type lets an account without a second factor enroll one.
     */
    public function mandateSatisfiable(CredentialTypes $types): bool
    {
        return $this->enrollmentOffer($types) !== [];
    }

    /**
     * Determine if the account holds a second factor of another type than the first factor's.
     */
    public function holdsSecondFactor(Model&KeystoneUser $account, ?string $firstFactor): bool
    {
        return (new Credentials($account))->holdsSecondFactor($account->getKey(), $firstFactor);
    }

    /**
     * Determine if the account, as read from its row, must enroll before it gets in: a second factor, unless one proven factor counts as two, or recovery codes.
     */
    public function owesEnrollment(Model&KeystoneUser $account, bool $multiFactorProven = false): bool
    {
        return (! $multiFactorProven && $this->owesSecondFactor($account)) || $this->owesRecoveryCodes($account);
    }

    /**
     * Determine if the account, as read from its row, must hold a second factor it doesn't hold.
     */
    public function owesSecondFactor(Model&KeystoneUser $account): bool
    {
        return config('keystone.require_second_factor') === true && ! (bool) $account->getRawOriginal('has_second_factor');
    }

    /**
     * Determine if the account, as read from its row, must hold recovery codes it doesn't hold.
     */
    public function owesRecoveryCodes(Model&KeystoneUser $account): bool
    {
        return config('keystone.require_recovery_codes') === true && ! (bool) $account->getRawOriginal('has_recovery_codes');
    }

    /**
     * Determine if the account, as read from its row, is deleted, invalidated or suspended.
     */
    public function isBarred(Model&KeystoneUser $account): bool
    {
        $columns = [...self::BARRING_COLUMNS, $account->getDeletedAtColumn()];

        foreach ($columns as $column) {
            if (! is_null($account->getRawOriginal($column))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a proof of the named type counts as two factors on its own.
     */
    protected function provesMultipleFactors(?string $firstFactor): bool
    {
        if ($firstFactor === null) {
            return false;
        }

        return app(CredentialTypes::class)->registered($firstFactor)?->representsMultipleFactors() ?? false;
    }
}
