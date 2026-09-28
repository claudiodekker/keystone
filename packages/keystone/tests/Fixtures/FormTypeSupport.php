<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Methods\Surface;

class FormTypeSupport implements CredentialTypeSupport
{
    /**
     * The secret the arranged credential holds.
     */
    protected const string SECRET = 'correct horse battery staple';

    public function type(): string
    {
        return 'form';
    }

    public function arrange(Surface $surface): array
    {
        return ['identifier' => null, 'secret' => FormType::hash(self::SECRET), 'label' => null];
    }

    public function validProof(Surface $surface): array
    {
        return ['secret' => self::SECRET];
    }

    public function rejectedProof(Surface $surface): array
    {
        return ['secret' => 'wrong '.self::SECRET];
    }
}
