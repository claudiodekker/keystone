<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class SecurityPage
{
    /**
     * Create a new security page value.
     *
     * @param  list<array{
     *     type: string,
     *     enrollable: bool,
     *     credentials: list<array{id: int, label: ?string, addedAt: ?string, lastUsedAt: ?string, disabled: bool}>,
     * }>  $types  every listed type, with none held included, and whether the user can set one up from here
     * @param  list<array{id: int, type: string, label: ?string, addedAt: ?string, lastUsedAt: ?string, disabled: bool}>  $leftovers  credentials of types no longer listed, which count as no factor
     * @param  ?string  $sudoEndsAt  ISO 8601, or null when the session holds no sudo that would pass from here
     */
    public function __construct(
        public array $types,
        public array $leftovers,
        public int $recoveryCodes,
        public bool $recoveryCodesLow,
        public ?string $sudoEndsAt,
        public ?string $status,
    ) {
        //
    }
}
