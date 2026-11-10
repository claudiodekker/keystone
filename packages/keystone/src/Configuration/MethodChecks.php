<?php

namespace ClaudioDekker\Keystone\Configuration;

use ClaudioDekker\Keystone\Methods\AllowListEntry;
use ClaudioDekker\Keystone\Methods\ChecksConfiguration;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypeRegistry;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\MethodAllowList;
use ClaudioDekker\Keystone\SignIn\SignInDecision;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

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
        protected SignInDecision $decision,
        protected Application $app,
        protected Config $config,
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
            ...$this->configurations(),
            ...$allowList->problems,
            ...$this->unservedSurfaces($allowList),
            ...$this->unregistered($allowList),
            ...$this->unenrollableSecondFactor(),
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
     * Get the failure of each problem a registered type finds with its own config.
     *
     * @return list<string>
     */
    protected function configurations(): array
    {
        $failures = [];

        foreach ($this->registry->types() as $type) {
            if ($type instanceof ChecksConfiguration) {
                $failures = [...$failures, ...$type->configurationFailures()];
            }
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

    /**
     * Get the failure of requiring a second factor in production while no listed type can be enrolled as one, which would hold every sign-in at a step it can't finish.
     *
     * @return list<string>
     */
    protected function unenrollableSecondFactor(): array
    {
        if (! $this->app->isProduction()
            || $this->config->get('keystone.require_second_factor') !== true
            || $this->decision->enrollable() !== []
        ) {
            return [];
        }

        return ['keystone.require_second_factor is on, and keystone.methods lists no type on both enrollment and the challenge, so no account could enroll a second factor.'];
    }
}
