<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Methods;

use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\StartsCeremony;
use ClaudioDekker\Keystone\Methods\Surface;
use RuntimeException;

class FakeCeremonyType extends FakeChallengeType implements StartsCeremony
{
    /**
     * The number of ceremonies the type started.
     */
    protected int $starts = 0;

    /**
     * Whether the next start throws.
     */
    protected bool $failsNextStart = false;

    /**
     * Get the client ceremony shape on a challenge, and nothing on every other surface.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        if ($surface !== Surface::CHALLENGE) {
            return null;
        }

        return InitiateShape::CLIENT_CEREMONY;
    }

    /**
     * Start a ceremony that keeps the type's name, its number and its surface, or throw when told to fail.
     *
     * @return array<string, mixed>
     */
    public function start(Surface $surface): array
    {
        $this->starts++;

        if ($this->failsNextStart) {
            $this->failsNextStart = false;

            throw new RuntimeException('The ceremony could not start.');
        }

        return [
            'challenge' => $this->name().' ceremony '.$this->starts,
            'surface' => $surface->value,
        ];
    }

    /**
     * Show what the ceremony kept, on its surface, for the account.
     *
     * @param  array<string, mixed>  $ceremony
     * @return array<string, mixed>
     */
    public function page(Surface $surface, array $ceremony, string $account): array
    {
        return [
            'shown' => $ceremony['challenge'].' on '.$surface->value,
            'account' => $account,
        ];
    }

    /**
     * Make the next start throw, as a type whose provider is down would.
     */
    public function failNextStart(): void
    {
        $this->failsNextStart = true;
    }
}
