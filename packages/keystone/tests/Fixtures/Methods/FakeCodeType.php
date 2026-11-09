<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Methods;

use ClaudioDekker\Keystone\Methods\OneTimeCode;

class FakeCodeType extends FakeFormType implements OneTimeCode
{
    /**
     * The secret every arranged credential holds.
     */
    protected const string SECRET = '613302';

    /**
     * The secret compared in place of a credential when there is none.
     */
    protected const string DECOY = '000000';

    /**
     * Get the type's name.
     */
    public function name(): string
    {
        return 'code';
    }
}
