<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
class CredentialTypeRegistry
{
    /**
     * The registered credential types, by name.
     *
     * @var array<string, CredentialType>
     */
    protected array $types = [];

    /**
     * Register a credential type, as a plain instance from a method package's boot().
     */
    public function register(CredentialType $type): void
    {
        $this->types[$type->name()] = $type;
    }

    /**
     * Get the registered credential types, by name.
     *
     * @return array<string, CredentialType>
     */
    public function types(): array
    {
        return $this->types;
    }
}
