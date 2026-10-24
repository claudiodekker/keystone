<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Methods;

class FakePinType extends FakeFormType
{
    /**
     * The secret every arranged credential holds.
     */
    protected const string SECRET = '4921';

    /**
     * The secret compared in place of a credential when there is none.
     */
    protected const string DECOY = '0000';

    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return 'pin';
    }
}
