<?php

namespace ClaudioDekker\Keystone\Methods;

use Illuminate\Support\Arr;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class MethodAllowList
{
    /**
     * Create the allow-list.
     *
     * @param  array<string, AllowListEntry>|null  $entries  the listed types by name, where null offers every type on every surface it serves
     * @param  list<string>  $problems  what is wrong with the value, a sentence each
     */
    public function __construct(
        protected ?array $entries,
        public array $problems,
    ) {
        //
    }

    /**
     * Read the config value: null for every type, else a list of names and names with their surfaces.
     */
    public static function from(mixed $value): static
    {
        if ($value === null) {
            return new static(null, []);
        }

        if (! is_array($value)) {
            return new static([], ['keystone.methods must be null, or a list of credential types by name, each optionally with the list of its surfaces.']);
        }

        $entries = [];
        $problems = [];
        $repeated = [];

        foreach ($value as $key => $item) {
            $entry = static::parse($key, $item);

            if ($entry === null) {
                $problems[] = static::malformed($key);

                continue;
            }

            if (! array_key_exists($entry->name, $entries)) {
                $entries[$entry->name] = $entry;

                continue;
            }

            if (in_array($entry->name, $repeated, true)) {
                continue;
            }

            $repeated[] = $entry->name;
            $problems[] = "keystone.methods names [{$entry->name}] twice.";
        }

        return new static($entries, $problems);
    }

    /**
     * Determine if the type is offered on the surface, as far as the list goes.
     */
    public function allows(string $name, Surface $surface): bool
    {
        if ($this->entries === null) {
            return true;
        }

        $entry = $this->entries[$name] ?? null;

        return $entry?->allows($surface) ?? false;
    }

    /**
     * Get every entry of the list, none when there is no list.
     *
     * @return list<AllowListEntry>
     */
    public function entries(): array
    {
        return array_values($this->entries ?? []);
    }

    /**
     * Read one entry of the config value, null when it is malformed.
     */
    protected static function parse(int|string $key, mixed $item): ?AllowListEntry
    {
        if (is_int($key)) {
            return static::bareName($item);
        }

        $surfaces = static::surfacesOf($item);

        return $surfaces === null ? null : new AllowListEntry($key, $surfaces);
    }

    /**
     * Read a bare entry as a type kept on every surface, null when it is not a name.
     */
    protected static function bareName(mixed $item): ?AllowListEntry
    {
        if (! is_string($item) || $item === '') {
            return null;
        }

        return new AllowListEntry($item, null);
    }

    /**
     * Read the surfaces of an entry, null when they are not a non-empty list of surface values.
     *
     * @return list<Surface>|null
     */
    protected static function surfacesOf(mixed $item): ?array
    {
        if (
            ! is_array($item)
            || $item === []
            || ! array_is_list($item)
        ) {
            return null;
        }

        $surfaces = array_map(
            fn (mixed $surface) => is_string($surface) ? Surface::tryFrom($surface) : null,
            $item,
        );

        if (in_array(null, $surfaces, true)) {
            return null;
        }

        return $surfaces;
    }

    /**
     * Get the problem with the malformed entry.
     */
    protected static function malformed(int|string $key): string
    {
        $values = array_map(fn (Surface $surface) => $surface->value, Surface::cases());
        $surfaces = Arr::join($values, ', ', ' and ');

        return "keystone.methods entry [{$key}] must be a credential type's name, or its name with a non-empty list of surfaces from {$surfaces}.";
    }
}
