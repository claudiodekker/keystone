<?php

namespace ClaudioDekker\Keystone;

use Carbon\CarbonImmutable;

/**
 * @internal
 */
readonly class RememberToken
{
    /**
     * Create a new remember token instance.
     */
    public function __construct(
        public int $id,
        public int|string $accountId,
        protected int $epoch,
        protected CarbonImmutable $expiresAt,
    ) {
        //
    }

    /**
     * Determine if the token is unexpired and was issued on the credential epoch.
     */
    public function isLiveOn(int $epoch): bool
    {
        return $this->epoch === $epoch && $this->expiresAt->isFuture();
    }
}
