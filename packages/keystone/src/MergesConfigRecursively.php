<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Contracts\Foundation\CachesConfiguration;

/**
 * @internal
 */
trait MergesConfigRecursively
{
    /**
     * Merge the app's config over the package's: maps merge key by key, and anything else the app sets replaces the default whole.
     */
    protected function mergeConfigRecursivelyFrom(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');

        $config->set($key, $this->mergeOver(defaults: require $path, values: $config->get($key, [])));
    }

    /**
     * Merge the values over the defaults, recursing into every map the defaults hold.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    protected function mergeOver(array $defaults, array $values): array
    {
        foreach ($values as $key => $value) {
            $default = $defaults[$key] ?? null;

            $defaults[$key] = $this->isMap($default) && ($value === [] || $this->isMap($value))
                ? $this->mergeOver($default, $value)
                : $value;
        }

        return $defaults;
    }

    /**
     * Determine if the value is an array keyed by name.
     *
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    protected function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }
}
