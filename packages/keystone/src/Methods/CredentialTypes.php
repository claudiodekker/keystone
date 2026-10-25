<?php

namespace ClaudioDekker\Keystone\Methods;

/**
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
     * Find the type with the given name, when it serves the surface.
     */
    public function find(string $name, Surface $surface): ?CredentialType
    {
        $type = $this->types[$name] ?? null;

        if ($type === null || ! $this->serves($type, $surface)) {
            return null;
        }

        return $type;
    }

    /**
     * Get the name of every registered type.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->types);
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
            fn (CredentialType $type) => $this->serves($type, $surface),
        ));
    }

    /**
     * Get the name and initiate shape of each type that serves the surface, as a page lists them.
     *
     * @return array<int, array{name: string, shape: string}>
     */
    public function shapes(Surface $surface): array
    {
        return collect($this->types)
            ->map(fn (CredentialType $type) => $type->initiateShape($surface))
            ->filter()
            ->map(fn (InitiateShape $shape, string $name) => ['name' => $name, 'shape' => $shape->value])
            ->values()
            ->all();
    }

    /**
     * Determine if the type serves the surface.
     */
    protected function serves(CredentialType $type, Surface $surface): bool
    {
        return $type->initiateShape($surface) !== null;
    }
}
