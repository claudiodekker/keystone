<?php

namespace ClaudioDekker\Keystone\SignIn;

use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\RateLimits\FailedAttempt;
use Illuminate\Database\Eloquent\Collection;

/**
 * @internal
 */
readonly class Answer
{
    /**
     * Create the answer a type gave on the surface.
     *
     * @param  Collection<int, UserCredential>  $credentials  the credentials the type checked the input against
     * @param  FailedAttempt  $attempt  the failed attempt counted before the type checked anything
     */
    public function __construct(
        public CredentialType $type,
        public Surface $surface,
        public Proof $proof,
        public Collection $credentials,
        public FailedAttempt $attempt,
    ) {
        //
    }

    /**
     * Get the credential the proof names, when it is one the type was given.
     */
    public function credential(): ?UserCredential
    {
        return $this->credentials->find($this->proof->credential);
    }
}
