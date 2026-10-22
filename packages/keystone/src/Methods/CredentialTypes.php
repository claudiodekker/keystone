<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * The registered credential types, as far as the methods config lets them be offered.
 *
 * @internal
 */
readonly class CredentialTypes
{
    /**
     * Create the set from what the packages registered and the methods config allows.
     *
     * @param  array<string, CredentialType>  $types  by name
     * @param  array<string, list<class-string<CredentialType>>>  $clashes  every class that registered a name more than once, in order, by name
     */
    public function __construct(
        protected array $types,
        protected MethodAllowList $allowList,
        protected array $clashes,
    ) {
        //
    }

    /**
     * Find the type with the given name, when it is offered on the surface.
     */
    public function find(string $name, Surface $surface): ?CredentialType
    {
        $type = $this->types[$name] ?? null;

        if ($type === null || ! $this->offers($name, $surface)) {
            return null;
        }

        return $type;
    }

    /**
     * Get the registered type with the given name, even when the allow-list offers it nowhere.
     */
    public function registered(string $name): ?CredentialType
    {
        return $this->types[$name] ?? null;
    }

    /**
     * Get the name of every type the allow-list lets be offered.
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
     * Get the classes that registered each name more than once, in order, by name.
     *
     * @return array<string, list<class-string<CredentialType>>>
     */
    public function clashes(): array
    {
        return $this->clashes;
    }

    /**
     * Determine if the allow-list lets the type be offered on any surface.
     */
    protected function isOfferedAnywhere(string $name): bool
    {
        return array_any(Surface::cases(), fn (Surface $surface) => $this->allowList->allows($name, $surface));
    }

    /**
     * Determine if the type serves the surface and the allow-list lets it be offered there.
     */
    protected function offers(string $name, Surface $surface): bool
    {
        return $this->types[$name]->initiateShape($surface) !== null
            && $this->allowList->allows($name, $surface);
    }
}
