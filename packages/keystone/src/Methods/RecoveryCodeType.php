<?php

namespace ClaudioDekker\Keystone\Methods;

use LogicException;

/**
 * @internal
 */
class RecoveryCodeType implements CredentialType
{
    /**
     * The input field holding the typed code.
     */
    public const string FIELD = 'code';

    /**
     * The most characters a typed code may hold, spaces and dashes included.
     */
    public const int MAX_TYPED_LENGTH = 64;

    /**
     * Get the name core keeps for recovery codes.
     */
    public function name(): string
    {
        return CredentialTypes::RECOVERY_CODE;
    }

    /**
     * Get the form the challenge shows, the only surface a recovery code answers on.
     */
    public function surfaces(): array
    {
        return [
            Surface::CHALLENGE->value => InitiateShape::FORM,
        ];
    }

    /**
     * Determine if a recovery code counts as multiple factors, which it never does.
     */
    public function representsMultipleFactors(): bool
    {
        return false;
    }

    /**
     * Determine if recovery code failures share one count across flows, which they don't: a code is far too long to guess.
     */
    public function sharesFailedAttempts(): bool
    {
        return false;
    }

    /**
     * Get what is wrong with the type's configuration, which is nothing: it has none.
     */
    public function configFailures(): array
    {
        return [];
    }

    /**
     * Get the rules for the typed code.
     */
    public function rules(Surface $surface): array
    {
        return [self::FIELD => ['required', 'string', 'max:'.self::MAX_TYPED_LENGTH]];
    }

    /**
     * Refuse to verify through the method contract: core spends a recovery code under the account's lock instead.
     */
    public function verify(Surface $surface, array $input, array $credentials): Proof
    {
        throw new LogicException('Recovery codes are spent by core, never verified through the method contract.');
    }
}
