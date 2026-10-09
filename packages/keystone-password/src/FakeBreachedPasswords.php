<?php

namespace ClaudioDekker\Keystone\Password;

/**
 * @api
 */
class FakeBreachedPasswords implements BreachedPasswords
{
    /**
     * The passwords asked about, in order.
     *
     * @var list<string>
     */
    public array $asked = [];

    /**
     * Create a new fake breached passwords instance.
     *
     * @param  list<string>  $breached
     */
    public function __construct(
        protected array $breached = [],
    ) {
        //
    }

    /**
     * Determine if the password is one the fake was given, remembering that it was asked.
     */
    public function isBreached(#[\SensitiveParameter] string $password): bool
    {
        $this->asked[] = $password;

        return in_array($password, $this->breached, true);
    }
}
