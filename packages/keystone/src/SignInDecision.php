<?php

namespace ClaudioDekker\Keystone;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
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
     * Decide what an account proven by a credential of the type gets: refused, held for a challenge, or signed in.
     */
    public function demand(Model&KeystoneUser $account, CredentialType $proven): Demand
    {
        return match (true) {
            $this->isBarred($account) => Demand::REFUSE,
            ! $proven->representsMultipleFactors() && $this->holdsSecondFactor($account, $proven->name()) => Demand::CHALLENGE,
            default => Demand::SIGN_IN,
        };
    }

    /**
     * Get the listed challenge types the account can answer with, leaving out the type of its first factor.
     *
     * @return list<CredentialType>
     */
    public function challengeOffer(Model&KeystoneUser $account, string $firstFactor): array
    {
        $held = (new Credentials($account))->typesOf($account->getKey());
        $types = app(CredentialTypes::class)->serving(Surface::CHALLENGE);

        $offered = array_filter($types, fn (CredentialType $type) => $type->name() !== $firstFactor && in_array($type->name(), $held, true));

        return array_values($offered);
    }

    /**
     * Determine if the account holds a second factor besides the first factor's type, counting stamped credentials of types keystone.methods no longer lists.
     */
    public function holdsSecondFactor(Model&KeystoneUser $account, string $firstFactor): bool
    {
        return (new Credentials($account))->holdsSecondFactor($account->getKey(), $firstFactor);
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
}
