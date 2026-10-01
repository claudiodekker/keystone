<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
class CredentialTypes
{
    /**
     * The type name core keeps for recovery codes.
     */
    public const string RECOVERY_CODE = 'recovery-code';

    /**
     * The registered credential types, keyed by name.
     *
     * @var array<string, CredentialType>
     */
    protected array $types = [];

    /**
     * The names more than one type was registered under.
     *
     * @var list<string>
     */
    protected array $clashes = [];

    /**
     * Register a credential type, keeping the first type registered under a name and noting the clash for boot to refuse.
     */
    public function register(CredentialType $type): void
    {
        $name = $type->name();

        if ($name === self::RECOVERY_CODE || isset($this->types[$name])) {
            $this->clashes[] = $name;

            return;
        }

        $this->types[$name] = $type;
    }

    /**
     * Get every registered type, whatever keystone.methods lists.
     *
     * @return list<CredentialType>
     */
    public function all(): array
    {
        return array_values($this->types);
    }

    /**
     * Get the registered type with the name, whatever keystone.methods lists.
     */
    public function registered(string $name): ?CredentialType
    {
        return $this->types[$name] ?? null;
    }

    /**
     * Get the names more than one type was registered under.
     *
     * @return list<string>
     */
    public function clashes(): array
    {
        return array_values(array_unique($this->clashes));
    }

    /**
     * Get the named type if it serves the surface.
     */
    public function find(string $name, Surface $surface): ?CredentialType
    {
        $type = $this->types[$name] ?? null;

        return $type !== null && $this->serves($type, $surface) ? $type : null;
    }

    /**
     * Get every type that serves the surface.
     *
     * @return list<CredentialType>
     */
    public function serving(Surface $surface): array
    {
        return array_values(array_filter($this->types, fn (CredentialType $type) => $this->serves($type, $surface)));
    }

    /**
     * Determine if the type serves the surface and keystone.methods allows it there.
     */
    protected function serves(CredentialType $type, Surface $surface): bool
    {
        return isset($type->surfaces()[$surface->value]) && $this->allows($type->name(), $surface);
    }

    /**
     * Determine if keystone.methods allows the type on the surface: null allows every type, a bare entry every surface.
     */
    protected function allows(string $name, Surface $surface): bool
    {
        $methods = config('keystone.methods');

        if (! is_array($methods)) {
            return true;
        }

        foreach ($methods as $key => $entry) {
            if ($key === $name) {
                return is_array($entry) && in_array($surface->value, $entry, true);
            }

            if (is_int($key) && $entry === $name) {
                return true;
            }
        }

        return false;
    }
}
