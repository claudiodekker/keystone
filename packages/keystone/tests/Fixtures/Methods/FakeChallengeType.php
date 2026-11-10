<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Methods;

use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Surface;

class FakeChallengeType extends FakeFormType
{
    /**
     * The secret every arranged credential holds.
     */
    protected const string SECRET = 'second horse';

    /**
     * The secret compared in place of a credential when there is none.
     */
    protected const string DECOY = 'decoy second horse';

    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return 'challenge-only';
    }

    /**
     * Get the form shape on a challenge, and nothing on every other surface.
     */
    public function initiateShape(Surface $surface): ?InitiateShape
    {
        if ($surface !== Surface::CHALLENGE) {
            return null;
        }

        return InitiateShape::FORM;
    }
}
