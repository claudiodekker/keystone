<?php

namespace ClaudioDekker\Keystone\Configuration;

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Arr;

/**
 * Check the methods config against what the installed packages register.
 *
 * @internal
 */
class MethodChecks
{
    /**
     * Get the failure of each problem with the methods config and the registrations.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        $types = Keystone::credentialTypes();

        return [
            ...$this->clashes($types),
            ...$types->allowList()->problems,
            ...$this->undeclaredSurfaces($types),
            ...$this->unregistered($types),
        ];
    }

    /**
     * Get the failure of each name that more than one package registers.
     *
     * @return list<string>
     */
    protected function clashes(CredentialTypes $types): array
    {
        $failures = [];

        foreach ($types->clashes() as $name => $classes) {
            $failures[] = "Two packages register the credential type [{$name}]: ".Arr::join($classes, ', ', ' and ').'.';
        }

        return $failures;
    }

    /**
     * Get the failure of each listed surface that the type does not serve.
     *
     * @return list<string>
     */
    protected function undeclaredSurfaces(CredentialTypes $types): array
    {
        $failures = [];

        foreach ($types->allowList()->names() as $name) {
            $type = $types->registered($name);

            foreach ($types->allowList()->surfaces($name) ?? [] as $surface) {
                if ($type !== null && $type->initiateShape($surface) === null) {
                    $failures[] = $this->undeclared($name, $surface);
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
    protected function unregistered(CredentialTypes $types): array
    {
        $missing = array_filter($types->allowList()->names(), fn (string $name) => $types->registered($name) === null);

        return array_map(
            fn (string $name) => "keystone.methods names [{$name}], and no installed package registers a credential type with that name.",
            array_values($missing),
        );
    }

    /**
     * Get the failure for a type listed on a surface it does not serve.
     */
    protected function undeclared(string $name, Surface $surface): string
    {
        return "keystone.methods lists [{$name}] on [{$surface->value}], which that type doesn't serve.";
    }
}
