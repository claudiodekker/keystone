<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
readonly class StagedRecoveryCodes
{
    /**
     * Create a new staged recovery codes instance.
     *
     * @param  list<string>  $codes
     */
    public function __construct(
        #[\SensitiveParameter] public array $codes,
        public int $epoch,
    ) {
        //
    }

    /**
     * Rebuild the staged set from what its slot kept.
     *
     * @param  array<array-key, mixed>  $kept
     */
    public static function fromSlot(#[\SensitiveParameter] array $kept): self
    {
        /** @var array{codes: array<array-key, mixed>, epoch: mixed} $kept */
        return new self(array_values(array_map(strval(...), $kept['codes'])), (int) $kept['epoch']);
    }

    /**
     * Get what the slot keeps for the set.
     *
     * @return array{codes: list<string>, epoch: int}
     */
    public function toSlot(): array
    {
        return ['codes' => $this->codes, 'epoch' => $this->epoch];
    }
}
