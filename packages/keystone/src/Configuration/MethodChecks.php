<?php

namespace ClaudioDekker\Keystone\Configuration;

use ClaudioDekker\Keystone\Methods\AllowListEntry;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypeRegistry;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\MethodAllowList;

/**
 * @internal
 */
class MethodChecks
{
    /**
     * Create the checks.
     */
    public function __construct(
        protected CredentialTypeRegistry $registry,
        protected CredentialTypes $types,
    ) {
        //
    }

    /**
     * Get the failure of each problem with the methods config and the registrations.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        $allowList = $this->types->allowList();

        return [
            ...$this->clashes(),
            ...$allowList->problems,
            ...$this->unservedSurfaces($allowList),
            ...$this->unregistered($allowList),
        ];
    }

    /**
     * Get the failure of each name that more than one package registers.
     *
     * @return list<string>
     */
    protected function clashes(): array
    {
        $failures = [];

        foreach ($this->registry->clashes() as $name => $types) {
            $classes = collect($types)->map(fn (CredentialType $type) => $type::class)->join(', ', ' and ');
            $failures[] = "Two packages register the credential type [{$name}]: {$classes}.";
        }

        return $failures;
    }

    /**
     * Get the failure of each listed surface that the type does not serve.
     *
     * @return list<string>
     */
    protected function unservedSurfaces(MethodAllowList $allowList): array
    {
        $registered = $this->registry->types();
        $failures = [];

        foreach ($allowList->entries() as $entry) {
            $type = $registered[$entry->name] ?? null;

            if ($type === null) {
                continue;
            }

            foreach ($entry->surfaces ?? [] as $surface) {
                if ($type->initiateShape($surface) === null) {
                    $failures[] = "keystone.methods lists [{$entry->name}] on [{$surface->value}], which that type doesn't serve.";
                }
            }
        }

        return $failures;
    }

    /**
     * Get the failure of each listed name that no package registers.
     *
     * @return list<string>
     */
    protected function unregistered(MethodAllowList $allowList): array
    {
        $registered = $this->registry->types();
        $missing = array_filter($allowList->entries(), fn (AllowListEntry $entry) => ! array_key_exists($entry->name, $registered));

        return array_map(
            fn (AllowListEntry $entry) => "keystone.methods names [{$entry->name}], and no installed package registers a credential type with that name.",
            array_values($missing),
        );
    }
}
