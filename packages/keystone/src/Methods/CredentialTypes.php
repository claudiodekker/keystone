<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
readonly class CredentialTypes
{
    /**
     * Create the set from what the packages registered and the methods config allows.
     *
     * @param  array<string, CredentialType>  $types  by name
     */
    public function __construct(
        protected array $types,
        protected MethodAllowList $allowList,
    ) {
        //
    }

    /**
     * Find the type with the given name, when it is offered on the surface.
     */
    public function find(string $name, Surface $surface): ?CredentialType
    {
        if (! $this->offers($name, $surface)) {
            return null;
        }

        return $this->registered($name);
    }

    /**
     * Get the name of every type offered on some surface.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->types);

        return array_values(array_filter($names, $this->isOfferedAnywhere(...)));
    }

    /**
     * Get the types that are offered on the surface.
     *
     * @return list<CredentialType>
     */
    public function serving(Surface $surface): array
    {
        return array_values(array_filter(
            $this->types,
            fn (CredentialType $type, string $name) => $this->offers($name, $surface),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Get the name and initiate shape of each type that is offered on the surface, as a page lists them.
     *
     * @return array<int, array{name: string, shape: string}>
     */
    public function shapes(Surface $surface): array
    {
        return collect($this->types)
            ->filter(fn (CredentialType $type, string $name) => $this->offers($name, $surface))
            ->map(fn (CredentialType $type) => $type->initiateShape($surface))
            ->filter()
            ->map(fn (InitiateShape $shape, string $name) => ['name' => $name, 'shape' => $shape->value])
            ->values()
            ->all();
    }

    /**
     * Get the allow-list the set was built with.
     */
    public function allowList(): MethodAllowList
    {
        return $this->allowList;
    }

    /**
     * Determine if the type is offered on any surface.
     */
    protected function isOfferedAnywhere(string $name): bool
    {
        return array_any(Surface::cases(), fn (Surface $surface) => $this->offers($name, $surface));
    }

    /**
     * Determine if the type serves the surface and the allow-list lets it be offered there.
     */
    protected function offers(string $name, Surface $surface): bool
    {
        return $this->registered($name)?->initiateShape($surface) !== null
            && $this->allowList->allows($name, $surface);
    }

    /**
     * Get the registered type with the given name, even when the allow-list offers it nowhere.
     */
    protected function registered(string $name): ?CredentialType
    {
        return $this->types[$name] ?? null;
    }
}
