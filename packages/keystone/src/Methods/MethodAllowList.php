<?php

namespace ClaudioDekker\Keystone\Methods;

use Illuminate\Support\Arr;

/**
 * The methods config key read as an allow-list: which credential types are offered on which surfaces, narrowing what the installed types declare.
 *
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class MethodAllowList
{
    /**
     * The problem with a value that is neither null nor a list.
     */
    protected const string NOT_A_LIST = 'keystone.methods must be null, or a list of credential types by name, each optionally with the list of its surfaces.';

    /**
     * Create the allow-list.
     *
     * @param  array<string, list<Surface>|null>|null  $entries  the surfaces each listed type is narrowed to, by name, where null keeps every surface the type declares
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
            return new static([], [static::NOT_A_LIST]);
        }

        $entries = [];
        $problems = [];
        $repeated = [];

        foreach ($value as $key => $item) {
            $entry = static::parse($key, $item);

            if ($entry === null) {
                $problems[] = static::malformed($key);
            } elseif (! array_key_exists($entry['name'], $entries)) {
                $entries[$entry['name']] = $entry['surfaces'];
            } elseif (! in_array($entry['name'], $repeated, true)) {
                $repeated[] = $entry['name'];
                $problems[] = "keystone.methods names [{$entry['name']}] twice.";
            }
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

        if (! array_key_exists($name, $this->entries)) {
            return false;
        }

        $surfaces = $this->entries[$name];

        return $surfaces === null || in_array($surface, $surfaces, true);
    }

    /**
     * Get the name of every type the list names, none when there is no list.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->entries ?? []);
    }

    /**
     * Get the surfaces the list narrows the type to.
     *
     * @return list<Surface>|null null for a type that keeps every surface it declares
     */
    public function surfaces(string $name): ?array
    {
        return $this->entries[$name] ?? null;
    }

    /**
     * Read one entry as a type's name and the surfaces it is narrowed to, null when the entry is malformed.
     *
     * @return array{name: string, surfaces: list<Surface>|null}|null
     */
    protected static function parse(int|string $key, mixed $item): ?array
    {
        if (is_int($key)) {
            return static::bareName($item);
        }

        $surfaces = static::surfacesOf($item);

        return $surfaces === null ? null : ['name' => $key, 'surfaces' => $surfaces];
    }

    /**
     * Read a bare entry as a type's name kept on every surface, null when it is not a name.
     *
     * @return array{name: string, surfaces: null}|null
     */
    protected static function bareName(mixed $item): ?array
    {
        if (! is_string($item) || $item === '') {
            return null;
        }

        return ['name' => $item, 'surfaces' => null];
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

        $surfaces = array_map(fn (mixed $surface) => is_string($surface) ? Surface::tryFrom($surface) : null, $item);

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
        $surfaces = Arr::join(array_map(fn (Surface $surface) => $surface->value, Surface::cases()), ', ', ' and ');

        return "keystone.methods entry [{$key}] must be a credential type's name, or its name with a non-empty list of surfaces from {$surfaces}.";
    }
}
