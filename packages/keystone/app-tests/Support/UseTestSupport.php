<?php

namespace ClaudioDekker\Keystone\AppTests\Support;

use Attribute;

/**
 * Name the test support of a credential type that ships in a method package, as UseFactory names a model's factory.
 *
 * @internal
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UseTestSupport
{
    /**
     * Create the attribute.
     *
     * @param  class-string<CredentialTypeSupport>  $class
     */
    public function __construct(public string $class)
    {
        //
    }

    /**
     * Make the support the attribute names.
     */
    public function support(): CredentialTypeSupport
    {
        return new $this->class;
    }
}
