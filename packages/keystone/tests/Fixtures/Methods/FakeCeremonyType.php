<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Methods;

use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\StartsCeremony;
use ClaudioDekker\Keystone\Methods\Surface;

class FakeCeremonyType extends FakeChallengeType implements StartsCeremony
{
    /**
     * The number of ceremonies the type started.
     */
    protected int $starts = 0;

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
     * Start a ceremony that keeps its own number and surface.
     *
     * @return array<string, mixed>
     */
    public function start(Surface $surface): array
    {
        $this->starts++;

        return [
            'challenge' => 'ceremony '.$this->starts,
            'surface' => $surface->value,
        ];
    }
}
