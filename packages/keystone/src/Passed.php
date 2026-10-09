<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 *
 * @template-covariant TOutcome of Demand|SudoResult
 */
readonly class Passed
{
    /**
     * Create a new passed instance.
     *
     * @param  TOutcome  $outcome  what the pass leads the session to
     * @param  SecurityEventType|null  $event  the event the pass records, or null for a pass that earns no row
     * @param  bool|null  $knownDevice  whether a signed-in browser was a known device of its account
     * @param  bool  $alert  whether the event alerts the account's owner as its type does, which the sign-in ending a registration never does
     */
    public function __construct(
        public Demand|SudoResult $outcome,
        public ?SecurityEventType $event = null,
        public ?string $reason = null,
        public ?bool $knownDevice = null,
        public bool $alert = true,
    ) {
        //
    }
}
