<?php

namespace ClaudioDekker\Keystone\Methods;

use LogicException;

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
     * Register a credential type.
     */
    public function register(CredentialType $type): void
    {
        $name = $type->name();

        if ($name === self::RECOVERY_CODE || isset($this->types[$name])) {
            throw new LogicException("The credential type name [{$name}] is already taken.");
        }

        $this->types[$name] = $type;
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
     * Determine if the type serves the surface.
     */
    protected function serves(CredentialType $type, Surface $surface): bool
    {
        return isset($type->surfaces()[$surface->value]);
    }
}
