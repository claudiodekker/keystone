<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
class CredentialTypeRegistry
{
    /**
     * Every type registered under each name, in the order the packages registered them.
     *
     * @var array<string, list<CredentialType>>
     */
    protected array $registrations = [];

    /**
     * Register a credential type, as a plain instance from a method package's boot().
     */
    public function register(CredentialType $type): void
    {
        $this->registrations[$type->name()][] = $type;
    }

    /**
     * Get the first type registered under each name.
     *
     * @return array<string, CredentialType>
     */
    public function types(): array
    {
        return array_map(fn (array $types) => $types[0], $this->registrations);
    }
}
