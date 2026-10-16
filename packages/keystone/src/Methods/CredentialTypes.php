<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * The registered credential types.
 *
 * @internal
 */
readonly class CredentialTypes
{
    /**
     * Create the set from the registered types, by name.
     *
     * @param  array<string, CredentialType>  $types
     */
    public function __construct(protected array $types)
    {
        //
    }

    /**
     * Find the type with the given name.
     */
    public function find(string $name): CredentialType
    {
        return $this->types[$name];
    }

    /**
     * Get the types that serve the surface.
     *
     * @return list<CredentialType>
     */
    public function serving(Surface $surface): array
    {
        return array_values(array_filter(
            $this->types,
            fn (CredentialType $type) => $type->initiateShape($surface) !== null,
        ));
    }
}
