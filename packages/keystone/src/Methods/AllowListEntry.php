<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
readonly class AllowListEntry
{
    /**
     * Create the entry.
     *
     * @param  list<Surface>|null  $surfaces  the surfaces the type is narrowed to, where null keeps every surface it serves
     */
    public function __construct(
        public string $name,
        public ?array $surfaces,
    ) {
        //
    }

    /**
     * Determine if the entry lets the type be offered on the surface.
     */
    public function allows(Surface $surface): bool
    {
        return $this->surfaces === null || in_array($surface, $this->surfaces, true);
    }
}
