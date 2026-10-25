<?php

namespace ClaudioDekker\Keystone\Http;

use Illuminate\Support\Str;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class ContentSecurityPolicy
{
    /**
     * Create the policy.
     *
     * @param  array<string, string>  $directives  Each directive's value by its lowercase name.
     */
    public function __construct(
        protected array $directives,
    ) {
        //
    }

    /**
     * Parse every policy of one header value, since a header may carry several, comma-separated.
     *
     * @return list<static>
     */
    public static function parseList(string $header): array
    {
        return array_map(static::parse(...), explode(',', $header));
    }

    /**
     * Parse one policy, ignoring a repeated directive as a browser does.
     */
    public static function parse(string $policy): static
    {
        $directives = [];

        foreach (explode(';', $policy) as $directive) {
            $directive = Str::squish($directive);

            if ($directive === '') {
                continue;
            }

            [$name, $value] = array_pad(explode(' ', $directive, 2), 2, '');

            $directives[Str::lower($name)] ??= $value;
        }

        return new static($directives);
    }

    /**
     * Get a copy of the policy with the directive set to the value, in place of any value it had.
     */
    public function with(string $directive, string $value): static
    {
        return new static([
            ...$this->directives,
            $directive => $value,
        ]);
    }

    /**
     * Get the policy as a header value.
     */
    public function toString(): string
    {
        $directives = [];

        foreach ($this->directives as $name => $value) {
            $directives[] = trim("{$name} {$value}");
        }

        return implode('; ', $directives);
    }
}
