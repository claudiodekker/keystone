<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Methods;

/**
 * A second form type with its own name and secret, so the contract AppTests run over more than one installed type.
 */
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
