<?php

namespace ClaudioDekker\Keystone\Methods;

use Closure;
use SensitiveParameter;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class Proof
{
    /**
     * Create the proof.
     *
     * @param  (Closure(): string)|null  $rehash
     */
    protected function __construct(
        public int|string|null $credential,
        protected ?Closure $rehash = null,
        #[SensitiveParameter] protected ?string $advancedSecret = null,
    ) {
        //
    }

    /**
     * Create a proof of one of the credentials the type was given, with the work that rehashes its secret when it is out of date.
     *
     * Core runs the rehash only once the proof signed the account in, so a refused proof never pays for it.
     *
     * @param  (Closure(): string)|null  $rehash
     */
    public static function proven(Credential $credential, ?Closure $rehash = null): static
    {
        return new static($credential->id, $rehash);
    }

    /**
     * Create a proof of one of the credentials the type was given, which holds only once its secret is advanced to the given one, such as a TOTP step that must not be accepted twice.
     */
    public static function advanced(Credential $credential, #[SensitiveParameter] string $secret): static
    {
        return new static($credential->id, advancedSecret: $secret);
    }

    /**
     * Create a rejection.
     */
    public static function rejected(): static
    {
        return new static(null);
    }

    /**
     * Get the secret the proven credential must be advanced to before the proof signs anyone in, or null when it needn't be.
     */
    public function advancedSecret(): ?string
    {
        return $this->advancedSecret;
    }

    /**
     * Get the proven credential's new secret, or null when the proof carries no rehash.
     */
    public function rehashed(): ?string
    {
        if ($this->rehash === null) {
            return null;
        }

        return value($this->rehash);
    }
}
